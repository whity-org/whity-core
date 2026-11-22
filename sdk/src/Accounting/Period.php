<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * One month of the books (SDK 1.154, WHIT-1318, register AC.7): `open` takes
 * entries, `closed` and `locked` refuse them.
 */
final class Period
{
    public function __construct(
        public readonly int $id,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            startsOn: is_string($data['starts_on'] ?? null) ? $data['starts_on'] : '',
            endsOn: is_string($data['ends_on'] ?? null) ? $data['ends_on'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
        );
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
