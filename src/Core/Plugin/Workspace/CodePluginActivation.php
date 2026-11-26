<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Workspace;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Plugin\Trust\PluginTrust;
use Whity\Core\Tenant\TenantContext;

/**
 * Whether an installed code plugin is on for a workspace (WHIT-1122), and
 * whether a paid one may be used there (WHIT-1125).
 *
 * A code plugin is installed once for the installation. The operator says
 * which workspaces may use it (`code_plugin_access`):
 * - `all`: on everywhere. This is also the answer for a plugin with no row.
 * - `choose`: on where the workspace turned it on (`tenant_code_plugins`).
 * - `operator`: only in the system workspace.
 *
 * A paid plugin ({@see \Whity\Sdk\PluginAccessInterface}) that is switched on
 * is in one of three states:
 * - `on`: the operator made it free for every workspace, the workspace's plan
 *   or add-on grants its entitlement, its trial is running, or its 7 days of
 *   grace after access lapsed are running;
 * - `read_only`: grace is over, or the installation has no store licence for
 *   it. Pages and reads still work, writes are refused, and jobs, hooks and
 *   notifications pause. Nothing is deleted;
 * - `off`: not switched on.
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

    public const STATE_ON = 'on';
    public const STATE_READ_ONLY = 'read_only';
    public const STATE_OFF = 'off';

    /** Days a paid plugin stays on after its access lapsed (owner, 2026-11-16). */
    public const GRACE_DAYS = 7;

    private const SYSTEM_TENANT_ID = 0;

    /** @var array<string, array{access: string, free_for_all: bool}>|null */
    private ?array $access = null;

    /** @var array<int, array<string, array{enabled: bool, trial_started_at: ?string, trial_ends_at: ?string, access_lapsed_at: ?string}>> */
    private array $rows = [];

    /** @var array<string, array{state: string, reason: ?string, graceUntil: ?string, trialEndsAt: ?string}> */
    private array $states = [];

    /** @var array<string, ?string> */
    private array $licences = [];

    /**
     * The code plugins a signed revocation list killed, by name, read once
     * per request (WHIT-1277).
     *
     * @var array<string, bool>|null
     */
    private ?array $killed = null;

    /** @var (\Closure(): array<string, array{entitlement: string, product: string, trialDays: int}>)|null */
    private ?\Closure $paidAccess = null;

    /** @var (\Closure(): array<string, list<string>>)|null plugin name => the workspace template keys it offers */
    private ?\Closure $templateKeys = null;

    /** @var (\Closure(int, string): bool)|null */
    private ?\Closure $entitled = null;

    /** @var (\Closure(string): ?string)|null */
    private ?\Closure $installLicence = null;

    /** @var (\Closure(int, string): ?array{active: bool, until: string})|null */
    private ?\Closure $cashCover = null;

    /**
     * Cash payments the operator recorded (WHIT-1153): (workspace, product) =>
     * whether one covers now, and until when the last one reaches.
     *
     * @param \Closure(int, string): ?array{active: bool, until: string} $cashCover
     */
    public function useCashCover(\Closure $cashCover): void
    {
        $this->cashCover = $cashCover;
    }

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly PDO $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Forget what this request read. */
    public function resetRequest(): void
    {
        $this->access = null;
        $this->rows = [];
        $this->states = [];
        $this->licences = [];
        $this->killed = null;
    }

    /**
     * Where the paid plugins' declarations come from: plugin name => access.
     *
     * @param \Closure(): array<string, array{entitlement: string, product: string, trialDays: int}> $paidAccess
     */
    public function usePaidAccess(\Closure $paidAccess): void
    {
        $this->paidAccess = $paidAccess;
    }

    /**
     * Which workspace templates each plugin offers (WHIT-1433). A template
     * plugin a workspace never switched either way is turned on there the
     * first time it is asked about, if the workspace has a run of one of its
     * templates: a template plugin is on only where it is used (owner,
     * 26 Nov 2026), and a workspace that used a template keeps it, its theme
     * included, when the template moves into its own plugin.
     *
     * @param \Closure(): array<string, list<string>> $templateKeys
     */
    public function useTemplateKeys(\Closure $templateKeys): void
    {
        $this->templateKeys = $templateKeys;
    }

    /** @param \Closure(int, string): bool $entitled (workspace, entitlement key) => granted */
    public function useEntitlements(\Closure $entitled): void
    {
        $this->entitled = $entitled;
    }

    /**
     * The installation's store licence for a plugin installed from the store:
     * licensed, grace or unlicensed; null when it wasn't installed from a store.
     *
     * @param \Closure(string): ?string $installLicence
     */
    public function useInstallLicence(\Closure $installLicence): void
    {
        $this->installLicence = $installLicence;
    }

    /**
     * Is the plugin fully on for this workspace? With no workspace given, the
     * current request's workspace. Read-only counts as not on: its jobs, hooks
     * and notifications pause.
     */
    public function isOn(string $pluginName, ?int $tenantId = null): bool
    {
        return $this->stateFor($pluginName, $tenantId)['state'] === self::STATE_ON;
    }

    /** Can the workspace see the plugin at all: on or read-only. */
    public function isVisible(string $pluginName, ?int $tenantId = null): bool
    {
        return $this->stateFor($pluginName, $tenantId)['state'] !== self::STATE_OFF;
    }

    /**
     * The plugin's state for a workspace, with why and until when.
     *
     * `reason` is one of: null (free or plain on), `free_for_all`, `entitled`,
     * `cash`, `trial`, `grace`, `lapsed`, `installation_unlicensed`,
     * `installation_grace`, `withdrawn`.
     *
     * A plugin a signed revocation list killed is off everywhere (WHIT-1277,
     * #2233), the system workspace and work with no workspace included: the
     * operator removes it, nobody runs it.
     *
     * @return array{state: string, reason: ?string, graceUntil: ?string, trialEndsAt: ?string}
     */
    public function stateFor(string $pluginName, ?int $tenantId = null): array
    {
        if ($this->isKilled($pluginName)) {
            return self::state(self::STATE_OFF, 'withdrawn');
        }
        $tenantId ??= TenantContext::getTenantId();
        if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
            return self::state(self::STATE_ON);
        }
        $key = $tenantId . ':' . $pluginName;

        return $this->states[$key] ??= $this->compute($pluginName, $tenantId);
    }

    /**
     * Whether a signed revocation list killed the version of this code plugin
     * that is installed (WHIT-1277, #2233). The version is the one
     * `plugin_installs` recorded; a plugin with no record (copied in by hand)
     * is matched only by an entry for every version.
     */
    public function isKilled(string $pluginName): bool
    {
        if ($this->killed === null) {
            $this->killed = [];
            try {
                $entries = PluginTrust::acceptedEntries($this->db);
            } catch (\PDOException) {
                $entries = []; // a database without the trust tables has no list to apply
            }
            if ($entries !== []) {
                $versions = [];
                $installs = $this->db->prepare('SELECT plugin_name, version FROM plugin_installs');
                $installs->execute();
                foreach ($installs->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $versions[(string) $row['plugin_name']] = $row['version'] === null ? null : (string) $row['version'];
                }
                foreach ($entries as $entry) {
                    $name = $entry['plugin'] ?? null;
                    if (is_string($name) && PluginTrust::kills($entries, $name, $versions[$name] ?? null)) {
                        $this->killed[$name] = true;
                    }
                }
            }
        }

        return $this->killed[$pluginName] ?? false;
    }

    /** @return array{state: string, reason: ?string, graceUntil: ?string, trialEndsAt: ?string} */
    private function compute(string $pluginName, int $tenantId): array
    {
        $row = $this->rowsFor($tenantId)[$pluginName] ?? null;
        if ($row === null && $this->accessOf($pluginName) === self::CHOOSE && $this->usesTemplateOf($pluginName, $tenantId)) {
            $this->setEnabled($tenantId, $pluginName, true);
            $row = $this->rowsFor($tenantId)[$pluginName] ?? null;
        }
        $switchedOn = match ($this->accessOf($pluginName)) {
            self::ALL => true,
            self::OPERATOR => false,
            default => $row['enabled'] ?? false,
        };
        if (!$switchedOn) {
            return self::state(self::STATE_OFF);
        }

        $paid = $this->paidAccessOf($pluginName);
        if ($paid === null) {
            return self::state(self::STATE_ON);
        }

        $licence = $this->installLicenceOf($pluginName);
        if ($licence === 'unlicensed') {
            return self::state(self::STATE_READ_ONLY, 'installation_unlicensed');
        }

        $trialEndsAt = $row['trial_ends_at'] ?? null;
        $now = ($this->clock)();
        if ($this->isFreeForAll($pluginName)) {
            return self::state(self::STATE_ON, $licence === 'grace' ? 'installation_grace' : 'free_for_all');
        }
        if ($this->entitled !== null && ($this->entitled)($tenantId, $paid['entitlement'])) {
            return self::state(self::STATE_ON, $licence === 'grace' ? 'installation_grace' : 'entitled');
        }
        $cash = $this->cashCover !== null ? ($this->cashCover)($tenantId, $paid['product']) : null;
        if ($cash !== null && $cash['active']) {
            return self::state(self::STATE_ON, 'cash', null, null);
        }
        if ($trialEndsAt !== null && strtotime($trialEndsAt) > $now) {
            return self::state(self::STATE_ON, 'trial', null, $trialEndsAt);
        }

        // Access has gone. Grace runs from when it was seen gone: the add-on's
        // end as the billing plugin reported it, the trial's end, or the end of
        // the last cash payment, whichever came last.
        $lapsedAt = $row['access_lapsed_at'] ?? $trialEndsAt;
        if ($cash !== null && ($lapsedAt === null || strtotime($cash['until']) > strtotime($lapsedAt))) {
            $lapsedAt = $cash['until'];
        }
        if ($lapsedAt !== null) {
            $graceUntil = strtotime($lapsedAt) + self::GRACE_DAYS * 86400;
            $until = gmdate('Y-m-d H:i:s', $graceUntil);
            if ($graceUntil > $now) {
                return self::state(self::STATE_ON, 'grace', $until, $trialEndsAt);
            }

            return self::state(self::STATE_READ_ONLY, 'lapsed', $until, $trialEndsAt);
        }

        return self::state(self::STATE_READ_ONLY, 'lapsed', null, $trialEndsAt);
    }

    /**
     * May this workspace turn the paid plugin on right now, without a trial?
     * True for a free plugin.
     */
    public function mayTurnOn(string $pluginName, int $tenantId): bool
    {
        $paid = $this->paidAccessOf($pluginName);
        if ($paid === null || $this->isFreeForAll($pluginName)) {
            return true;
        }
        if ($this->entitled !== null && ($this->entitled)($tenantId, $paid['entitlement'])) {
            return true;
        }
        if ($this->cashCover !== null && (($this->cashCover)($tenantId, $paid['product'])['active'] ?? false)) {
            return true;
        }
        $trialEndsAt = $this->rowsFor($tenantId)[$pluginName]['trial_ends_at'] ?? null;

        return $trialEndsAt !== null && strtotime($trialEndsAt) > ($this->clock)();
    }

    /** Has this workspace never had a trial of the plugin, and does it offer one? */
    public function trialAvailable(string $pluginName, int $tenantId): bool
    {
        $paid = $this->paidAccessOf($pluginName);
        if ($paid === null || $paid['trialDays'] <= 0) {
            return false;
        }

        return ($this->rowsFor($tenantId)[$pluginName]['trial_started_at'] ?? null) === null;
    }

    /**
     * Start the workspace's one trial and turn the plugin on. False when the
     * plugin offers none or the workspace already had it.
     *
     * `$days` is a workspace template's own length (WHIT-1195, 1 to 90); the
     * plugin's when null. It never creates a trial the plugin doesn't offer.
     */
    public function startTrial(int $tenantId, string $pluginName, ?int $profileId = null, ?int $days = null): bool
    {
        if (!$this->trialAvailable($pluginName, $tenantId)) {
            return false;
        }
        $days = $days !== null ? max(1, min(90, $days)) : ($this->paidAccessOf($pluginName)['trialDays'] ?? 0);
        $now = ($this->clock)();

        $stmt = $this->db->prepare(
            'INSERT INTO tenant_code_plugins
                (tenant_id, plugin_name, enabled, trial_started_at, trial_ends_at, access_lapsed_at, changed_by_profile_id, changed_at)
             VALUES (:tenant_id, :plugin, :enabled, :started, :ends, NULL, :profile, CURRENT_TIMESTAMP)
             ON CONFLICT (tenant_id, plugin_name) DO UPDATE SET
                enabled = excluded.enabled,
                trial_started_at = excluded.trial_started_at,
                trial_ends_at = excluded.trial_ends_at,
                access_lapsed_at = NULL,
                changed_by_profile_id = excluded.changed_by_profile_id,
                changed_at = excluded.changed_at'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':plugin', $pluginName);
        $stmt->bindValue(':enabled', true, PDO::PARAM_BOOL);
        $stmt->bindValue(':started', gmdate('Y-m-d H:i:s', $now));
        $stmt->bindValue(':ends', gmdate('Y-m-d H:i:s', $now + $days * 86400));
        $stmt->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        $this->forget($tenantId);

        return true;
    }

    /**
     * The paid plugin sold as this product: its access ended (true) or came
     * back (false) for the workspace. Starts or ends its grace.
     *
     * @return list<string> the plugins it applies to
     */
    public function markLapsed(int $tenantId, string $product, bool $lapsed): array
    {
        $plugins = [];
        foreach ($this->paidAccessAll() as $name => $paid) {
            if ($paid['product'] === $product) {
                $plugins[] = $name;
            }
        }
        foreach ($plugins as $name) {
            $stmt = $this->db->prepare(
                $lapsed
                    ? 'UPDATE tenant_code_plugins SET access_lapsed_at = :now
                        WHERE tenant_id = :tenant_id AND plugin_name = :plugin AND access_lapsed_at IS NULL'
                    : 'UPDATE tenant_code_plugins SET access_lapsed_at = NULL
                        WHERE tenant_id = :tenant_id AND plugin_name = :plugin'
            );
            if ($lapsed) {
                $stmt->bindValue(':now', gmdate('Y-m-d H:i:s', ($this->clock)()));
            }
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':plugin', $name);
            $stmt->execute();
        }
        $this->forget($tenantId);

        return $plugins;
    }

    /**
     * What a paid plugin declares, or null when it is free.
     *
     * @return array{entitlement: string, product: string, trialDays: int}|null
     */
    public function paidAccessOf(string $pluginName): ?array
    {
        return $this->paidAccessAll()[$pluginName] ?? null;
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
        $this->states = [];
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
        $this->states = [];
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
        // #2256: turning a plugin off in a workspace takes back the core
        // permissions the operator approved for its roles there. Turning it on
        // again does not bring them back: approving is the operator's.
        if (!$enabled) {
            (new \Whity\Core\Plugin\PluginCoreGrants($this->db))->removeAll($pluginName, $tenantId);
            \Whity\Auth\RoleChecker::clearCache();
        }
        $this->forget($tenantId);
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

    /** @return array{state: string, reason: ?string, graceUntil: ?string, trialEndsAt: ?string} */
    private static function state(string $state, ?string $reason = null, ?string $graceUntil = null, ?string $trialEndsAt = null): array
    {
        return ['state' => $state, 'reason' => $reason, 'graceUntil' => $graceUntil, 'trialEndsAt' => $trialEndsAt];
    }

    private function forget(int $tenantId): void
    {
        unset($this->rows[$tenantId]);
        foreach (array_keys($this->states) as $key) {
            if (str_starts_with($key, $tenantId . ':')) {
                unset($this->states[$key]);
            }
        }
    }

    private function installLicenceOf(string $pluginName): ?string
    {
        if ($this->installLicence === null) {
            return null;
        }
        if (!array_key_exists($pluginName, $this->licences)) {
            $this->licences[$pluginName] = ($this->installLicence)($pluginName);
        }

        return $this->licences[$pluginName];
    }

    /** @return array<string, array{entitlement: string, product: string, trialDays: int}> */
    private function paidAccessAll(): array
    {
        return $this->paidAccess !== null ? ($this->paidAccess)() : [];
    }

    /**
     * Has this workspace applied one of the templates this plugin offers?
     * Only asked for a plugin the workspace never switched either way.
     */
    private function usesTemplateOf(string $pluginName, int $tenantId): bool
    {
        if ($this->templateKeys === null) {
            return false;
        }
        $keys = ($this->templateKeys)()[$pluginName] ?? [];
        if ($keys === []) {
            return false;
        }
        $marks = implode(', ', array_fill(0, count($keys), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM workspace_template_runs WHERE tenant_id = ? AND template_key IN ({$marks}) LIMIT 1"
            );
            $stmt->execute([$tenantId, ...$keys]);

            return $stmt->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return array<string, array{enabled: bool, trial_started_at: ?string, trial_ends_at: ?string, access_lapsed_at: ?string}> */
    private function rowsFor(int $tenantId): array
    {
        if (!isset($this->rows[$tenantId])) {
            $stmt = $this->db->prepare(
                'SELECT plugin_name, enabled, trial_started_at, trial_ends_at, access_lapsed_at
                   FROM tenant_code_plugins WHERE tenant_id = :tenant_id'
            );
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(string) $row['plugin_name']] = [
                    'enabled' => DbBool::of($row['enabled']),
                    'trial_started_at' => self::stringOrNull($row['trial_started_at']),
                    'trial_ends_at' => self::stringOrNull($row['trial_ends_at']),
                    'access_lapsed_at' => self::stringOrNull($row['access_lapsed_at']),
                ];
            }
            $this->rows[$tenantId] = $map;
        }

        return $this->rows[$tenantId];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
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
