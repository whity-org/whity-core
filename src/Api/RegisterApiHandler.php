<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Plan\DefaultPlanAssigner;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Identity\AccountActivationPolicy;
use Whity\Core\Identity\EmailVerificationPolicy;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\NullEmailVerificationProvider;
use Whity\Core\PasswordPolicy;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;
use Whity\Http\Validation\Messages;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Affiliate\AffiliateAttribution;
use Whity\Core\Settings\SettingsService;
use Whity\Http\JsonBody;

/**
 * Public self-service registration (WC-235).
 *
 * Provisions a NEW tenant with the registrant as its owner. In one transaction
 * it creates: a `tenants` row, a global `profiles` row (+ a primary verified
 * `profile_emails` row), and a `memberships` row binding the profile to the new
 * tenant with the base `admin` role (ADR 0005 — identity is profiles +
 * profile_emails + memberships; there is no legacy `users` row). The membership
 * is 'active' by default; when {@see AccountActivationPolicy} is enforced it is
 * 'invited' (pending) and the owner cannot log in until a system-tenant admin
 * approves it.
 *
 * PUBLIC + UNAUTHENTICATED: registered with NO required permission and covered
 * by the global rate-limiter (a public tenant-creating endpoint is an abuse
 * vector — rate limiting, input validation, and the unique-email/name/slug
 * guards + a single all-or-nothing transaction are the safeguards). It does NOT
 * mint a session; on 201 the client logs in via `POST /api/login` with the same
 * credentials. Error bodies are generic; details are logged server-side only.
 *
 * NOTE: this deliberately bypasses the system-admin gate that guards the admin
 * `POST /api/tenants` path — self-service signup is precisely the sanctioned way
 * an unauthenticated caller provisions their own tenant.
 */
final class RegisterApiHandler
{
    /** The base (global, NULL-tenant) role the workspace owner is granted. */
    private const OWNER_ROLE = 'admin';

    private PDO $db;
    private SettingsService $settings;
    private EmailVerificationProvider $verificationProvider;
    private ?HookManager $hooks;

    public function __construct(
        PDO $db,
        SettingsService $settings,
        ?EmailVerificationProvider $verificationProvider = null,
        ?HookManager $hooks = null,
        /**
         * Links a new workspace to whoever referred it.
         *
         * Optional because a deployment can run no affiliate programme, and
         * because this handler is constructed in tests that have nothing to do
         * with commissions. Null simply means no code is ever attributed.
         */
        private readonly ?AffiliateAttribution $affiliates = null,
        // READINESS CE-8: per-address and per-email limits on creating a
        // workspace and an account. Null (tests): only the global limit.
        private readonly ?SharedStoreInterface $store = null,
    ) {
        $this->db = $db;
        // Instance-governance flags (self-registration open? approval required?)
        // are read fresh per request from the GLOBAL settings layer.
        $this->settings = $settings;
        // Default to the MVP no-op provider; a real one is bound in the
        // composition root when EMAIL_VERIFICATION_ENFORCED is turned on.
        $this->verificationProvider = $verificationProvider ?? new NullEmailVerificationProvider();
        // Optional: dispatches the `registration.completed` lifecycle hook (drives
        // the welcome email). Null in tests that don't exercise notifications.
        $this->hooks = $hooks;
    }

