<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\Document\SavedBlock\PerDocumentParts;
use Whity\Database\Database;

/**
 * Stable keys for a saved block's per-document parts (E.23a, design 3.4).
 *
 * Before this, a part was named by where it sat (`<block id>#<n>`), so
 * reordering a synced block's text moved a document's values onto the wrong
 * part. Now each part has a key minted once (`pd_` + 8 characters) on its
 * `perDocument` mark or field, and documents fill parts by key.
 *
 * - `saved_blocks.per_document_parts` (new): the parts in reading order, with
 *   a label (their own text to start with) and kind. `per_document_keys`
 *   stays for one release, holding the parts' keys.
 * - Every saved block's blocks and every row of `saved_block_versions` get
 *   keys, one map per saved block, so `a#0` is the same key in every version
 *   (that's what a document filling `a#0` meant before).
 * - Every document draft (`document_sources.blocks`) that uses a saved block
 *   has its `overrides` renamed from `a#0` to that key. Published versions
 *   (`document_source_versions`) stay frozen (owner, 14 Nov 2026): the printer
 *   and editor still read an override by its old name when the key has none.
 *
 * Idempotent: parts that have a key keep it, and the map is rebuilt from the
 * keys already there, so a second run renames nothing new; overrides already
 * on keys are left alone. One transaction per workspace. down() drops the
 * column only; keys on marks are harmless to the old reader, which ignores them.
 */
class SavedBlockPartKeys
{
    public static function up(Database $db): void
    {
        $db->exec("ALTER TABLE saved_blocks ADD COLUMN IF NOT EXISTS per_document_parts JSONB NOT NULL DEFAULT '[]'");

        $tenants = $db->query('SELECT DISTINCT tenant_id FROM saved_blocks')->fetchAll();
        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['tenant_id'];
            $pdo = $db->getPdo();
            $own = !$pdo->inTransaction();
            if ($own) {
                $pdo->beginTransaction();
            }
            try {
                self::tenant($db, $tenantId);
                if ($own) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE saved_blocks DROP COLUMN IF EXISTS per_document_parts');
    }

    /**
     * Keys one workspace's saved blocks and renames its drafts' overrides.
     * Public so a test can run it on real data shapes.
     */
    public static function tenant(Database $db, int $tenantId): void
    {
        /** @var array<int, array<string, string>> $maps saved block id => legacy name => key */
        $maps = [];
        $rows = $db->query(
            'SELECT id, blocks, per_document_parts FROM saved_blocks WHERE tenant_id = :tenant_id ORDER BY id',
            [':tenant_id' => $tenantId]
        )->fetchAll();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $map = [];
            $blocks = PerDocumentParts::assignKeys(self::decode($row['blocks']), $map);
            $given = [];
            foreach (self::decode($row['per_document_parts']) as $part) {
                if (is_array($part) && is_string($part['key'] ?? null)) {
                    $given[$part['key']] = $part;
                }
            }
            $parts = PerDocumentParts::fromBlocks($blocks, $given);

            $versions = $db->query(
                'SELECT id, blocks FROM saved_block_versions WHERE tenant_id = :tenant_id AND saved_block_id = :id ORDER BY version DESC',
                [':tenant_id' => $tenantId, ':id' => $id]
            )->fetchAll();
            foreach ($versions as $version) {
                $before = self::decode($version['blocks']);
                $after = PerDocumentParts::assignKeys($before, $map);
                if ($after !== $before) {
                    $db->query(
                        'UPDATE saved_block_versions SET blocks = :blocks WHERE id = :id AND tenant_id = :tenant_id',
                        [':blocks' => self::encode($after), ':id' => (int) $version['id'], ':tenant_id' => $tenantId]
                    );
                }
            }

            $db->query(
                'UPDATE saved_blocks SET blocks = :blocks, per_document_parts = :parts, per_document_keys = :keys
                  WHERE id = :id AND tenant_id = :tenant_id',
                [
                    ':blocks'    => self::encode($blocks),
                    ':parts'     => self::encode($parts),
                    ':keys'      => self::encode(array_map(static fn (array $p): string => $p['key'], $parts)),
                    ':id'        => $id,
                    ':tenant_id' => $tenantId,
                ]
            );
            $maps[$id] = $map;
        }
        if ($maps === []) {
            return;
        }

        $sources = $db->query(
            "SELECT id, blocks FROM document_sources WHERE tenant_id = :tenant_id AND CAST(blocks AS TEXT) LIKE '%savedBlock%'",
            [':tenant_id' => $tenantId]
        )->fetchAll();
        foreach ($sources as $source) {
            $before = self::decode($source['blocks']);
            $after = self::renameOverrides($before, $maps);
            if ($after !== $before) {
                $db->query(
                    'UPDATE document_sources SET blocks = :blocks WHERE id = :id AND tenant_id = :tenant_id',
                    [':blocks' => self::encode($after), ':id' => (int) $source['id'], ':tenant_id' => $tenantId]
                );
            }
        }
    }

    /**
     * @param list<mixed>                       $blocks
     * @param array<int, array<string, string>> $maps
     * @return list<mixed>
     */
    public static function renameOverrides(array $blocks, array $maps): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $type = $block['type'] ?? null;
                $ref = $block['savedBlockId'] ?? null;
                if ($type === 'savedBlock' && (is_int($ref) || (is_string($ref) && ctype_digit($ref)))
                    && isset($maps[(int) $ref]) && is_array($block['overrides'] ?? null) && $block['overrides'] !== []) {
                    $renamed = [];
                    foreach ($block['overrides'] as $name => $value) {
                        $name = (string) $name;
                        $key = $maps[(int) $ref][$name] ?? $name;
                        // A value already under the key wins over one under the old name.
                        if ($key !== $name && array_key_exists($key, $block['overrides'])) {
                            continue;
                        }
                        $renamed[$key] = $value;
                    }
                    $block['overrides'] = $renamed;
                }
                if ($type === 'toggle' && is_array($block['children'] ?? null)) {
                    $block['children'] = self::renameOverrides(array_values($block['children']), $maps);
                }
                if ($type === 'columns' && is_array($block['columns'] ?? null)) {
                    $columns = [];
                    foreach ($block['columns'] as $i => $column) {
                        $columns[$i] = is_array($column) ? self::renameOverrides(array_values($column), $maps) : $column;
                    }
                    $block['columns'] = $columns;
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function decode(mixed $json): array
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
