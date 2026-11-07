<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * A merge field a template prints (SDK 1.82).
 *
 * `source` says where its value comes from when a document is issued:
 *  - `template`: the template's own field (`template.<key>`). Pass it in
 *    {@see IssueRequest} `values`.
 *  - `record`: the record the document is issued for (`record.<field>`).
 *  - `host`: the host fills it (company, today, the document's number).
 */
final class TemplateField
{
    public const SOURCE_TEMPLATE = 'template';
    public const SOURCE_RECORD = 'record';
    public const SOURCE_HOST = 'host';

    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly string $source,
        /** Marked required in the template: an issue without a value is refused. */
        public readonly bool $required = false,
        /** The template's example value, for a template field that has one. */
        public readonly ?string $sample = null,
    ) {
    }

    public static function sourceOf(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'template.') => self::SOURCE_TEMPLATE,
            str_starts_with($path, 'record.') => self::SOURCE_RECORD,
            default => self::SOURCE_HOST,
        };
    }
}
