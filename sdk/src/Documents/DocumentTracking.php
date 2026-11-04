<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * The QR tracking record of one document a plugin issued (SDK 1.63, WHIT-886).
 *
 * `$scans` holds up to the 50 most recent scans, newest first. Each entry has
 * `scanned_at` (ISO-8601 text) and `outcome` (the server's answer, for example
 * `verified` or `withdrawn`). No scanner identity is included.
 */
final class DocumentTracking
{
    /** @param list<array{scanned_at: string, outcome: string}> $scans */
    public function __construct(
        public readonly int $documentId,
        public readonly bool $codeActive,
        public readonly int $scanCount,
        public readonly array $scans,
    ) {
    }
}
