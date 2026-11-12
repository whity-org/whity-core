<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\AuthHandler;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\OidcEngine;
use Whity\Auth\Oidc\StandardOidcProvider;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Federated sign-in ("Sign in with Google") over OIDC (WC-ae16).
 *
 * Two PUBLIC, unauthenticated GET routes:
 *   GET /api/v1/auth/sso/{provider}/start    → begin the Authorization Code + PKCE
 *     flow: resolve the tenant by host, load its enabled provider config, mint the
 *     flow-state (state/nonce/PKCE verifier) into a signed short-lived cookie, and
 *     302 to the provider's authorize endpoint.
 *   GET /api/v1/auth/sso/{provider}/callback → complete it: validate the flow-state
 *     cookie + `state` (CSRF/replay), exchange the code, verify the ID token, map
 *     (issuer, subject) → a LINKED local profile, and mint a session.
 *
 * FIRST LOGIN (WC-f3b17bd2): a verified identity with no existing link is resolved
 * by {@see FederatedIdentityLinker} — linked to an existing profile that owns the
 * same VERIFIED email, or used to provision a new passwordless profile. Unverified
 * emails and unverified local-email conflicts are refused (anti-takeover), bounced
 * to /login with a generic marker. Both routes are GET, so the CSRF guard
 * (POST-only) does not apply; `state` is the CSRF defense.
 */
final class SsoAuthHandler
{
    /**
     * Dispatched once per VERIFIED federated sign-in, after the identity has a
     * local profile and BEFORE the session is minted, so whatever a listener
     * applies is already true for the session that follows.
     *
     * A notification: the payload is not read back, and a listener that throws
     * is logged and ignored — it cannot refuse a sign-in. Payload:
     *   profile_id            int
     *   email                 string   the address stamped on the session
     *   provider_key          string
     *   issuer                string   the ID token's `iss`
     *   subject               string   the ID token's `sub`, stable per issuer
     *   global_trust          bool     operator (tenant 0) provider, or a tenant's own
     *   provider_tenant_id    int      0 for global trust
     *   provisioned_tenant_id null     always null since WHIT-994: a sign-in no longer
     *                                  creates a workspace (the "Create your workspace"
     *                                  step does, and announces `tenant.created`)
     *
     * Nothing from the token beyond `iss` and `sub` is passed on: a listener that
     * needs more about the person asks its own source with the subject.
     */
    public const SIGNED_IN_EVENT = 'auth.federated.signed_in';

    public function __construct(
        private readonly OidcEngine $engine,
        private readonly IdentityProviderRepository $providers,
        private readonly ProfileEmailRepository $emails,
        private readonly HostResolver $hostResolver,
        private readonly JwtParser $jwtParser,
        private readonly EncryptedSecretStore $secrets,
        private readonly AuthHandler $auth,
        private readonly FederatedIdentityLinker $linker,
        private readonly \Whity\Core\Settings\SettingsService $settings,
        private readonly string $appUrl,
        /**
         * Decides where a global-trust sign-in with no active membership lands
         * (WHIT-994): the "Create your workspace" step, a "your place is
         * waiting" note, or "you need an invitation". Null (tests, and any
         * composition that does not offer it) keeps the old behaviour: such a
         * person bounces with `no_membership`.
         */
        private readonly ?\Whity\Core\Tenant\FederatedWorkspaceProvisioner $workspaces = null,
        /**
         * Announces a verified federated sign-in to plugins (see
         * {@see self::SIGNED_IN_EVENT}). Null in tests that do not watch it.
         */
        private readonly ?\Whity\Core\Hooks\HookManager $hooks = null,
        /**
         * Where the browser lands after the callback. `$appUrl` is the
         * BACKEND's origin (the callback is built on it); the pages live on the
         * frontend, which a headless deployment serves elsewhere. Null derives
         * both from `$appUrl`, the pre-headless behaviour.
         */
        private readonly ?\Whity\Core\Http\FrontendUrls $frontendUrls = null,
        /**
         * Joining a workspace as a customer through a sign-in provider
         * (WHIT-917): `/start?join=<slug>`. Both null (tests that don't
         * exercise it) and the `join` parameter is refused.
         */
        private readonly ?\Whity\Core\Signup\CustomerSignupService $signups = null,
        private readonly ?\Whity\Core\Branding\TenantHostRepository $tenantHosts = null,
        /**
         * Signing again to confirm a signature (WHIT-922): `/start?reauth=sign`
         * from a signed-in person. All three null (tests that don't exercise
         * it) and `reauth` is refused.
         */
        private readonly ?\Whity\Auth\TokenValidator $sessions = null,
        private readonly ?\Whity\Core\Identity\ExternalIdentityRepository $identities = null,
        private readonly ?\Whity\Core\Document\Signing\SigningChallengeRepository $signingChallenges = null,
    ) {
    }

