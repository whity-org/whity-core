<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * One document in a {@see BulkIssueRequest} (SDK 1.83): the record it is for,
 * values for the template's own fields, a title and an origin key, as in
 * {@see IssueRequest}.
 */
final class BulkIssueItem
{
    /** @var array<string, string> */
    public readonly array $values;

    /** @param array<mixed> $values field path => text, checked here since a plugin may build it from anything */
    public function __construct(
        public readonly ?string $record = null,
        array $values = [],
        public readonly ?string $title = null,
        public readonly ?string $originKey = null,
    ) {
        $checked = [];
        foreach ($values as $path => $value) {
            if (!is_string($path) || !is_string($value)) {
                throw new \InvalidArgumentException('Values are field path => text');
            }
            $checked[$path] = $value;
        }
        $this->values = $checked;
    }
}
