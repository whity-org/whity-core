<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\AuthHandler;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;
use Whity\Http\JsonBody;

/**
 * "Create your workspace" after a sign-in that found no workspace (WHIT-994).
 *
 * Somebody the operator's own identity provider signs in, who belongs to no
 * workspace, used to hit a `no_membership` error, or (with
 * `auth.sso_self_provision_workspace` on) silently get a workspace named after
 * them. Now the SSO callback hands them a short-lived signed cookie (type
 * `workspace_setup`) and sends them to the frontend's "no workspace yet" page,
 * which talks to these two PUBLIC routes:
 *
 *   GET  /api/auth/sso/workspace → which screen: `create` (with their name to
 *        prefill), `waiting` (a membership somewhere that is not active yet,
 *        with those workspaces' names) or `needs_invite`; and the address they
 *        signed in with, either way.
 *   POST /api/auth/sso/workspace → { name, locale?, main_color? }: create their
 *        workspace and sign them straight into it as its owner.
 *
 * THE COOKIE IS NOT A SESSION. It names a profile, the address and the
 * provider, and opens nothing but these two routes. Nothing in it is trusted
 * beyond that: the provider is re-read from the database (still enabled, still
 * the operator's, tenant 0), and the POST goes through
 * {@see FederatedWorkspaceProvisioner::provisionFor()}, which re-checks every
 * gate itself under a lock on the profile row: the operator setting, global
 * trust, a profile that is not deactivated, no membership row in any status.
 *
 * Two POSTs racing (a double click, two tabs) make ONE workspace: the second
 * waits on the profile lock, then finds the first one's membership and
 * creates nothing. It is then signed into that same workspace, so both tabs
 * end in the same place.
 */
final class SsoWorkspaceSetupHandler
{
    /** JWT `type` of the cookie the callback sets. */
    public const TOKEN_TYPE = 'workspace_setup';

    /** How long the "no workspace yet" state lasts, in seconds. */
    public const TOKEN_TTL = 900;

    /** Longest workspace name accepted, in characters, after cleaning. */
    public const NAME_MAX = 120;

    public function __construct(
        private readonly JwtParser $jwtParser,
        private readonly IdentityProviderRepository $providers,
        private readonly FederatedWorkspaceProvisioner $workspaces,
        private readonly AuthHandler $auth,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * GET /api/auth/sso/workspace — which "no workspace yet" screen to show.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return self::expired();
        }
        [$profileId, $email, $ctx] = $pending;

        $state = $this->workspaces->stateFor($profileId, $ctx);
        if ($state === FederatedWorkspaceProvisioner::STATE_REFUSED) {
            CookieManager::clearWorkspaceSetupToken();
            return self::expired();
        }

        $data = ['state' => $state, 'email' => $email];
        if ($state === FederatedWorkspaceProvisioner::STATE_CREATE) {
            $data['suggested_name'] = $this->workspaces->suggestedName($profileId);
        }
        if ($state === FederatedWorkspaceProvisioner::STATE_WAITING) {
            $data['workspaces'] = $this->workspaces->waitingWorkspaces($profileId);
        }

        return Response::json(['data' => $data])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * POST /api/auth/sso/workspace — create the person's workspace and sign
     * them into it.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params = []): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return self::expired();
        }
        [$profileId, $email, $ctx] = $pending;

        $body = JsonBody::parsed($request);

        // The same cleaning the provisioner applies (control and bidi characters
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
            $tenantId = $this->workspaces->provisionFor($profileId, $email, $ctx, $name);
        } catch (\Throwable $e) {
            // A lock timeout behind a stuck request, or a tenant.created
            // listener that refused: nothing was created, so trying again is safe.
            error_log('[sso-workspace] create failed for profile ' . $profileId . ': ' . $e->getMessage());
            return Response::error('Your workspace could not be created. Try again.', 503, ['code' => 'busy']);
        }

        if ($tenantId === null) {
            // Nothing created. When a racing request created their workspace a
            // moment ago, they now hold an active membership: sign them into it.
            // Otherwise a gate closed (switched off, deactivated, placed
            // somewhere in the meantime) and there is nothing to sign into.
            $session = $this->auth->completeFederatedLogin($profileId, $email, $request, null);
            if ($session->getStatusCode() === 200) {
                CookieManager::clearWorkspaceSetupToken();
                return $session->withHeaders(['Cache-Control' => 'no-store']);
            }
            return Response::error('You can no longer create a workspace from this sign-in', 403, ['code' => 'not_allowed']);
        }

        // The workspace's look, as the onboarding card asks for it. Best effort:
        // the workspace exists either way, and both are in its settings.
        foreach ([SettingsRegistry::LOCALE => $locale, SettingsRegistry::BRANDING_MAIN_COLOR => $colour] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $this->settings->setTenant($tenantId, $key, $key === SettingsRegistry::BRANDING_MAIN_COLOR ? strtolower($value) : $value);
            } catch (\Throwable $e) {
                error_log('[sso-workspace] could not set ' . $key . ' on tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        CookieManager::clearWorkspaceSetupToken();
        $session = $this->auth->completeFederatedLogin($profileId, $email, $request, null);

        return $session->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * The pending "no workspace yet" sign-in, re-validated: a well-formed
     * cookie of the right type, and a provider that is still enabled and still
     * the operator's own (tenant 0).
     *
     * @return array{0: int, 1: string, 2: FederatedProviderContext}|null
     */
    private function pending(): ?array
    {
        if (!$this->ssoEnabled()) {
            return null;
        }
        $token = CookieManager::getWorkspaceSetupToken();
        if ($token === null) {
            return null;
        }
        $claims = $this->jwtParser->parse($token);
        if ($claims === null
            || ($claims['type'] ?? null) !== self::TOKEN_TYPE
            || !is_int($claims['profile_id'] ?? null) || $claims['profile_id'] <= 0
            || !is_int($claims['provider_id'] ?? null) || $claims['provider_id'] <= 0
            || !is_string($claims['email'] ?? null)
        ) {
            return null;
        }

        // Re-read under tenant 0 only: a tenant's own provider can never be
        // found here, so it can never be offered the step.
        $provider = $this->providers->findById($claims['provider_id'], 0);
        if ($provider === null || ($provider['enabled'] ?? false) !== true) {
            return null;
        }

        return [
            $claims['profile_id'],
            $claims['email'],
            new FederatedProviderContext($claims['provider_id'], (string) $provider['provider_key'], 0),
        ];
    }

    private function ssoEnabled(): bool
    {
        $global = $this->settings->getGlobal();
        return ($global[SettingsRegistry::SSO_ENABLED] ?? 'true') === 'true';
    }

    private static function expired(): Response
    {
        return Response::error('This sign-in has expired. Sign in again.', 401, ['code' => 'expired']);
    }
}