    /**
     * The signed-in person asking to sign in again before signing (WHIT-922),
     * and the page to come back to. Null when there is no session, the feature
     * is not wired, or the return path is not a plain path on this app.
     *
     * @return array{profile_id: int, tenant_id: int, route_id: int, return_to: string}|null
     */
    private function reauthTarget(): ?array
    {
        if ($this->sessions === null || $this->identities === null || $this->signingChallenges === null) {
            return null;
        }
        $claims = $this->sessions->validateAccessToken();
        $profileId = $claims['profile_id'] ?? null;
        $tenantId = $claims['tenant_id'] ?? null;
        $returnTo = (string) ($this->query('return_to') ?? '');
        // The route the signer is signing on: the sign-in counts only there.
        $routeId = (string) ($this->query('route_id') ?? '');
        if (!is_int($profileId) || !is_int($tenantId) || $tenantId <= 0
            || preg_match('/^[1-9]\d{0,18}$/', $routeId) !== 1
            || preg_match('#^/(?!/)[A-Za-z0-9/_\-.~%]{0,500}$#', $returnTo) !== 1
        ) {
            return null;
        }

        return ['profile_id' => $profileId, 'tenant_id' => $tenantId, 'route_id' => (int) $routeId, 'return_to' => $returnTo];
    }

    /**
     * Finish a sign-in made to confirm a signature (WHIT-922). No session is
     * minted or changed: the provider's answer only records that this person
     * signed in again, now. It must be the same person, and the provider must
     * say the sign-in happened within the last few minutes.
     *
     * @param array<string, mixed> $flow
     */
    private function completeReauth(array $flow, int $providerId, int $tenantId, \Whity\Sdk\Auth\ExternalIdentity $identity): Response
    {
        $profileId = (int) $flow['reauth_profile_id'];
        $signingTenantId = (int) ($flow['reauth_tenant_id'] ?? 0);
        $routeId = (int) ($flow['reauth_route_id'] ?? 0);
        $returnTo = (string) ($flow['reauth_return_to'] ?? '/');
        $base = rtrim(($this->frontendUrls ?? new \Whity\Core\Http\FrontendUrls(['APP_URL' => $this->appUrl]))->base(), '/');
        $back = static fn (string $outcome): Response => new Response(302, '', [
            'Location' => $base . $returnTo . '?signing=' . rawurlencode($outcome),
        ]);

        if ($this->identities === null || $this->signingChallenges === null || $signingTenantId <= 0 || $routeId <= 0) {
            return $back('sign_in_failed');
        }

        $link = $tenantId === 0
            ? $this->identities->findGlobalByIssuerSubject($identity->issuer, $identity->subject)
            : $this->identities->findByProviderSubject($providerId, $identity->subject);
        if ($link === null || (int) $link['profile_id'] !== $profileId) {
            return $back('sign_in_mismatch');
        }

        $authTime = $identity->claims['auth_time'] ?? null;
        $now = time();
        if (!is_int($authTime)
            || $authTime > $now + 60
            || $now - $authTime > \Whity\Core\Document\Signing\SigningChallengeRepository::FRESH_SIGN_IN_TTL
        ) {
            return $back('sign_in_not_fresh');
        }

        $this->signingChallenges->recordFreshSignIn($signingTenantId, $profileId, $routeId);

        return $back('signed_in');
    }

