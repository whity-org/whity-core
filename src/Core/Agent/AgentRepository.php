<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;

/**
 * Storage for workspace agents (WHIT-815, migration 180): each a persona plus
 * the toolsets and starting grant an assignment begins from.
 *
 * Every read and write is scoped to the tenant. Rows are never deleted through
 * here: archiving keeps the agent's name on its history (activity and
 * proposals name the agent long after it stops being offered).
 */
final class AgentRepository
{
    public const ACTIVE   = 'active';
    public const ARCHIVED = 'archived';

    /** Persona columns a create or update may set, as {@see AgentDefinition} names them. */
    private const FIELDS = [
        'slug', 'name', 'name_ar', 'role', 'role_ar', 'instructions', 'instructions_ar', 'tone', 'language',
    ];

    private const COLUMNS = 'id, tenant_id, slug, name, name_ar, role, role_ar, instructions, instructions_ar, tone,
                             language, toolsets, grant_template, preset_key, preset_version, status,
                             created_by_profile_id, updated_by_profile_id, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> the tenant's agents, by name; archived ones only when asked */
    public function list(int $tenantId, bool $includeArchived = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM agents WHERE tenant_id = :tenant_id'
            . ($includeArchived ? '' : ' AND status = :status') . ' ORDER BY name, id';
        $stmt = $this->db->prepare($sql);
        $params = [':tenant_id' => $tenantId];
        if (!$includeArchived) {
            $params[':status'] = self::ACTIVE;
        }
        $stmt->execute($params);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM agents WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /** Whether the slug is taken in the tenant, by any agent other than $exceptId (archived ones included). */
    public function slugTaken(int $tenantId, string $slug, ?int $exceptId = null): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM agents WHERE tenant_id = :tenant_id AND slug = :slug');
        $stmt->execute([':tenant_id' => $tenantId, ':slug' => $slug]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A free slug for the tenant: $base, else $base-2, $base-3 and so on.
     */
    public function freeSlug(int $tenantId, string $base): string
    {
        $base = $base === '' ? 'agent' : $base;
        $slug = $base;
        for ($n = 2; $this->slugTaken($tenantId, $slug); $n++) {
            $suffix = '-' . $n;
            $slug   = rtrim(substr($base, 0, AgentDefinition::SLUG_MAX - strlen($suffix)), '-') . $suffix;
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $fields validated by {@see AgentDefinition::validate()}; `slug` and `name` set
     */
    public function create(int $tenantId, array $fields, int $actorProfileId, ?string $presetKey = null, ?int $presetVersion = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO agents
                 (tenant_id, slug, name, name_ar, role, role_ar, instructions, instructions_ar, tone, language,
                  toolsets, grant_template, preset_key, preset_version, status,
                  created_by_profile_id, updated_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :slug, :name, :name_ar, :role, :role_ar, :instructions, :instructions_ar, :tone, :language,
                  :toolsets, :grant_template, :preset_key, :preset_version, :status,
                  :actor, :actor, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':slug'            => (string) $fields['slug'],
            ':name'            => (string) $fields['name'],
            ':name_ar'         => (string) ($fields['name_ar'] ?? ''),
            ':role'            => (string) ($fields['role'] ?? ''),
            ':role_ar'         => (string) ($fields['role_ar'] ?? ''),
            ':instructions'    => (string) ($fields['instructions'] ?? ''),
            ':instructions_ar' => (string) ($fields['instructions_ar'] ?? ''),
            ':tone'            => (string) ($fields['tone'] ?? 'plain'),
            ':language'        => (string) ($fields['language'] ?? 'auto'),
            ':toolsets'        => self::encode(array_values((array) ($fields['toolsets'] ?? []))),
            ':grant_template'  => self::encode(self::object((array) ($fields['grant'] ?? []))),
            ':preset_key'      => $presetKey,
            ':preset_version'  => $presetVersion,
            ':status'          => self::ACTIVE,
            ':actor'           => $actorProfileId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Change the fields given; the rest stay. True when the agent exists in the tenant.
     *
     * @param array<string, mixed> $fields validated by {@see AgentDefinition::validate()} with `$partial`
     */
    public function update(int $id, int $tenantId, array $fields, int $actorProfileId): bool
    {
        $sets   = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId, ':actor' => $actorProfileId];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $sets[]               = "{$field} = :{$field}";
                $params[":{$field}"] = (string) $fields[$field];
            }
        }
        if (array_key_exists('toolsets', $fields)) {
            $sets[]              = 'toolsets = :toolsets';
            $params[':toolsets'] = self::encode(array_values((array) $fields['toolsets']));
        }
        if (array_key_exists('grant', $fields)) {
            $sets[]                    = 'grant_template = :grant_template';
            $params[':grant_template'] = self::encode(self::object((array) $fields['grant']));
        }
        $sets[] = 'updated_by_profile_id = :actor';
        $sets[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare('UPDATE agents SET ' . implode(', ', $sets) . ' WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    /** Set the status (active or archived). True when the agent exists in the tenant. */
    public function setStatus(int $id, int $tenantId, string $status, int $actorProfileId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE agents SET status = :status, updated_by_profile_id = :actor, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':status' => $status, ':actor' => $actorProfileId, ':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $json = static function (mixed $value): array {
            $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
            return is_array($decoded) ? $decoded : [];
        };

        return [
            'id'                    => (int) $row['id'],
            'tenant_id'             => (int) $row['tenant_id'],
            'slug'                  => (string) $row['slug'],
            'name'                  => (string) $row['name'],
            'name_ar'               => (string) $row['name_ar'],
            'role'                  => (string) $row['role'],
            'role_ar'               => (string) $row['role_ar'],
            'instructions'          => (string) $row['instructions'],
            'instructions_ar'       => (string) $row['instructions_ar'],
            'tone'                  => (string) $row['tone'],
            'language'              => (string) $row['language'],
            'toolsets'              => array_values(array_filter($json($row['toolsets']), 'is_string')),
            'grant'                 => array_filter($json($row['grant_template']), 'is_string'),
            'preset_key'            => $row['preset_key'] === null ? null : (string) $row['preset_key'],
            'preset_version'        => $row['preset_version'] === null ? null : (int) $row['preset_version'],
            'status'                => (string) $row['status'],
            'created_by_profile_id' => $row['created_by_profile_id'] === null ? null : (int) $row['created_by_profile_id'],
            'updated_by_profile_id' => $row['updated_by_profile_id'] === null ? null : (int) $row['updated_by_profile_id'],
            'created_at'            => (string) $row['created_at'],
            'updated_at'            => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $map */
    private static function object(array $map): object
    {
        return (object) $map;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
