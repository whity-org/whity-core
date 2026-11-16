<?php

declare(strict_types=1);

namespace Whity\Sdk;

use Whity\Sdk\Messaging\MessageChannel;

/**
 * Add channels to Messages, such as WhatsApp or SMS (SDK 1.97, ADR 0016).
 *
 * The host stores each channel as `<plugin>:<key>`, opens its inbound webhook
 * at `POST /api/public/messages/inbound/<plugin>:<key>/{workspaceSlug}`, sends
 * through it from a background job, and meters what it sends like core's own
 * channels. A channel that throws is isolated: its messages fail and say why,
 * and every other channel keeps working.
 */
interface PluginMessageChannelsInterface
{
    /** @return list<MessageChannel> */
    public function getMessageChannels(): array;
}