    /**
     * The workspace a `?join=<slug>` names, when it takes sign-ups and the
     * request's host does not name a different workspace. Null otherwise; the
     * caller answers every case the same way, so slugs can't be probed.
     */
    private function joinTenant(Request $request, string $slug): ?int
    {
        if ($this->signups === null || $this->tenantHosts === null
            || preg_match('/^[a-z0-9][a-z0-9-]{0,254}$/', $slug) !== 1
        ) {
            return null;
        }

        $tenantId = $this->tenantHosts->findIdBySlug($slug);
        if ($tenantId === null || $tenantId === 0) {
            return null;
        }

        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
        $hostTenant = $this->hostResolver->resolveTenantIdByHost($host);
        if ($hostTenant !== null && $hostTenant !== $tenantId) {
            return null;
        }

        return $this->signups->isOpen($tenantId) ? $tenantId : null;
    }

    /** Back to the workspace's sign-up page with an outcome the page explains. */
    private function joinOutcome(string $slug, string $outcome): Response
    {
        $base = $this->frontendLink(\Whity\Core\Http\FrontendUrls::SIGNUP_CONFIRM);
        // SIGNUP_CONFIRM is /join/confirm by default; its parent is /join.
        $join = (string) preg_replace('#/confirm/?$#', '', $base);

        return new Response(302, '', ['Location' => rtrim($join, '/') . '/' . rawurlencode($slug) . '?sso=' . rawurlencode($outcome)]);
    }

    private function frontendLink(string $link): string
    {
        $urls = $this->frontendUrls ?? new \Whity\Core\Http\FrontendUrls(['APP_URL' => $this->appUrl]);

        return $urls->linkOrPath($link);
    }

    /**
     * Instance SSO kill-switch (WC-28fb2e19): federated sign-in is available only
     * when the operator has left it enabled globally. Read fresh per request.
     */
    private function ssoEnabled(): bool
    {
        $global = $this->settings->getGlobal();
        return ($global[\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED] ?? 'true') === 'true';
    }

