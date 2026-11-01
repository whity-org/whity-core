<?php

declare(strict_types=1);

namespace Whity\Sdk\Secrets;

/**
 * One plugin's secrets, per workspace (SDK 1.60, WHIT-838). Get one from
 * {@see PluginSecrets::for()}.
 *
 * A value is encrypted with the host's key before it is stored, and comes back
 * as the plain string only through {@see self::get()}. It never appears in a
 * settings read, the API reference or the audit trail: setting and deleting
 * are audited by key, never by value.
 *
 * Every call names the workspace. Use the one the host authorized the call in
 * (the request's tenant, or {@see \Whity\Sdk\Mcp\McpToolContext::$tenantId}),
 * never one the caller supplied.
 */
interface SecretStore
{
    /** The longest value accepted, in bytes. */
    public const MAX_VALUE_BYTES = 16384;

    /**
     * Store a secret, replacing any value already under $key.
     *
     * @param string $key   `[a-z0-9_.-]{1,128}`
     * @param string $value at most {@see self::MAX_VALUE_BYTES} bytes
     * @throws \InvalidArgumentException for a bad key or a value too long
     */
    public function set(int $tenantId, string $key, string $value): void;

    /** The secret under $key in this workspace, or null when none is stored. */
    public function get(int $tenantId, string $key): ?string;

    /** Remove the secret under $key in this workspace; nothing happens when none is stored. */
    public function delete(int $tenantId, string $key): void;
}
