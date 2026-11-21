<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\PluginFrameTokens;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * `POST /api/plugin-frames/{feature}/token`: a scoped token for an embedded
 * plugin screen (#1688).
 *
 * The web app's embed screen asks for one when the framed page first talks to it
 * over the postMessage bridge, and again shortly before it runs out. It is used by
 * the HOST page, which makes the frame's API calls for it with this token and no
 * cookies; the token is never handed to the framed page.
 *
 * Refused (404, so a caller cannot probe which screens exist) unless:
 *  - the caller is signed in with a session's own access token (a frame token
 *    cannot mint another; it is refused on this core route upstream anyway);
 *  - `feature` is an `embed` screen of a plugin that is on for this workspace;
 *  - the caller holds the screen's `requiredPermission`, the same check that
 *    decides whether they see the screen at all;
 *  - the screen's page is served by a plugin route, whose namespace the token is
 *    bound to (see {@see PluginFrameTokens::admits()}).
 */
final class PluginFrameTokenApiHandler
{
    public function __construct(
        private readonly PluginLoader $pluginLoader,
        private readonly RoleChecker $roleChecker,
        private readonly Router $router,
        private readonly JwtParser $jwt,
    ) {
    }

    /** @param array<string, string> $params */
    public function issue(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $claims = is_object($request->user) ? (array) $request->user : null;
        $profileId = $claims['profile_id'] ?? null;
        if ($tenantId === null || $claims === null || !is_int($profileId)
            || ($claims['type'] ?? null) !== 'access'
        ) {
            return Response::error('Authentication required', 401);
        }

        $featureId = $params['feature'] ?? '';
        $feature = null;
        foreach ($this->pluginLoader->getFrontendFeatures() as $candidate) {
            if (($candidate['id'] ?? null) === $featureId && ($candidate['screen'] ?? null) === 'embed') {
                $feature = $candidate;
                break;
            }
        }

        $permission = $feature['requiredPermission'] ?? null;
        $path = is_array($feature['embed'] ?? null) ? ($feature['embed']['path'] ?? null) : null;
        if (!is_string($permission) || !is_string($path)
            || !$this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId)
        ) {
            return Response::error('Not found', 404);
        }

        $namespace = PluginFrameTokens::ownerOfGetRoute($this->router, $path);
        if ($namespace === null) {
            return Response::error('Not found', 404);
        }

        $issued = PluginFrameTokens::issue($this->jwt, $claims, $featureId, $namespace);

        return Response::json(['data' => $issued], 200)->withHeaders(['Cache-Control' => 'no-store']);
    }
}
