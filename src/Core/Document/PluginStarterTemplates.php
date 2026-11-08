<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Starter templates a workspace plugin ships under `documents.starters`
 * (SDK 1.81, WHIT-995): seeded into the workspace when the plugin goes live
 * there (published, or a version activated, or switched on).
 *
 * Each starter becomes a template every member can see, published as its
 * first version, so the plugin can issue from it straight away. When it names
 * one of the plugin's slots and that slot has no template yet, it is bound to
 * the slot. From then on it is the workspace's:
 *
 *  - It is seeded once. A starter the workspace deleted stays deleted
 *    (`plugin_starter_seeds` remembers it).
 *  - A copy a person changed is never overwritten. The seed row keeps the
 *    hash of what the plugin last wrote; a template that no longer hashes to
 *    it was edited.
 *  - An untouched copy follows the plugin: when a new plugin version changes
 *    the starter, the template is replaced and the change published.
 *  - Disabling or removing the plugin leaves its templates in place.
 *
 * Nothing the host checks before a person publishes (unknown merge fields, for
 * example) is checked here: a starter is the plugin author's, and an issue
 * that needs a field the record can't fill is still refused at issue time.
 * The version is published with no frozen theme, so it prints in the
 * workspace's current theme until a person publishes it again.
 *
 * Seeding never fails the plugin's publish or switch: a starter that can't be
 * seeded is logged and skipped.
 */
final class PluginStarterTemplates
{
    private bool $ownsTransaction = false;

