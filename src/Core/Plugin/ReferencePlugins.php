<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

/**
 * The reference plugins core ships beside its code: HelloWorld and
 * ExamplePlugin (#1831).
 *
 * They demonstrate the SDK, and the e2e suite and plugin tests use HelloWorld,
 * so they stay in the repository and in the image (`.dockerignore` lets them
 * in). Whether they RUN is `WHITY_REFERENCE_PLUGINS`:
 *
 *  - `on` (the default when unset): discovered and loaded like any plugin.
 *    Development, CI and the e2e stack run this way.
 *  - `off`: discovery skips them, so they register no routes, permissions,
 *    roles, jobs or migrations and are not listed. The release image sets this
 *    (Dockerfile `release` stage); an operator who wants the examples sets `on`.
 *
 * `off` only stops them running. Rows they wrote while they ran (their table,
 * permissions, `hello_viewer` role, migration records) stay where they are.
 */
final class ReferencePlugins
{
    public const ENV = 'WHITY_REFERENCE_PLUGINS';

    /** Top-level entries under `plugins/` that are reference plugins. */
    private const ENTRIES = [
        'HelloWorld',
        'ExamplePlugin.php',
        'ExamplePlugin.php.disabled',
    ];

    /** Whether the reference plugins run here. Anything but an explicit off means yes. */
    public static function enabled(): bool
    {
        $raw = $_ENV[self::ENV] ?? getenv(self::ENV);
        if (!is_string($raw)) {
            return true;
        }

        return !in_array(strtolower(trim($raw)), ['off', '0', 'false', 'no'], true);
    }

    /**
     * Whether discovery should skip this top-level entry of `plugins/`.
     *
     * @param string $entry A directory or file name directly under `plugins/`.
     */
    public static function skips(string $entry): bool
    {
        return in_array($entry, self::ENTRIES, true) && !self::enabled();
    }
}