    public function register(Request $request): Response
    {
        try {
            // Instance governance (WC-696206d8): self-service signup must be
            // enabled by the operator. A fresh, sovereign instance is CLOSED by
            // default (the operator opens signup via global instance settings), so
            // a public caller cannot self-provision a tenant + account. Read fresh
            // per request and fail closed with a generic 403 (no enumeration).
            $global = $this->settings->getGlobal();
            if (($global[SettingsRegistry::SELF_REGISTRATION_ENABLED] ?? 'false') !== 'true') {
                return Response::error('Self-service registration is disabled', 403);
            }

            // Whether a self-provisioned workspace has to be paid for before it
            // does anything. Read here, used inside the transaction below.
            //
            // DEFAULTS TO FALSE, which preserves exactly what every existing
            // deployment does today. Turning it on is an operator decision about
            // a commercial model; defaulting it on would silently wall the next
            // signup on every instance that upgraded, including sovereign ones
            // that sell nothing and have no billing service to pay.
            $paymentRequired =
                ($global[SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED] ?? 'false') === 'true';

            // Every attempt counts against the address, valid or not: each one
            // that succeeds makes a workspace, an account and an email (CE-8).
            $ip = ClientIp::fromRequest($request);
            if ($this->store !== null && $ip !== null
                && ($limited = RateLimits::registerPerAddress()->take($this->store, 'register:ip:' . $ip)) !== null
            ) {
                return $limited;
            }

            $body = JsonBody::parsed($request);

            // ── Validate inputs (#2174: the shared field validator) ─────────────
            // Lengths are capped to the backing VARCHAR(255) columns: an
            // over-long value would otherwise surface as a Postgres 22001 → a
            // generic 500. The top-level sentence for the first problem is the
            // one this route has always answered with; every field's problem is
            // listed alongside, in English and Arabic.
            $checked = FieldValidator::check($body, [
                'email'        => Field::email()->required()->maxBytes(255),
                'tenant_name'  => Field::text()->from('tenant_name', 'tenantName')->required()->maxBytes(255),
                'display_name' => Field::text()->from('display_name', 'displayName')->maxBytes(255),
                'password'     => Field::password()->required(),
            ]);
            if (!$checked->ok()) {
                $first = (string) $checked->first();

                return $checked->refusal(match (true) {
                    // An address too long for its column that is still an address
                    // was always reported as the length problem.
                    $first === 'email' && !($checked->code('email') === Messages::TOO_LONG && Field::isEmail($checked->value('email')))
                        => 'A valid email address is required',
                    $first === 'tenant_name' && $checked->code('tenant_name') === Messages::REQUIRED => 'A workspace name is required',
                    $first === 'password' => 'Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters',
                    default => 'Email, workspace name, and display name must each be 255 characters or fewer',
                });
            }
            $email       = $checked->value('email');
            if ($this->store !== null
                && ($limited = RateLimits::registerPerEmail()->take($this->store, 'register:email:' . hash('sha256', strtolower((string) $email)))) !== null
            ) {
                return $limited;
            }
            $password    = $checked->value('password');
            try {
                PasswordPolicy::refuseBreached($password);
            } catch (\InvalidArgumentException) {
                // READINESS CE-7: a breached or common new password.
                return Response::error(PasswordPolicy::BREACHED_MESSAGE, 422, ['password' => 'breached']);
            }
            $tenantName  = $checked->value('tenant_name');
            $displayName = $checked->value('display_name');

            $slug = self::slugify($tenantName);
            if ($slug === '') {
                return FieldValidator::refusal('Workspace name must contain letters or numbers', [
                    'tenant_name' => Messages::problem(Messages::INVALID, [
                        'Use some letters or numbers in the workspace name.',
                        'استخدم بعض الحروف أو الأرقام في اسم مساحة العمل.',
                    ]),
                ]);
            }
            if ($displayName === '') {
                $displayName = self::localPart($email);
            }

            // Email-verification enforcement (WC-235): default OFF (MVP). When ON,
            // the primary email starts unverified and we hand off to the provider
            // after commit; when OFF, the email is self-attested verified and
            // nothing is sent. Flipping the flag needs no change to this handler.
            $enforced = EmailVerificationPolicy::isEnforced();

            // Admin-approval enforcement (WC-235 + WC-696206d8): when ON, the owner
            // membership is provisioned as 'invited' (pending) instead of 'active',
            // so the owner CANNOT log in until a system-tenant admin approves it.
            // Required by DEFAULT via the global instance setting (secure default);
            // the legacy ADMIN_APPROVAL_ENFORCED env flag still forces it on too.
            $approvalRequired =
                (($global[SettingsRegistry::REGISTRATION_APPROVAL_REQUIRED] ?? 'true') === 'true')
                || AccountActivationPolicy::isEnforced();
            $membershipStatus = $approvalRequired
                ? MembershipRepository::STATUS_INVITED
                : MembershipRepository::STATUS_ACTIVE;

            // ── Resolve the base owner role; fail closed if the platform has not
            //    been seeded (a workspace owner cannot exist without it). ────────
            // Base roles are global (NULL tenant_id); looked up by unique name
            $roleStmt = $this->db->prepare(
                'SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1'
            );
            $roleStmt->execute([':name' => self::OWNER_ROLE]);
            $roleId = $roleStmt->fetchColumn();
            if ($roleId === false) {
                error_log('[register] base "' . self::OWNER_ROLE . '" role missing — platform not seeded');
                return Response::error('Registration is temporarily unavailable', 503);
            }
            $roleId = (int) $roleId;

            // The owner membership's id, for the audit payload built after the
            // transaction block below (#889).
            $membershipId = 0;

            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            try {
                // Reject an already-registered email (profile_emails.email is globally unique).
                // Profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $emailStmt = $this->db->prepare('SELECT 1 FROM profile_emails WHERE email = :email LIMIT 1');
                $emailStmt->execute([':email' => $email]);
                if ($emailStmt->fetchColumn() !== false) {
                    if ($ownTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return Response::error('An account with this email already exists', 409);
                }

                // Reject a taken workspace name or slug.
                // Registration provisions a NEW tenant for an unauthenticated caller; the tenants table is the tenant registry itself, not a tenant-scoped resource
                $tenantStmt = $this->db->prepare('SELECT 1 FROM tenants WHERE name = :name OR slug = :slug LIMIT 1');
                $tenantStmt->execute([':name' => $tenantName, ':slug' => $slug]);
                if ($tenantStmt->fetchColumn() !== false) {
                    if ($ownTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return Response::error('That workspace name is already taken', 409);
                }

                // 1. New tenant.
                // Registration provisions the tenant itself (the tenant registry, not a tenant-scoped row)
                $tenantId = $this->insertReturningId(
                    'INSERT INTO tenants (name, slug, name_language, created_at) VALUES (:name, :slug, :name_language, NOW())',
                    // The name is the original; Arabic letters mean an Arabic one (#821).
                    [':name' => $tenantName, ':slug' => $slug, ':name_language' => LocalizedText::guessLanguage($tenantName)]
                );

                // 2. Global profile (bcrypt password; 2FA off; token epoch 0).
                // Profiles is a sanctioned GLOBAL table (ADR 0005 §1)
                $profileId = $this->insertReturningId(
                    'INSERT INTO profiles
                         (display_name, password_hash, two_factor_enabled,
                          two_factor_secret, two_factor_backup_codes_version, token_epoch,
                          created_at, updated_at)
                     VALUES (:display_name, :password_hash, false, NULL, 0, 0, NOW(), NOW())',
                    [
                        ':display_name'  => $displayName,
                        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ]
                );

                // 3. Primary email. When verification is enforced the address
                // starts UNVERIFIED (the provider drives the confirm round-trip);
                // otherwise it is self-attested verified at signup (MVP default).
                // The literal is a controlled boolean (never user input) and is
                // portable across Postgres + the SQLite test shim.
                $verifiedLiteral = $enforced ? 'false' : 'true';
                // Profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $this->db->prepare(
                    "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
                     VALUES (:profile_id, :email, {$verifiedLiteral}, true, NOW())"
                )->execute([':profile_id' => $profileId, ':email' => $email]);

                // 4. Owner membership binding the profile to the new tenant. Its
                // status is 'active' (owner can log in immediately) unless admin
                // approval is enforced, in which case it is 'invited' (pending)
                // and login is refused until a system-tenant admin approves it.
                // The value is a controlled enum constant, never user input.
                $this->db->prepare(
                    "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                     VALUES (:profile_id, :tenant_id, :role_id, NULL, :status, NOW())"
                )->execute([
                    ':profile_id' => $profileId,
                    ':tenant_id'  => $tenantId,
                    ':role_id'    => $roleId,
                    ':status'     => $membershipStatus,
                ]);
                $membershipId = (int) $this->db->lastInsertId();

                // 5. PAYMENT GATE. When this deployment sells self-service
                // workspaces, the new tenant starts BLOCKED and stays blocked
                // until the billing service says it may use paid features.
                //
                // WRITTEN INSIDE THE TRANSACTION, deliberately. A tenant that
                // existed for even a moment without this row would be a tenant
                // the wall lets through: SubscriptionService::decide() treats a
                // null status as "not billed, never block", which is what keeps
                // free-tier and self-hosted tenants working. Creating the
                // workspace and gating it are therefore one act or neither.
                //
                // `expired` is the status because it is the one the wall already
                // refuses, and `block_all` because a workspace nobody has paid
                // for should do nothing except accept payment — the billing
                // routes are exempt from the wall precisely so that stays
                // reachable.
                //
                // The status is later overwritten by whatever the billing
                // service says. It is not a claim that a subscription lapsed;
                // it is the closed state the wall understands, chosen because
                // inventing a sixth status would mean teaching every existing
                // consumer of that column about it.
                if ($paymentRequired) {
                    $this->db->prepare(
                        'INSERT INTO tenant_plan (tenant_id, status, enforcement_mode, assigned_at)
                         VALUES (:tenant_id, :status, :mode, NOW())'
                    )->execute([
                        ':tenant_id' => $tenantId,
                        ':status'    => 'expired',
                        ':mode'      => 'block_all',
                    ]);
                }

                // The person who registers owns the workspace (WHIT-1169).
                (new \Whity\Core\Tenant\WorkspaceOwners($this->db))->add($tenantId, $profileId, $profileId);

                // 6. WHO SENT THEM. Inside the transaction, because a referral
                // pointing at a workspace whose creation rolled back would be a
                // commission owed for a customer that does not exist.
                //
                // It cannot fail the signup: every refusal inside returns null.
                // A referral code is marketing, not authentication — a typo or a
                // retired campaign must not cost somebody their account.
                if ($this->affiliates !== null) {
                    $referralCode = $body['referral_code'] ?? null;
                    if (is_string($referralCode) && trim($referralCode) !== '') {
                        $this->affiliates->attribute($referralCode, $tenantId, $profileId);
                    }
                }

                if ($ownTx) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            // After the commit: applying a plan opens its own transaction, and a
            // problem with it must not cost somebody their new account. A closed
            // payment gate is a plan row already, so it stays closed.
            DefaultPlanAssigner::forDatabase($this->db)->assign($tenantId);

            // Hand off to the verification provider AFTER commit (never inside the
            // transaction), and only when enforced. A delivery failure must not
            // undo the already-created account — log it and still return 201; the
            // owner can re-request verification. (The MVP provider is a no-op.)
            if ($enforced) {
                try {
                    $this->verificationProvider->sendVerification($profileId, $email);
                } catch (\Throwable $e) {
                    error_log('[register] verification dispatch failed for profile ' . $profileId . ': ' . $e->getMessage());
                }
            }

            // Self-registration mints a workspace OWNER, so it is a membership
            // grant like any other and belongs in the trail (#889). Post-commit
            // and best-effort, for the reason the block below gives: a listener
            // failure must never undo the created account.
            //
            // `status` distinguishes the two shapes this path produces — an
            // ACTIVE owner who can log in now, or an INVITED one still waiting
            // on operator approval. Recording the invited case is deliberate:
            // "who asked for a workspace and was left pending" is a question an
            // operator reviewing the approval queue actually has, and dropping
            // it would make the trail silent about every registration that was
            // never approved.
            if ($this->hooks !== null) {
                try {
                    $this->hooks->dispatch('user.membership.added', [
                        'profile_id'    => $profileId,
                        'tenant_id'     => $tenantId,
                        'membership_id' => $membershipId,
                        'role_id'       => $roleId,
                        'role_name'     => self::OWNER_ROLE,
                        'ou_id'         => null,
                        'status'        => $membershipStatus,
                        'via'           => 'self_registration',
                    ]);
                } catch (\Throwable $e) {
                    error_log('[register] membership audit dispatch failed for profile ' . $profileId . ': ' . $e->getMessage());
                }
            }

            // Lifecycle hook (drives the welcome email + any plugin reaction).
            // Best-effort and post-commit: a listener failure must never undo the
            // created account. The subscriber decides whether to send (e.g. it
            // holds the welcome when approval is still pending).
            if ($this->hooks !== null) {
                try {
                    $this->hooks->dispatch('registration.completed', [
                        'email'                 => $email,
                        'name'                  => $displayName,
                        'tenant_name'           => $tenantName,
                        'approval_required'     => $approvalRequired,
                        'verification_required' => $enforced,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[register] registration.completed hook failed: ' . $e->getMessage());
                }
            }

            return Response::json([
                'data' => [
                    'profile_id'            => $profileId,
                    'tenant_id'             => $tenantId,
                    'email'                 => $email,
                    'verification_required' => $enforced,
                    // When true the owner cannot log in until a system-tenant
                    // admin approves the pending registration (WC-235). The
                    // client shows a "pending approval" message instead of
                    // chaining a login it knows will be refused.
                    'approval_required'     => $approvalRequired,
                ],
            ], 201);
        } catch (\Throwable $e) {
            error_log('[register] ' . $e->getMessage());
            return Response::error('Registration failed', 500);
        }
    }

    /**
     * Portable single-row INSERT returning the new id (Postgres RETURNING /
     * SQLite lastInsertId).
     *
     * @param array<string, mixed> $params
     */
    private function insertReturningId(string $sql, array $params): int
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->db->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        $this->db->prepare($sql)->execute($params);
        return (int) $this->db->lastInsertId();
    }

    /** Lowercase, hyphenate, and trim a workspace name into a URL-safe slug. */
    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function localPart(string $email): string
    {
        $at = strpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
