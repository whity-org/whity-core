<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth\Jwt;

/**
 * Verifies a JWT signed by one issuer, such as Whity ID, and returns its claims
 * (SDK 1.106).
 *
 * The one Whity ID token check for the host, plugins and services. In order:
 *   - the token's unverified `iss` must be the configured issuer, checked first
 *     so a stray bearer never costs a network fetch;
 *   - when a type is asked for, the header's `typ` must be it (RFC 8725 §3.11;
 *     `at+jwt` and `application/at+jwt` are the same type, RFC 7515 §4.1.9),
 *     so a JWT minted for one purpose cannot be replayed for another;
 *   - the signature, by {@see JwsVerifier}, against the issuer's key set: from
 *     `$jwksUri` when given, otherwise found through the issuer's RFC 8414 or
 *     OIDC metadata (whose `issuer` must match, and whose `jwks_uri` must be
 *     https), cached by {@see JwksCache} with a refetch for a rotated key;
 *   - `exp` required and in the future, `nbf` and `iat` not in the future,
 *     each with `$leewaySeconds`;
 *   - `iss` exact, and `aud` must name the expected audience (a string, or a
 *     list that contains it), or satisfy the closure given instead.
 *
 * Returns null on any failure and never throws. Instance state is the
 * discovered key-set location only, the same for every request, so a
 * worker-lived instance is safe.
 */
final class IssuerTokenVerifier
{
    public const DEFAULT_LEEWAY_SECONDS = 30;

    private ?string $discoveredJwksUri = null;

    /** @var \Closure(string): (array<mixed>|null) */
    private \Closure $fetchJson;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param string                              $issuer    The exact `iss` accepted (no trailing slash).
     * @param JwksCache                           $jwks      Key-set cache; share one per worker.
     * @param (callable(string): (array<mixed>|null))|null $fetchJson Fetches issuer metadata; defaults to {@see HttpsJson::get()}.
     * @param string|null                         $jwksUri   The issuer's key-set uri, or null to discover it.
     * @param list<string>                        $algorithms Accepted algorithms, see {@see JwsVerifier::ALGORITHMS}.
     * @param (callable(): int)|null              $clock     Current Unix time, for tests.
     */
    public function __construct(
        private readonly string $issuer,
        private readonly JwksCache $jwks,
        ?callable $fetchJson = null,
        private readonly ?string $jwksUri = null,
        private readonly int $leewaySeconds = self::DEFAULT_LEEWAY_SECONDS,
        private readonly array $algorithms = JwsVerifier::ALGORITHMS,
        ?callable $clock = null,
    ) {
        $this->fetchJson = $fetchJson !== null
            ? \Closure::fromCallable($fetchJson)
            : static fn (string $url): ?array => HttpsJson::get($url);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /** The issuer every verified token comes from. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The verified claims, or null when the token is not valid for `$audience`
     * (and, when given, of type `$type`).
     *
     * @param string|\Closure(list<mixed>): bool $audience The audience to require, or a closure judging the token's audiences.
     * @return array<string, mixed>|null
     */
    public function verify(string $token, string|\Closure $audience, ?string $type = null): ?array
    {
        $header = JwsVerifier::unverifiedHeader($token);
        $unverified = JwsVerifier::unverifiedClaims($token);
        if ($header === null || $unverified === null || ($unverified['iss'] ?? null) !== $this->issuer) {
            return null;
        }
        // The header is covered by the signature checked below, so reading it
        // first only saves a fetch; it never decides acceptance alone.
        if ($type !== null && !self::typeMatches($header['typ'] ?? null, $type)) {
            return null;
        }

        $jwksUri = $this->jwksUri ?? $this->discoverJwksUri();
        if ($jwksUri === null) {
            return null;
        }

        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        try {
            $verified = JwsVerifier::verify($token, $this->jwks->getForKid($jwksUri, $kid), $this->algorithms);
        } catch (\Throwable) {
            return null;
        }
        if ($verified === null) {
            return null;
        }

        $claims = $verified['claims'];
        if (($claims['iss'] ?? null) !== $this->issuer || !self::timesAreValid($claims, ($this->clock)(), $this->leewaySeconds)) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? array_values($aud) : [$aud];
        if (is_string($audience) ? !in_array($audience, $audiences, true) : !$audience($audiences)) {
            return null;
        }

        return $claims;
    }

    /**
     * Whether `exp` is present and in the future and `nbf` / `iat`, when
     * present, are not in the future, each with `$leewaySeconds`.
     *
     * @param array<string, mixed> $claims
     */
    public static function timesAreValid(array $claims, int $now, int $leewaySeconds = self::DEFAULT_LEEWAY_SECONDS): bool
    {
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            return false;
        }
        if ($exp + $leewaySeconds <= $now) {
            return false;
        }
        foreach (['nbf', 'iat'] as $notAfter) {
            if (!array_key_exists($notAfter, $claims)) {
                continue;
            }
            $value = $claims[$notAfter];
            if ((!is_int($value) && !is_float($value)) || $value - $leewaySeconds > $now) {
                return false;
            }
        }

        return true;
    }

    private static function typeMatches(mixed $actual, string $expected): bool
    {
        if (!is_string($actual)) {
            return false;
        }
        $strip = static fn (string $t): string => str_starts_with(strtolower($t), 'application/') ? substr($t, 12) : $t;

        return strcasecmp($strip($actual), $strip($expected)) === 0;
    }

    /**
     * The key-set location from the issuer's metadata: RFC 8414 first, then
     * OIDC discovery. Not cached on failure, so an issuer that was briefly down
     * is tried again on the next request.
     */
    private function discoverJwksUri(): ?string
    {
        if ($this->discoveredJwksUri !== null) {
            return $this->discoveredJwksUri;
        }
        foreach ($this->metadataUrls() as $url) {
            try {
                $doc = ($this->fetchJson)($url);
            } catch (\Throwable) {
                $doc = null;
            }
            if (
                is_array($doc)
                && ($doc['issuer'] ?? null) === $this->issuer
                && is_string($doc['jwks_uri'] ?? null)
                && str_starts_with(strtolower($doc['jwks_uri']), 'https://')
            ) {
                return $this->discoveredJwksUri = $doc['jwks_uri'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function metadataUrls(): array
    {
        $parts = parse_url($this->issuer);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return [
            // RFC 8414 §3.1: the well-known segment goes before the issuer's path.
            $origin . '/.well-known/oauth-authorization-server' . $path,
            $this->issuer . '/.well-known/openid-configuration',
        ];
    }
}