    /**
     * @param array<string, string> $params
     */
    public function start(Request $request, array $params): Response
    {
        if (!$this->ssoEnabled()) {
            return $this->fail('sso_disabled');
        }
        $providerKey = $this->providerKey($params);
        if ($providerKey === null) {
            return $this->fail('unknown_provider');
        }

        // WHIT-917: "Continue with …" on a workspace's sign-up page. The
        // workspace is checked here and then travels ONLY inside the signed
        // flow cookie, so the callback can't be pointed at another one. Joining
        // uses the operator's global-trust providers: a workspace's own
        // tenant-trust provider only ever signs in its existing members.
        // WHIT-922: signing in again to confirm a signature. Who is asking, and
        // where they come back to, travel only inside the signed flow cookie.
        $reauth = null;
        if ($this->query('reauth') === 'sign') {
            $reauth = $this->reauthTarget();
            if ($reauth === null) {
                return $this->fail('reauth_unavailable');
            }
        }

        $joinSlug = $reauth === null ? strtolower(trim((string) ($this->query('join') ?? ''))) : '';
        $joinTenantId = null;
        if ($joinSlug !== '') {
            $joinTenantId = $this->joinTenant($request, $joinSlug);
            if ($joinTenantId === null) {
                return $this->joinOutcome($joinSlug, 'closed');
            }
        }

        $tenantId = $joinTenantId !== null ? 0 : $this->resolveTenantId($request);
        $config = $this->providers->findEnabledByProviderKey($tenantId, $providerKey);
        if ($config === null) {
            // Not configured for this tenant → generic bounce (no enumeration).
            return $joinTenantId !== null
                ? $this->joinOutcome($joinSlug, 'provider_unavailable')
                : $this->fail('provider_unavailable');
        }

        // A refused or unreachable issuer can throw rather than return null;
        // either way the visitor is told the provider is unavailable, not a 500.
        try {
            $discovery = $this->engine->discover($this->discoveryUrl($config));
        } catch (\Throwable $e) {
            error_log('[sso] discovery failed for provider ' . (int) $config['id'] . ': ' . $e->getMessage());
            $discovery = null;
        }
        if ($discovery === null) {
            return $joinTenantId !== null
                ? $this->joinOutcome($joinSlug, 'provider_unavailable')
                : $this->fail('provider_unavailable');
        }

        $provider = $this->providerFor($config);
        $state = $this->engine->randomToken();
        $nonce = $this->engine->randomToken();
        $pkce  = $this->engine->generatePkce();
        $redirectUri = $this->redirectUri($providerKey);

        // Flow state → a signed, short-lived cookie (HttpOnly/Lax/Secure). Binding
        // the state inside the signed cookie to the state echoed by the provider is
        // the CSRF/replay defense; the verifier never leaves the server unencrypted
        // to the provider (only the S256 challenge does).
        $flowToken = $this->jwtParser->create([
            'state'         => $state,
            'nonce'         => $nonce,
            'code_verifier' => $pkce['verifier'],
            'provider_id'   => (int) $config['id'],
            'tenant_id'     => $tenantId,
            'provider_key'  => $providerKey,
            'join_tenant_id' => $joinTenantId,
            'join_slug'     => $joinTenantId !== null ? $joinSlug : null,
            'reauth_profile_id' => $reauth['profile_id'] ?? null,
            'reauth_tenant_id'  => $reauth['tenant_id'] ?? null,
            'reauth_return_to'  => $reauth['return_to'] ?? null,
            'reauth_route_id'   => $reauth['route_id'] ?? null,
        ], 600, 'oidc_flow');
        CookieManager::setSsoFlowToken($flowToken, 600);

        $authorizeUrl = $this->engine->buildAuthorizationUrl(
            $provider,
            $discovery,
            (string) $config['client_id'],
            $redirectUri,
            $state,
            $nonce,
            $pkce['challenge'],
            // A fresh sign-in: the provider asks again even when it remembers
            // the person, and reports when they signed in.
            $reauth !== null ? ['prompt' => 'login', 'max_age' => '0'] : [],
        );

        return new Response(302, '', ['Location' => $authorizeUrl]);
    }

