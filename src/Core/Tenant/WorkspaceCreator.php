<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * "Create workspace" for a person who is already signed in (WHIT-1169).
 *
 * Until now sign-up created one workspace, and after that a person could only
 * join others by invitation. Here a signed-in person creates another and becomes
 * its owner, from the workspace switcher or Ctrl+K. No password is asked for or
 * checked and no profile is created: the signed-in session is the proof, so it
 * works when an installation signs in only through Whity ID (WHIT-1168).
 *
 * Gates, all read again under the person's profile row lock:
 *  - `workspaces.self_create_enabled` (operator; off by default, on for app.whity.dev);
 *  - the person is not deactivated;
 *  - `workspaces.max_owned_per_person`: how many workspaces they already own
 *    (0 = no limit). Two racing requests can't both pass the limit.
 *
 * A person's first owned workspace starts on the default plan
 * (`billing.default_plan`, Free on app.whity.dev), behind the payment gate when
 * the operator requires payment for new workspaces, exactly as sign-up does.
 * Their second and later ones start on `workspaces.additional_plan` in a trial,
 * then read-only until paid (WHIT-1203, {@see AdditionalWorkspacePlan}).
 * Which one applies is decided under the profile lock, from the same owned
 * count the limit reads.
 */
final class WorkspaceCreator
{
    public const CREATED = 'created';
    public const DISABLED = 'disabled';
    public const LIMIT_REACHED = 'limit_reached';
    public const REFUSED = 'refused';

    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function enabled(): bool
    {
        return ($this->settings->getGlobal()[SettingsRegistry::WORKSPACES_SELF_CREATE_ENABLED] ?? 'false') === 'true';
    }

    /** The owned-workspace limit; 0 means none. */
    public function limit(): int
    {
        return max(0, (int) ($this->settings->getGlobal()[SettingsRegistry::WORKSPACES_MAX_OWNED_PER_PERSON] ?? '0'));
    }

    /**
     * What this person may do now, to show or hide "Create workspace".
     *
     * `starts_on` is null for the default plan, else the paid plan this one
     * would start on, its trial and its monthly price (WHIT-1203).
     *
     * @return array{allowed: bool, reason: ?string, owned: int, limit: int,
     *               starts_on: array{plan_key: string, plan_name: string, trial_days: int,
     *                                price: array{unit_amount: int, currency: string, billing_period: string}|null}|null}
     */
    public function stateFor(int $profileId): array
    {
        $owned = (new WorkspaceOwners($this->db))->countOwnedBy($profileId);
        $limit = $this->limit();
        $reason = null;
        if (!$this->enabled()) {
            $reason = self::DISABLED;
        } elseif ($limit > 0 && $owned >= $limit) {
            $reason = self::LIMIT_REACHED;
        }
        return [
            'allowed'   => $reason === null,
            'reason'    => $reason,
            'owned'     => $owned,
            'limit'     => $limit,
            'starts_on' => $reason === null ? $this->additional()->noticeFor($owned) : null,
        ];
    }

    /**
     * Create a workspace this person owns.
     *
     * `paid_plan` is the plan key when it started on the additional plan.
     *
     * @return array{status: string, tenant_id: ?int, name: ?string, paid_plan?: ?string}
     *
     * @throws \LogicException when called inside an open transaction
     */
    public function createFor(int $profileId, string $name): array
    {
        if (!$this->enabled()) {
            return ['status' => self::DISABLED, 'tenant_id' => null, 'name' => null];
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('WorkspaceCreator must own its transaction; it was called inside one.');
        }
        $paymentRequired = ($this->settings->getGlobal()[SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED] ?? 'false') === 'true';
        $limit = $this->limit();
        $founder = new WorkspaceFounder($this->db, $this->hooks);

        $this->db->beginTransaction();
        try {
            $profile = $founder->lockProfile($profileId);
            if ($profile === null || $profile['status'] === 'inactive') {
                $this->db->rollBack();
                return ['status' => self::REFUSED, 'tenant_id' => null, 'name' => null];
            }
            $owned = (new WorkspaceOwners($this->db))->countOwnedBy($profileId);
            if ($limit > 0 && $owned >= $limit) {
                $this->db->rollBack();
                return ['status' => self::LIMIT_REACHED, 'tenant_id' => null, 'name' => null];
            }
            $paidPlan = $this->additional()->forOwnedCount($owned);
            $roleId = $founder->ownerRoleId();
            if ($roleId === null) {
                error_log('[workspace-create] base "' . WorkspaceFounder::OWNER_ROLE . '" role missing; platform not seeded');
                $this->db->rollBack();
                return ['status' => self::REFUSED, 'tenant_id' => null, 'name' => null];
            }

            // A paid workspace writes its own plan row (trial, then the gate),
            // so the founder must not also write the closed gate.
            $founded = $founder->found($profileId, $name, $roleId, $paymentRequired && $paidPlan === null);
            if ($paidPlan !== null) {
                $this->additional()->start($founded['tenant_id'], $paidPlan, $profileId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $founder->afterCommit($founded, $profileId, 'self_create');

        return [
            'status'    => self::CREATED,
            'tenant_id' => $founded['tenant_id'],
            'name'      => $founded['name'],
            'paid_plan' => $paidPlan['plan_key'] ?? null,
        ];
    }

    private function additional(): AdditionalWorkspacePlan
    {
        return new AdditionalWorkspacePlan($this->db, $this->settings);
    }
}
