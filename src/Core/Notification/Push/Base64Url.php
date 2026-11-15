<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

/** base64url without padding (RFC 4648 §5), the encoding Web Push uses everywhere. */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The bytes, or null when the text isn't base64url. Padding is accepted. */
    public static function decode(string $text): ?string
    {
        $text = rtrim($text, '=');
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
