<?php

declare(strict_types=1);

namespace Whity\Sdk\Templates;

/**
 * A {@see TemplateLedger} in memory (SDK 1.169, WHIT-1409).
 *
 * - With no base: a fresh workspace's ledger, for a template author's tests
 *   ({@see \Whity\Sdk\Testing\WorkspaceTemplateTestCase}).
 * - Over a real ledger: reads what the workspace's runs really made and keeps
 *   every write here, so a dry run plans against the truth and saves nothing.
 *   Runs it starts that the base does not have get negative ids.
 */
final class MemoryLedger implements TemplateLedger
{
    /** @var array<string, array{id: int, version: int, plugin: ?string}> by `tenant:key` */
    private array $runs = [];

    /** @var array<int, array<string, array{id: int, tenant: int, kind: string, ref_id: string, example: bool, removed: bool, fingerprint: ?string}>> by run, then `kind:key` */
    private array $items = [];

    /** @var array<int, array<string, int>> by run, then part key */
    private array $parts = [];

    /** @var array<int, true> item ids of the base's examples removed here */
    private array $removedBase = [];

    private int $nextRun = 1;

    private int $nextItem = 1;

    public function __construct(private readonly ?TemplateLedger $base = null)
    {
        if ($base !== null) {
            $this->nextRun = -1;
            $this->nextItem = -1;
        }
    }

    public function find(int $tenantId, string $templateKey): ?array
    {
        $own = $this->runs["{$tenantId}:{$templateKey}"] ?? null;
        if ($own !== null) {
            return ['id' => $own['id'], 'version' => $own['version']];
        }
        $base = $this->base?->find($tenantId, $templateKey);

        return $base === null ? null : ['id' => (int) $base['id'], 'version' => (int) $base['version']];
    }

    public function start(int $tenantId, string $templateKey, ?string $pluginName, int $version, int $profileId): int
    {
        $id = $this->find($tenantId, $templateKey)['id'] ?? $this->newRunId();
        $this->runs["{$tenantId}:{$templateKey}"] = ['id' => $id, 'version' => $version, 'plugin' => $pluginName];

        return $id;
    }

    public function items(int $tenantId, int $runId): array
    {
        $out = $runId > 0 && $this->base !== null ? $this->base->items($tenantId, $runId) : [];
        foreach ($this->items[$runId] ?? [] as $kindKey => $item) {
            if ($item['tenant'] === $tenantId) {
                $out[$kindKey] = ['ref_id' => $item['ref_id'], 'removed' => $item['removed'], 'fingerprint' => $item['fingerprint']];
            }
        }

        return $out;
    }

    public function record(int $tenantId, int $runId, string $kind, string $itemKey, string $refId, bool $isExample): void
    {
        $this->items[$runId]["{$kind}:{$itemKey}"] = [
            'id' => $this->newItemId(),
            'tenant' => $tenantId,
            'kind' => $kind,
            'ref_id' => $refId,
            'example' => $isExample,
            'removed' => false,
            'fingerprint' => null,
        ];
    }

    public function setFingerprint(int $tenantId, int $runId, string $kind, string $itemKey, string $fingerprint): void
    {
        $kindKey = "{$kind}:{$itemKey}";
        if (!isset($this->items[$runId][$kindKey])) {
            // The base's item: copy it here so the change stays in memory.
            $base = $this->items($tenantId, $runId)[$kindKey] ?? null;
            if ($base === null) {
                return;
            }
            $this->items[$runId][$kindKey] = [
                'id' => $this->newItemId(),
                'tenant' => $tenantId,
                'kind' => $kind,
                'ref_id' => $base['ref_id'],
                'example' => false,
                'removed' => $base['removed'],
                'fingerprint' => null,
            ];
        }
        $this->items[$runId][$kindKey]['fingerprint'] = $fingerprint;
    }

    public function liveExamples(int $tenantId): array
    {
        $out = [];
        foreach (array_reverse($this->items, true) as $items) {
            foreach (array_reverse($items) as $item) {
                if ($item['tenant'] === $tenantId && $item['example'] && !$item['removed']) {
                    $out[] = ['id' => $item['id'], 'kind' => $item['kind'], 'ref_id' => $item['ref_id']];
                }
            }
        }
        foreach ($this->base?->liveExamples($tenantId) ?? [] as $item) {
            if (!isset($this->removedBase[$item['id']])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function markRemoved(int $tenantId, int $itemId): void
    {
        foreach ($this->items as $runId => $items) {
            foreach ($items as $kindKey => $item) {
                if ($item['id'] === $itemId && $item['tenant'] === $tenantId) {
                    $this->items[$runId][$kindKey]['removed'] = true;

                    return;
                }
            }
        }
        $this->removedBase[$itemId] = true;
    }

    public function recordPart(int $tenantId, int $runId, string $partKey, ?string $pluginName, int $version): void
    {
        $this->parts[$runId][$partKey] = $version;
    }

    public function parts(int $tenantId, int $runId): array
    {
        $out = $runId > 0 && $this->base !== null ? $this->base->parts($tenantId, $runId) : [];

        return array_merge($out, $this->parts[$runId] ?? []);
    }

    private function newRunId(): int
    {
        $id = $this->nextRun;
        $this->nextRun += $this->base === null ? 1 : -1;

        return $id;
    }

    private function newItemId(): int
    {
        $id = $this->nextItem;
        $this->nextItem += $this->base === null ? 1 : -1;

        return $id;
    }
}
