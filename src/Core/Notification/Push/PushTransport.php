<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Mail\EmailGate;
use Whity\Core\Queue\QueueService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * The `push` notification channel: web push to every device the person turned
 * on in this workspace (WHIT-1074).
 *
 * WHO GETS ONE is decided before this runs, by the dispatcher with the same
 * gate as email ({@see EmailGate::allows()} on the `push` channel): the
 * workspace's push switch, then the person's choice for the kind. This only
 * delivers.
 *
 * A BURST CAN'T FLOOD A PHONE. Each person gets at most {@see self::PER_MINUTE}
 * pushes a minute and {@see self::PER_HOUR} an hour per workspace. Past that a
 * push is held (its delivery is recorded as sent, provider id `push:held`) and
 * one {@see PushDigestJob} sends a single "N more things are waiting" once the
 * minute (or hour) is over. Security notifications are never held or grouped
 * (TmPush: sign-in approvals come first).
 *
 * GONE DEVICES GO. A push service answering 404 or 410 means the browser
 * unsubscribed or the subscription expired: the row is deleted at once. Other
 * failures count against the device; after {@see self::MAX_FAILURES} in a row
 * it is deleted too.
 *
 * RETRIES. The delivery succeeds when any device took the push, or when there
 * is no device left to try. It fails (and the queue retries it) only when
 * every device failed for a reason that may pass (5xx, 429, no connection).
 * A retry reaching a device that already showed it replaces the notification
 * (same tag) rather than adding a second.
 */
final class PushTransport implements NotificationTransport
{
    public const CHANNEL = 'push';

    public const PER_MINUTE = 4;
    public const PER_HOUR = 30;
    public const MAX_FAILURES = 10;

    /** Seconds a push service keeps a message for an offline device. */
    public const TTL = 86400;

    /** Every message is padded to this size so its length says nothing. */
    public const PAD_TO = 1024;

    public const PROVIDER_SENT = 'push:sent';
    public const PROVIDER_HELD = 'push:held';
    public const PROVIDER_NO_DEVICE = 'push:no-device';

    /**
     * @param \Closure(): VapidKeys $keys
     * @param PushBranding|null $branding The workspace's name, icon and badge; without it, the bare workspace name.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly \Closure $keys,
        private readonly PushHttpClient $http,
        private readonly SharedStoreInterface $counters,
        private readonly ?QueueService $queue,
        private readonly string $subject,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?PushBranding $branding = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * The transport as the web process and the worker build it: the key ring
     * is read only when a push is actually sent.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnv(PDO $pdo, ?LoggerInterface $logger, array $env): self
    {
        return new self(
            $pdo,
            new PushSubscriptionRepository($pdo),
            static fn (): VapidKeys => new VapidKeys(
                new \Whity\Core\Settings\GlobalSettingsRepository($pdo),
                \Whity\Core\Security\EncryptedSecretStore::fromEnv($env),
            ),
            new StreamPushHttpClient(),
            new \Whity\Core\Store\DatabaseSharedStore($pdo),
            new QueueService(new \Whity\Core\Queue\JobRepository($pdo)),
            VapidAuth::subjectFrom($env),
            $logger,
            PushBranding::fromPdo($pdo),
        );
    }

    public function channel(): string
    {
        return self::CHANNEL;
    }

    /** Whether the person has at least one device on in this workspace. */
    public function hasDevice(int $tenantId, int $profileId): bool
    {
        return $this->subscriptions->listForProfile($tenantId, $profileId) !== [];
    }

    public function send(NotificationMessage $message): SendResult
    {
        $tenantId = $message->tenantId;
        $profileId = ctype_digit(trim($message->recipient)) ? (int) trim($message->recipient) : 0;
        if ($profileId <= 0) {
            return SendResult::failed('push needs a person to send to');
        }
        $devices = $this->subscriptions->listForProfile($tenantId, $profileId);
        if ($devices === []) {
            return SendResult::sent(self::PROVIDER_NO_DEVICE);
        }

        $security = EmailGate::isSecurityType($message->type);
        if (!$security && !$this->withinLimits($tenantId, $profileId)) {
            return SendResult::sent(self::PROVIDER_HELD);
        }

        $language = $this->language($message->locale, $profileId, $tenantId);
        $notificationId = (int) ($message->data['_notification_id'] ?? 0);
        $brand = $this->brand($tenantId);
        $payload = PushPayload::build($message, $language, $brand['name'], $notificationId, $brand);
        if ($security) {
            $payload['tag'] = 'security' . $notificationId;
        }

        return $this->deliver($tenantId, $devices, $payload, $security ? 'high' : 'normal');
    }

