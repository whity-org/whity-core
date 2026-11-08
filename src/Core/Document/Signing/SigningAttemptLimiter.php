<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use Whity\Core\Store\SharedStoreInterface;

/**
 * How many signing checks a person and an address may try in a window
 * (WHIT-922).
 *
 * A try takes a slot BEFORE the check is made: the shared store's increment is
 * atomic and returns the new count, so parallel tries each see their own
 * number and the ones past the limit are refused without being checked. A
 * check that passes gives its slot back, so a person signing many documents is
 * not held up by their own successes; a check that fails keeps it until the
 * window ends.
 */
final class SigningAttemptLimiter
{
    public const DEFAULT_USER_LIMIT = 10;
    public const DEFAULT_IP_LIMIT = 20;
    public const DEFAULT_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly SharedStoreInterface $store,
        private readonly int $userLimit = self::DEFAULT_USER_LIMIT,
        private readonly int $ipLimit = self::DEFAULT_IP_LIMIT,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
    }

    /** Take a slot. False when the person or the address is over the limit (nothing is held then). */
    public function reserve(int $profileId, ?string $ip): bool
    {
        $userKey = self::userKey($profileId);
        if ($this->store->increment($userKey, $this->windowSeconds) > $this->userLimit) {
            $this->store->decrement($userKey);

            return false;
        }
        if ($ip !== null) {
            $ipKey = self::ipKey($ip);
            if ($this->store->increment($ipKey, $this->windowSeconds) > $this->ipLimit) {
                $this->store->decrement($ipKey);
                $this->store->decrement($userKey);

                return false;
            }
        }

        return true;
    }

    /** Give the slot back after a check that passed. */
    public function release(int $profileId, ?string $ip): void
    {
        $this->store->decrement(self::userKey($profileId));
        if ($ip !== null) {
            $this->store->decrement(self::ipKey($ip));
        }
    }

    private static function userKey(int $profileId): string
    {
        return 'signing_proof:user:' . $profileId;
    }

    private static function ipKey(string $ip): string
    {
        return 'signing_proof:ip:' . $ip;
    }
}
