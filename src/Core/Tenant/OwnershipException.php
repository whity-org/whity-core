<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Why an ownership offer, acceptance or removal was refused (WHIT-1170). The
 * code is what the API sends back, so the app can say it in plain words.
 */
final class OwnershipException extends \RuntimeException
{
    public const INVALID = 'invalid';
    public const SELF = 'self';
    public const NOT_OWNER = 'not_owner';
    public const NOT_MEMBER = 'not_member';
    public const ALREADY_OWNER = 'already_owner';
    public const PENDING = 'offer_pending';
    public const NOT_FOUND = 'not_found';
    public const NOT_OPEN = 'not_open';
    public const EXPIRED = 'expired';
    public const LAST_OWNER = 'last_owner';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** The HTTP status for the reason. */
    public function status(): int
    {
        return match ($this->reason) {
            self::NOT_OWNER => 403,
            self::NOT_FOUND => 404,
            self::PENDING, self::NOT_OPEN, self::EXPIRED, self::ALREADY_OWNER, self::LAST_OWNER => 409,
            default => 422,
        };
    }
}
