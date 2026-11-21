<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\AccountingDb;
use Whity\Core\Accounting\AccountingRejectedException;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Accounting\AccountingRejected;
use Whity\Sdk\PluginInterface;

/**
 * What the accounting host services share (SDK 1.132, WHIT-1318), the same
 * rules as inventory's:
 *
 * - THE WORKSPACE is the one the call runs in ({@see TenantContext}), read on
 *   every call and never kept. No workspace, or the system workspace, refuses
 *   with reason `no_workspace`.
 * - WRITES act as a person who is in that workspace and holds the permission.
 * - REFUSALS from core become the SDK's {@see AccountingRejected} with a
 *   stable reason.
 */
final class HostAccountingGuard
{
    /** @var \Closure(): ?int */
    private readonly \Closure $workspace;

    /**
     * @param \Closure(int, string, int): bool $hasPermission profile, permission, workspace
     * @param (\Closure(): ?int)|null $workspace the call's workspace; {@see TenantContext} by default
     */
    public function __construct(
        private readonly AccountingDb $db,
        private readonly \Closure $hasPermission,
        ?\Closure $workspace = null,
    ) {
        $this->workspace = $workspace ?? static fn (): ?int => TenantContext::getTenantId();
    }

    /** @throws AccountingRejected */
    public function workspace(): int
    {
        $id = ($this->workspace)();
        if ($id === null || $id <= 0) {
            throw new AccountingRejected('Accounting needs a workspace, and this call has none.', 403, null, AccountingRejected::NO_WORKSPACE);
        }

        return $id;
    }

    /** @throws AccountingRejected */
    public function writer(int $tenantId, int $profileId, string $permission): void
    {
        $member = $profileId > 0 && $this->db->one(
            "SELECT 1 AS hit FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = 'active'",
            [':tenant_id' => $tenantId, ':profile_id' => $profileId]
        ) !== null;
        if (!$member) {
            throw new AccountingRejected('That person is not in this workspace.', 403, null, AccountingRejected::FORBIDDEN);
        }
        if (!($this->hasPermission)($profileId, $permission, $tenantId)) {
            throw new AccountingRejected('That person can\'t do this here (' . $permission . ').', 403, null, AccountingRejected::FORBIDDEN);
        }
    }

    /**
     * The slug of a plugin object the loader loaded.
     *
     * @throws AccountingRejected
     */
    public static function slugOf(PluginInterface $plugin): string
    {
        try {
            return PluginIdentity::slugOf($plugin);
        } catch (\Throwable) {
            throw new AccountingRejected('Only a loaded plugin can write the books.', 403, null, AccountingRejected::FORBIDDEN);
        }
    }

    /** @throws AccountingRejected */
    public static function useContext(string $how): AccountingRejected
    {
        return new AccountingRejected('The books are written through the plugin context: ' . $how . '.', 403, null, AccountingRejected::USE_PLUGIN_CONTEXT);
    }

    /**
     * Runs core work, turning its refusals into the SDK's.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws AccountingRejected
     */
    public static function run(callable $work): mixed
    {
        try {
            return $work();
        } catch (AccountingRejectedException $e) {
            $field = $e->extra['field'] ?? null;
            $code = $e->extra['code'] ?? null;
            $reason = match (true) {
                $code === 'vetoed' => AccountingRejected::VETOED,
                $code === 'unbalanced' => AccountingRejected::UNBALANCED,
                $code === 'period_closed' => AccountingRejected::PERIOD_CLOSED,
                $e->status === 403 => AccountingRejected::FORBIDDEN,
                $e->status === 404 => AccountingRejected::NOT_FOUND,
                $e->status === 409 => AccountingRejected::CONFLICT,
                default => AccountingRejected::INVALID,
            };

            throw new AccountingRejected($e->clientMessage, $e->status, is_string($field) ? $field : null, $reason);
        }
    }
}
