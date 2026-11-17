<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * A workspace's Messages settings (ADR 0016 §4-5): whether web chat is on, the
 * greeting it opens with, the address replies to email come back to, and the
 * inbound email webhook secret.
 *
 * Stored as the `messaging.*` keys of the settings registry (migration 294,
 * merge map plumbing); a workspace that never saved them has web chat off and
 * no inbound email. The secret is stored encrypted with the instance key under
 * an unregistered key, so no settings read returns it, and is shown once, when
 * it is made.
 */
final class MessagingSettings
{
    public const MAX_GREETING = 500;

    /** Not a registry key, so the settings API never lists or returns it. */
    public const INBOUND_SECRET_KEY = 'messaging.inbound_secret_encrypted';

    private const KEYS = [
        SettingsRegistry::MESSAGING_WEBCHAT_ENABLED,
        SettingsRegistry::MESSAGING_WEBCHAT_GREETING,
        SettingsRegistry::MESSAGING_INBOUND_EMAIL_ADDRESS,
        self::INBOUND_SECRET_KEY,
    ];

    private readonly TenantSettingsRepository $store;

    public function __construct(
        private readonly PDO $db,
        private readonly EncryptedSecretStore $secrets,
    ) {
        $this->store = new TenantSettingsRepository($db);
    }

    /**
     * @return array{webchat_enabled: bool, webchat_greeting: ?string, inbound_email_address: ?string, has_inbound_secret: bool, updated_at: ?string}
     */
    public function get(int $tenantId): array
    {
        $stored = $this->store->valuesFor($tenantId, self::KEYS);
        $greeting = (string) ($stored[SettingsRegistry::MESSAGING_WEBCHAT_GREETING] ?? '');
        $address = (string) ($stored[SettingsRegistry::MESSAGING_INBOUND_EMAIL_ADDRESS] ?? '');

        return [
            'webchat_enabled' => ($stored[SettingsRegistry::MESSAGING_WEBCHAT_ENABLED] ?? 'false') === 'true',
            'webchat_greeting' => $greeting !== '' ? $greeting : null,
            'inbound_email_address' => $address !== '' ? $address : null,
            'has_inbound_secret' => ($stored[self::INBOUND_SECRET_KEY] ?? '') !== '',
            'updated_at' => $stored === [] ? null : $this->store->lastUpdatedAt($tenantId, self::KEYS),
        ];
    }

    /** Whether the workspace's web chat is on, without reading anything else. */
    public function webchatEnabled(int $tenantId): bool
    {
        return ($this->store->valuesFor($tenantId, [SettingsRegistry::MESSAGING_WEBCHAT_ENABLED])[SettingsRegistry::MESSAGING_WEBCHAT_ENABLED] ?? 'false') === 'true';
    }

    public function save(int $tenantId, bool $webchatEnabled, ?string $greeting, ?string $inboundAddress): void
    {
        $values = [
            SettingsRegistry::MESSAGING_WEBCHAT_ENABLED => $webchatEnabled ? 'true' : 'false',
            SettingsRegistry::MESSAGING_WEBCHAT_GREETING => $greeting ?? '',
            SettingsRegistry::MESSAGING_INBOUND_EMAIL_ADDRESS => $inboundAddress ?? '',
        ];
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($values as $key => $value) {
                $this->store->set($tenantId, $key, $value);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** The workspace's slug, for its chat link and webhook address. */
    public function workspaceSlug(int $tenantId): ?string
    {
        $stmt = $this->db->prepare('SELECT slug FROM tenants WHERE id = :tid');
        $stmt->execute([':tid' => $tenantId]);
        $slug = $stmt->fetchColumn();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Make a new inbound secret, replacing any old one. Returned once; only the ciphertext is kept. */
    public function rotateInboundSecret(int $tenantId): string
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $this->store->set($tenantId, self::INBOUND_SECRET_KEY, $this->secrets->encrypt($secret));

        return $secret;
    }

    /** The inbound secret in clear, or null when there is none or it cannot be read. */
    public function inboundSecret(int $tenantId): ?string
    {
        $stored = $this->store->valuesFor($tenantId, [self::INBOUND_SECRET_KEY])[self::INBOUND_SECRET_KEY] ?? null;
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
}