    /**
     * Send the one push standing for every push held since the last one.
     * Called by {@see PushDigestJob}.
     */
    public function sendDigest(int $tenantId, int $profileId): SendResult
    {
        $held = $this->counters->count(self::heldKey($tenantId, $profileId));
        if ($held <= 0) {
            return SendResult::sent(self::PROVIDER_NO_DEVICE);
        }
        $this->counters->delete(self::heldKey($tenantId, $profileId));
        $devices = $this->subscriptions->listForProfile($tenantId, $profileId);
        if ($devices === []) {
            return SendResult::sent(self::PROVIDER_NO_DEVICE);
        }
        $brand = $this->brand($tenantId);
        $payload = PushPayload::digest($held, $this->language(null, $profileId, $tenantId), $brand['name'], $tenantId, $brand);

        return $this->deliver($tenantId, $devices, $payload, 'normal');
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * @param list<array{id: int, endpoint: string, p256dh: string, auth: string, failures: int}> $devices
     * @param array<string, string> $payload
     */
    private function deliver(int $tenantId, array $devices, array $payload, string $urgency): SendResult
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pair = ($this->keys)()->pair();
        $now = $this->now();

        $delivered = 0;
        $retryable = 0;
        foreach ($devices as $device) {
            $uaPublic = Base64Url::decode($device['p256dh']);
            $auth = Base64Url::decode($device['auth']);
            if ($uaPublic === null || $auth === null) {
                $this->subscriptions->delete($tenantId, $device['id']);
                continue;
            }
            try {
                $body = WebPushCrypto::encrypt($json, $uaPublic, $auth, self::PAD_TO);
                $status = $this->http->post($device['endpoint'], [
                    'Authorization'    => VapidAuth::header($device['endpoint'], $pair, $this->subject, $now),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type'     => 'application/octet-stream',
                    'TTL'              => (string) self::TTL,
                    'Urgency'          => $urgency,
                ], $body);
            } catch (\Throwable $e) {
                $this->logger?->warning('[push] could not prepare a push', ['tenant_id' => $tenantId, 'subscription_id' => $device['id'], 'error' => $e->getMessage()]);
                $status = 0;
            }

            if ($status >= 200 && $status < 300) {
                $delivered++;
                $this->subscriptions->markSuccess($tenantId, $device['id']);
            } elseif ($status === 404 || $status === 410) {
                // The browser unsubscribed or the subscription expired.
                $this->subscriptions->delete($tenantId, $device['id']);
            } else {
                // 0 (unreachable), 429, 5xx may pass; 400/401/403/413 won't
                // change on a retry but a device that keeps failing still goes.
                if ($status === 0 || $status === 429 || $status >= 500) {
                    $retryable++;
                }
                $this->subscriptions->markFailure($tenantId, $device['id']);
                if ($device['failures'] + 1 >= self::MAX_FAILURES) {
                    $this->subscriptions->delete($tenantId, $device['id']);
                }
                $this->logger?->info('[push] a push service refused a push', ['tenant_id' => $tenantId, 'subscription_id' => $device['id'], 'status' => $status]);
            }
        }

        if ($delivered > 0 || $retryable === 0) {
            return SendResult::sent($delivered > 0 ? self::PROVIDER_SENT : self::PROVIDER_NO_DEVICE);
        }

        return SendResult::failed('no device took the push');
    }

    /**
     * Count this push against the person's minute and hour; past either, hold
     * it and make sure one digest follows.
     */
    private function withinLimits(int $tenantId, int $profileId): bool
    {
        $now = $this->now();
        $minuteKey = 'push-rate:' . $tenantId . ':' . $profileId . ':m' . gmdate('YmdHi', $now);
        $hourKey = 'push-rate:' . $tenantId . ':' . $profileId . ':h' . gmdate('YmdH', $now);

        $minute = $this->counters->increment($minuteKey, 120);
        $hour = $this->counters->increment($hourKey, 3700);
        if ($minute <= self::PER_MINUTE && $hour <= self::PER_HOUR) {
            return true;
        }

        $this->counters->increment(self::heldKey($tenantId, $profileId), 7200);
        // Send the digest when the window that is full ends.
        $delay = $hour > self::PER_HOUR ? 3600 - ($now % 3600) + 5 : 60 - ($now % 60) + 5;
        $window = $hour > self::PER_HOUR ? 'h' . gmdate('YmdH', $now) : 'm' . gmdate('YmdHi', $now);
        $this->queue?->dispatch(PushDigestJob::NAME, ['tenant_id' => $tenantId, 'profile_id' => $profileId], [
            'tenant_id'       => $tenantId,
            'delay'           => $delay,
            'idempotency_key' => 'push-digest:' . $tenantId . ':' . $profileId . ':' . $window,
        ]);

        return false;
    }

    private static function heldKey(int $tenantId, int $profileId): string
    {
        return 'push-held:' . $tenantId . ':' . $profileId;
    }

    /** The message's language, else the person's, else the workspace's. */
    private function language(?string $locale, int $profileId, int $tenantId): string
    {
        if ($locale !== null && trim($locale) !== '') {
            return str_starts_with(strtolower(trim($locale)), 'ar') ? 'ar' : 'en';
        }
        try {
            // Profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
            $stmt = $this->db->prepare('SELECT language_code FROM profiles WHERE id = :id');
            $stmt->execute([':id' => $profileId]);
            $code = $stmt->fetchColumn();
            if (is_string($code) && $code !== '') {
                return str_starts_with(strtolower($code), 'ar') ? 'ar' : 'en';
            }
            $stmt = $this->db->prepare('SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key');
            $stmt->execute([':tenant_id' => $tenantId, ':key' => \Whity\Core\Settings\SettingsRegistry::LOCALE]);
            $tenantLocale = $stmt->fetchColumn();

            return is_string($tenantLocale) && str_starts_with(strtolower($tenantLocale), 'ar') ? 'ar' : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }

    private function workspaceName(int $tenantId): string
    {
        if ($tenantId <= 0) {
            return '';
        }
        try {
            // Tenants is the tenant registry: its primary key IS the tenant id.
            $stmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
            $stmt->execute([':id' => $tenantId]);
            $name = $stmt->fetchColumn();

            return is_string($name) ? $name : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{name: string, icon?: string, badge?: string} */
    private function brand(int $tenantId): array
    {
        if ($this->branding !== null) {
            try {
                return $this->branding->forWorkspace($tenantId);
            } catch (\Throwable) {
                // Fall back to the bare name below.
            }
        }

        return ['name' => $this->workspaceName($tenantId)];
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
