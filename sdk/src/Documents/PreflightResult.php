<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Whether a document would issue, and every reason it wouldn't (SDK 1.66). Nothing
 * was written to find out. The answer can be out of date a moment later, and a real
 * issue checks everything again.
 *
 * Each check has `label` and `ok`, `why` when it failed, and `code`: `template`,
 * `record`, `values`, `fields`, `origin_key` or `render` from core, `plugin` from a
 * `document.issuing` listener. `replaysDocumentId` is the document the origin key
 * already names: an issue would return that one instead of raising another.
 */
final class PreflightResult
{
    /**
     * @param list<array<string, mixed>> $checks
     * @param list<string>               $missingFields
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $checks,
        public readonly array $missingFields = [],
        public readonly ?int $replaysDocumentId = null,
    ) {
    }
}
