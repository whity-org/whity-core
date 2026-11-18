<?php

declare(strict_types=1);

namespace Whity\Sdk\Contacts;

/**
 * A contact call the caller can fix (SDK 1.119): the words, a status like the
 * matching route's (403 not allowed, 404 not found, 409 conflict, 422 not
 * valid) and, when one field is at fault, its name (`email`,
 * `fields.keyhub.supplier_code`).
 */
final class ContactRejected extends \RuntimeException implements \Whity\Sdk\SdkException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message, $status);
    }
}
