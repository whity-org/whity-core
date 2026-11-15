<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Push;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\PushHttpClient;
use Whity\Core\Notification\Push\WebPushCrypto;

/** A push service: checks the VAPID signature and hands the message to the browser. */
final class FakePushService implements PushHttpClient
{
    /** @var array<string, FakeBrowser> */
    public array $browsers = [];
    /** @var list<array<string, string>> */
    public array $headers = [];
    /** @var list<string> */
    public array $audiences = [];

    public function __construct(private readonly string $vapidPublic)
    {
    }

    public function post(string $url, array $headers, string $body): int
    {
        $this->headers[] = $headers;
        if (preg_match('/^vapid t=([^,]+), k=(.+)$/', $headers['Authorization'] ?? '', $m) !== 1 || $m[2] !== $this->vapidPublic) {
            return 401;
        }
        $claims = (array) JWT::decode($m[1], new Key(WebPushCrypto::publicKeyFromPoint((string) Base64Url::decode($this->vapidPublic)), 'ES256'));
        $this->audiences[] = (string) $claims['aud'];

        $browser = $this->browsers[$url] ?? null;
        if ($browser === null) {
            return 404;
        }
        if ($browser->status >= 300) {
            return $browser->status;
        }
        $payload = json_decode(WebPushCrypto::decrypt($body, $browser->key, $browser->auth), true);
        \assert(is_array($payload));
        $browser->received[] = $payload;

        return $browser->status;
    }
}
