<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use PDO;
use Whity\Auth\RoleChecker;

/**
 * The email addresses of a workspace's active members who hold a permission:
 * who to tell about something only they can act on (the plan limit goes to
 * whoever can pay for a plan). By capability, never by a role's name.
 */
class WorkspaceRecipients
{
    private const MAX_MEMBERS_SCANNED = 200;

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roles,
    ) {
    }

    /**
     * @return list<string>
     */
    public function holding(int $tenantId, string $permission): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT m.profile_id, pe.email
               FROM memberships m
               JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = TRUE
              WHERE m.tenant_id = :tenant_id AND m.status = 'active'
              ORDER BY m.profile_id LIMIT " . self::MAX_MEMBERS_SCANNED
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim((string) $row['email']);
            if ($email !== '' && $this->roles->hasPermissionForProfile((int) $row['profile_id'], $permission, $tenantId)) {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }
}
