<?php

declare(strict_types=1);

namespace Whity\Sdk\Secrets;

/**
 * One plugin's secrets in the current workspace (SDK 1.124). Get it from
 * {@see \Whity\Sdk\Context\PluginScope::secrets()}.
 *
 * The same store as {@see SecretStore}, without the workspace argument: the
 * host uses the workspace the request or job runs in, so a call can't name
 * another (decision D2). Without a workspace every call throws
 * \RuntimeException. Values are encrypted with the host's key, setting and
 * deleting are audited by key, never by value.
 */
interface WorkspaceSecrets
{
    /**
     * Store a secret, replacing any value under $key.
     *
     * @param string $key   `[a-z0-9_.-]{1,128}`
     * @param string $value at most {@see SecretStore::MAX_VALUE_BYTES} bytes
     * @throws \InvalidArgumentException for a bad key or a value too long
     */
    public function set(string $key, string $value): void;

    /** The secret under $key, or null when none is stored. */
    public function get(string $key): ?string;

    /** Remove the secret under $key; nothing happens when none is stored. */
    public function delete(string $key): void;
}
