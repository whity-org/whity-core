<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * A published template, as {@see Templates} describes it (SDK 1.82): its
 * published version, the slots it fills, and the merge fields it prints.
 */
final class TemplateInfo
{
    /**
     * @param list<string>        $slots  Slot keys (`<plugin>.<slot>`) the workspace bound it to.
     * @param list<TemplateField> $fields The merge fields its published version prints, each once.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        /** `template` or `label`. */
        public readonly string $kind,
        /** `en` or `ar`. */
        public readonly string $lang,
        /** The record type it is filled from, e.g. `Invoicing.invoice`, or null. */
        public readonly ?string $recordType,
        public readonly int $publishedVersion,
        public readonly array $slots,
        public readonly array $fields,
    ) {
    }

    /**
     * The fields an issue has to pass values for: the template's own, that are required.
     *
     * @return list<string>
     */
    public function requiredValues(): array
    {
        return array_values(array_map(
            static fn (TemplateField $f): string => $f->path,
            array_filter($this->fields, static fn (TemplateField $f): bool => $f->required && $f->source === TemplateField::SOURCE_TEMPLATE),
        ));
    }
}
