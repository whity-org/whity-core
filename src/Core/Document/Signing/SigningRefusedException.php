<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

/**
 * A signature that cannot be made, with a reason code a screen can act on and
 * a sentence for the signer (WHIT-922).
 */
final class SigningRefusedException extends \RuntimeException
{
    public const CONSENT_REQUIRED = 'consent_required';
    public const APPEARANCE_REQUIRED = 'appearance_required';
    public const CONTENT_CHANGED = 'content_changed';
    public const SECOND_FACTOR_REQUIRED = 'second_factor_required';
    public const SECOND_FACTOR_INVALID = 'second_factor_invalid';
    public const THROTTLED = 'throttled';
    public const NOT_SIGNER = 'not_signer';

    public function __construct(
        public readonly string $reason,
        public readonly string $clientMessage,
        public readonly int $status = 422,
    ) {
        parent::__construct($clientMessage);
    }
}
