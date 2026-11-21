<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One posted journal entry (SDK 1.131, WHIT-1318, register AC.3). Never
 * changed and never deleted; a correction is another entry, the mirror, with
 * `reversesEntryId` set. Amounts are in the books' base currency's minor
 * units. `hash` chains it to the entry before it in the workspace (AC.4).
 */
final class Entry
{
    /** @param list<EntryLine> $lines empty when read from a list */
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly string $journal,
        public readonly string $date,
        public readonly string $memo,
        public readonly string $sourceType,
        public readonly ?int $sourceId,
        public readonly string $currency,
        public readonly int $totalMinor,
        public readonly array $lines = [],
        public readonly ?int $reversesEntryId = null,
        public readonly ?int $reversedByEntryId = null,
        public readonly ?int $postedBy = null,
        public readonly string $hash = '',
    ) {
    }

    /** @param array<string, mixed> $data the API's `AccountingEntry` */
    public static function fromArray(array $data): self
    {
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;
        $text = static fn (string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = EntryLine::fromArray($line);
            }
        }

        return new self(
            id: $int('id') ?? 0,
            number: $text('number'),
            journal: $text('journal_code'),
            date: $text('date'),
            memo: $text('memo'),
            sourceType: $text('source_type'),
            sourceId: $int('source_id'),
            currency: $text('currency'),
            totalMinor: $int('total_minor') ?? 0,
            lines: $lines,
            reversesEntryId: $int('reverses_entry_id'),
            reversedByEntryId: $int('reversed_by_entry_id'),
            postedBy: $int('posted_by_profile_id'),
            hash: $text('hash'),
        );
    }
}
