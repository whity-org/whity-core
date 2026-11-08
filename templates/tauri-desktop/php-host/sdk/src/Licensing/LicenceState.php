<?php

declare(strict_types=1);

namespace Whity\Sdk\Licensing;

/**
 * The answer {@see StoreLicence::status()} gives (SDK 1.85).
 *
 * - `licensed`: paid up or in a trial. `until` is when the current period ends, or null when
 *   it does not (a one-off purchase, or a free plugin).
 * - `grace`: a renewal failed, the period has ended, or the store cannot be reached. Keep
 *   working until `until`, then the answer becomes `unlicensed`.
 * - `unlicensed`: no licence, or it has run out.
 *
 * `message` is a plain sentence for an operator. `offline` is true when the answer came from the
 * cache because the store could not be reached.
 */
final class LicenceState
{
    public const LICENSED = 'licensed';
    public const GRACE = 'grace';
    public const UNLICENSED = 'unlicensed';

    private function __construct(
        public readonly string $state,
        public readonly ?string $until,
        public readonly string $message,
        public readonly bool $offline = false,
        public readonly ?string $checkedAt = null,
    ) {
    }

    public static function licensed(string $message, ?string $until = null, bool $offline = false, ?string $checkedAt = null): self
    {
        return new self(self::LICENSED, $until, $message, $offline, $checkedAt);
    }

    public static function grace(string $message, string $until, bool $offline = false, ?string $checkedAt = null): self
    {
        return new self(self::GRACE, $until, $message, $offline, $checkedAt);
    }

    public static function unlicensed(string $message, bool $offline = false, ?string $checkedAt = null): self
    {
        return new self(self::UNLICENSED, null, $message, $offline, $checkedAt);
    }

    public function isUsable(): bool
    {
        return $this->state !== self::UNLICENSED;
    }

    /** @return array{state: string, until: ?string, message: string, offline: bool, checked_at: ?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'until' => $this->until,
            'message' => $this->message,
            'offline' => $this->offline,
            'checked_at' => $this->checkedAt,
        ];
    }
}
