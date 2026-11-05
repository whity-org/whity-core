<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

/** Somebody else's revision of this document got there first (WHIT-888). */
final class DocumentRevisionConflictException extends \RuntimeException
{
    public function __construct(public readonly int $documentId)
    {
        parent::__construct('This document has already been replaced.');
    }
}
