<?php

declare(strict_types=1);

namespace Whity\Core\Security;

/**
 * The one way core makes a token that goes in a link or a URL a person holds
 * (merge map plumbing): a calendar feed, a booking's manage link, an emailed
 * verification, reset or invitation, a form key, a QR code, a web chat visitor.
 *
 * - **Make**: 32 random bytes from the system's CSPRNG (`random_bytes()`
 *   throws rather than return weak output), as 64 hex characters, or as
 *   unpadded base64url where a URL wants it shorter.
 * - **Hash**: SHA-256 of the token as written, in hex. Only the hash is stored,
 *   so a database leak gives nobody a working link. The tables keep their own
 *   rows; their stored hashes are the same as before this helper.
 * - **Compare**: in constant time, for the few places that compare rather than
 *   look a token up by its hash.
 * - **Expired**: whether an expiry has passed, the same reading everywhere.
 */
final class PublicToken
{
    /** 256 bits, the strength every link token already had. */
    public const BYTES = 32;

    private function __construct()
    {
    }

    /** A new token, as hex (64 characters for the default 32 bytes). */
    public static function make(int $bytes = self::BYTES): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /** A new token as unpadded base64url (43 characters for 32 bytes). */
    public static function makeUrlSafe(int $bytes = self::BYTES): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(16, $bytes))), '+/', '-_'), '=');
    }

    /** What is stored for a token: its SHA-256 in hex. */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether a token is the one a stored hash was made from, in constant time. */
    public static function matches(#[\SensitiveParameter] string $token, string $storedHash): bool
    {
        return $token !== '' && $storedHash !== '' && hash_equals($storedHash, self::hash($token));
    }

    /** Whether an expiry has passed. No expiry never expires. */
    public static function expired(?\DateTimeInterface $expiresAt, ?\DateTimeInterface $now = null): bool
    {
        return $expiresAt !== null && $expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
