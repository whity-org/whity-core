<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use Whity\Sdk\JobInterface;

/**
 * Sends the one "N more things are waiting" push for the pushes a person's
 * rate limit held back (WHIT-1074). Queued by {@see PushTransport} once per
 * person per full window, delayed until that window ends.
 */
final class PushDigestJob implements JobInterface
{
    public const NAME = 'core.notifications.push_digest';

    /** @param \Closure(): ?PushTransport $transport */
    public function __construct(private readonly \Closure $transport)
    {
    }

    public function handle(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $profileId = (int) ($payload['profile_id'] ?? 0);
        $transport = ($this->transport)();
        if ($transport === null || $profileId <= 0) {
            return ['status' => 'skipped'];
        }
        $result = $transport->sendDigest($tenantId, $profileId);
        if (!$result->success) {
            throw new \RuntimeException('push digest failed: ' . ($result->error ?? 'send failed'));
        }

        return ['status' => 'sent', 'provider_id' => $result->providerId];
    }
}
