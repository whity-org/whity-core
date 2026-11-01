<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Sdk\Secrets\SecretStore;

/**
 * One plugin's secrets (SDK 1.60, WHIT-838): every query is bound to the
 * workspace given and to this plugin's slug. See {@see HostPluginSecrets}.
 */
final class HostPluginSecretStore implements SecretStore
{
    private const KEY_PATTERN = '/^[a-z0-9_.-]{1,128}$/';

    /**
     * @param \Closure(): EncryptedSecretStore $cipher
     */
    public function __construct(
        private readonly PDO $db,
        private readonly string $plugin,
        private readonly \Closure $cipher,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    public function set(int $tenantId, string $key, string $value): void
    {
        self::assertKey($key);
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException('A secret may be at most ' . self::MAX_VALUE_BYTES . ' bytes.');
        }
        $stored = ($this->cipher)()->encrypt($value);
        $now    = gmdate('Y-m-d H:i:s');

        $update = $this->db->prepare('UPDATE plugin_secrets SET value = :value, updated_at = :now
            WHERE tenant_id = :tenant_id AND plugin = :plugin AND secret_key = :key');
        $update->execute([':value' => $stored, ':now' => $now, ':tenant_id' => $tenantId, ':plugin' => $this->plugin, ':key' => $key]);
        if ($update->rowCount() === 0) {
            $this->db->prepare('INSERT INTO plugin_secrets (tenant_id, plugin, secret_key, value, created_at, updated_at)
                VALUES (:tenant_id, :plugin, :key, :value, :now, :now2)')
                ->execute([':tenant_id' => $tenantId, ':plugin' => $this->plugin, ':key' => $key, ':value' => $stored, ':now' => $now, ':now2' => $now]);
        }
        $this->audited('plugin.secret.set', $tenantId, $key);
    }

    public function get(int $tenantId, string $key): ?string
    {
        self::assertKey($key);
        $stmt = $this->db->prepare('SELECT value FROM plugin_secrets WHERE tenant_id = :tenant_id AND plugin = :plugin AND secret_key = :key');
        $stmt->execute([':tenant_id' => $tenantId, ':plugin' => $this->plugin, ':key' => $key]);
        $stored = $stmt->fetchColumn();
        if (!is_string($stored)) {
            return null;
        }

        $cipher = ($this->cipher)();
        $value  = $cipher->decrypt($stored);
        // Rotation: a value under a retired key moves to the current one.
        if (!$cipher->isEncryptedWithCurrentKey($stored)) {
            $this->db->prepare('UPDATE plugin_secrets SET value = :value WHERE tenant_id = :tenant_id AND plugin = :plugin AND secret_key = :key')
                ->execute([':value' => $cipher->encrypt($value), ':tenant_id' => $tenantId, ':plugin' => $this->plugin, ':key' => $key]);
        }
        return $value;
    }

    public function delete(int $tenantId, string $key): void
    {
        self::assertKey($key);
        $stmt = $this->db->prepare('DELETE FROM plugin_secrets WHERE tenant_id = :tenant_id AND plugin = :plugin AND secret_key = :key');
        $stmt->execute([':tenant_id' => $tenantId, ':plugin' => $this->plugin, ':key' => $key]);
        if ($stmt->rowCount() > 0) {
            $this->audited('plugin.secret.deleted', $tenantId, $key);
        }
    }

    private static function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('A secret key is 1 to 128 of a-z, 0-9, ".", "_" and "-".');
        }
    }

    /** By key, never by value. */
    private function audited(string $action, int $tenantId, string $key): void
    {
        $this->audit?->record($action, [
            'tenant_id'   => $tenantId,
            'target_type' => 'plugin_secret',
            'metadata'    => ['plugin' => $this->plugin, 'key' => $key],
        ]);
    }
}