    /**
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params): Response
    {
        if (!$this->ssoEnabled()) {
            return $this->fail('sso_disabled');
        }
        $providerKey = $this->providerKey($params);
        if ($providerKey === null) {
            return $this->fail('unknown_provider');
        }

        // The flow-state cookie is single-use — read then clear immediately.
        $flowCookie = CookieManager::getSsoFlowToken();
        CookieManager::clearSsoFlowToken();
        if ($flowCookie === null) {
            return $this->fail('expired');
        }

        $flow = $this->jwtParser->parse($flowCookie);
        if ($flow === null || ($flow['type'] ?? null) !== 'oidc_flow') {
            return $this->fail('expired');
        }

        // The provider may signal an error (user denied consent, etc.).
        if ($this->query('error') !== null) {
            return $this->fail('denied');
        }

        // CSRF/replay: the echoed state MUST equal the state minted into the cookie.
        $stateParam = $this->query('state');
        if (!is_string($flow['state'] ?? null) || !is_string($stateParam)
            || !hash_equals((string) $flow['state'], $stateParam)
        ) {
            return $this->fail('state_mismatch');
        }

        $code = $this->query('code');
        if ($code === null || $code === '') {
            return $this->fail('failed');
        }

        // Re-load the provider config by the id + tenant captured at /start (never
        // re-resolved from the callback host, so a different host can't swap config).
        $providerId = (int) ($flow['provider_id'] ?? 0);
        $tenantId   = (int) ($flow['tenant_id'] ?? -1);
        $config = $this->providers->findById($providerId, $tenantId);
        if ($config === null || (string) $config['provider_key'] !== $providerKey) {
            return $this->fail('provider_unavailable');
        }

        $discovery = $this->engine->discover($this->discoveryUrl($config));
        if ($discovery === null) {
            return $this->fail('provider_unavailable');
        }

        $ciphertext = $this->providers->findClientSecretCiphertext($providerId, $tenantId);
        $clientSecret = null;
        if ($ciphertext !== null) {
            try {
                $clientSecret = $this->secrets->decrypt($ciphertext);
            } catch (\Throwable $e) {
                error_log('[sso] client-secret decrypt failed for provider ' . $providerId);
                return $this->fail('provider_unavailable');
            }
        }

        $tokens = $this->engine->exchangeCode(
            $discovery,
            (string) $config['client_id'],
            $clientSecret,
            $code,
            $this->redirectUri($providerKey),
            is_string($flow['code_verifier'] ?? null) ? (string) $flow['code_verifier'] : '',
        );
        $idToken = is_array($tokens) && isset($tokens['id_token']) && is_string($tokens['id_token'])
            ? $tokens['id_token'] : null;
        if ($idToken === null) {
            return $this->fail('failed');
        }

        $nonce = is_string($flow['nonce'] ?? null) ? (string) $flow['nonce'] : null;
        $identity = $this->engine->verifyIdToken(
            $idToken,
            $discovery,
            (string) $config['client_id'],
            (string) $config['issuer'],
            $nonce,
            $this->providerFor($config),
        );
        if ($identity === null) {
            return $this->fail('failed');
        }

        // WHIT-922: a sign-in made to confirm a signature. Read from the signed
        // flow cookie only; it never mints a session.
        if (isset($flow['reauth_profile_id']) && is_int($flow['reauth_profile_id'])) {
            return $this->completeReauth($flow, $providerId, $tenantId, $identity);
        }

        // WHIT-917: a join started on a workspace's sign-up page. Read from the
        // signed flow cookie only, never from this request.
        $joinTenantId = isset($flow['join_tenant_id']) && is_int($flow['join_tenant_id']) ? $flow['join_tenant_id'] : null;
        $joinSlug = isset($flow['join_slug']) && is_string($flow['join_slug']) ? $flow['join_slug'] : '';
        if ($joinTenantId !== null) {
            if ($this->signups === null || $tenantId !== 0) {
                return $this->joinOutcome($joinSlug, 'closed');
            }
            // The provider's verified address is what the workspace's domain
            // list is checked against, and what stands in for the emailed link.
            if (!$identity->hasVerifiedEmail()) {
                return $this->joinOutcome($joinSlug, 'email_unverified');
            }
        }

        // Resolve the verified identity to a local profile (WC-f3b17bd2), applying
        // the provider's TRUST TIER: a system-tenant (operator) provider is
        // global-trust; any other tenant's provider is tenant-trust and may only
        // reach its own active members. See FederatedIdentityLinker.
        $ctx = new FederatedProviderContext(
            $providerId,
            $providerKey,
            $tenantId,
        );
        $resolution = $this->linker->resolveForLogin($identity, $ctx);
        switch ($resolution['status']) {
            case 'refused_unverified':
                return $joinTenantId !== null ? $this->joinOutcome($joinSlug, 'email_unverified') : $this->fail('email_unverified');
            case 'refused_conflict':
                return $joinTenantId !== null ? $this->joinOutcome($joinSlug, 'link_conflict') : $this->fail('link_conflict');
            case 'refused_no_account':
                // Tenant-trust IdP with no reachable local account in this tenant.
                return $this->fail('no_account');
        }

        // Non-refused statuses (existing/linked/provisioned) always carry a profile_id.
        $profileId = $resolution['profile_id'] ?? null;
        if ($profileId === null) {
            return $joinTenantId !== null ? $this->joinOutcome($joinSlug, 'failed') : $this->fail('failed');
        }

        if ($joinTenantId !== null) {
            return $this->completeJoin($request, $joinTenantId, $joinSlug, $profileId, $identity, $ctx, $providerKey);
        }
        // last_login_at is stamped inside the linker on the resolved link.
        //
        // NOTE: domain-claim JIT membership (auto-join a tenant whose verified
        // email domain a person's address matches) IS handled inside the linker's
        // tenant-trust path — but ONLY when the tenant's `tenant_email_domains`
        // claim is DNS-ownership-VERIFIED (WC-628738f5) and has auto_provision on.
        // A self-asserted, unverified claim never onboards (the cross-tenant
        // harvesting guard). Tenant-trust also onboarding via explicit invites,
        // JIT-accepted in FederatedIdentityLinker. The JIT-assigned role is
        // validated to belong to the tenant (or be global) before it is granted.

        $email = $this->loginEmailFor($profileId, $identity->normalizedEmail());

        if ($this->hooks !== null) {
            try {
                $this->hooks->dispatch(self::SIGNED_IN_EVENT, [
                    'profile_id'            => $profileId,
                    'email'                 => $email,
                    'provider_key'          => $providerKey,
                    'issuer'                => $identity->issuer,
                    'subject'               => $identity->subject,
                    'global_trust'          => $ctx->isGlobalTrust(),
                    'provider_tenant_id'    => $tenantId,
                    // WHIT-994: a sign-in never creates a workspace any more;
                    // the "Create your workspace" step does, afterwards.
                    'provisioned_tenant_id' => null,
                ]);
            } catch (\Throwable $e) {
                error_log('[sso] ' . self::SIGNED_IN_EVENT . ' listener failed for profile ' . $profileId . ': ' . $e->getMessage());
            }
        }

        // Mint the session (sets cookies as a side effect). Global-trust logs the
        // person into their own memberships (per-person identity, ADR 0005);
        // tenant-trust CONFINES the session to the configuring tenant so a
        // bring-your-own IdP cannot mint a session into another of the person's
        // tenants. Translate the JSON result into a browser redirect.
        $result = $this->auth->completeFederatedLogin(
            $profileId,
            $email,
            $request,
            $ctx->isGlobalTrust() ? null : $tenantId,
        );
        return $this->redirectForLoginResult($result, $profileId, $email, $ctx);
    }

    /**
     * Finish a join started on a workspace's sign-up page (WHIT-917): apply the
     * workspace's sign-up rules to the person the provider just verified, then
     * either sign them into that workspace or send them back to its sign-up
     * page to say what happened. Never creates a workspace: the self-service
     * workspace step of an ordinary sign-in does not run here.
     */
    private function completeJoin(
        Request $request,
        int $joinTenantId,
        string $joinSlug,
        int $profileId,
        \Whity\Sdk\Auth\ExternalIdentity $identity,
        FederatedProviderContext $ctx,
        string $providerKey,
    ): Response {
        if ($this->signups === null) {
            return $this->joinOutcome($joinSlug, 'closed');
        }

        try {
            $joined = $this->signups->joinVerified(
                $joinTenantId,
                $profileId,
                (string) $identity->normalizedEmail(),
                (string) ($identity->displayName ?? ''),
            );
        } catch (\Throwable $e) {
            error_log('[sso] join failed for profile ' . $profileId . ': ' . $e->getMessage());

            return $this->joinOutcome($joinSlug, 'failed');
        }

        $result = $joined['result'];
        if ($result !== \Whity\Core\Signup\CustomerSignupService::CONFIRM_JOINED
            && $result !== \Whity\Core\Signup\CustomerSignupService::CONFIRM_ALREADY_MEMBER
        ) {
            return $this->joinOutcome($joinSlug, match ($result) {
                \Whity\Core\Signup\CustomerSignupService::CONFIRM_PENDING => 'pending',
                \Whity\Core\Signup\CustomerSignupService::CONFIRM_DOMAIN => 'domain',
                \Whity\Core\Signup\CustomerSignupService::CONFIRM_REFUSED => 'refused',
                default => 'closed',
            });
        }

        $email = $this->loginEmailFor($profileId, $identity->normalizedEmail());

        if ($this->hooks !== null) {
            try {
                $this->hooks->dispatch(self::SIGNED_IN_EVENT, [
                    'profile_id'            => $profileId,
                    'email'                 => $email,
                    'provider_key'          => $providerKey,
                    'issuer'                => $identity->issuer,
                    'subject'               => $identity->subject,
                    'global_trust'          => $ctx->isGlobalTrust(),
                    'provider_tenant_id'    => $ctx->tenantId,
                    'provisioned_tenant_id' => null,
                ]);
            } catch (\Throwable $e) {
                error_log('[sso] ' . self::SIGNED_IN_EVENT . ' listener failed for profile ' . $profileId . ': ' . $e->getMessage());
            }
        }

        // Straight into the workspace they joined.
        $session = $this->auth->completeFederatedLogin($profileId, $email, $request, $joinTenantId);

        return $session->getStatusCode() === 200
            ? new Response(302, '', ['Location' => $this->frontendLink(\Whity\Core\Http\FrontendUrls::SSO_SUCCESS)])
            : $this->joinOutcome($joinSlug, 'failed');
    }

