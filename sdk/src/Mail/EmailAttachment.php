<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * A file sent with a plugin's email (WHIT-959), such as an invoice PDF. The host
 * checks it before sending: a plain file name (letters, digits, dots, dashes,
 * spaces), a `type/subtype` content type, and at most 1 MiB.
 */
final class EmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $content,
    ) {
    }
}
