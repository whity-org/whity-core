<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Why {@see TimeOff} wrote nothing (SDK 1.120). `reason` is one of the
 * constants below and stable; the message is for logs.
 */
final class TimeOffRejected extends \RuntimeException implements \Whity\Sdk\SdkException
{
    /** The acting person may not change this person's calendar (`calendar:manage`). */
    public const FORBIDDEN = 'forbidden';
    /** The person is not a member of staff of this workspace. */
    public const NOT_STAFF = 'not_staff';
    /** Times missing an offset, out of order, or longer than a year. */
    public const INVALID_TIMES = 'invalid_times';
    /** An empty reference, or one longer than 255 characters. */
    public const INVALID_REF = 'invalid_ref';
    /** The reference already names time off for someone else in this workspace. */
    public const REF_CONFLICT = 'ref_conflict';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
