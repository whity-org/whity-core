<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification\Push;

use PHPUnit\Framework\TestCase;
use Whity\Core\Notification\Push\Base64Url;
use Whity\Core\Notification\Push\VapidKeys;
use Whity\Core\Notification\Push\WebPushCrypto;

/** Web Push message encryption (RFC 8291) against the RFC's own example. */
final class WebPushCryptoTest extends TestCase
{
    /** RFC 8291 Appendix A. */
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';
    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const RESULT = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private static function b(string $text): string
    {
        $bytes = Base64Url::decode($text);
        self::assertNotNull($bytes);

        return $bytes;
    }

    private static function privateKey(string $d): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => self::b($d)]]);
        self::assertNotFalse($key);

        return $key;
    }

    public function testTheRfcExampleEncryptsToTheRfcBytes(): void
    {
        $body = WebPushCrypto::encrypt(
            self::PLAINTEXT,
            self::b(self::UA_PUBLIC),
            self::b(self::AUTH),
            0,
            self::b(self::SALT),
            self::privateKey(self::AS_PRIVATE),
        );

        self::assertSame(self::RESULT, Base64Url::encode($body));
    }

    public function testTheRfcExampleDecrypts(): void
    {
        self::assertSame(
            self::PLAINTEXT,
            WebPushCrypto::decrypt(self::b(self::RESULT), self::privateKey(self::UA_PRIVATE), self::b(self::AUTH)),
        );
    }

    public function testAPaddedMessageRoundTripsAndHidesItsLength(): void
    {
        $browser = WebPushCrypto::newKey();
        $details = openssl_pkey_get_details($browser);
        self::assertIsArray($details);
        $public = VapidKeys::uncompressedPoint($details['ec']['x'], $details['ec']['y']);
        $auth = random_bytes(16);

        $short = WebPushCrypto::encrypt('{"t":"Hi"}', $public, $auth, 1024);
        $long = WebPushCrypto::encrypt(str_repeat('مرحبا ', 80), $public, $auth, 1024);

        self::assertSame(strlen($short), strlen($long));
        self::assertSame('{"t":"Hi"}', WebPushCrypto::decrypt($short, $browser, $auth));
        self::assertSame(str_repeat('مرحبا ', 80), WebPushCrypto::decrypt($long, $browser, $auth));
    }

    public function testTheWrongBrowserCannotRead(): void
    {
        $browser = WebPushCrypto::newKey();
        $details = openssl_pkey_get_details($browser);
        self::assertIsArray($details);
        $body = WebPushCrypto::encrypt('secret', VapidKeys::uncompressedPoint($details['ec']['x'], $details['ec']['y']), random_bytes(16));

        $this->expectException(\RuntimeException::class);
        WebPushCrypto::decrypt($body, WebPushCrypto::newKey(), random_bytes(16));
    }

    public function testAMessageTooLongIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebPushCrypto::encrypt(str_repeat('a', WebPushCrypto::MAX_PLAINTEXT + 1), self::b(self::UA_PUBLIC), self::b(self::AUTH));
    }
}
