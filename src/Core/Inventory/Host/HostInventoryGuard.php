<?php

declare(strict_types=1);

namespace Whity\Core\Inventory\Host;

use Whity\Core\Contacts\ContactRejectedException;
use Whity\Core\Inventory\InventoryDb;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Inventory\InventoryRejected;

/**
 * What the inventory host services share (SDK 1.126, WHIT-1232):
 *
 * - THE WORKSPACE is the one the call runs in ({@see TenantContext}), read on
 *   every call and never kept (owner decision D2: plugin-facing methods take
 *   no workspace argument). No workspace, or the system workspace, refuses
 *   with reason `no_workspace`.
 * - WRITES act as a person who is in that workspace and holds the route's
 *   permission.
 * - REFUSALS from core become the SDK's {@see InventoryRejected} with a
 *   stable reason.
 */
final class HostInventoryGuard
{
    /** @var \Closure(): ?int */
    private readonly \Closure $workspace;

    /**
     * @param \Closure(int, string, int): bool $hasPermission profile, permission, workspace
     * @param (\Closure(): ?int)|null $workspace the call's workspace; {@see TenantContext} by default
     */
    public function __construct(
        private readonly InventoryDb $db,
        private readonly \Closure $hasPermission,
        ?\Closure $workspace = null,
    ) {
        $this->workspace = $workspace ?? static fn (): ?int => TenantContext::getTenantId();
    }

    /** @throws InventoryRejected */
    public function workspace(): int
    {
        $id = ($this->workspace)();
        if ($id === null || $id <= 0) {
            throw new InventoryRejected('Inventory needs a workspace, and this call has none.', 403, null, InventoryRejected::NO_WORKSPACE);
        }

        return $id;
    }

    /**
     * @return \Closure(string): bool the person's permissions, for fields with their own
     * @throws InventoryRejected
     */
    public function writer(int $tenantId, int $profileId, string $permission): \Closure
    {
        $member = $profileId > 0 && $this->db->one(
            "SELECT 1 AS hit FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = 'active'",
            [':tenant_id' => $tenantId, ':profile_id' => $profileId]
        ) !== null;
        if (!$member) {
            throw new InventoryRejected('That person is not in this workspace.', 403, null, InventoryRejected::FORBIDDEN);
        }
        $check = $this->hasPermission;
        if (!$check($profileId, $permission, $tenantId)) {
            throw new InventoryRejected('That person can\'t do this here (' . $permission . ').', 403, null, InventoryRejected::FORBIDDEN);
        }

        return static fn (string $p): bool => $check($profileId, $p, $tenantId);
    }

    /**
     * Runs core work, turning its refusals into the SDK's.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws InventoryRejected
     */
    public static function run(callable $work): mixed
    {
        try {
            return $work();
        } catch (InventoryRejectedException $e) {
            throw self::rejected($e->clientMessage, $e->status, $e->extra);
        } catch (ContactRejectedException $e) {
            // The workspace's own fields on an item answer with this one.
            throw self::rejected($e->clientMessage, $e->status, $e->extra);
        }
    }

    /** @param array<string, mixed> $extra */
    private static function rejected(string $message, int $status, array $extra): InventoryRejected
    {
        $field = $extra['field'] ?? null;
        $code = $extra['code'] ?? null;
        $reason = match (true) {
            $code === 'vetoed' => InventoryRejected::VETOED,
            $status === 403 => InventoryRejected::FORBIDDEN,
            $status === 404 => InventoryRejected::NOT_FOUND,
            $status === 409 => InventoryRejected::CONFLICT,
            default => InventoryRejected::INVALID,
        };

        return new InventoryRejected($message, $status, is_string($field) ? $field : null, $reason);
    }
}
