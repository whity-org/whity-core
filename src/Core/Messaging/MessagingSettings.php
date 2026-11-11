<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;
use Whity\Core\Db\DbBool;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * A workspace's Messages settings (ADR 0016 §4-5): whether web chat is on, the
 * greeting it opens with, the address replies to email come back to, and the
 * inbound email webhook secret.
 *
 * The secret is stored encrypted with the instance key and is shown once, when
 * it is made. A workspace with no row has web chat off and no inbound email.
 */
final class MessagingSettings
{
    public const MAX_GREETING = 500;

    public function __construct(
        private readonly PDO $db,
        private readonly EncryptedSecretStore $secrets,
    ) {
    }

    /**
     * @return array{webchat_enabled: bool, webchat_greeting: ?string, inbound_email_address: ?string, has_inbound_secret: bool, updated_at: ?string}
     */
    public function get(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT webchat_enabled, webchat_greeting, inbound_email_address, inbound_secret, updated_at
               FROM messaging_settings WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'webchat_enabled' => false,
                'webchat_greeting' => null,
                'inbound_email_address' => null,
                'has_inbound_secret' => false,
                'updated_at' => null,
            ];
        }

        return [
            'webchat_enabled' => DbBool::of($row['webchat_enabled']),
            'webchat_greeting' => $row['webchat_greeting'] !== null ? (string) $row['webchat_greeting'] : null,
            'inbound_email_address' => $row['inbound_email_address'] !== null ? (string) $row['inbound_email_address'] : null,
            'has_inbound_secret' => is_string($row['inbound_secret']) && $row['inbound_secret'] !== '',
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    public function save(int $tenantId, bool $webchatEnabled, ?string $greeting, ?string $inboundAddress): void
    {
        $this->ensureRow($tenantId);
        $stmt = $this->db->prepare(
            'UPDATE messaging_settings
                SET webchat_enabled = :enabled, webchat_greeting = :greeting,
                    inbound_email_address = :address, updated_at = NOW()
              WHERE tenant_id = :tid'
        );
        $stmt->bindValue(':enabled', $webchatEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':greeting', $greeting);
        $stmt->bindValue(':address', $inboundAddress);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Make a new inbound secret, replacing any old one. Returned once; only the ciphertext is kept. */
    public function rotateInboundSecret(int $tenantId): string
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $this->ensureRow($tenantId);
        $this->db->prepare(
            'UPDATE messaging_settings SET inbound_secret = :secret, updated_at = NOW() WHERE tenant_id = :tid'
        )->execute([':secret' => $this->secrets->encrypt($secret), ':tid' => $tenantId]);

        return $secret;
    }

    /** The inbound secret in clear, or null when there is none or it cannot be read. */
    public function inboundSecret(int $tenantId): ?string
    {
        $stmt = $this->db->prepare('SELECT inbound_secret FROM messaging_settings WHERE tenant_id = :tid');
        $stmt->execute([':tid' => $tenantId]);
        $stored = $stmt->fetchColumn();
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return $this->secrets->decrypt($stored);
        } catch (\Throwable) {
            error_log('[MessagingSettings] the inbound secret could not be read');

            return null;
        }
    }

    private function ensureRow(int $tenantId): void
    {
        $this->db->prepare(
            'INSERT INTO messaging_settings (tenant_id, updated_at) VALUES (:tid, NOW())
             ON CONFLICT (tenant_id) DO NOTHING'
        )->execute([':tid' => $tenantId]);
    }
}
