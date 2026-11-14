<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth\Jwt;

/**
 * Fetches and caches JSON Web Key Sets by uri (SDK 1.106).
 *
 * Issuers publish their signing keys at a `jwks_uri` and rotate them. Each set
 * is cached on the instance for a TTL; a worker-lived instance therefore caches
 * across requests. When a token names a `kid` the cached set lacks (a freshly
 * rotated key), {@see getForKid()} refetches once before giving up.
 *
 * On a public endpoint set `$minRefreshSeconds`, or anyone could send made-up
 * `kid`s and make the host refetch the issuer's keys on every request.
 *
 * Network I/O goes through the injected fetcher, so this class is
 * deterministic in tests. {@see HttpsJson::get()} is a ready-made fetcher; a
 * host with its own guarded HTTP client passes that instead.
 */
class JwksCache
{
    /** @var \Closure(string): array<string, mixed> */
    private \Closure $fetcher;

    /** @var array<string, array{jwks: array<string, mixed>, fetchedAt: int}> */
    private array $cache = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param callable(string): array<string, mixed> $fetcher Fetches and decodes a JWKS by uri; throws or returns [] on failure.
     * @param int                                    $ttlSeconds Cache lifetime.
     * @param int                                    $minRefreshSeconds Least time between two unknown-kid refetches of one uri; 0 refetches on every miss.
     * @param (callable(): int)|null                 $clock Current Unix time, for tests.
     */
    public function __construct(
        callable $fetcher,
        private readonly int $ttlSeconds = 3600,
        private readonly int $minRefreshSeconds = 0,
        ?callable $clock = null,
    ) {
        $this->fetcher = \Closure::fromCallable($fetcher);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * The JWKS for a uri, fetched (and cached) when absent or stale.
     *
     * @return array<string, mixed>
     */
    public function get(string $jwksUri): array
    {
        $entry = $this->cache[$jwksUri] ?? null;
        if ($entry !== null && ($this->now() - $entry['fetchedAt']) < $this->ttlSeconds) {
            return $entry['jwks'];
        }

        return $this->refresh($jwksUri);
    }

    /**
     * A JWKS that contains `$kid` if at all possible: the cached set, refetched
     * once when it lacks `$kid` (unless fetched within `$minRefreshSeconds`).
     * A null or empty kid returns the cached set.
     *
     * @return array<string, mixed>
     */
    public function getForKid(string $jwksUri, ?string $kid): array
    {
        $jwks = $this->get($jwksUri);

        if ($kid === null || $kid === '' || self::containsKid($jwks, $kid)) {
            return $jwks;
        }

        $fetchedAt = $this->cache[$jwksUri]['fetchedAt'] ?? 0;
        if ($this->minRefreshSeconds > 0 && ($this->now() - $fetchedAt) < $this->minRefreshSeconds) {
            return $jwks;
        }

        return $this->refresh($jwksUri);
    }

    /** @return array<string, mixed> */
    private function refresh(string $jwksUri): array
    {
        $jwks = ($this->fetcher)($jwksUri);
        $this->cache[$jwksUri] = ['jwks' => $jwks, 'fetchedAt' => $this->now()];

        return $jwks;
    }

    /** @param array<string, mixed> $jwks */
    private static function containsKid(array $jwks, string $kid): bool
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
