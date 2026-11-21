<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use PDO;
use Whity\Sdk\JobInterface;

/**
 * Once a day: every workspace's snapshot meters into the usage ledger (seats,
 * customers, items, invoices, storage), so each month keeps its highest
 * reading even when nobody opens the usage screen. Internal: the scheduler
 * queues it; safe to run twice (a snapshot sets, it never adds).
 */
final class UsageSnapshotJob implements JobInterface
{
    public const NAME = 'core.usage.snapshot';

    public function __construct(
        private readonly PDO $db,
        private readonly UsageSnapshots $snapshots,
    ) {
    }

    public function handle(array $payload): array
    {
        $done = 0;
        foreach (self::workspaceIds($this->db) as $tenantId) {
            $this->snapshots->take($tenantId);
            $done++;
        }

        return ['workspaces' => $done];
    }

    /** @return list<int> */
    public static function workspaceIds(PDO $db): array
    {
        // Every workspace, across tenants by design: the daily snapshot of each.
        $stmt = $db->prepare('SELECT id FROM tenants WHERE id > :system ORDER BY id');
        $stmt->execute([':system' => 0]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}
