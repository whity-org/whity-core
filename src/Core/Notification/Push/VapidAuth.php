<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Push;

use Firebase\JWT\JWT;

/**
 * The `Authorization: vapid …` header (RFC 8292) that tells a push service
 * which application server is sending, signed with the instance's key
 * (WHIT-1074).
 *
 * The token is an ES256 JWT for the endpoint's origin, valid 12 hours (the
 * services refuse more than 24). `sub` is how a push service reaches the
 * operator about abuse: the instance's https address, else a mailto.
 */
final class VapidAuth
{
    public const LIFETIME = 43200;

    /**
     * @param array{public: string, privatePem: string} $pair
     */
    public static function header(string $endpoint, array $pair, string $subject, int $now): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Not a push endpoint.');
        }
        $audience = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);

        $jwt = JWT::encode(
            ['aud' => $audience, 'exp' => $now + self::LIFETIME, 'sub' => $subject],
            $pair['privatePem'],
            'ES256',
        );

        return 'vapid t=' . $jwt . ', k=' . $pair['public'];
    }

    /**
     * The `sub` claim: the instance's public https address, else a mailto to
     * its sender address, else a placeholder the services accept.
     *
     * @param array<string, mixed> $env
     */
    public static function subjectFrom(array $env): string
    {
        foreach (['APP_URL', 'FRONTEND_URL', 'WHITY_PUBLIC_URL'] as $name) {
            $url = trim((string) ($env[$name] ?? ''));
            if (str_starts_with(strtolower($url), 'https://')) {
                return rtrim($url, '/');
            }
        }
        foreach (['MAIL_FROM_ADDRESS', 'MAIL_FROM'] as $name) {
            $mail = trim((string) ($env[$name] ?? ''));
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) {
                return 'mailto:' . $mail;
            }
        }

        return 'mailto:admin@localhost.localdomain';
    }
}
