<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Value-object helper for building and parsing canonical tenant-scoped storage
 * keys.
 *
 * Key format: `tenants/{tenantId}/{plugin}/{filename}`
 *
 * All builder methods sanitize each segment to prevent directory traversal and
 * other injection attacks:
 *  - null bytes are stripped
 *  - backslashes are normalised to forward slashes
 *  - leading slashes are stripped
 *  - `..` path components are removed
 *  - the filename segment is further reduced to its basename only
 */
final class StorageKey
{
    /**
     * Build a canonical tenant-scoped storage key.
     *
     * Result format: `tenants/{tenantId}/{plugin}/{filename}`
     *
     * Each segment is sanitized: null bytes stripped, backslashes converted,
     * leading slashes removed, `..` traversals eliminated, and the filename is
     * reduced to its basename.
     *
     * @param int    $tenantId Numeric tenant identifier.
     * @param string $plugin   Plugin slug or sub-directory within the tenant.
     * @param string $filename File name (will be reduced to basename only).
     *
     * @return string The sanitized canonical key.
     */
    public static function build(int $tenantId, string $plugin, string $filename): string
    {
        $pluginSegment   = self::sanitizeSegment($plugin);
        $filenameSegment = self::sanitizeFilename($filename);

        return sprintf('tenants/%d/%s/%s', $tenantId, $pluginSegment, $filenameSegment);
    }

    /**
     * The prefix under which one plugin keeps its files in one tenant:
     * `tenants/{tenantId}/{plugin}/`. Every key {@see self::pluginPath()} builds
     * starts with it, so listing the prefix lists exactly that plugin's files.
     *
     * @throws \InvalidArgumentException for a bad tenant id or plugin slug.
     */
    public static function pluginPrefix(int $tenantId, string $plugin): string
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A plugin file needs a workspace (tenant id above zero).');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $plugin) !== 1) {
            throw new \InvalidArgumentException('Plugin slug is not a usable storage segment.');
        }

        return sprintf('tenants/%d/%s/', $tenantId, $plugin);
    }

    /**
     * The key for a file a plugin names inside its own prefix, strictly.
     *
     * Unlike {@see self::build()}, which quietly sanitises, this REJECTS: a
     * relative path with `..`, `.`, empty segments, a backslash, a NUL, a leading
     * slash or a control character never becomes a key, so a caller that asked for
     * `../other-plugin/x` learns that it was refused rather than being written
     * somewhere else. Each segment must start with a letter or digit, so no
     * segment can be a dot-name.
     *
     * @param string $relativePath e.g. `invoices/2026/march.pdf`
     *
     * @throws \InvalidArgumentException when the path is not a plain relative path.
     */
    public static function pluginPath(int $tenantId, string $plugin, string $relativePath): string
    {
        $prefix = self::pluginPrefix($tenantId, $plugin);

        if ($relativePath === '' || strlen($relativePath) > 512) {
            throw new \InvalidArgumentException('File path must be 1 to 512 bytes.');
        }
        if (str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('File path may not contain backslashes or NUL bytes.');
        }
        foreach (explode('/', $relativePath) as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/', $segment) !== 1) {
                throw new \InvalidArgumentException('File path segments must be plain names (letters, digits, dot, dash, underscore, space).');
            }
        }

        return $prefix . $relativePath;
    }

    /**
     * Extract the tenant ID from a key built by {@see self::build()}.
     *
     * Returns null if the key does not match the expected format
     * (`tenants/{integer}/...`).
     */
    public static function tenantId(string $key): ?int
    {
        if (preg_match('#^tenants/(\d+)/.+$#', $key, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Sanitize a generic path segment (plugin slug, sub-directory, etc.).
     *
     * Strips null bytes, converts backslashes, removes leading slashes, and
     * eliminates all `..` components.
     */
    private static function sanitizeSegment(string $segment): string
    {
        // Strip null bytes
        $segment = str_replace("\0", '', $segment);

        // Normalize directory separators
        $segment = str_replace('\\', '/', $segment);

        // Strip leading slashes
        $segment = ltrim($segment, '/');

        // Remove any .. components
        $parts    = explode('/', $segment);
        $safe     = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                continue;
            }
            $safe[] = $part;
        }

        return implode('/', $safe);
    }

    /**
     * Sanitize a filename segment.
     *
     * Applies {@see self::sanitizeSegment()} first, then reduces the result to
     * its basename only to prevent any remaining traversal via path separators.
     */
    private static function sanitizeFilename(string $filename): string
    {
        $sanitized = self::sanitizeSegment($filename);

        return basename($sanitized);
    }
}
