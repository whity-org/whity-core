<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Say which host services your plugin uses, and which of your tables it
 * reaches through `$ctx->database()` (SDK 1.151, #1693).
 *
 * ```php
 * use Whity\Sdk\Context\Service;
 *
 * public function getServices(): array
 * {
 *     return [Service::SETTINGS, Service::JOBS, Service::DATABASE];
 * }
 *
 * public function getTables(): array
 * {
 *     return ['keyhub_orders', 'keyhub_order_lines'];
 * }
 * ```
 *
 * Once a plugin implements this, its {@see \Whity\Sdk\Context\PluginScope}
 * gives it only what it declared: any other service throws
 * {@see \Whity\Sdk\Context\ServiceNotDeclared}. The operator sees the list
 * under the plugin (`GET /api/plugins`), so keep it to what the plugin uses.
 * A plugin that doesn't implement this keeps every service, as before, until
 * 2.0, which makes the declaration required; `database()` needs it now.
 *
 * Tables are your own workspace tables, created by your migrations, each with
 * a `tenant_id` column. A core table, a table another plugin declared first, or
 * a name that is not `[a-z_][a-z0-9_]*` is dropped with a logged warning.
 *
 * This is least privilege inside the host, not a sandbox: plugin PHP runs in the
 * host's process. What stops a plugin from going around its context is review
 * in the store and the declaration the operator sees.
 */
interface PluginCapabilitiesInterface
{
    /**
     * @return list<string> Names from {@see \Whity\Sdk\Context\Service}; others are ignored.
     */
    public function getServices(): array;

    /**
     * @return list<string> This plugin's own workspace tables for `$ctx->database()`.
     */
    public function getTables(): array;
}
