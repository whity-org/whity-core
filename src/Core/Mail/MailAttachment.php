<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * A file sent with an email (WHIT-947), such as a booking's `.ics`.
 *
 * The name and type go into MIME headers, so both are checked here: no line
 * breaks, no quotes, a plain `type/subtype` with optional simple parameters.
 * The content is sent base64-encoded. Kept small on purpose: an email is not
 * a file store, so one attachment is capped at 1 MiB.
 */
final class MailAttachment
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $content,
    ) {
        if ($filename === '' || strlen($filename) > 100 || preg_match('/^[A-Za-z0-9._ -]+$/', $filename) !== 1) {
            throw new MailException('An attachment name must be plain letters, digits, dots, dashes or spaces.');
        }
        if (preg_match('#^[a-z]+/[a-z0-9.+-]+(; ?[a-z-]+=[A-Za-z0-9-]+)*$#', $contentType) !== 1) {
            throw new MailException('An attachment type must look like type/subtype.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new MailException('An attachment is larger than 1 MiB.');
        }
    }
}
