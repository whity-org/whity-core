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
 * The workspace starts on the default plan (`billing.default_plan`, Free on
 * app.whity.dev), and behind the payment gate when the operator requires
 * payment for new workspaces, exactly as sign-up does.
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
     * @return array{allowed: bool, reason: ?string, owned: int, limit: int}
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
        return ['allowed' => $reason === null, 'reason' => $reason, 'owned' => $owned, 'limit' => $limit];
    }

    /**
     * Create a workspace this person owns.
     *
     * @return array{status: string, tenant_id: ?int, name: ?string}
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
            if ($limit > 0 && (new WorkspaceOwners($this->db))->countOwnedBy($profileId) >= $limit) {
                $this->db->rollBack();
                return ['status' => self::LIMIT_REACHED, 'tenant_id' => null, 'name' => null];
            }
            $roleId = $founder->ownerRoleId();
            if ($roleId === null) {
                error_log('[workspace-create] base "' . WorkspaceFounder::OWNER_ROLE . '" role missing; platform not seeded');
                $this->db->rollBack();
                return ['status' => self::REFUSED, 'tenant_id' => null, 'name' => null];
            }

            $founded = $founder->found($profileId, $name, $roleId, $paymentRequired);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $founder->afterCommit($founded, $profileId, 'self_create');

        return ['status' => self::CREATED, 'tenant_id' => $founded['tenant_id'], 'name' => $founded['name']];
    }
}
