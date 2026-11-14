<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use Whity\Auth\Oidc\JwksProvider;
use Whity\Core\Http\HttpClient;
use Whity\Sdk\Auth\Jwt\IssuerTokenVerifier;

/**
 * Verifies a JWT signed by the configured OAuth authorization server
 * (`MCP_OAUTH_ISSUER`, ADR-0015) and returns its claims.
 *
 * Shared by the two places that trust that server's key: access tokens at /mcp
 * ({@see OAuthAccessTokenVerifier}) and the workspace-list assertion
 * ({@see OAuthWorkspacesHandler}). What each JWT is FOR is told apart by its
 * `aud` (and, for the assertion, its `typ`), never by which code verified it.
 *
 * The checks are the plugin SDK's {@see IssuerTokenVerifier} (WHIT-1026), the
 * one Whity ID token verifier plugins and services use too: issuer first, `typ`
 * when asked, signature against the issuer's discovered and cached key set
 * (asymmetric keys only), then `exp`, `nbf`, `iat`, `iss` and `aud`. Metadata
 * is fetched through the host's {@see HttpClient}.
 */
final class IssuerJwtVerifier
{
    private readonly IssuerTokenVerifier $verifier;

    public function __construct(
        private readonly string $issuer,
        HttpClient $http,
        JwksProvider $jwks,
        ?string $jwksUri = null,
    ) {
        $this->verifier = new IssuerTokenVerifier(
            $issuer,
            $jwks,
            static fn (string $url): ?array => $http->getJson($url),
            $jwksUri,
        );
    }

    /**
     * Built when {@see ProtectedResource} is on, with MCP_OAUTH_JWKS_URI
     * (optional; discovered otherwise).
     */
    public static function fromEnv(ProtectedResource $resource, HttpClient $http, JwksProvider $jwks): ?self
    {
        $issuer = $resource->issuer();
        if ($issuer === null) {
            return null;
        }
        return new self($issuer, $http, $jwks, self::env('MCP_OAUTH_JWKS_URI'));
    }

    /** The issuer every verified JWT comes from. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    /**
     * The verified claims, or null when the JWT is not valid for `$audience`
     * (and, when given, of type `$type`).
     *
     * @param string|\Closure(list<mixed>): bool $audience
     * @return array<string, mixed>|null
     */
    public function verify(string $token, string|\Closure $audience, ?string $type = null): ?array
    {
        return $this->verifier->verify($token, $audience, $type);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }
}
