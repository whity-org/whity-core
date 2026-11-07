<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\PasswordPolicy;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Signup\CustomerSignupMailer;
use Whity\Core\Signup\CustomerSignupService;
use Whity\Core\Signup\WorkspaceSignupSettings;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * Public, unauthenticated side of workspace customer sign-up (WHIT-915).
 *
 *   GET  /api/v1/public/signup/workspaces/{workspace} — is sign-up open here, and how?
 *   POST /api/v1/public/signup/workspaces/{workspace} — ask to join (mails a link)
 *   GET  /api/v1/public/signup/confirm?token=…       — what the link is for
 *   POST /api/v1/public/signup/confirm               — use the link
 *
 * WHICH WORKSPACE. `{workspace}` is the workspace's slug. When the request's
 * host names a workspace too (its custom domain or its slug subdomain, the
 * resolution branding and public pages use), the two must agree; otherwise the
 * answer is the same 404 as for an unknown slug. The tenant is never taken from
 * the body, and after the request it is read from the stored row only.
 *
 * NO ENUMERATION. The request endpoint answers 202 with the same body for every
 * address it accepts, and does the same work: it never looks up a profile or a
 * membership. Whether the address has an account, already belongs here, or was
 * rejected before is decided after the link is used, and shown only to the
 * mailbox holder. An unknown workspace and a workspace with sign-up off answer
 * the same 404. Limits are counted before anything is examined.
 *
 * Turnstile: the instance's public-form bot check (Settings → Public forms). With
 * no provider configured, sign-up is refused unless the operator has allowed
 * public forms without a bot check, the same rule public forms follow.
 */
final class PublicSignupApiHandler
{
    private const WINDOW_SECONDS = 3600;
    private const IP_REQUEST_MAX = 10;
    private const EMAIL_REQUEST_MAX = 3;
    private const TENANT_REQUEST_MAX = 300;
    private const IP_CONFIRM_MAX = 30;
    private const IP_INFO_MAX = 120;

    private const SYSTEM_TENANT_ID = 0;

    private const NOT_OPEN = 'Sign-up is not open for this workspace';
    private const LINK_INVALID = 'This link is invalid, has expired, or has already been used';
    private const ACCEPTED = 'Check your email. If this address can join, we have sent a link to finish signing up.';

    public function __construct(
        private readonly CustomerSignupService $service,
        private readonly WorkspaceSignupSettings $settings,
        private readonly TenantHostRepository $tenants,
        private readonly HostResolver $hosts,
        private readonly BotCheck $bot,
        private readonly CustomerSignupMailer $mailer,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
        /** The sign-in providers a visitor may join with (WHIT-917); null lists none. */
        private readonly ?\Whity\Core\Signup\JoinProviders $joinProviders = null,
    ) {
    }

    /**
     * Whether the email form can work on this instance: a bot check is set up,
     * or the operator chose to go without. Answered up front so a visitor is
     * not asked to fill the form in first (WHIT-917).
     */
    private function emailSignupReady(): bool
    {
        return $this->bot->isRequired() ? $this->bot->isReady() : $this->bot->allowsNoBotCheck();
    }

    /** @return list<array{provider_key: string, display_name: string}> */
    private function joinProviders(): array
    {
        return $this->joinProviders?->list() ?? [];
    }

