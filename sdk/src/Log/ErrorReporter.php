<?php

declare(strict_types=1);

namespace Whity\Sdk\Log;

/**
 * Report an error the plugin caught to the deployment's error tracker (SDK 1.152,
 * #1686), from `$ctx->errors()`.
 *
 * Use it for a failure you handle but someone should look at, say a payment
 * provider that answered with nonsense. An exception your route or job lets
 * escape is captured by the host already; don't report it twice.
 *
 * The message, the trace and the context pass through core's scrubber before
 * anything is stored or sent, and the host tags the report with the plugin and
 * the workspace. When the deployment has no tracker the report is written to the
 * log instead. Reporting never throws.
 */
interface ErrorReporter
{
    /** @param array<string, mixed> $context Secret-free tags; scrubbed anyway. */
    public function report(\Throwable $error, array $context = []): void;
}
