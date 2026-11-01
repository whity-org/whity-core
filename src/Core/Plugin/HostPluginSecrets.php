<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;
use Whity\Sdk\Secrets\PluginSecrets;
use Whity\Sdk\Secrets\SecretStore;

/**
 * The host's plugin secret store (SDK 1.60, WHIT-838; migration 187).
 *
 * Values are encrypted with the platform key through {@see EncryptedSecretStore}
 * (authenticated encryption, key-id prefixed) and kept in `plugin_secrets`,
 * one row per workspace, plugin and key. A value written under a retired key
 * is re-encrypted under the current one the next time it is read, so a key
 * rotation completes as secrets are used.
 *
 * Kept apart by plugin: a store only reaches rows under its plugin's slug,
 * the same slug that prefixes the plugin's permissions and events. Plugins
 * run in the host's process, so this is a boundary of the API, not a sandbox.
 *
 * Audited by key, never by value.
 *
 * Worker-safe: holds the PDO and a lazily built cipher, which is the same for
 * every request. The cipher is built on first use, so an instance with no
 * platform key boots and fails only when a plugin actually uses a secret.
 */
final class HostPluginSecrets implements PluginSecrets
{
    private ?EncryptedSecretStore $cipher = null;

    /**
     * @param \Closure(): EncryptedSecretStore $cipherFactory builds the platform cipher
     */
    public function __construct(
        private readonly PDO $db,
        private readonly \Closure $cipherFactory,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    public function for(PluginInterface $plugin): SecretStore
    {
        $slug = PluginNamespace::slug($plugin->getName());
        if ($slug === null) {
            throw new \InvalidArgumentException('This plugin has no usable name, so it can keep no secrets.');
        }
        return new HostPluginSecretStore($this->db, $slug, $this->cipher(...), $this->audit);
    }

    private function cipher(): EncryptedSecretStore
    {
        return $this->cipher ??= ($this->cipherFactory)();
    }
}
