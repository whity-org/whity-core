<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Sdk\Documents\DocumentNumberingRule;

/**
 * What to issue (DOCUMENT-AUTOMATION.md 3.1): a template's version, the
 * values it prints with, and what the caller calls it.
 */
final class IssueRequest
{
    /**
     * @param array<string, mixed>   $source      The `document_sources` row.
     * @param array<string, mixed>   $version     The version to issue (published, or a letter's frozen draft).
     * @param array<string, ?string> $values      Field path => value, as printed.
     * @param string|null            $recordLabel The record's name, for the letterhead.
     * @param bool|null              $render      true: a PDF is required; false: record only; null: render if this instance can.
     * @param string|null            $record      The record it is for (`type:id`), for the events.
     * @param string|null            $originKey   The caller's name for what this document is.
     * @param string|null            $slot        The slot it is issued through.
     * @param DocumentNumberingRule|null $numbering The slot's rule; the template's own wins over it.
     */
    public function __construct(
        public readonly array $source,
        public readonly array $version,
        public readonly array $values,
        public readonly string $title,
        public readonly ?string $recordLabel = null,
        public readonly ?bool $render = null,
        public readonly ?string $record = null,
        public readonly ?string $originKey = null,
        public readonly ?string $slot = null,
        public readonly ?DocumentNumberingRule $numbering = null,
    ) {
    }
}
