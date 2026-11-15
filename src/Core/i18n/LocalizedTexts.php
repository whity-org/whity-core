<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;

/**
 * The `localized_texts` table: other-language names of stored entities (#821).
 *
 * An entity keeps its ORIGINAL name in its own column (`tenants.name`), with
 * the language of that name beside it (`tenants.name_language`). This class
 * reads and writes the other languages, one `localized_texts` row each.
 *
 * In the API a name travels as one map of every language it has, original
 * included (`{"ar": "...", "en": "..."}`), plus the original's language. The
 * split between the column and this table is a storage detail. Reading,
 * merging and picking a name are {@see LocalizedText}'s, the one bilingual
 * text model (WHIT-1038); its fallback rule puts the original before English.
 *
 * Stateless apart from the connection; worker-safe.
 */
final class LocalizedTexts
{
    public const ENTITY_TENANT = 'tenant';
    public const ENTITY_OU = 'ou';

    public const FIELD_NAME = 'name';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The other-language texts for several entities of one workspace.
     *
     * @param array<int> $entityIds
     * @return array<int, array<string, string>> entity id => language => text
     */
    public function forEntities(int $tenantId, string $entityType, array $entityIds, string $field = self::FIELD_NAME): array
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        if ($entityIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($entityIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT entity_id, language_code, value FROM localized_texts
              WHERE tenant_id = ? AND entity_type = ? AND field = ? AND entity_id IN ({$placeholders})
              ORDER BY language_code"
        );
        $stmt->execute([$tenantId, $entityType, $field, ...$entityIds]);

        return self::group($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The other-language names of several workspaces. A workspace's own name is
     * stored under its own id as tenant_id, so each row is bound to the
     * workspace it names.
     *
     * @param array<int> $tenantIds
     * @return array<int, array<string, string>> tenant id => language => text
     */
    public function forTenants(array $tenantIds): array
    {
        $tenantIds = array_values(array_unique(array_map('intval', $tenantIds)));
        if ($tenantIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($tenantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT entity_id, language_code, value FROM localized_texts
              WHERE tenant_id IN ({$placeholders}) AND entity_id = tenant_id
                AND entity_type = ? AND field = ?
              ORDER BY language_code"
        );
        $stmt->execute([...$tenantIds, self::ENTITY_TENANT, self::FIELD_NAME]);

        return self::group($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Replace an entity's other-language texts for one field with `$texts`.
     * Languages left out are removed. Callers pass the map WITHOUT the original
     * language, which lives in the entity's own column.
     *
     * @param array<string, string> $texts
     */
    public function replace(int $tenantId, string $entityType, int $entityId, array $texts, string $field = self::FIELD_NAME): void
    {
        $delete = $this->db->prepare(
            'DELETE FROM localized_texts
              WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? AND field = ?'
        );
        $delete->execute([$tenantId, $entityType, $entityId, $field]);

        if ($texts === []) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO localized_texts (tenant_id, entity_type, entity_id, field, language_code, value, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        foreach ($texts as $language => $text) {
            $insert->execute([$tenantId, $entityType, $entityId, $field, $language, $text]);
        }
    }

    /**
     * Remove every text of one entity, when the entity itself is deleted.
     */
    public function forget(int $tenantId, string $entityType, int $entityId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM localized_texts WHERE tenant_id = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$tenantId, $entityType, $entityId]);
    }

    /**
     * Remove every text a workspace holds, when the workspace is deleted.
     */
    public function forgetTenant(int $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM localized_texts WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
    }

    /**
     * @param array<mixed> $rows
     * @return array<int, array<string, string>>
     */
    private static function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['entity_id'], $row['language_code'], $row['value'])) {
                continue;
            }
            $out[(int) $row['entity_id']][(string) $row['language_code']] = (string) $row['value'];
        }

        return $out;
    }
}
