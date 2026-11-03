<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Trust;

/**
 * The public keys whose signatures can make a plugin version Verified.
 *
 * Pinned here in code on purpose: no setting, environment variable, store or
 * manifest can add a trust root. The owner holds the private key on their own
 * machine and it never comes near this repository; a key reaches this list only
 * as a public key in a reviewed change.
 *
 * EMPTY UNTIL THE OWNER SUPPLIES ONE. With no key here every signature fails, so
 * nothing can become Verified. Never put a placeholder in it.
 */
final class TrustedKeys
{
    /** Base64 Ed25519 public keys: the current one and, when rotating, the next. */
    private const PINNED = [];

    /** @param list<string> $keys Base64 public keys. Tests pass their own; production uses {@see self::pinned()}. */
    public function __construct(private readonly array $keys)
    {
    }

    public static function pinned(): self
    {
        return new self(self::PINNED);
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /** The raw key, when this base64 key is one of the pinned ones. */
    public function match(string $base64Key): ?string
    {
        foreach ($this->keys as $key) {
            if (hash_equals($key, $base64Key)) {
                $raw = base64_decode($key, true);

                return $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? null : $raw;
            }
        }

        return null;
    }
}
