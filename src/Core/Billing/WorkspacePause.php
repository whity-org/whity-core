<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\WorkspaceOwners;

/**
 * Whether a workspace is paused because its plan lapsed, and who can unpause it
 * (2026-11-22).
 *
 * A workspace whose subscription is `expired` or `canceled` and whose wall
 * refuses changes is PAUSED. Its owners (and anyone else who may pay) open it
 * read-only with Billing reachable; everyone else lands on a plain page that
 * says it is paused until an owner pays and names those owners, rather than
 * meeting a 402.
 *
 * A trial that ended unpaid stays `trialing` and is NOT paused here: it keeps
 * its own read-only banner ({@see WorkspaceTrial}), unchanged.
 *
 * Read from this deployment's own `tenant_plan` and `workspace_owners` rows,
 * never from the caller. Served under `/api/v1/billing`, which the payment
 * wall never walls, so a paused workspace can always learn it is paused.
 */
final class WorkspacePause
{
    private const PAUSED_STATUSES = [
        SubscriptionService::STATUS_EXPIRED,
        SubscriptionService::STATUS_CANCELED,
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WorkspaceOwners $owners,
    ) {
    }

    /**
     * Null when the workspace is not paused.
     *
     * @return array{status: string, reads_refused: bool, owners: list<array{name: string}>}|null
     */
    public function stateFor(int $tenantId): ?array
    {
        $sub = $this->subscriptions->getSubscription($tenantId);
        $status = is_string($sub['status'] ?? null) ? (string) $sub['status'] : null;
        if ($status === null || !in_array($status, self::PAUSED_STATUSES, true)) {
            return null;
        }
        // Not enforced (off / warn): nothing is refused, so nothing is paused.
        if ($this->subscriptions->decide($tenantId, true)->allowed) {
            return null;
        }

        $owners = [];
        foreach ($this->owners->list($tenantId) as $owner) {
            $name = trim($owner['display_name']);
            if ($name === '') {
                // No name on the profile: the address is how they will be reached.
                $name = $owner['email'];
            }
            if ($name !== '') {
                $owners[] = ['name' => $name];
            }
        }

        return [
            'status'        => $status,
            'reads_refused' => !$this->subscriptions->decide($tenantId, false)->allowed,
            'owners'        => $owners,
        ];
    }
}
