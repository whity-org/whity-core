<?php

declare(strict_types=1);

namespace Whity\Sdk\Usage;

/**
 * One usage meter for one month (SDK 1.133). Read-only; see {@see Usage}.
 */
final class UsageMeter
{
    public const STATE_UNLIMITED = 'unlimited';
    public const STATE_OK = 'ok';
    public const STATE_NEAR = 'near';
    public const STATE_OVER = 'over';

    /**
     * @param string $meter      One of the {@see Usage} meter names.
     * @param string $key        The entitlement its base is set on, e.g. `api.calls.per_month`.
     * @param string $kind       `counted` (added to as things happen) or `snapshot` (a held total, read daily).
     * @param string $unit       `count`, or `bytes` for storage.
     * @param int $used          This month so far; for a snapshot, the latest reading.
     * @param int $peak          A snapshot's highest reading this month; for a counted meter, $used.
     * @param int|null $base     What the plan includes; null for no limit.
     * @param string $state      One of the STATE_* constants. Over is a warning, never a block.
     * @param array<string, int> $dimensions The split: messages by channel, API calls by `api` or `mcp`, agents by `runs`, `tool_calls`, `tokens_in`, `tokens_out`.
     * @param string|null $resetsAt When a monthly count starts again (ISO 8601); null for a held total.
     */
    public function __construct(
        public readonly string $meter,
        public readonly string $key,
        public readonly string $kind,
        public readonly string $unit,
        public readonly int $used,
        public readonly int $peak,
        public readonly ?int $base,
        public readonly string $state,
        public readonly array $dimensions = [],
        public readonly ?string $resetsAt = null,
    ) {
    }

    public function isOver(): bool
    {
        return $this->state === self::STATE_OVER;
    }

    public function isNear(): bool
    {
        return $this->state === self::STATE_NEAR;
    }

    public function isUnlimited(): bool
    {
        return $this->base === null;
    }

    /**
     * From the API shape (`GET /api/usage`'s `data.meters[]`).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $dimensions = [];
        foreach (is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [] as $name => $n) {
            if (is_string($name) && is_int($n)) {
                $dimensions[$name] = $n;
            }
        }

        return new self(
            (string) ($data['meter'] ?? ''),
            (string) ($data['key'] ?? ''),
            (string) ($data['kind'] ?? 'counted'),
            (string) ($data['unit'] ?? 'count'),
            (int) ($data['used'] ?? 0),
            (int) ($data['peak'] ?? 0),
            isset($data['base']) && is_int($data['base']) ? $data['base'] : null,
            (string) ($data['state'] ?? self::STATE_UNLIMITED),
            $dimensions,
            isset($data['resets_at']) && is_string($data['resets_at']) ? $data['resets_at'] : null,
        );
    }
}
