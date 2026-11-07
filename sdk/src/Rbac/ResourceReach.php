<?php

declare(strict_types=1);

namespace Whity\Sdk\Rbac;

/**
 * The records of one resource type a caller may act on (SDK 1.74).
 *
 * Returned by {@see ResourceScope::reach()}. It is either a finite set of ids
 * or UNRESTRICTED (the caller holds the plugin's named "see everything"
 * permission). A value object only: it holds no connection and grants nothing.
 *
 * Use it as the second check, next to the permission check:
 *
 *     $reach = $scope->reach($profileId, $tenantId, 'acme:records_read', 'acme:record');
 *     $visible = $reach->filter($candidateIds);       // never more than you passed in
 *     if (!$reach->contains($recordId)) { return Response::error('Not found', 404); }
 *
 * {@see self::filter()} only ever removes ids from its input, so a reach can
 * narrow a list and can never add to it.
 */
final class ResourceReach
{
    /**
     * @param array<int, true>|null $ids Keyed by id; null means unrestricted.
     */
    private function __construct(private readonly ?array $ids)
    {
    }

    /** No records: the caller may act on none of them. */
    public static function none(): self
    {
        return new self([]);
    }

    /** Every record of the type. Only the host should hand this out. */
    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * Exactly these records.
     *
     * @param iterable<int> $ids
     */
    public static function of(iterable $ids): self
    {
        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return new self($set);
    }

    /** True when the caller may act on every record of the type. */
    public function isUnrestricted(): bool
    {
        return $this->ids === null;
    }

    /** True when the caller may act on no record of the type. */
    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /** Whether the caller may act on this record. */
    public function contains(int $id): bool
    {
        return $this->ids === null || isset($this->ids[$id]);
    }

    /**
     * The ids from `$candidates` the caller may act on, in their input order.
     *
     * Always a subset of `$candidates`: a reach narrows a list and never
     * widens it.
     *
     * @param iterable<int> $candidates
     * @return list<int>
     */
    public function filter(iterable $candidates): array
    {
        $out = [];
        foreach ($candidates as $id) {
            if ($this->contains((int) $id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * The ids in a finite reach, ascending. Use it to build a SQL `IN (...)`;
     * an empty list means no rows, so write `WHERE false`, not no WHERE.
     *
     * @return list<int>
     * @throws \LogicException When the reach is unrestricted: there is no list
     *                         to return, and returning an empty one would read
     *                         as "nothing" to code that forgot to check.
     */
    public function ids(): array
    {
        if ($this->ids === null) {
            throw new \LogicException(
                'An unrestricted reach has no id list; check isUnrestricted() first.'
            );
        }

        $ids = array_keys($this->ids);
        sort($ids);

        return $ids;
    }
}