    public function __construct(
        private readonly PDO $db,
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly SettingsService $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** The `starter_key` of a plugin's starter: short, stable, and never a core starter's. */
    public static function starterKey(string $plugin, string $starter): string
    {
        return 'plugin:' . substr(hash('sha256', $plugin . '.' . $starter), 0, 32);
    }

    /**
     * Seed the plugin's starters the workspace doesn't have yet, and bring
     * untouched copies up to date.
     *
     * @return array{seeded: int, refreshed: int, bound: int}
     */
    public function seed(int $tenantId, PluginManifest $manifest): array
    {
        $counts = ['seeded' => 0, 'refreshed' => 0, 'bound' => 0];
        $plugin = $manifest->name();
        $slots = $manifest->documentSlots();

        foreach ($manifest->documentStarters() as $name => $starter) {
            $name = (string) $name;
            try {
                $content = self::content($plugin, $starter, $slots);
                $hash = self::hash($content);
                $seed = $this->seedRow($tenantId, $plugin, $name);

                if ($seed === null) {
                    $sourceId = $this->create($tenantId, $plugin, $name, $content, $hash);
                    $counts['seeded']++;
                    $slot = $starter['slot'] ?? null;
                    if (is_string($slot) && $this->bindIfUnbound($tenantId, "{$plugin}.{$slot}", $sourceId)) {
                        $counts['bound']++;
                    }
                    continue;
                }
                if ($seed['content_hash'] === $hash || $seed['source_id'] === null) {
                    continue;
                }
                $row = $this->sources->findById($seed['source_id'], $tenantId);
                if ($row === null || self::hash(self::rowContent($row)) !== $seed['content_hash']) {
                    // Deleted, or changed by a person: the workspace's.
                    continue;
                }
                if ($this->refresh($tenantId, $row, $content, $hash, $plugin, $name)) {
                    $counts['refreshed']++;
                }
            } catch (\Throwable $e) {
                if ($this->ownsTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->ownsTransaction = false;
                $this->logger?->error('Seeding a plugin starter template failed', [
                    'event'     => 'documents.plugin_starter_seed_failed',
                    'tenant_id' => $tenantId,
                    'plugin'    => $plugin,
                    'starter'   => $name,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * What a starter writes into a template, in the shape a `document_sources`
     * row is compared in.
     *
     * @param array<string, mixed> $starter
     * @param array<string, array<string, mixed>> $slots
     * @return array{title: string, lang: string, kind: string, record_type: ?string, page: array<string, mixed>,
     *               blocks: list<array<string, mixed>>, own_fields: list<array<string, string>>}
     */
    public static function content(string $plugin, array $starter, array $slots): array
    {
        $lang = is_string($starter['lang'] ?? null) ? $starter['lang'] : 'en';
        /** @var array{en: string, ar: string} $label */
        $label = $starter['label'];
        $record = $starter['record'] ?? null;
        $slot = $starter['slot'] ?? null;
        if (!is_string($record) && is_string($slot) && is_string($slots[$slot]['record'] ?? null)) {
            $record = $slots[$slot]['record'];
        }
        /** @var array<string, mixed> $page */
        $page = $starter['page'];
        /** @var list<array<string, mixed>> $blocks */
        $blocks = array_values($starter['blocks']);
        // A sample per language takes the starter's own, so an Arabic starter's samples are Arabic.
        $own = [];
        foreach (is_array($starter['ownFields'] ?? null) ? $starter['ownFields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $sample = $field['sample'] ?? null;
            if (is_array($sample)) {
                $sample = $sample[$lang] ?? $sample['en'] ?? reset($sample);
            }
            $resolved = ['key' => (string) $field['key'], 'label' => (string) $field['label']];
            if (is_string($sample)) {
                $resolved['sample'] = $sample;
            }
            $own[] = $resolved;
        }

        return [
            'title'       => $label[$lang] ?? $label['en'],
            'lang'        => $lang,
            'kind'        => is_string($starter['kind'] ?? null) ? $starter['kind'] : 'template',
            'record_type' => is_string($record) ? $plugin . '.' . $record : null,
            'page'        => $page,
            'blocks'      => $blocks,
            'own_fields'  => $own,
        ];
    }

    /**
     * @param array<string, mixed> $row a `document_sources` row with blocks
     * @return array<string, mixed>
     */
    private static function rowContent(array $row): array
    {
        return [
            'title'       => (string) $row['title'],
            'lang'        => (string) $row['lang'],
            'kind'        => (string) $row['kind'],
            'record_type' => $row['record_type'] ?? null,
            'page'        => $row['page'] ?? [],
            'blocks'      => $row['blocks'] ?? [],
            'own_fields'  => $row['own_fields'] ?? [],
        ];
    }

    /** @param array<string, mixed> $content */
    private static function hash(array $content): string
    {
        return hash('sha256', (string) json_encode(self::canonical($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Key order doesn't count: PostgreSQL's JSONB stores object keys in its own order. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array{source_id: ?int, content_hash: string}|null */
    private function seedRow(int $tenantId, string $plugin, string $starter): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT source_id, content_hash FROM plugin_starter_seeds
              WHERE tenant_id = :tenant_id AND plugin = :plugin AND starter = :starter'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plugin' => $plugin, ':starter' => $starter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'source_id'    => $row['source_id'] !== null ? (int) $row['source_id'] : null,
            'content_hash' => (string) $row['content_hash'],
        ];
    }

    /** @param array<string, mixed> $content */
    private function create(int $tenantId, string $plugin, string $starter, array $content, string $hash): int
    {
        $own = $this->begin();
        $sourceId = $this->sources->create($tenantId, [
            'kind'         => (string) $content['kind'],
            'title'        => (string) $content['title'],
            'lang'         => (string) $content['lang'],
            'default_view' => 'page',
            'page'         => $content['page'],
            'record_type'  => $content['record_type'],
            'blocks'       => $content['blocks'],
            'own_fields'   => $content['own_fields'],
            'scope'        => DocumentAccessPolicy::SCOPE_SYSTEM,
            'created_by'   => null,
            'starter_key'  => self::starterKey($plugin, $starter),
        ]);
        if ($this->versions->publish($sourceId, $tenantId, 1, null) === null) {
            throw new \RuntimeException('The starter could not be published');
        }
        $this->db->prepare(
            'INSERT INTO plugin_starter_seeds (tenant_id, plugin, starter, source_id, content_hash, created_at, updated_at)
             VALUES (:tenant_id, :plugin, :starter, :source_id, :content_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([
            ':tenant_id'    => $tenantId,
            ':plugin'       => $plugin,
            ':starter'      => $starter,
            ':source_id'    => $sourceId,
            ':content_hash' => $hash,
        ]);
        $this->commit($own);

        return $sourceId;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $content
     */
    private function refresh(int $tenantId, array $row, array $content, string $hash, string $plugin, string $starter): bool
    {
        $id = (int) $row['id'];
        $draft = (int) $row['draft_version'];

        $own = $this->begin();
        $update = $this->db->prepare(
            'UPDATE document_sources
                SET title = :title, lang = :lang, kind = :kind, record_type = :record_type, page = :page, blocks = :blocks,
                    own_fields = :own_fields, draft_version = draft_version + 1, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tenant_id AND draft_version = :draft_version'
        );
        $update->execute([
            ':title'         => $content['title'],
            ':lang'          => $content['lang'],
            ':kind'          => $content['kind'],
            ':record_type'   => $content['record_type'],
            ':page'          => json_encode($content['page'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':blocks'        => json_encode($content['blocks'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':own_fields'    => json_encode($content['own_fields'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':id'            => $id,
            ':tenant_id'     => $tenantId,
            ':draft_version' => $draft,
        ]);
        if ($update->rowCount() !== 1 || $this->versions->publish($id, $tenantId, $draft + 1, null) === null) {
            // Somebody saved in between: theirs wins.
            if ($own) {
                $this->db->rollBack();
            }

            return false;
        }
        $this->db->prepare(
            'UPDATE plugin_starter_seeds SET content_hash = :content_hash, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND plugin = :plugin AND starter = :starter'
        )->execute([':content_hash' => $hash, ':tenant_id' => $tenantId, ':plugin' => $plugin, ':starter' => $starter]);
        $this->commit($own);

        return true;
    }

    /** Begins a transaction unless the caller is in one; whether this owns it. */
    private function begin(): bool
    {
        $this->ownsTransaction = !$this->db->inTransaction();
        if ($this->ownsTransaction) {
            $this->db->beginTransaction();
        }

        return $this->ownsTransaction;
    }

    private function commit(bool $own): void
    {
        if ($own) {
            $this->db->commit();
        }
        $this->ownsTransaction = false;
    }

    private function bindIfUnbound(int $tenantId, string $slotKey, int $sourceId): bool
    {
        $effective = $this->settings->effective($tenantId);
        $bindings = SlotBindings::parse((string) ($effective[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''));
        if (isset($bindings[$slotKey])) {
            return false;
        }
        $bindings[$slotKey] = $sourceId;
        $this->settings->setTenant($tenantId, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode($bindings));

        return true;
    }
}
