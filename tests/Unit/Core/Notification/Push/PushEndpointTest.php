<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification\Push;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\PushEndpoint;
use Whity\Core\Notification\Push\VapidKeys;

/** What a browser may hand the server as its push subscription (WHIT-1073). */
final class PushEndpointTest extends TestCase
{
    private static function p256dh(): string
    {
        return VapidKeys::generate()['public'];
    }

    private static function auth(): string
    {
        return Base64Url::encode(random_bytes(16));
    }

    #[DataProvider('browserEndpoints')]
    public function testEveryBrowsersPushServiceIsAccepted(string $endpoint): void
    {
        self::assertNull(PushEndpoint::problem($endpoint, self::p256dh(), self::auth()));
    }

    /** @return array<string, array{string}> */
    public static function browserEndpoints(): array
    {
        return [
            'chrome'  => ['https://fcm.googleapis.com/fcm/send/dXk3:APA91bH'],
            'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAAAB'],
            'safari'  => ['https://web.push.apple.com/QGuQyavXutnMRP'],
            'edge'    => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAAD'],
        ];
    }

    #[DataProvider('refusedEndpoints')]
    public function testAnEndpointTheServerShouldNotPostToIsRefused(string $endpoint): void
    {
        self::assertNotNull(PushEndpoint::problem($endpoint, self::p256dh(), self::auth()));
    }

    /** @return array<string, array{string}> */
    public static function refusedEndpoints(): array
    {
        return [
            'plain http'      => ['http://fcm.googleapis.com/fcm/send/x'],
            'other host'      => ['https://example.com/fcm/send/x'],
            'lookalike'       => ['https://fcm.googleapis.com.example.com/x'],
            'suffix trick'    => ['https://evilfcm.googleapis.com.evil/x'],
            'ip literal'      => ['https://169.254.169.254/latest/meta-data'],
            'credentials'     => ['https://user:pass@fcm.googleapis.com/x'],
            'odd port'        => ['https://fcm.googleapis.com:8443/x'],
            'empty'           => [''],
            'too long'        => ['https://fcm.googleapis.com/' . str_repeat('a', 2100)],
            'not a url'       => ['fcm.googleapis.com/x'],
        ];
    }

    public function testAnOperatorCanAddTheirOwnPushService(): void
    {
        $endpoint = 'https://push.example.org/wpush/v2/abc';
        self::assertNotNull(PushEndpoint::problem($endpoint, self::p256dh(), self::auth()));
        $extra = PushEndpoint::extraHostsFrom(['PUSH_SERVICE_HOSTS' => ' push.example.org , ']);
        self::assertSame(['push.example.org'], $extra);
        self::assertNull(PushEndpoint::problem($endpoint, self::p256dh(), self::auth(), $extra));
    }

    public function testKeysMustHaveTheRightShape(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/x';
        self::assertNotNull(PushEndpoint::problem($endpoint, Base64Url::encode(random_bytes(65)), self::auth()), 'not a point');
        self::assertNotNull(PushEndpoint::problem($endpoint, Base64Url::encode("\x04" . random_bytes(32)), self::auth()), 'short');
        self::assertNotNull(PushEndpoint::problem($endpoint, self::p256dh(), Base64Url::encode(random_bytes(8))), 'short auth');
        self::assertNotNull(PushEndpoint::problem($endpoint, 'not base64!', self::auth()));
    }

    public function testAGeneratedPublicKeyIsAnUncompressedPoint(): void
    {
        $pair = VapidKeys::generate();
        $raw = Base64Url::decode($pair['public']);
        self::assertNotNull($raw);
        self::assertSame(65, strlen($raw));
        self::assertSame("\x04", $raw[0]);
        self::assertStringContainsString('PRIVATE KEY', $pair['privatePem']);
    }
}
