<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * A server-to-server call the host has authenticated (SDK 1.84).
 *
 * A plugin route declared `'service' => '<scope>'` runs only when the caller
 * presents a bearer JWT that the host has verified:
 *   - issued by the deployment's trusted issuer (Whity ID), checked against its JWKS;
 *   - with this deployment's API as the audience;
 *   - from a client on the operator's allow-list;
 *   - short-lived;
 *   - carrying the route's scope.
 * {@see self::from()} reads the result. Nothing in it comes from the request
 * itself, so a handler can trust every field.
 *
 * THERE IS NO SIGNED-IN USER AND NO WORKSPACE. A service call acts for a
 * client, and the host sets no tenant context. When the issuer's OWN client
 * (Whity ID's, `SERVICE_TOKEN_ACTING_CLIENT`, default `whity-id`) acts for a
 * person, the token's `sub_on_behalf` names them and {@see self::$onBehalfOf}
 * carries it. Any other client sending `sub_on_behalf` is refused outright, so
 * no other service can claim to act for somebody. Resolve the person with
 * {@see \Whity\Sdk\Identity\LinkedPeople}, which takes them from this call
 * and from nothing a handler passes in.
 */
final class ServiceCall
{
    public const ATTRIBUTE = 'whity.service_call';

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly array $scopes,
        /** The person at the issuer this call acts for; only ever set for the issuer's own client. */
        public readonly ?string $onBehalfOf = null,
    ) {
    }

    public static function from(Request $request): ?self
    {
        $value = $request->getAttribute(self::ATTRIBUTE);

        return $value instanceof self ? $value : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
