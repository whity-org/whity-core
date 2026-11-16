<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Tenant\TenantContext;

/**
 * Whether an installed code plugin is on for a workspace (WHIT-1122).
 *
 * A code plugin is installed once for the installation. The operator says
 * which workspaces may use it (`code_plugin_access`):
 * - `all`: on everywhere. This is also the answer for a plugin with no row.
 * - `choose`: on where the workspace turned it on (`tenant_code_plugins`).
 * - `operator`: only in the system workspace.
 *
 * The system workspace, and work with no workspace at all (boot, CLI), always
 * sees every plugin on, so the operator can still manage everything.
 *
 * The loader asks on every plugin route, screen, card, hook, job and tool. So
 * the answers are read once per request and memoised; the worker loop calls
 * {@see resetRequest()} between requests.
 */
final class CodePluginActivation
{
    public const ALL = 'all';
    public const CHOOSE = 'choose';
    public const OPERATOR = 'operator';

    public const ACCESS = [self::ALL, self::CHOOSE, self::OPERATOR];

    private const SYSTEM_TENANT_ID = 0;

    /** @var array<string, array{access: string, free_for_all: bool}>|null */
    private ?array $access = null;

    /** @var array<int, array<string, bool>> */
    private array $enabled = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Forget what this request read. */
    public function resetRequest(): void
    {
        $this->access = null;
        $this->enabled = [];
    }

    /**
     * Is the plugin on for this workspace? With no workspace given, the
     * current request's workspace.
     */
    public function isOn(string $pluginName, ?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::getTenantId();
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
            return true;
        }

        return match ($this->accessOf($pluginName)) {
            self::ALL => true,
            self::OPERATOR => false,
            default => $this->enabledFor($tenantId)[$pluginName] ?? false,
        };
    }

    /** The operator's setting for the plugin: all, choose or operator. */
    public function accessOf(string $pluginName): string
    {
        return $this->accessRows()[$pluginName]['access'] ?? self::ALL;
    }

    /** The operator's "free for all my workspaces" switch for a paid plugin. */
    public function isFreeForAll(string $pluginName): bool
    {
        return $this->accessRows()[$pluginName]['free_for_all'] ?? false;
    }

    /**
     * Set who may use the plugin. Only the operator calls this.
     *
     * @throws \InvalidArgumentException for an unknown access value.
     */
    public function setAccess(string $pluginName, string $access, ?bool $freeForAll = null, ?int $profileId = null): void
    {
        if (!in_array($access, self::ACCESS, true)) {
            throw new \InvalidArgumentException('workspace_access must be one of: ' . implode(', ', self::ACCESS));
        }
        $free = $freeForAll ?? $this->isFreeForAll($pluginName);

        $stmt = $this->db->prepare(
            'INSERT INTO code_plugin_access (plugin_name, workspace_access, free_for_all, updated_by_profile_id, updated_at)
             VALUES (:plugin, :access, :free, :profile, CURRENT_TIMESTAMP)
             ON CONFLICT (plugin_name) DO UPDATE SET
                workspace_access = excluded.workspace_access,
                free_for_all = excluded.free_for_all,
                updated_by_profile_id = excluded.updated_by_profile_id,
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':plugin', $pluginName);
        $stmt->bindValue(':access', $access);
        $stmt->bindValue(':free', $free, PDO::PARAM_BOOL);
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        $this->access = null;
    }

    /**
     * Write `choose` for a plugin that was just installed, unless the operator
     * already decided. New installs start off for every workspace.
     */
    public function defaultToChoose(string $pluginName, ?int $profileId = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO code_plugin_access (plugin_name, workspace_access, updated_by_profile_id, updated_at)
             VALUES (:plugin, 'choose', :profile, CURRENT_TIMESTAMP)
             ON CONFLICT (plugin_name) DO NOTHING"
        );
        $stmt->bindValue(':plugin', $pluginName);
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        $this->access = null;
    }

    /**
     * Turn the plugin on or off for one workspace and record who did it. Data
     * is never touched.
     */
    public function setEnabled(int $tenantId, string $pluginName, bool $enabled, ?int $profileId = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_code_plugins (tenant_id, plugin_name, enabled, changed_by_profile_id, changed_at)
             VALUES (:tenant_id, :plugin, :enabled, :profile, CURRENT_TIMESTAMP)
             ON CONFLICT (tenant_id, plugin_name) DO UPDATE SET
                enabled = excluded.enabled,
                changed_by_profile_id = excluded.changed_by_profile_id,
                changed_at = excluded.changed_at'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plugin', $pluginName);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        unset($this->enabled[$tenantId]);
    }

    /**
     * This workspace's rows, with the name of whoever made the last change.
     *
     * @return array<string, array{enabled: bool, changed_at: string, changed_by: array{id: int, name: string}|null, trial_ends_at: string|null}>
     */
    public function workspaceRows(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.plugin_name, t.enabled, t.changed_at, t.trial_ends_at, t.changed_by_profile_id, p.display_name
               FROM tenant_code_plugins t
               LEFT JOIN profiles p ON p.id = t.changed_by_profile_id
              WHERE t.tenant_id = :tenant_id'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $by = $row['changed_by_profile_id'] !== null
                ? ['id' => (int) $row['changed_by_profile_id'], 'name' => (string) ($row['display_name'] ?? '')]
                : null;
            $rows[(string) $row['plugin_name']] = [
                'enabled' => DbBool::of($row['enabled']),
                'changed_at' => (string) $row['changed_at'],
                'changed_by' => $by,
                'trial_ends_at' => $row['trial_ends_at'] !== null ? (string) $row['trial_ends_at'] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, bool> */
    private function enabledFor(int $tenantId): array
    {
        if (!isset($this->enabled[$tenantId])) {
            $stmt = $this->db->prepare('SELECT plugin_name, enabled FROM tenant_code_plugins WHERE tenant_id = :tenant_id');
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(string) $row['plugin_name']] = DbBool::of($row['enabled']);
            }
            $this->enabled[$tenantId] = $map;
        }

        return $this->enabled[$tenantId];
    }

    /** @return array<string, array{access: string, free_for_all: bool}> */
    private function accessRows(): array
    {
        if ($this->access === null) {
            $map = [];
            $stmt = $this->db->query('SELECT plugin_name, workspace_access, free_for_all FROM code_plugin_access');
            foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $map[(string) $row['plugin_name']] = [
                    'access' => (string) $row['workspace_access'],
                    'free_for_all' => DbBool::of($row['free_for_all']),
                ];
            }
            $this->access = $map;
        }

        return $this->access;
    }
}
