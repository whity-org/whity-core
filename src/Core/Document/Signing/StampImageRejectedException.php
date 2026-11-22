<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

/**
 * A company stamp's image that cannot be used (#1729), with a sentence written
 * for the person uploading it. Only that sentence reaches a response.
 */
final class StampImageRejectedException extends \InvalidArgumentException
{
    public function __construct(public readonly string $clientMessage)
    {
        parent::__construct($clientMessage);
    }
}
