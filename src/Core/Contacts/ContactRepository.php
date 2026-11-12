<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data access for `contacts` (WHIT-953, migration 261).
 *
 * TENANT-OWNED: every statement binds `tenant_id`, so an id from another
 * workspace finds nothing. Validation lives in {@see ContactService}; this
 * class only reads and writes rows.
 */
final class ContactRepository
{
    /** The text columns a caller may write, with their length limits. */
    public const TEXT_FIELDS = [
        'name' => 200,
        'contact_person' => 200,
        'email' => 320,
        'mobile' => 40,
        'phone' => 40,
        'tax_number' => 64,
        'payment_terms' => 200,
        'address' => 1000,
        'notes' => 4000,
    ];

    /** The keys an import or a lookup may match an existing contact on. */
    public const MATCH_KEYS = ['tax_number', 'mobile', 'email', 'name'];

    private const COLUMNS = 'id, tenant_id, is_customer, is_supplier, name, contact_person, email, mobile, phone,
        tax_number, payment_terms, address, notes, profile_id, created_by_profile_id, archived_at, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $fields validated fields
     */
    public function insert(int $tenantId, array $fields, ?int $actor): int
    {
        $columns = ['tenant_id', 'created_by_profile_id', 'created_at', 'updated_at'];
        $values = [':tenant_id', ':actor', 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'];
        $params = [':tenant_id' => $tenantId, ':actor' => $actor];
        foreach ($this->writable($fields) as $column => $value) {
            $columns[] = $column;
            $values[] = ':' . $column;
            $params[':' . $column] = $value;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO contacts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ') RETURNING id'
        );
        $this->bind($stmt, $params);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $fields validated fields
     */
    public function update(int $tenantId, int $id, array $fields): bool
    {
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];
        foreach ($this->writable($fields) as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        if ($sets === []) {
            return $this->find($tenantId, $id) !== null;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare('UPDATE contacts SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id');
        $this->bind($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM contacts WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The first contact (not archived) whose `$key` equals `$value`, ignoring
     * case for name and email. Null when none, or when the value is empty.
     *
     * @return array<string, mixed>|null
     */
    public function findBy(int $tenantId, string $key, string $value): ?array
    {
        if (!in_array($key, self::MATCH_KEYS, true) || trim($value) === '') {
            return null;
        }
        $predicate = in_array($key, ['name', 'email'], true) ? 'LOWER(' . $key . ') = LOWER(:value)' : $key . ' = :value';
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contacts
              WHERE tenant_id = :tenant_id AND archived_at IS NULL AND ' . $predicate . '
              ORDER BY id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':value' => trim($value)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{kind?: ?string, tag_id?: ?int, q?: ?string, archived?: bool, ids?: ?list<int>} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($tenantId, $filter);
        // @tenant-guard-ignore: where() always starts with c.tenant_id = :tenant_id
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contacts c WHERE ' . $where . ' ORDER BY LOWER(c.name), c.id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{kind?: ?string, tag_id?: ?int, q?: ?string, archived?: bool, ids?: ?list<int>} $filter
     */
    public function count(int $tenantId, array $filter): int
    {
        [$where, $params] = $this->where($tenantId, $filter);
        // @tenant-guard-ignore: where() always starts with c.tenant_id = :tenant_id
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM contacts c WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function setArchived(int $tenantId, int $id, bool $archived): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE contacts SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP' : 'NULL') . ', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Removes a contact for good. Only an import's undo does this. */
    public function delete(int $tenantId, int $id): bool
    {
        $tags = $this->db->prepare("DELETE FROM entity_tags WHERE tenant_id = :tenant_id AND entity_type = 'contact' AND entity_id = :id");
        $tags->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM contacts WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The tags on each of these contacts, keyed by contact id.
     *
     * @param list<int> $ids
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function tagsFor(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [':tenant_id' => $tenantId];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params[':id' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            "SELECT et.entity_id, t.id, t.name
               FROM entity_tags et
               JOIN tags t ON t.id = et.tag_id AND t.tenant_id = et.tenant_id
              WHERE et.tenant_id = :tenant_id AND et.entity_type = 'contact'
                AND et.entity_id IN (" . implode(', ', $placeholders) . ')
              ORDER BY t.name'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['entity_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return $out;
    }

    /**
     * A profile with this verified email that belongs to the workspace, or
     * null. Used to link a new contact to the person it already is.
     */
    public function memberProfileByEmail(int $tenantId, string $email): ?int
    {
        if (trim($email) === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT pe.profile_id
               FROM profile_emails pe
               JOIN memberships m ON m.profile_id = pe.profile_id AND m.tenant_id = :tenant_id
              WHERE LOWER(pe.email) = LOWER(:email) AND pe.verified = TRUE
              ORDER BY pe.profile_id LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':email' => trim($email)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function isMemberProfile(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id');
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /** The display name of a linked profile, for the record page. */
    public function profileName(int $profileId): ?string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /**
     * @param array{kind?: ?string, tag_id?: ?int, q?: ?string, archived?: bool, ids?: ?list<int>} $filter
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(int $tenantId, array $filter): array
    {
        $where = ['c.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        $where[] = ($filter['archived'] ?? false) ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL';
        $kind = $filter['kind'] ?? null;
        if ($kind === 'customer') {
            $where[] = 'c.is_customer = TRUE';
        } elseif ($kind === 'supplier') {
            $where[] = 'c.is_supplier = TRUE';
        }
        $tagId = $filter['tag_id'] ?? null;
        if (is_int($tagId) && $tagId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM entity_tags et WHERE et.tenant_id = c.tenant_id AND et.entity_type = 'contact'
                        AND et.entity_id = c.id AND et.tag_id = :tag_id)";
            $params[':tag_id'] = $tagId;
        }
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(LOWER(c.name) LIKE :q OR LOWER(c.contact_person) LIKE :q2 OR LOWER(c.email) LIKE :q3 OR c.mobile LIKE :q4 OR c.tax_number LIKE :q5)';
            // Wildcards are dropped rather than escaped: the two engines escape differently.
            $like = '%' . mb_strtolower(str_replace(['%', '_', '\\'], '', $q)) . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
        }
        $ids = $filter['ids'] ?? null;
        if (is_array($ids)) {
            if ($ids === []) {
                $where[] = '1 = 0';
            } else {
                $in = [];
                foreach ($ids as $i => $id) {
                    $in[] = ':cid' . $i;
                    $params[':cid' . $i] = $id;
                }
                $where[] = 'c.id IN (' . implode(', ', $in) . ')';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function writable(array $fields): array
    {
        $out = [];
        foreach (array_keys(self::TEXT_FIELDS) as $column) {
            if (array_key_exists($column, $fields)) {
                $out[$column] = (string) $fields[$column];
            }
        }
        foreach (['is_customer', 'is_supplier'] as $column) {
            if (array_key_exists($column, $fields)) {
                $out[$column] = DbBool::of($fields[$column]);
            }
        }
        if (array_key_exists('profile_id', $fields)) {
            $out['profile_id'] = $fields['profile_id'] === null ? null : (int) $fields['profile_id'];
        }

        return $out;
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
    }
}
