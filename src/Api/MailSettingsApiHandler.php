<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Http\HttpClient;
use Whity\Core\Mail\OAuth\SmtpOAuthStore;
use Whity\Core\Mail\OAuth\SmtpOAuthTokenManager;
use Whity\Core\Mail\MailException;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Email settings API (WC-email) — the operator-only surface backing the admin
 * "Email" settings page. It complements the generic settings endpoints (which
 * carry the plaintext `mail.*` config) with the two things those cannot express:
 *
 *  - GET  /api/v1/settings/mail/status        (settings:manage, system tenant) —
 *        the current transport plus whether an SMTP password is stored. The
 *        password itself is write-only and is NEVER returned.
 *  - PUT  /api/v1/settings/mail/smtp-password (settings:manage, system tenant) —
 *        set (encrypt-at-rest) or clear the SMTP password. Body `{ "password": ... }`;
 *        null/empty clears it.
 *  - PUT  /api/v1/settings/mail/oauth-credentials (settings:manage, system tenant) —
 *        set or clear the OAuth2 client secret and refresh token (WHIT-901). Same
 *        rules as the password: encrypted at rest, write-only, 204 on success.
 *  - POST /api/v1/settings/mail/test          (settings:manage, system tenant) —
 *        send a one-off test message to a given address using the CURRENTLY
 *        configured transport, so the operator can verify SMTP end-to-end.
 *
 * Like {@see SettingsApiHandler}, global mail config is a SYSTEM-TENANT resource:
 * `settings:manage` is necessary but not sufficient — the caller must also be
 * acting in the system tenant (id 0), so a self-service tenant owner can never
 * reach instance-wide mail settings (cross-tenant escalation guard, WC-235).
 *
 * Issues no SQL directly except through {@see GlobalSettingsRepository} for the
 * out-of-registry encrypted-password key. Holds no request state — safe for a
 * FrankenPHP worker.
 */
