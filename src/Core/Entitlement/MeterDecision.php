<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Whether an action may spend from a metered limit, and what to say if not.
 *
 * CARRIES THE REASON, NOT JUST THE VERDICT. "You have reached your limit" is
 * not a usable message: a customer who is told no needs to know WHICH limit —
 * the daily one they will get back tonight, or the monthly one that means they
 * should upgrade — and WHEN it returns. Those two answers lead to completely
 * different actions, and a boolean can express neither.
 *
 * A WARNING IS ALLOWED AND STILL NAMES THE LIMIT. With `meters.enforcement` on
 * `warn` an action past its limit goes ahead, and {@see self::$warned} is what
 * separates it from an ordinary allowed action, the same split
 * {@see \Whity\Core\Seat\SeatDecision} makes for seats.
 */
final class MeterDecision
{
    private function __construct(
        public readonly bool $allowed,
        /** The limit that refused or was passed, or null when neither. */
        public readonly ?string $key = null,
        public readonly int $limit = 0,
        public readonly int $remaining = 0,
        /** ISO-8601 instant when the refusing window reopens. */
        public readonly ?string $resetsAt = null,
        /** Past the limit, on an instance that warns instead of refusing. */
        public readonly bool $warned = false,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function refused(string $key, int $limit, int $remaining, string $resetsAt): self
    {
        return new self(false, $key, $limit, max(0, $remaining), $resetsAt);
    }

    public static function warned(string $key, int $limit, string $resetsAt): self
    {
        return new self(true, $key, $limit, 0, $resetsAt, true);
    }

    /**
     * The period the named limit runs on, for wording the message.
     *
     * @throws \InvalidArgumentException When no limit was refused or passed.
     */
    public function period(): ?string
    {
        if ($this->key === null) {
            throw new \InvalidArgumentException('An allowed decision has no refusing limit.');
        }

        return EntitlementRegistry::periodFor($this->key);
    }
}
