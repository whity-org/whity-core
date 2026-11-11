<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * When a signed-in customer sees "Messages" in their menu (ADR 0016 §5): they
 * are a customer of the workspace, and either web chat is on or they already
 * have a conversation to read. Staff use the staff inbox instead.
 */
final class MessagesNavConditions
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isCustomerWithMessages(int $profileId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND kind = 'customer' AND status = 'active'"
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $settings = $this->db->prepare('SELECT webchat_enabled FROM messaging_settings WHERE tenant_id = :tid');
        $settings->execute([':tid' => $tenantId]);
        $enabled = $settings->fetchColumn();
        if ($enabled !== false && DbBool::of($enabled)) {
            return true;
        }

        $own = $this->db->prepare('SELECT 1 FROM conversations WHERE tenant_id = :tid AND customer_profile_id = :pid LIMIT 1');
        $own->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return $own->fetchColumn() !== false;
    }
}
