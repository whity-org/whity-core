<?php

declare(strict_types=1);

namespace Whity\Auth\Oidc;

use Whity\Sdk\Auth\Jwt\JwksCache;

/**
 * Fetches and caches provider JWKS (JSON Web Key Sets) for token verification
 * (WC-24a1).
 *
 * The cache itself is the plugin SDK's {@see JwksCache} (WHIT-1026), shared
 * with plugins and services; this name stays for the host's wiring. The
 * composition root passes a fetcher that goes through the host's SSRF-guarded
 * HTTP client.
 */
final class JwksProvider extends JwksCache
{
}
