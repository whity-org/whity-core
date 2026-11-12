<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

use Whity\Sdk\PluginInterface;

/**
 * Host service (SDK 1.99): a plugin tells one of the workspace's customers
 * something, through Messages. Resolve it with
 * `\Whity\app(CustomerMessages::class)`.
 *
 *     $channel = \Whity\app(CustomerMessages::class)->send(
 *         $this, $tenantId, $profileId, $body, ['whatsapp', 'email'], 'Payment failed'
 *     );
 *
 * Channels are tried in the order given, and the first one that takes the
 * message wins. A name is a channel key (`email`, `acme:whatsapp`) or a bare
 * name, which also matches any plugin channel ending in `:<name>`, so
 * `whatsapp` reaches whichever WhatsApp channel is installed. A channel that
 * is unknown, off for the workspace, or has no address for the person is
 * skipped. The message lands in the customer's conversation on that channel,
 * so staff see it in Messages, marked as sent by the plugin.
 *
 * The person must be a member (staff or customer) of the workspace. The host
 * queues delivery; a delivery failure later shows on the message in Messages.
 */
interface CustomerMessages
{
    /**
     * @param list<string> $channels in order of preference
     * @return string|null the key of the channel it was queued on, or null when none took it
     */
    public function send(
        PluginInterface $from,
        int $tenantId,
        int $profileId,
        string $body,
        array $channels,
        ?string $subject = null,
    ): ?string;
}
