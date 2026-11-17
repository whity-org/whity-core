<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\WorkspaceCreator;
use Whity\Http\JsonBody;

/**
 * Creating another workspace while signed in (WHIT-1169):
 *   GET  /api/v1/workspaces/new → may this person create one now, and why not
 *   POST /api/v1/workspaces     → { name, locale?, main_color? }: create it; the
 *                                 caller becomes its owner and then switches
 *                                 into it with the usual switch-tenant call.
 *
 * Self-authenticating via the access token (cookie, or Bearer), like
 * {@see MeIdentitiesApiHandler}: the question is about the PERSON, not about a
 * permission in the workspace they happen to be in. No password is asked for.
 */
final class WorkspacesApiHandler
{
    /** Same bound as the SSO create step. */
    private const NAME_MAX = 120;

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly WorkspaceCreator $creator,
        private readonly SettingsService $settings,
    ) {
    }

    public function state(Request $request): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }
        return Response::json(['data' => $this->creator->stateFor($profileId)])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function create(Request $request): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $body = JsonBody::parsed($request);

        // The same cleaning the founder applies (control and bidi characters
        // out, spaces collapsed), so "required" means a name that survives it.
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName)
            ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $rawName)))
            : '';
        if ($name === '') {
            return Response::error('A workspace name is required', 422, ['code' => 'name_required']);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return Response::error('The workspace name is too long', 422, ['code' => 'name_too_long']);
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && (!is_string($locale) || preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) !== 1)) {
            return Response::error('Choose a language from the list', 422, ['code' => 'invalid_locale']);
        }
        $colour = $body['main_color'] ?? null;
        if ($colour !== null && (!is_string($colour) || ($colour !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1))) {
            return Response::error('Choose a colour from the list', 422, ['code' => 'invalid_colour']);
        }

        try {
            $result = $this->creator->createFor($profileId, $name);
        } catch (\Throwable $e) {
            // A lock timeout behind a stuck request, or a tenant.created listener
            // that refused: nothing was created, so trying again is safe.
            error_log('[workspace-create] failed for profile ' . $profileId . ': ' . $e->getMessage());
            return Response::error('Your workspace could not be created. Try again.', 503, ['code' => 'busy']);
        }

        switch ($result['status']) {
            case WorkspaceCreator::DISABLED:
                return Response::error('Creating workspaces is switched off on this installation', 403, ['code' => 'disabled']);
            case WorkspaceCreator::LIMIT_REACHED:
                return Response::error(
                    'You already own as many workspaces as this installation allows',
                    409,
                    ['code' => 'limit_reached', 'limit' => $this->creator->limit()]
                );
            case WorkspaceCreator::REFUSED:
                return Response::error('You can\'t create a workspace', 403, ['code' => 'not_allowed']);
        }

        $tenantId = (int) $result['tenant_id'];

        // The workspace's look, as the create step asks for it. Best effort:
        // the workspace exists either way, and both are in its settings.
        foreach ([SettingsRegistry::LOCALE => $locale, SettingsRegistry::BRANDING_MAIN_COLOR => $colour] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $this->settings->setTenant($tenantId, $key, $key === SettingsRegistry::BRANDING_MAIN_COLOR ? strtolower($value) : $value);
            } catch (\Throwable $e) {
                error_log('[workspace-create] could not set ' . $key . ' on tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        return Response::json(['data' => ['tenant_id' => $tenantId, 'name' => (string) $result['name']]], 201)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** The caller's profile id from a valid access token (cookie or Bearer). */
    private function resolveProfileId(Request $request): ?int
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $claims = $this->tokenValidator->validateAccessTokenFromBearer(substr($header, 7));
            }
        }
        if ($claims === null) {
            return null;
        }
        $profileId = $claims['profile_id'] ?? null;
        return is_int($profileId) && $profileId > 0 ? $profileId : null;
    }
}
