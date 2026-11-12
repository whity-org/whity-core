<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Closure;
use Whity\Sdk\Messaging\CustomerMessages;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginNamespace;

/**
 * {@see CustomerMessages} over core's Messages (WHIT-967): the first channel
 * in the plugin's order that is on for the workspace and can reach the person
 * takes the message, in the customer's own conversation, with the source
 * `plugin.<slug>`.
 */
final class HostCustomerMessages implements CustomerMessages
{
    /** @param Closure(): MessagingService $messaging */
    public function __construct(private readonly Closure $messaging)
    {
    }

    public function send(
        PluginInterface $from,
        int $tenantId,
        int $profileId,
        string $body,
        array $channels,
        ?string $subject = null,
    ): ?string {
        $messaging = ($this->messaging)();
        $source = 'plugin.' . substr((string) PluginNamespace::slug($from->getName()), 0, 56);

        foreach ($this->candidates($messaging->channels()->keys(), $channels) as $key) {
            try {
                $messaging->send(
                    $tenantId,
                    Recipient::person($profileId),
                    $body,
                    new SendOptions(channel: $key, subject: $subject, source: $source),
                );

                return $key;
            } catch (MessagingException) {
                // Off, unknown, or no address for this person here: try the next.
                continue;
            }
        }

        return null;
    }

    /**
     * Registered keys matching the wanted names, in the wanted order.
     *
     * @param list<string> $keys
     * @param array<mixed> $wanted
     * @return list<string>
     */
    public function candidates(array $keys, array $wanted): array
    {
        $out = [];
        foreach ($wanted as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            foreach ($keys as $key) {
                $matches = $key === $name || (!str_contains($name, ':') && str_ends_with($key, ':' . $name));
                if ($matches && !in_array($key, $out, true)) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }
}
