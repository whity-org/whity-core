<?php

declare(strict_types=1);

namespace Whity\Core\Document\SavedBlock;

use PDO;

/**
 * Data access for saved blocks (editor M7; E.21-E.24, E.38; migrations 260
 * and 265): `saved_blocks`, the insert-only `saved_block_versions`, and
 * `saved_block_uses`.
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate
 * (literal SQL, so the CI tenant-predicate guard verifies it). A blocks change
 * bumps `version` only while it is still the version that was read, in the
 * same transaction as the new version row, so two people saving at once can't
 * both make version 3.
 */
final class SavedBlockRepository
{
    public const SYNCED = 'synced';
    public const COPY = 'copy';
    public const MODES = [self::SYNCED, self::COPY];

    public const SHARING_ME = 'me';
    public const SHARING_WORKSPACE = 'workspace';
    public const SHARINGS = [self::SHARING_ME, self::SHARING_WORKSPACE];

    private const COLUMNS = 'b.id, b.tenant_id, b.name, b.description, b.mode, b.sharing, b.owner_profile_id,
        b.blocks, b.per_document_keys, b.per_document_parts, b.version, b.archived_at, b.created_at, b.updated_at,
        p.display_name AS owner_name,
        (SELECT COUNT(*) FROM saved_block_uses u WHERE u.tenant_id = b.tenant_id AND u.saved_block_id = b.id) AS used_in';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Creates a saved block at version 1 with its first version row.
     *
     * @param array{name: string, description: ?string, mode: string, sharing: string,
     *              owner_profile_id: int, blocks: list<array<string, mixed>>,
     *              per_document_parts?: list<array<string, mixed>>, per_document_keys?: list<string>} $rec
     *        `per_document_parts` (E.23a) default to the parts found in the blocks;
     *        `per_document_keys` is ignored (kept for one release, it's the parts' keys).
     */
    public function create(int $tenantId, array $rec): int
    {
        $parts = $rec['per_document_parts'] ?? PerDocumentParts::fromBlocks($rec['blocks']);
        $own = $this->begin();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO saved_blocks
                    (tenant_id, name, description, mode, sharing, owner_profile_id, blocks, per_document_keys, per_document_parts, version, created_at, updated_at)
                 VALUES (:tenant_id, :name, :description, :mode, :sharing, :owner, :blocks, :keys, :parts, 1, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'   => $tenantId,
                ':name'        => $rec['name'],
                ':description' => $rec['description'],
                ':mode'        => $rec['mode'],
                ':sharing'     => $rec['sharing'],
                ':owner'       => $rec['owner_profile_id'],
                ':blocks'      => self::encode($rec['blocks']),
                ':keys'        => self::encode(self::keysOf($parts)),
                ':parts'       => self::encode($parts),
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->insertVersion($tenantId, $id, 1, $rec['blocks']);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }

        return $id;
    }

