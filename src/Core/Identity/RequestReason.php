<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * The optional "why" on a password reset or a two-step reset (migration 209).
 *
 * The text is shown to an approver, so it is reduced to one bounded line. A bad value
 * is dropped rather than refused: a reason is a courtesy, and it must never stop
 * somebody who has just proved control of their mailbox from getting their request in.
 */
final class RequestReason
{
    public const MAX_LENGTH = 500;

    private function __construct()
    {
    }

    public static function from(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        if ($clean === null) {
            return null;
        }
        $clean = trim($clean);

        return $clean === '' ? null : mb_substr($clean, 0, self::MAX_LENGTH);
    }
}
