<?php

declare(strict_types=1);

namespace Whity\Auth\Service;

use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Mcp\Auth\OAuth\IssuerJwtVerifier;
use Whity\Sdk\Http\ServiceCall;

/**
 * Verifies the bearer token on a plugin SERVICE route (SDK 1.84, WHIT-1011).
 *
 * A service route is called server to server, for example by Whity ID's
 * account pages calling the Whity Pay plugin. The caller holds no session. It
 * presents a client-credentials JWT from the deployment's trusted issuer, and
 * that JWT is the whole credential. So every condition below has to hold, and
 * missing configuration means NO service call is accepted:
 *
 *   - signed by the issuer (SERVICE_TOKEN_ISSUER, else MCP_OAUTH_ISSUER) with
 *     an asymmetric key from its JWKS. A shared secret would let anyone holding
 *     it mint calls, so it is not accepted;
 *   - audience is this deployment's API, APP_URL + /api/v1, so a token minted
 *     for another service or deployment is refused;
 *   - client (`client_id`, else `azp`) is on SERVICE_TOKEN_CLIENTS. The issuer
 *     signs tokens for many clients, and only the ones the operator names may
 *     call in. An empty list switches service routes off;
 *   - lifetime (exp - iat) at most MAX_LIFETIME_SECONDS, so a leaked token is
 *     short-lived;
 *   - `scope` (space-separated, RFC 9068) or `scp` (list) contains the route's
 *     scope;
 *   - `sub_on_behalf`, the person a call acts for, comes ONLY from the issuer's
 *     own client (SERVICE_TOKEN_ACTING_CLIENT, default `whity-id`). Any other
 *     client sending it is refused, not just ignored, so a service that tries to
 *     act for someone is told no rather than quietly served as itself.
 */
final class ServiceTokenVerifier
{
    public const MAX_LIFETIME_SECONDS = 600;

    public const DEFAULT_ACTING_CLIENT = 'whity-id';

    /** @param list<string> $clients */
    public function __construct(
        private readonly IssuerJwtVerifier $issuer,
        private readonly string $audience,
        private readonly array $clients,
        private readonly string $actingClient = self::DEFAULT_ACTING_CLIENT,
    ) {
    }

    public static function fromEnv(string $appUrl, HttpClient $http, JwksProvider $jwks): ?self
    {
        $issuer = self::env('SERVICE_TOKEN_ISSUER') ?: self::env('MCP_OAUTH_ISSUER');
        $appUrl = rtrim(trim($appUrl), '/');
        $clients = array_values(array_filter(array_map('trim', explode(',', self::env('SERVICE_TOKEN_CLIENTS')))));
        if ($issuer === '' || $appUrl === '' || $clients === []) {
            return null;
        }

        $jwksUri = self::env('SERVICE_TOKEN_JWKS_URI') ?: self::env('MCP_OAUTH_JWKS_URI');

        return new self(
            new IssuerJwtVerifier(rtrim($issuer, '/'), $http, $jwks, $jwksUri === '' ? null : $jwksUri),
            $appUrl . '/api/v1',
            $clients,
            self::env('SERVICE_TOKEN_ACTING_CLIENT') ?: self::DEFAULT_ACTING_CLIENT,
        );
    }

    /** The verified call, or null when the token does not grant `$scope`. */
    public function verify(string $authorization, string $scope): ?ServiceCall
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m) !== 1) {
            return null;
        }

        $claims = $this->issuer->verify($m[1], $this->audience);
        if ($claims === null) {
            return null;
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $exp - $iat > self::MAX_LIFETIME_SECONDS) {
            return null;
        }

        $client = $claims['client_id'] ?? $claims['azp'] ?? null;
        if (!is_string($client) || !in_array($client, $this->clients, true)) {
            return null;
        }

        $scopes = self::scopes($claims);
        if (!in_array($scope, $scopes, true)) {
            return null;
        }

        $onBehalf = null;
        if (array_key_exists('sub_on_behalf', $claims)) {
            $onBehalf = $claims['sub_on_behalf'];
            if (!is_string($onBehalf) || $onBehalf === '' || $client !== $this->actingClient) {
                return null;
            }
        }

        return new ServiceCall($this->issuer->issuer(), $client, $scopes, $onBehalf);
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private static function scopes(array $claims): array
    {
        $scope = $claims['scope'] ?? null;
        if (is_string($scope)) {
            return array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== ''));
        }

        $scp = $claims['scp'] ?? null;

        return is_array($scp) ? array_values(array_filter($scp, 'is_string')) : [];
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