    /**
     * One saved block, archived or not, with its owner's name and use count.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM saved_blocks b
               LEFT JOIN profiles p ON p.id = b.owner_profile_id
              WHERE b.id = :id AND b.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::row($row) : null;
    }

    /**
     * The live blocks a person may see: their own `me` ones and every
     * `workspace` one, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function listVisible(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            "SELECT " . self::COLUMNS . " FROM saved_blocks b
               LEFT JOIN profiles p ON p.id = b.owner_profile_id
              WHERE b.tenant_id = :tenant_id AND b.archived_at IS NULL
                AND (b.sharing = 'workspace' OR (b.sharing = 'me' AND b.owner_profile_id = :profile_id))
              ORDER BY LOWER(b.name), b.id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return self::rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Changes name, description and sharing. Archived blocks are left alone.
     *
     * @param array{name: string, description: ?string, sharing: string} $fields
     */
    public function updateDetails(int $id, int $tenantId, array $fields): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE saved_blocks
                SET name = :name, description = :description, sharing = :sharing, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND archived_at IS NULL'
        );
        $stmt->execute([
            ':name'        => $fields['name'],
            ':description' => $fields['description'],
            ':sharing'     => $fields['sharing'],
            ':id'          => $id,
            ':tenant_id'   => $tenantId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Replaces the blocks while the block is still at $expectedVersion:
     * bumps the version and inserts that version's row, in one transaction.
     * Null when it changed in between (or was archived); else the new version.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $parts  E.23a: the parts each document fills
     */
    public function replaceBlocks(int $id, int $tenantId, int $expectedVersion, array $blocks, array $parts): ?int
    {
        $own = $this->begin();
        try {
            $stmt = $this->db->prepare(
                'UPDATE saved_blocks
                    SET blocks = :blocks, per_document_keys = :keys, per_document_parts = :parts, version = version + 1, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id AND version = :expected AND archived_at IS NULL'
            );
            $stmt->execute([
                ':blocks'    => self::encode($blocks),
                ':keys'      => self::encode(self::keysOf($parts)),
                ':parts'     => self::encode($parts),
                ':id'        => $id,
                ':tenant_id' => $tenantId,
                ':expected'  => $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                $this->rollBack($own);

                return null;
            }
            $version = $expectedVersion + 1;
            $this->insertVersion($tenantId, $id, $version, $blocks);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }

        return $version;
    }

    /**
     * Changes only the per-document parts' labels, required and placeholders
     * (no new version: the blocks are the same).
     *
     * @param list<array<string, mixed>> $parts
     */
    public function updatePerDocumentParts(int $id, int $tenantId, array $parts): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE saved_blocks SET per_document_keys = :keys, per_document_parts = :parts, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND archived_at IS NULL'
        );
        $stmt->execute([':keys' => self::encode(self::keysOf($parts)), ':parts' => self::encode($parts), ':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    /** Archives a live block. False when it was archived already (or isn't there). */
    public function archive(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE saved_blocks SET archived_at = NOW(), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND archived_at IS NULL'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * One version's blocks.
     *
     * @return array{saved_block_id: int, version: int, blocks: list<array<string, mixed>>, created_at: string}|null
     */
    public function findVersion(int $savedBlockId, int $tenantId, int $version): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT saved_block_id, version, blocks, created_at FROM saved_block_versions
              WHERE tenant_id = :tenant_id AND saved_block_id = :saved_block_id AND version = :version'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':saved_block_id' => $savedBlockId, ':version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'saved_block_id' => (int) $row['saved_block_id'],
            'version'        => (int) $row['version'],
            'blocks'         => self::decodeList($row['blocks']),
            'created_at'     => (string) $row['created_at'],
        ];
    }

    public function countUses(int $savedBlockId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM saved_block_uses WHERE tenant_id = :tenant_id AND saved_block_id = :saved_block_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':saved_block_id' => $savedBlockId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The documents using a saved block, with the version each shows, by document id.
     *
     * @return list<array{source_id: int, version: int}>
     */
    public function uses(int $savedBlockId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT source_id, version FROM saved_block_uses
              WHERE tenant_id = :tenant_id AND saved_block_id = :saved_block_id ORDER BY source_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':saved_block_id' => $savedBlockId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = ['source_id' => (int) $row['source_id'], 'version' => (int) $row['version']];
            }
        }

        return $out;
    }

    /**
     * The saved blocks one document uses, by saved block id.
     *
     * @return array<int, int> saved block id => version
     */
    public function usesForSource(int $tenantId, int $sourceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT saved_block_id, version FROM saved_block_uses
              WHERE tenant_id = :tenant_id AND source_id = :source_id ORDER BY saved_block_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[(int) $row['saved_block_id']] = (int) $row['version'];
            }
        }

        return $out;
    }

    /**
     * The saved blocks a document's blocks use, as [savedBlockId, version]
     * for {@see self::recordUses()}: every `savedBlock`, nested ones in
     * toggles and columns included. Copies are ordinary blocks and use none.
     *
     * @param array<mixed> $blocks
     * @return list<array{0: int, 1: int}>
     */
    public static function usesIn(array $blocks): array
    {
        $uses = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'savedBlock') {
                $id = $block['savedBlockId'] ?? null;
                $version = $block['version'] ?? null;
                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && is_int($version)) {
                    $uses[] = [(int) $id, $version];
                }
                continue;
            }
            if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                array_push($uses, ...self::usesIn($block['children']));
            }
            if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                foreach ($block['columns'] as $column) {
                    if (is_array($column)) {
                        array_push($uses, ...self::usesIn($column));
                    }
                }
            }
        }

        return $uses;
    }

    /**
     * Replaces the saved blocks a document uses, for the editor's draft save
     * to call. Each entry is [savedBlockId, version]; a saved block named
     * twice keeps the last version given. Ids that aren't a saved block of
     * this workspace are skipped, so another workspace's ids record nothing.
     * The document must belong to the workspace; nothing is checked here
     * beyond the tenant.
     *
     * @param list<array{0: int, 1: int}> $uses
     * @return int how many rows the document now has
     */
    public function recordUses(int $tenantId, int $sourceId, array $uses): int
    {
        $byBlock = [];
        foreach ($uses as $use) {
            [$savedBlockId, $version] = $use;
            if ($savedBlockId > 0 && $version > 0) {
                $byBlock[$savedBlockId] = $version;
            }
        }

        $own = $this->begin();
        try {
            $delete = $this->db->prepare(
                'DELETE FROM saved_block_uses WHERE tenant_id = :tenant_id AND source_id = :source_id'
            );
            $delete->execute([':tenant_id' => $tenantId, ':source_id' => $sourceId]);

            $insert = $this->db->prepare(
                'INSERT INTO saved_block_uses (tenant_id, saved_block_id, source_id, version, created_at)
                 SELECT b.tenant_id, b.id, :source_id, :version, NOW() FROM saved_blocks b
                  WHERE b.tenant_id = :tenant_id AND b.id = :saved_block_id'
            );
            $count = 0;
            foreach ($byBlock as $savedBlockId => $version) {
                $insert->execute([
                    ':source_id'      => $sourceId,
                    ':version'        => $version,
                    ':tenant_id'      => $tenantId,
                    ':saved_block_id' => $savedBlockId,
                ]);
                $count += $insert->rowCount();
            }
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }

        return $count;
    }

    /**
     * Starts a transaction unless the caller already holds one (the draft
     * save may wrap recordUses in its own). True when this call started it.
     */
    private function begin(): bool
    {
        if ($this->db->inTransaction()) {
            return false;
        }
        $this->db->beginTransaction();

        return true;
    }

    private function commit(bool $own): void
    {
        if ($own) {
            $this->db->commit();
        }
    }

    private function rollBack(bool $own): void
    {
        if ($own && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** @param list<array<string, mixed>> $blocks */
    private function insertVersion(int $tenantId, int $savedBlockId, int $version, array $blocks): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO saved_block_versions (tenant_id, saved_block_id, version, blocks, created_at)
             VALUES (:tenant_id, :saved_block_id, :version, :blocks, NOW())'
        );
        $stmt->execute([
            ':tenant_id'      => $tenantId,
            ':saved_block_id' => $savedBlockId,
            ':version'        => $version,
            ':blocks'         => self::encode($blocks),
        ]);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::row($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $keys = array_values(array_filter(self::decodeList($row['per_document_keys']), 'is_string'));

        return [
            'id'                => (int) $row['id'],
            'tenant_id'         => (int) $row['tenant_id'],
            'name'              => (string) $row['name'],
            'description'       => $row['description'] !== null ? (string) $row['description'] : null,
            'mode'              => (string) $row['mode'],
            'sharing'           => (string) $row['sharing'],
            'owner_profile_id'  => $row['owner_profile_id'] !== null ? (int) $row['owner_profile_id'] : null,
            'owner_name'        => $row['owner_name'] !== null ? (string) $row['owner_name'] : null,
            'blocks'            => self::decodeList($row['blocks']),
            'per_document_keys' => $keys,
            'per_document_parts' => array_values(array_filter(self::decodeList($row['per_document_parts'] ?? null), 'is_array')),
            'version'           => (int) $row['version'],
            'used_in'           => (int) $row['used_in'],
            'archived_at'       => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return list<string>
     */
    private static function keysOf(array $parts): array
    {
        return array_values(array_filter(array_map(static fn (array $p): mixed => $p['key'] ?? null, $parts), 'is_string'));
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
