<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use PDO;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;

/**
 * Contacts' tags, on core's taxonomy (WHIT-953).
 *
 * A contact's tags live in one tag group per workspace, key `contacts`, made
 * the first time a tag is needed. Tags are named, found case-insensitively,
 * and created on first use. They are attached through `entity_tags` with
 * entity type `contact`, so the Tags settings page manages them like any other.
 *
 * Every lookup reads before it writes, so a name that exists is never
 * re-inserted: inside an import's transaction a unique violation would abort
 * the whole transaction on Postgres.
 */
final class ContactTags
{
    public const ENTITY_TYPE = 'contact';
    public const GROUP_KEY = 'contacts';
    public const MAX_NAME = 128;

    private readonly TagGroupRepository $groups;
    private readonly TagRepository $tags;
    private readonly EntityTagRepository $entityTags;

    public function __construct(private readonly PDO $db)
    {
        $this->groups = new TagGroupRepository($db);
        $this->tags = new TagRepository($db);
        $this->entityTags = new EntityTagRepository($db);
    }

    /** The contacts tag group's id, or null when it has not been made yet. */
    public function groupId(int $tenantId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM tag_groups WHERE tenant_id = :tenant_id AND group_key = :key');
        $stmt->execute([':tenant_id' => $tenantId, ':key' => self::GROUP_KEY]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** The id of the tag with this name, or null. */
    public function find(int $tenantId, string $name): ?int
    {
        $groupId = $this->groupId($tenantId);
        if ($groupId === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM tags WHERE tenant_id = :tenant_id AND group_id = :group_id AND LOWER(name) = LOWER(:name) ORDER BY id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':group_id' => $groupId, ':name' => $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** The tag with this name, made when it does not exist yet. */
    public function findOrCreate(int $tenantId, string $name): int
    {
        $name = self::clean($name);
        $existing = $this->find($tenantId, $name);
        if ($existing !== null) {
            return $existing;
        }
        $groupId = $this->groupId($tenantId)
            ?? $this->groups->create($tenantId, self::GROUP_KEY, ['en' => 'Contacts', 'ar' => 'جهات الاتصال'])
            ?? $this->groupId($tenantId);
        if ($groupId === null) {
            throw new \RuntimeException('The contacts tag group could not be made');
        }
        $id = $this->tags->create($tenantId, $groupId, $name) ?? $this->find($tenantId, $name);
        if ($id === null) {
            throw new \RuntimeException('The tag could not be made');
        }

        return $id;
    }

    /**
     * Make the contact's tags exactly these names. Returns the tag ids it added.
     *
     * @param list<string> $names
     * @return list<int>
     */
    public function replace(int $tenantId, int $contactId, array $names): array
    {
        $wanted = [];
        foreach ($names as $name) {
            $wanted[$this->findOrCreate($tenantId, $name)] = true;
        }
        $current = [];
        foreach ($this->entityTags->tagsForEntity($tenantId, self::ENTITY_TYPE, $contactId) as $tag) {
            $current[(int) $tag['id']] = true;
        }
        foreach (array_keys($current) as $tagId) {
            if (!isset($wanted[$tagId])) {
                $this->entityTags->detach($tenantId, self::ENTITY_TYPE, $contactId, $tagId);
            }
        }
        $added = [];
        foreach (array_keys($wanted) as $tagId) {
            if (!isset($current[$tagId])) {
                $this->entityTags->attach($tenantId, self::ENTITY_TYPE, $contactId, $tagId);
                $added[] = $tagId;
            }
        }

        return $added;
    }

    /**
     * Add these tags to the contact, keeping the ones it has. Returns the tag
     * ids that were not there before.
     *
     * @param list<string> $names
     * @return list<int>
     */
    public function add(int $tenantId, int $contactId, array $names): array
    {
        $added = [];
        foreach ($names as $name) {
            $tagId = $this->findOrCreate($tenantId, $name);
            if ($this->entityTags->attach($tenantId, self::ENTITY_TYPE, $contactId, $tagId)) {
                $added[] = $tagId;
            }
        }

        return $added;
    }

    /** @param list<int> $tagIds */
    public function remove(int $tenantId, int $contactId, array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $this->entityTags->detach($tenantId, self::ENTITY_TYPE, $contactId, $tagId);
        }
    }

    /**
     * The contacts tags with how many contacts carry each, for the filter.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    public function listWithCounts(int $tenantId): array
    {
        $groupId = $this->groupId($tenantId);
        if ($groupId === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT t.id, t.name, COUNT(c.id) AS uses
               FROM tags t
               LEFT JOIN entity_tags et ON et.tag_id = t.id AND et.tenant_id = t.tenant_id AND et.entity_type = 'contact'
               LEFT JOIN contacts c ON c.id = et.entity_id AND c.tenant_id = t.tenant_id AND c.archived_at IS NULL
              WHERE t.tenant_id = :tenant_id AND t.group_id = :group_id
              GROUP BY t.id, t.name
              ORDER BY t.name"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);

        return array_values(array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['uses']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** A tag name trimmed, with its inner spaces collapsed and cut to the limit. */
    public static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new ContactRejectedException('A tag needs a name');
        }

        return mb_substr($name, 0, self::MAX_NAME);
    }
}
