<?php

declare(strict_types=1);

namespace Whity\Sdk\Log;

/**
 * A plugin's log (SDK 1.152, #1686), from `$ctx->log()`.
 *
 * Every record goes through the same scrubber as core's error tracker before it
 * is written: values that look like secrets, tokens, emails or card numbers
 * are replaced, and any context key whose name suggests a secret (`password`,
 * `token`, `secret`, `authorization`, …) loses its value. The host adds the
 * plugin's name and the workspace, which the plugin cannot set or change.
 * Context values are scalars, nulls and arrays of them; objects are recorded as
 * `[object]`.
 *
 * Logging never throws.
 */
interface PluginLog
{
    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;
}
