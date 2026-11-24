<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

/**
 * What the catalogue knows about one event (SDK 1.164): from
 * {@see EventCatalogue::get()}.
 */
final class EventInfo
{
    /**
     * @param string $kind one of {@see EventCatalogue::KINDS}
     * @param list<string> $payload the payload keys the event documents; empty when none are documented yet
     */
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $kind,
        public readonly bool $vetoable,
        public readonly array $payload,
        public readonly ?string $async,
        public readonly ?string $asyncOf,
        public readonly string $since,
        public readonly string $notes,
    ) {
    }

    /** Whether the host reads what a listener returns. */
    public function isFilter(): bool
    {
        return $this->kind === EventCatalogue::FILTER;
    }

    /**
     * @return array{name: string, group: string, kind: string, vetoable: bool, payload: list<string>, async: ?string, asyncOf: ?string, since: string, notes: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'group' => $this->group,
            'kind' => $this->kind,
            'vetoable' => $this->vetoable,
            'payload' => $this->payload,
            'async' => $this->async,
            'asyncOf' => $this->asyncOf,
            'since' => $this->since,
            'notes' => $this->notes,
        ];
    }
}
