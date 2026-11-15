<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Push;

use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Notification\Push\WebPushCrypto;

/** A browser: its keys, and what it decrypted. */
final class FakeBrowser
{
    public \OpenSSLAsymmetricKey $key;
    public string $auth;
    public int $status = 201;
    /** @var list<array<string, string>> */
    public array $received = [];

    public function __construct(public readonly string $endpoint)
    {
        $this->key = WebPushCrypto::newKey();
        $this->auth = random_bytes(16);
    }

    public function p256dh(): string
    {
        $d = openssl_pkey_get_details($this->key);
        \assert(is_array($d));

        return Base64Url::encode(VapidKeys::uncompressedPoint($d['ec']['x'], $d['ec']['y']));
    }
}
