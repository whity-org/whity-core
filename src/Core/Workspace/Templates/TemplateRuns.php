<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use PDO;

/**
 * Which templates a workspace applied and what each one made (migration 305,
 * WHIT-1194). Every query binds the tenant.
 */
final class TemplateRuns
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The workspace's runs, oldest first.
     *
     * @return list<array{id: int, template_key: string, plugin_name: ?string, version: int, applied_at: string, setup_closed_at: ?string, offer_closed_at: ?string}>
     */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, template_key, plugin_name, version, applied_at, setup_closed_at, offer_closed_at
               FROM workspace_template_runs WHERE tenant_id = ? ORDER BY id'
        );
        $stmt->execute([$tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'template_key' => (string) $row['template_key'],
                'plugin_name' => $row['plugin_name'] === null ? null : (string) $row['plugin_name'],
                'version' => (int) $row['version'],
                'applied_at' => (string) $row['applied_at'],
                'setup_closed_at' => $row['setup_closed_at'] === null ? null : (string) $row['setup_closed_at'],
                'offer_closed_at' => $row['offer_closed_at'] === null ? null : (string) $row['offer_closed_at'],
            ];
        }

        return $out;
    }

    /**
     * Is the workspace new enough to be offered the picker: created within
     * the last `$days` days. A workspace that has been running for longer is
     * never sent there on sign-in; its owner can still open it.
     */
    public function isNew(int $tenantId, int $days, ?\DateTimeImmutable $now = null): bool
    {
        $stmt = $this->db->prepare('SELECT created_at FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $created = $stmt->fetchColumn();
        if (!is_string($created) || $created === '') {
            return false;
        }
        try {
            $at = new \DateTimeImmutable($created, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $at > ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
    }

    /** @return array{id: int, template_key: string, plugin_name: ?string, version: int, applied_at: string, setup_closed_at: ?string, offer_closed_at: ?string}|null */
    public function find(int $tenantId, string $templateKey): ?array
    {
        foreach ($this->forTenant($tenantId) as $run) {
            if ($run['template_key'] === $templateKey) {
                return $run;
            }
        }

        return null;
    }

    /** The run for this template, made now or brought up to this version. */
    public function start(int $tenantId, string $templateKey, ?string $pluginName, int $version, int $profileId): int
    {
        $existing = $this->find($tenantId, $templateKey);
        if ($existing !== null) {
            $stmt = $this->db->prepare('UPDATE workspace_template_runs SET version = ?, plugin_name = ?, applied_at = CURRENT_TIMESTAMP, applied_by_profile_id = ? WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$version, $pluginName, $profileId, $existing['id'], $tenantId]);

            return $existing['id'];
        }
        $stmt = $this->db->prepare(
            'INSERT INTO workspace_template_runs (tenant_id, template_key, plugin_name, version, applied_by_profile_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tenantId, $templateKey, $pluginName, $version, $profileId]);

        return (int) $this->db->lastInsertId();
    }

    /** Close the guided setup or the offer (`setup` or `offer`). */
    public function close(int $tenantId, string $templateKey, string $what): bool
    {
        $column = match ($what) {
            'setup' => 'setup_closed_at',
            'offer' => 'offer_closed_at',
            default => throw new \InvalidArgumentException('setup or offer'),
        };
        $stmt = $this->db->prepare("UPDATE workspace_template_runs SET {$column} = CURRENT_TIMESTAMP WHERE tenant_id = ? AND template_key = ? AND {$column} IS NULL");
        $stmt->execute([$tenantId, $templateKey]);

        return $stmt->rowCount() > 0;
    }

    /**
     * What a run made, by kind and the template's key for it.
     *
     * @return array<string, array{ref_id: string, removed: bool}>
     */
    public function items(int $tenantId, int $runId): array
    {
        $stmt = $this->db->prepare('SELECT kind, item_key, ref_id, removed_at FROM workspace_template_items WHERE tenant_id = ? AND run_id = ?');
        $stmt->execute([$tenantId, $runId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['kind'] . ':' . $row['item_key']] = ['ref_id' => (string) $row['ref_id'], 'removed' => $row['removed_at'] !== null];
        }

        return $out;
    }

    public function record(int $tenantId, int $runId, string $kind, string $itemKey, string $refId, bool $isExample): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO workspace_template_items (tenant_id, run_id, kind, item_key, ref_id, is_example)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (run_id, kind, item_key) DO UPDATE SET ref_id = EXCLUDED.ref_id, removed_at = NULL'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $runId, PDO::PARAM_INT);
        $stmt->bindValue(3, $kind);
        $stmt->bindValue(4, $itemKey);
        $stmt->bindValue(5, $refId);
        $stmt->bindValue(6, $isExample, PDO::PARAM_BOOL);
        $stmt->execute();
    }

    /**
     * The examples still in the workspace, newest first.
     *
     * @return list<array{id: int, kind: string, ref_id: string}>
     */
    public function liveExamples(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, ref_id FROM workspace_template_items
              WHERE tenant_id = ? AND is_example = TRUE AND removed_at IS NULL ORDER BY id DESC'
        );
        $stmt->execute([$tenantId]);

        return array_values(array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'ref_id' => (string) $row['ref_id']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    public function markRemoved(int $tenantId, int $itemId): void
    {
        $stmt = $this->db->prepare('UPDATE workspace_template_items SET removed_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$itemId, $tenantId]);
    }
}