    /** @param array<string, mixed> $params */
    public function info(Request $request, array $params = []): Response
    {
        if (($limited = $this->limit('signup:info:ip:', ClientIp::fromRequest($request), self::IP_INFO_MAX)) !== null) {
            return $limited;
        }

        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_OPEN, 404);
        }

        return Response::json(['data' => [
            'workspace_name' => $workspace['name'],
            'workspace_slug' => $workspace['slug'],
            'approval_required' => $workspace['settings']['mode'] === WorkspaceSignupSettings::MODE_APPROVAL,
            'allowed_domains' => $workspace['settings']['allowed_domains'],
            'bot_check' => $this->bot->provider() !== null
                ? ['provider' => $this->bot->provider(), 'site_key' => $this->bot->siteKey()]
                : null,
            // WHIT-917: whether the email form can work here, said up front.
            'email_signup' => $this->emailSignupReady(),
            'email_signup_reason' => $this->emailSignupReady() ? null : 'bot_check_unavailable',
            'providers' => $this->joinProviders(),
        ]], 200);
    }

    /** @param array<string, mixed> $params */
    public function request(Request $request, array $params = []): Response
    {
        $ip = ClientIp::fromRequest($request);
        if (($limited = $this->limit('signup:request:ip:', $ip, self::IP_REQUEST_MAX)) !== null) {
            return $limited;
        }

        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_OPEN, 404);
        }
        $tenantId = $workspace['id'];

        if (($limited = $this->limit('signup:request:tenant:', (string) $tenantId, self::TENANT_REQUEST_MAX)) !== null) {
            return $limited;
        }

        $body = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $name = trim((string) ($body['display_name'] ?? ''));
        $botToken = (string) ($body['bot_token'] ?? '');

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('Enter a valid email address', 422, ['email' => 'invalid']);
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return Response::error('Enter your name (up to 120 characters)', 422, ['display_name' => 'invalid']);
        }

        // Bot check before anything is written or mailed. A bot check that is
        // not set up is a configuration state, not an outage: 409 with a code
        // the page shows as "not taking sign-ups yet" (WHIT-917). Only a
        // provider that can't be reached is a 503 "try later".
        if (!$this->emailSignupReady()) {
            error_log('[customer-signup] no bot check is set up and the operator has not allowed going without; email sign-up refused');

            return Response::error('This workspace is not taking sign-ups yet', 409, ['code' => 'email_signup_unavailable']);
        }
        if ($this->bot->isRequired()) {
            $verdict = $this->bot->verify($botToken, $ip);
            if ($verdict === BotCheck::UNREACHABLE) {
                return Response::error('Sign-up is unavailable right now. Try again later.', 503);
            }
            if ($verdict !== BotCheck::PASSED) {
                return Response::error('We couldn\'t confirm you are a person. Try again.', 422, ['bot_token' => 'failed']);
            }
        }

        // Says nothing about the address beyond what the workspace publishes:
        // its domain list is public on the info endpoint already.
        if (!WorkspaceSignupSettings::emailAllowed($email, $workspace['settings']['allowed_domains'])) {
            return Response::error('This workspace only accepts sign-ups from some email domains', 422, ['email' => 'domain']);
        }

        // Per address, after validation so it cannot be filled with junk. Over
        // the limit the answer is the same 202 and nothing is sent, so the
        // limit cannot be used to learn anything either.
        $emailKey = 'signup:request:email:' . $tenantId . ':' . hash('sha256', $email);
        if ($this->store->count($emailKey) >= self::EMAIL_REQUEST_MAX) {
            return $this->accepted();
        }
        $this->store->increment($emailKey, self::WINDOW_SECONDS);

        try {
            $token = $this->service->request($tenantId, $email, $name);
        } catch (\Throwable $e) {
            error_log('[customer-signup] request failed: ' . $e->getMessage());

            return Response::error('Sign-up failed. Try again later.', 500);
        }

        try {
            $this->mailer->send($email, $workspace['name'], $token);
        } catch (\Throwable $e) {
            error_log('[customer-signup] mail failed: ' . $e->getMessage());
        }

        $this->audit->record('tenant.signup.requested', [
            'tenant_id' => $tenantId,
            'target_type' => 'customer_signup',
            'ip_address' => $ip,
        ]);

        return $this->accepted();
    }

    public function preview(Request $request): Response
    {
        if (($limited = $this->limit('signup:confirm:ip:', ClientIp::fromRequest($request), self::IP_CONFIRM_MAX)) !== null) {
            return $limited;
        }

        try {
            $preview = $this->service->preview($this->tokenFromQuery($request));
        } catch (\Throwable $e) {
            error_log('[customer-signup] preview failed: ' . $e->getMessage());

            return Response::error('Failed to read the link', 500);
        }

        if ($preview === null) {
            return Response::error(self::LINK_INVALID, 404);
        }

        return Response::json(['data' => [
            'workspace_name' => $preview['workspace_name'],
            'email' => $preview['email'],
            'requires_password' => $preview['requires_password'],
        ]], 200);
    }

    public function confirm(Request $request): Response
    {
        $ip = ClientIp::fromRequest($request);
        if (($limited = $this->limit('signup:confirm:ip:', $ip, self::IP_CONFIRM_MAX)) !== null) {
            return $limited;
        }

        $body = JsonBody::parsed($request);
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($token === '') {
            return Response::error('A token is required', 422);
        }

        $passwordHash = null;
        if ($password !== '') {
            try {
                PasswordPolicy::validate($password);
            } catch (\InvalidArgumentException) {
                return Response::error('Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters', 422, ['password' => 'too_short']);
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        try {
            $result = $this->service->confirm($token, $passwordHash);
        } catch (\Throwable $e) {
            error_log('[customer-signup] confirm failed: ' . $e->getMessage());

            return Response::error('Sign-up failed. Try again later.', 500);
        }

        $tenantId = $result['tenant_id'] ?? self::SYSTEM_TENANT_ID;

        return match ($result['result']) {
            CustomerSignupService::CONFIRM_JOINED,
            CustomerSignupService::CONFIRM_PENDING,
            CustomerSignupService::CONFIRM_ALREADY_MEMBER => $this->confirmed($result['result'], $tenantId, $result['profile_id'], $ip),

            CustomerSignupService::CONFIRM_PASSWORD_REQUIRED => Response::error(
                'Choose a password to finish signing up',
                422,
                ['password' => 'required']
            ),

            CustomerSignupService::CONFIRM_CLOSED => Response::error(self::NOT_OPEN, 409, ['reason' => 'closed']),

            CustomerSignupService::CONFIRM_REFUSED => Response::error(
                'You can\'t join this workspace with this link. Contact the workspace if you think this is wrong.',
                409,
                ['reason' => 'refused']
            ),

            default => Response::error(self::LINK_INVALID, 400),
        };
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function confirmed(string $status, int $tenantId, ?int $profileId, ?string $ip): Response
    {
        $this->audit->record('tenant.signup.confirmed', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $profileId,
            'target_type' => 'profile',
            'target_id' => $profileId,
            'ip_address' => $ip,
            'metadata' => ['outcome' => $status],
        ]);

        $message = match ($status) {
            CustomerSignupService::CONFIRM_JOINED => 'You have joined. Sign in to continue.',
            CustomerSignupService::CONFIRM_PENDING => 'Your email is confirmed. The workspace will review your request, and you can sign in once it is approved.',
            default => 'You already have access to this workspace. Sign in to continue.',
        };

        return Response::json(['data' => ['status' => $status, 'message' => $message]], 200);
    }

    private function accepted(): Response
    {
        return Response::json(['data' => ['status' => 'check_email', 'message' => self::ACCEPTED]], 202);
    }

    /**
     * The workspace this request is for, only when its sign-up is on.
     *
     * @param array<string, mixed> $params
     * @return array{id: int, name: string, slug: string, settings: array{mode: string, role_id: int|null, allowed_domains: list<string>, updated_at: string|null}}|null
     */
    private function workspace(Request $request, array $params): ?array
    {
        $slug = strtolower(trim((string) ($params['workspace'] ?? '')));
        if ($slug === '' || strlen($slug) > 255 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return null;
        }

        try {
            $tenantId = $this->tenants->findIdBySlug($slug);
            if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
                return null;
            }

            $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
            $hostTenant = $this->hosts->resolveTenantIdByHost($host);
            if ($hostTenant !== null && $hostTenant !== $tenantId) {
                return null;
            }

            $settings = $this->settings->get($tenantId);
            if ($settings['mode'] === WorkspaceSignupSettings::MODE_OFF || $settings['role_id'] === null) {
                return null;
            }

            $name = $this->tenants->findNameById($tenantId);
        } catch (\Throwable $e) {
            error_log('[customer-signup] workspace lookup failed: ' . $e->getMessage());

            return null;
        }

        return ['id' => $tenantId, 'name' => $name ?? $slug, 'slug' => $slug, 'settings' => $settings];
    }

    private function limit(string $prefix, ?string $subject, int $max): ?Response
    {
        if ($subject === null || $subject === '') {
            return null;
        }

        $key = $prefix . $subject;
        if ($this->store->count($key) >= $max) {
            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }
        $this->store->increment($key, self::WINDOW_SECONDS);

        return null;
    }

    private function tokenFromQuery(Request $request): string
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (is_string($params['token'] ?? null)) {
                return $params['token'];
            }
        }

        return is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    }
}
