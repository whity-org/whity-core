<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Inbox\InboxSource;

/**
 * Contribute sources to the person's inbox (SDK 1.97, #881).
 *
 * Return bare keys (`approvals`, `[a-z][a-z0-9_]*`); the host stores each under
 * `<plugin>:<key>` and attributes it to the plugin by its own name, never by
 * anything the plugin returns. A source that throws while being read is shown
 * as unavailable and the rest of the inbox still renders.
 */
interface PluginInboxSourcesInterface
{
    /** @return array<string, InboxSource> bare key => source */
    public function getInboxSources(): array;
}
