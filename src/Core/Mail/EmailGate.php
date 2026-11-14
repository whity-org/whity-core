<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\Settings\SettingsService;

/**
 * The one answer to "may this email go?" (E2). Every core email asks here
 * instead of reading its own switch. The layers below are checked in order,
 * and any of them can stop an email:
 *
 * 1. **The operator, per event** (`mail.events.*`): only for the instance's own
 *    account emails (welcome, approval, invitation, deletion, password reset and
 *    account recovery). Read fail-closed: a settings error sends nothing.
 * 2. **The workspace** (its `email` channel in Settings › Notifications): off
 *    stops every email the workspace sends to its people, except security ones.
 * 3. **The source** (an agent's "Email me about this agent",
 *    `agents.notify_by_email`): off stops that agent's emails.
 * 4. **The person** (Preferences › Notifications): the kind on `email`, or every
 *    kind on `email` (`*`). Opt-out: on unless the person turned it off.
 *
 * Security and account emails (types starting `security.`, `account.`,
 * `auth.`, `password.`, `billing.`) skip layers 2 and 4: they can't be turned
 * off by the workspace or the person (EM.1).
 *
 * Without a database (a unit test, a CLI without one) layers 2-4 answer yes.
 */
final class EmailGate
{
    public const CHANNEL = 'email';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ?PDO $db = null,
    ) {
    }

    /**
     * Layer 1: the operator's switch for one of the instance's account emails.
     * True only when the `mail.events.*` key reads 'true'.
     */
    public function instanceEvent(string $key): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[$key] ?? 'false') === 'true';
    }

    /**
     * Layer 2: whether the workspace sends email to its people. On unless its
     * `email` channel was turned off.
     */
    public function workspace(int $tenantId): bool
    {
        if ($this->db === null || $tenantId <= 0) {
            return true;
        }
        try {
            $row = (new TenantNotificationSettingsRepository($this->db))->findForChannel($tenantId, self::CHANNEL);
        } catch (\Throwable) {
            return true;
        }

        return $row === null || ($row['enabled'] ?? true) !== false;
    }

    /**
     * Layer 3: an agent's own switch, for the agent assignment that caused the
     * email. On when the agent has no switch (a personal credential).
     */
    public function agent(int $tenantId, int $assignmentId): bool
    {
        if ($this->db === null) {
            return true;
        }
        $stmt = $this->db->prepare(
            'SELECT a.notify_by_email
               FROM agent_assignments aa
               JOIN agents a ON a.id = aa.agent_id AND a.tenant_id = aa.tenant_id
              WHERE aa.id = :id AND aa.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $assignmentId, ':tenant_id' => $tenantId]);
        // A row, not a column: fetchColumn() answers false both for "no row" and
        // for a false the driver returns as a real boolean.
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['notify_by_email'] === null) {
            return true;
        }

        return DbBool::of($row['notify_by_email']);
    }

    /**
     * Layer 4: the person's own choice for this kind of email. An exact
     * (type, email) row wins over the (`*`, email) row; no row means on.
     */
    public function person(int $tenantId, ?int $profileId, string $type): bool
    {
        if ($this->db === null || $profileId === null || $profileId <= 0 || $this->isSecurity($type)) {
            return true;
        }
        $exact = null;
        $every = null;
        foreach ((new NotificationPreferenceRepository($this->db))->listForProfile($tenantId, $profileId) as $pref) {
            if ($pref['channel'] !== self::CHANNEL) {
                continue;
            }
            if ($pref['type'] === $type) {
                $exact = $pref['enabled'];
            } elseif ($pref['type'] === NotificationPreferenceResolver::WILDCARD) {
                $every = $pref['enabled'];
            }
        }

        return $exact ?? $every ?? true;
    }

    /**
     * Layers 2 and 4 together: whether the workspace's email of this type may
     * reach this person. Security types always may.
     */
    public function allows(int $tenantId, ?int $profileId, string $type): bool
    {
        if ($this->isSecurity($type)) {
            return true;
        }

        return $this->workspace($tenantId) && $this->person($tenantId, $profileId, $type);
    }

    /** Security and account emails can't be turned off by a workspace or a person. */
    public function isSecurity(string $type): bool
    {
        foreach (NotificationPreferenceResolver::DEFAULT_TRANSACTIONAL_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
