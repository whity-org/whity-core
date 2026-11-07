<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Core\Container\HostWiredService;
use Whity\Sdk\PluginNamespace;

/**
 * MCP resources contributed by plugins the operator granted agent context
 * (SDK 1.78, WHIT-993; {@see \Whity\Sdk\PluginMcpResourcesInterface}).
 *
 * Each is published as `whity-plugin://<plugin slug>/<path>`: the host adds the
 * slug, so one plugin can't take another's address. Every entry carries a
 * permission, checked per caller by `resources/list` and `resources/read`.
 *
 * Filled while the worker boots, and again whenever the plugin set or the
 * operator's grants change; read-only in between.
 */
final class PluginResourceRegistry implements HostWiredService
{
    public const URI_SCHEME = 'whity-plugin://';

    public const MAX_DESCRIPTION = 500;
    /** What a read may return, in bytes. */
    public const MAX_BYTES = 1_048_576;

    /** @var array<string, array{uri: string, name: string, description: string, mimeType: string, requiredPermission: string, plugin: string, read: callable}> */
    private array $resources = [];

    /**
     * Register one descriptor. Returns why it was refused, or null.
     *
     * @param array<mixed> $descriptor
     */
    public function register(array $descriptor, string $pluginName): ?string
    {
        $path = $descriptor['path'] ?? null;
        if (!is_string($path) || preg_match('#^[a-z0-9][a-z0-9._/-]{0,119}$#', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            return 'resource has a missing or malformed `path` (lowercase letters, digits, - _ . / and at most 120 characters)';
        }
        $name = $descriptor['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return "resource '{$path}' has a missing or empty `name`";
        }
        $description = $descriptor['description'] ?? '';
        if (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION) {
            return "resource '{$path}' has a `description` that is not a string of at most " . self::MAX_DESCRIPTION . ' characters';
        }
        $permission = $descriptor['requiredPermission'] ?? null;
        if (!is_string($permission) || $permission === '') {
            return "resource '{$path}' declares no `requiredPermission`; every plugin resource needs one";
        }
        $read = $descriptor['read'] ?? null;
        if (!is_callable($read)) {
            return "resource '{$path}' has a missing or non-callable `read`";
        }
        $mimeType = $descriptor['mimeType'] ?? 'text/plain';
        if (!is_string($mimeType) || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType) !== 1) {
            return "resource '{$path}' has a malformed `mimeType`";
        }

        $uri = self::URI_SCHEME . PluginNamespace::slug($pluginName) . '/' . $path;
        if (isset($this->resources[$uri])) {
            return "resource '{$uri}' is already registered — duplicate dropped";
        }
        $this->resources[$uri] = [
            'uri'                => $uri,
            'name'               => $name,
            'description'        => $description,
            'mimeType'           => $mimeType,
            'requiredPermission' => $permission,
            'plugin'             => $pluginName,
            'read'               => $read,
        ];

        return null;
    }

    /** @return list<array{uri: string, name: string, description: string, mimeType: string, requiredPermission: string, plugin: string, read: callable}> */
    public function all(): array
    {
        return array_values($this->resources);
    }

    /** @return array{uri: string, name: string, description: string, mimeType: string, requiredPermission: string, plugin: string, read: callable}|null */
    public function find(string $uri): ?array
    {
        return $this->resources[$uri] ?? null;
    }

    public function reset(): void
    {
        $this->resources = [];
    }
}