    /**
     * PUBLIC: the ENABLED SSO providers for the current tenant, for the login
     * screen to render "Sign in with …" buttons. Resolves the tenant by host (as
     * /start does) and returns only display-safe fields — provider_key +
     * display_name — NEVER client_id/secret/issuer. Returns an empty list when SSO
     * is globally disabled, so the login UI shows no buttons.
     */
    public function providers(Request $request): Response
    {
        if (!$this->ssoEnabled()) {
            return Response::json(['data' => []]);
        }

        $tenantId = $this->resolveTenantId($request);
        $out = [];
        foreach ($this->providers->listForTenant($tenantId) as $row) {
            if (($row['enabled'] ?? false) === true) {
                $out[] = [
                    'provider_key' => (string) $row['provider_key'],
                    'display_name' => (string) $row['display_name'],
                ];
            }
        }

        return Response::json(['data' => $out]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    private function providerKey(array $params): ?string
    {
        $key = strtolower(trim($params['provider'] ?? ''));
        return preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) === 1 ? $key : null;
    }

    /**
     * Resolve the tenant whose IdP config drives this /start, from the request
     * host. An unresolved host falls back to the system tenant (0) — this is
     * BY DESIGN under tiered trust: tenant 0 is the operator's GLOBAL-TRUST tier,
     * reached via the default/apex host (e.g. "Sign in with Google" configured by
     * the deployment operator). If tenant 0 has no such provider, the lookup still
     * fails closed (findEnabledByProviderKey(0, …) → null → `provider_unavailable`),
     * so the fall-through can only ever reach the operator's own configuration,
     * never another tenant's.
     */
    private function resolveTenantId(Request $request): int
    {
        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function discoveryUrl(array $config): string
    {
        $discovery = isset($config['discovery_url']) ? (string) $config['discovery_url'] : '';
        if ($discovery !== '') {
            return $discovery;
        }
        return rtrim((string) $config['issuer'], '/') . '/.well-known/openid-configuration';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function providerFor(array $config): StandardOidcProvider
    {
        $scopes = array_values(array_filter(explode(' ', (string) $config['scopes']), static fn(string $s): bool => $s !== ''));
        if ($scopes === []) {
            $scopes = ['openid', 'email', 'profile'];
        }
        return new StandardOidcProvider(
            (string) $config['provider_key'],
            (string) $config['display_name'],
            $scopes,
        );
    }

    private function redirectUri(string $providerKey): string
    {
        return rtrim($this->appUrl, '/') . '/api/v1/auth/sso/' . $providerKey . '/callback';
    }

    /**
     * Read an OIDC callback query parameter. The framework does not model query
     * strings on {@see Request} (routing uses the path only), so the provider's
     * ?code/?state/?error come from $_GET, which FrankenPHP populates per request.
     */
    private function query(string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * The email to stamp on the session: prefer the profile's primary address,
     * fall back to the IdP-asserted one.
     */
    private function loginEmailFor(int $profileId, ?string $identityEmail): string
    {
        $primary = $this->emails->findPrimaryForProfile($profileId);
        if ($primary !== null && isset($primary['email'])) {
            return (string) $primary['email'];
        }
        return $identityEmail ?? '';
    }

    /**
     * Translate completeFederatedLogin's JSON result into a browser 302. Session
     * cookies were already emitted as a side effect of the login; here we only
     * choose the destination.
     */
    private function redirectForLoginResult(Response $result, int $profileId, string $email, FederatedProviderContext $ctx): Response
    {
        $body = json_decode($result->getBody(), true);
        $data = is_array($body) ? $body : [];

        if ($result->getStatusCode() === 200 && isset($data['user'])) {
            return new Response(302, '', ['Location' => $this->frontendLink(\Whity\Core\Http\FrontendUrls::SSO_SUCCESS)]);
        }
        if ($result->getStatusCode() === 200 && ($data['requires_tenant_selection'] ?? false) === true) {
            // The tenant-selection cookie is set; the login UI completes the choice.
            return new Response(302, '', ['Location' => $this->frontendLink(\Whity\Core\Http\FrontendUrls::SSO_LOGIN) . '?sso=select']);
        }
        if ($result->getStatusCode() === 403 && $ctx->isGlobalTrust()) {
            return $this->noWorkspace($profileId, $email, $ctx);
        }
        // No active membership, or anything unexpected.
        return $this->fail('no_membership');
    }

    /**
     * Somebody the operator's own provider signed in who has no active
     * membership (WHIT-994). Rather than a `no_membership` error code, they
     * land on a page that either invites them to create a workspace, tells
     * them a place is waiting for them, or says plainly that they need an
     * invitation, naming the address they signed in with.
     *
     * What they hold there is NOT a session: a short-lived signed cookie
     * naming the profile, the address and the provider, good only for
     * GET/POST /api/auth/sso/workspace, which re-checks every gate.
     *
     * A deactivated account, a tenant's own provider, or a composition with
     * no provisioner keeps the old generic bounce: nothing is offered.
     */
    private function noWorkspace(int $profileId, string $email, FederatedProviderContext $ctx): Response
    {
        if ($this->workspaces === null
            || $this->workspaces->stateFor($profileId, $ctx) === \Whity\Core\Tenant\FederatedWorkspaceProvisioner::STATE_REFUSED
        ) {
            return $this->fail('no_membership');
        }

        $token = $this->jwtParser->create([
            'profile_id'  => $profileId,
            'email'       => $email,
            'provider_id' => $ctx->providerId,
        ], SsoWorkspaceSetupHandler::TOKEN_TTL, SsoWorkspaceSetupHandler::TOKEN_TYPE);
        CookieManager::setWorkspaceSetupToken($token, SsoWorkspaceSetupHandler::TOKEN_TTL);

        return new Response(302, '', ['Location' => $this->frontendLink(\Whity\Core\Http\FrontendUrls::SSO_NO_WORKSPACE)]);
    }

    /** Bounce to the login page with a generic, non-enumerating SSO marker. */
    private function fail(string $reason): Response
    {
        return new Response(302, '', ['Location' => $this->frontendLink(\Whity\Core\Http\FrontendUrls::SSO_LOGIN) . '?sso_error=' . $reason]);
    }
}
