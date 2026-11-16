<?php

declare(strict_types=1);

namespace Whity\Core\CustomFields;

use PDO;

/**
 * Data access for `custom_field_definitions` and `custom_field_values`
 * (WHIT-954, migration 263). TENANT-OWNED: every statement binds `tenant_id`.
 */
final class CustomFieldRepository
{
    private const COLUMNS = 'id, tenant_id, record_type, field_key, label, kind, options_json, show_in_list, is_required, edit_permission,
        sort_order, created_by_profile_id, archived_at, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(int $tenantId, string $recordType, bool $withArchived = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM custom_field_definitions
              WHERE tenant_id = :tenant_id AND record_type = :record_type' . ($withArchived ? '' : ' AND archived_at IS NULL') . '
              ORDER BY sort_order, id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':record_type' => $recordType]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, int> record type => number of live fields */
    public function counts(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT record_type, COUNT(*) AS n FROM custom_field_definitions
              WHERE tenant_id = :tenant_id AND archived_at IS NULL GROUP BY record_type'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['record_type']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM custom_field_definitions WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function keyTaken(int $tenantId, string $recordType, string $key): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM custom_field_definitions WHERE tenant_id = :tenant_id AND record_type = :record_type AND field_key = :field_key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':record_type' => $recordType, ':field_key' => $key]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{record_type: string, field_key: string, label: string, kind: string, options_json: string, show_in_list: bool, is_required: bool, edit_permission: ?string} $row
     */
    public function insert(int $tenantId, array $row, ?int $actor): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO custom_field_definitions
                (tenant_id, record_type, field_key, label, kind, options_json, show_in_list, is_required, edit_permission, sort_order, created_by_profile_id, created_at, updated_at)
             VALUES (:tenant_id, :record_type, :field_key, :label, :kind, :options_json, :show_in_list, :is_required, :edit_permission,
                (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM custom_field_definitions WHERE tenant_id = :tenant_id2 AND record_type = :record_type2),
                :actor, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id2', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':record_type', $row['record_type']);
        $stmt->bindValue(':record_type2', $row['record_type']);
        $stmt->bindValue(':field_key', $row['field_key']);
        $stmt->bindValue(':label', $row['label']);
        $stmt->bindValue(':kind', $row['kind']);
        $stmt->bindValue(':options_json', $row['options_json']);
        $stmt->bindValue(':show_in_list', $row['show_in_list'], PDO::PARAM_BOOL);
        $stmt->bindValue(':is_required', $row['is_required'], PDO::PARAM_BOOL);
        $stmt->bindValue(':edit_permission', $row['edit_permission'], $row['edit_permission'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':actor', $actor, $actor === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $fields label, options_json, show_in_list, is_required, edit_permission, sort_order */
    public function update(int $tenantId, int $id, array $fields): void
    {
        $sets = [];
        foreach (['label', 'options_json', 'show_in_list', 'is_required', 'edit_permission', 'sort_order'] as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = $column . ' = :' . $column;
            }
        }
        if ($sets === []) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE custom_field_definitions SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        foreach ($fields as $column => $value) {
            if (!in_array($column, ['label', 'options_json', 'show_in_list', 'is_required', 'edit_permission', 'sort_order'], true)) {
                continue;
            }
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(':' . $column, $value, $type);
        }
        $stmt->execute();
    }

    public function setArchived(int $tenantId, int $id, bool $archived): void
    {
        $stmt = $this->db->prepare(
            'UPDATE custom_field_definitions SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP' : 'NULL') . ', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
    }

    /**
     * Values of live fields on these records: record id => field key => value.
     *
     * @param list<int> $recordIds
     * @return array<int, array<string, mixed>>
     */
    public function values(int $tenantId, string $recordType, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }
        $in = [];
        $params = [':tenant_id' => $tenantId, ':record_type' => $recordType];
        foreach ($recordIds as $i => $id) {
            $in[] = ':r' . $i;
            $params[':r' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT v.record_id, d.field_key, v.value_json
               FROM custom_field_values v
               JOIN custom_field_definitions d ON d.id = v.field_id AND d.tenant_id = v.tenant_id
              WHERE v.tenant_id = :tenant_id AND v.record_type = :record_type AND d.archived_at IS NULL
                AND v.record_id IN (' . implode(', ', $in) . ')'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['record_id']][(string) $row['field_key']] = json_decode((string) $row['value_json'], true);
        }

        return $out;
    }

    public function setValue(int $tenantId, int $fieldId, string $recordType, int $recordId, mixed $value, string $text): void
    {
        $this->clearValue($tenantId, $fieldId, $recordId);
        $stmt = $this->db->prepare(
            'INSERT INTO custom_field_values (tenant_id, field_id, record_type, record_id, value_json, value_text, updated_at)
             VALUES (:tenant_id, :field_id, :record_type, :record_id, :value_json, :value_text, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':field_id' => $fieldId,
            ':record_type' => $recordType,
            ':record_id' => $recordId,
            ':value_json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':value_text' => mb_substr($text, 0, 1000),
        ]);
    }

    public function clearValue(int $tenantId, int $fieldId, int $recordId): void
    {
        $stmt = $this->db->prepare('DELETE FROM custom_field_values WHERE tenant_id = :tenant_id AND field_id = :field_id AND record_id = :record_id');
        $stmt->execute([':tenant_id' => $tenantId, ':field_id' => $fieldId, ':record_id' => $recordId]);
    }

    public function clearRecord(int $tenantId, string $recordType, int $recordId): void
    {
        $stmt = $this->db->prepare('DELETE FROM custom_field_values WHERE tenant_id = :tenant_id AND record_type = :record_type AND record_id = :record_id');
        $stmt->execute([':tenant_id' => $tenantId, ':record_type' => $recordType, ':record_id' => $recordId]);
    }

    /** How many records have a value for this field. */
    public function usage(int $tenantId, int $fieldId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM custom_field_values WHERE tenant_id = :tenant_id AND field_id = :field_id');
        $stmt->execute([':tenant_id' => $tenantId, ':field_id' => $fieldId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A predicate for a list query: the record has this value in this field.
     * For a choose-several field the value is one of its choices.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function filterPredicate(string $recordAlias, int $fieldId, string $value, bool $multi, string $paramPrefix): array
    {
        $params = [':' . $paramPrefix . 'f' => $fieldId];
        if ($multi) {
            $match = 'v.value_text LIKE :' . $paramPrefix . 'v';
            $params[':' . $paramPrefix . 'v'] = '%' . "\n" . str_replace(['%', '_'], '', $value) . "\n" . '%';
        } else {
            $match = 'LOWER(v.value_text) = LOWER(:' . $paramPrefix . 'v)';
            $params[':' . $paramPrefix . 'v'] = $value;
        }

        return [
            'EXISTS (SELECT 1 FROM custom_field_values v WHERE v.tenant_id = ' . $recordAlias . '.tenant_id AND v.record_id = ' . $recordAlias . '.id
                AND v.field_id = :' . $paramPrefix . 'f AND ' . $match . ')',
            $params,
        ];
    }
}