final class MailSettingsApiHandler
{
    /** Well above any real provider token, and a bound on what gets encrypted. */
    private const MAX_OAUTH_SECRET_BYTES = 8192;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
        private readonly RoleChecker $roleChecker,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLoggerInterface $audit = null,
        private readonly ?HttpClient $tokenHttp = null,
    ) {
    }

    /**
     * GET /api/v1/settings/mail/status — transport + whether a password is stored.
     */
    public function status(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        try {
            $global = $this->settings->getGlobal();

            return Response::json([
                'data' => [
                    'transport' => $global[SettingsRegistry::MAIL_TRANSPORT] ?? 'none',
                    'has_smtp_password' => $this->hasStoredPassword(),
                    'auth_mode' => $global[SettingsRegistry::MAIL_SMTP_AUTH_MODE] ?? MailerFactory::AUTH_MODE_PASSWORD,
                    'oauth' => $this->oauthTokens()->describe(
                        trim((string) ($global[SettingsRegistry::MAIL_SMTP_HOST] ?? '')),
                        (string) ($global[SettingsRegistry::MAIL_SMTP_ENCRYPTION] ?? 'tls'),
                    ),
                ],
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('[MailSettingsApiHandler] status failed: ' . $e->getMessage());
            return Response::error('Failed to read mail status', 500);
        }
    }

    /**
     * PUT /api/v1/settings/mail/smtp-password — set or clear the SMTP password.
     *
     * Body: `{ "password": "<secret>" | null }`. A null or empty value clears the
     * stored password; a non-empty value is encrypted at rest and stored. The
     * value is never echoed back — success is 204 No Content, and clients re-read
     * `has_smtp_password` from GET /mail/status.
     */
    public function setPassword(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        if (!array_key_exists('password', $body)) {
            return Response::error('Request body must include a "password" field (null to clear)', 400);
        }
        $password = $body['password'];

        if ($password !== null && !is_string($password)) {
            return Response::error('Validation failed', 422, ['password' => 'password must be a string or null.']);
        }

        try {
            if ($password === null || $password === '') {
                $this->globals->delete(MailerFactory::SMTP_PASSWORD_SETTING_KEY);
            } else {
                $this->globals->set(
                    MailerFactory::SMTP_PASSWORD_SETTING_KEY,
                    $this->secrets->encrypt($password)
                );
            }

            // 204 No Content: the password is write-only, so there is nothing to
            // return. Clients re-read has_smtp_password from GET /mail/status.
            return Response::json([], 204);
        } catch (\Throwable $e) {
            // Never log the password or crypto detail.
            $this->logger->error('[MailSettingsApiHandler] setPassword failed: ' . $e->getMessage());
            return Response::error('Failed to store SMTP password', 500);
        }
    }

    /**
     * PUT /api/v1/settings/mail/oauth-credentials — set or clear the OAuth2 secrets.
     *
     * Body: `{ "client_secret": "<secret>" | null, "refresh_token": "<token>" | null }`.
     * A field left out stays as it is, and null or empty clears it. Values are never
     * echoed, logged or audited: the audit entry names which fields changed. The
     * provider and client ID are ordinary settings on PATCH /settings/global.
     */
    public function setOAuthCredentials(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        $changes = [];
        $errors = [];
        foreach (['client_secret', 'refresh_token'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value !== null && (!is_string($value) || strlen($value) > self::MAX_OAUTH_SECRET_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)
            ) {
                $errors[$field] = $field . ' must be a single line of text or null.';
                continue;
            }
            $changes[$field] = is_string($value) ? trim($value) : '';
        }
        if ($errors !== []) {
            return Response::error('Validation failed', 422, $errors);
        }
        if ($changes === []) {
            return Response::error('Request body must include "client_secret" or "refresh_token" (null to clear)', 400);
        }

        try {
            $store = new SmtpOAuthStore($this->globals, $this->secrets);
            $summary = [];
            foreach ($changes as $field => $value) {
                $summary[] = ($field === 'client_secret' ? 'client secret ' : 'refresh token ')
                    . ($value === '' ? 'cleared' : 'set');
                match (true) {
                    $field === 'client_secret' && $value === '' => $store->clearClientSecret(),
                    $field === 'client_secret' => $store->setClientSecret($value),
                    $value === '' => $store->clearRefreshToken(),
                    default => $store->setRefreshToken($value),
                };
            }
            $store->forgetDerivedState();

            $this->audit?->record('settings.mail_oauth_credentials_changed', [
                'tenant_id' => $auth['tenantId'],
                'actor_user_id' => $auth['userId'],
                // Under one plain key: the audit writer rightly drops any key with
                // "secret" or "token" in its name, which would leave this empty.
                'metadata' => ['changed' => implode(', ', $summary)],
            ]);

            return Response::json([], 204);
        } catch (\Throwable $e) {
            // The class alone: the message of a crypto or database failure is not
            // guaranteed free of what was being written.
            $this->logger->error('[MailSettingsApiHandler] setOAuthCredentials failed: ' . $e::class);
            return Response::error('Failed to store the OAuth credentials', 500);
        }
    }

    /**
     * POST /api/v1/settings/mail/test — send a test message via the current transport.
     *
     * Body: `{ "to": "<email>" }`. Builds the mailer from the CURRENT settings and
     * attempts one send. Returns 422 when email is not configured (no transport /
     * incomplete SMTP), 502 on a transport failure (with a safe message), 200 on
     * success.
     */
    public function test(Request $request): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $body = JsonBody::parsed($request);
        $to = $body['to'] ?? null;
        if (!is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('Validation failed', 422, ['to' => 'to must be a valid email address.']);
        }

        // A person pressing "send test" may have just fixed something on the
        // provider's side, so it asks the provider again rather than waiting out
        // the pause on a recorded failure.
        $this->oauthTokens()->clearFailure();

        $mailer = MailerFactory::fromSettings(
            $this->settings,
            $this->globals,
            $this->secrets,
            $this->logger,
            $this->tokenHttp,
        );

        // A NullMailer means email is disabled or the SMTP config is incomplete —
        // "sending" would silently no-op, which is a misleading test result.
        if ($mailer instanceof NullMailer) {
            return Response::error(
                'Email is not configured: set a transport and complete the SMTP settings first',
                422
            );
        }

        $siteName = $this->settings->getGlobal()[SettingsRegistry::SITE_NAME] ?? 'Whity';

        try {
            $mailer->send(
                $to,
                sprintf('%s: test email', $siteName),
                "This is a test message from your Whity instance.\r\n\r\n"
                . "If you received it, your email settings are working."
            );

            return Response::json(['data' => ['sent' => true]], 200);
        } catch (MailException $e) {
            // The exact transport detail goes to the SERVER log; the client gets a
            // curated, safe hint (never raw exception text — WC-186) so the
            // operator who clicked "send test" can act without reading logs.
            $this->logger->warning('[MailSettingsApiHandler] test send failed: ' . $e->getMessage());
            return Response::error('Test email failed: ' . $e->publicHint(), 502);
        } catch (\Throwable $e) {
            $this->logger->error('[MailSettingsApiHandler] test send error: ' . $e->getMessage());
            return Response::error('Test email failed', 502);
        }
    }

    private function oauthTokens(): SmtpOAuthTokenManager
    {
        return MailerFactory::oauthTokens($this->settings, $this->globals, $this->secrets, $this->logger, $this->tokenHttp);
    }

    /**
     * Whether a non-empty encrypted SMTP password is stored.
     */
    private function hasStoredPassword(): bool
    {
        $stored = $this->globals->get(MailerFactory::SMTP_PASSWORD_SETTING_KEY);

        return $stored !== null && $stored !== '';
    }

    /**
     * Resolve the tenant + acting user, require `settings:manage`, and enforce the
     * system-tenant constraint (global mail config is instance-wide).
     *
     * @return array{tenantId: int, userId: int}|Response Context, or a 403 Response.
     */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SETTINGS_MANAGE, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SETTINGS_MANAGE]);
        }

        if ($tenantId !== SettingsService::SYSTEM_TENANT_ID) {
            return Response::error('Mail settings are managed by the system tenant only', 403);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
