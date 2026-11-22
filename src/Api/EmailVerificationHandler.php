<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Identity\EmailVerificationService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TenantEmailDomainPolicyService;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RateLimit\RateLimited;
use Whity\Core\RateLimit\RateLimits;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;
use Whity\Http\Validation\Field;
use Whity\Http\Validation\FieldValidator;

/**
 * Public email-verification endpoints (WC-235):
 *   POST /api/v1/email/request-verification  — (re)send a verification link
 *   POST /api/v1/email/verify                — confirm a token
 *
 * Both are PUBLIC + UNAUTHENTICATED by design: a freshly-registered owner is not
 * logged in yet, and the confirm link is clicked from an email with no session.
 * They resolve no tenant, so they sit on the tenant-isolation public-route
 * allowlist; the token lifecycle and rate-limiting are the safeguards.
 *
 * NO ENUMERATION: `request-verification` returns the SAME 202 whether or not the
 * address exists / is already verified, so it cannot be used to probe which
 * emails are registered. `verify` returns a generic 400 for any bad/expired/
 * replayed token.
 *
 * Audited as system-level (tenant 0) identity events — the email is a global
 * identity artifact, not a per-tenant resource.
 */
final class EmailVerificationHandler
{
    /** System tenant that owns cross-tenant / system-level audit records. */
    private const SYSTEM_TENANT_ID = 0;

    public function __construct(
        private readonly EmailVerificationService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly EmailVerificationProvider $provider,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
        private readonly TenantEmailDomainPolicyService $domainPolicy,
    ) {}

    /**
     * POST /api/v1/email/request-verification — (re)issue a verification link.
     *
     * Rate-limited per-email and per-IP. Always answers 202 with a generic
     * message (no enumeration); dispatch happens only for a known, unverified
     * address and any delivery failure is swallowed (logged) so the response
     * never varies with existence.
     */
    public function request(Request $request): Response
    {
        // Format validation is not enumeration (it never touches the DB).
        // #2174: the shared field validator; the sentence and status are unchanged.
        $checked = FieldValidator::check(JsonBody::parsed($request), [
            'email' => Field::email()->required()->maxBytes(255),
        ]);
        if (!$checked->ok()) {
            return $checked->refusal('A valid email address is required');
        }
        $email = $checked->value('email');

        $ip       = ClientIp::fromRequest($request);
        $emailKey = 'emailverify:req:email:' . hash('sha256', $email);
        $ipKey    = $ip !== null ? 'emailverify:req:ip:' . $ip : null;

        $emailRule = RateLimits::emailVerificationPerEmail();
        $ipRule    = RateLimits::emailVerificationPerAddress();
        if ($emailRule->blocked($this->store, $emailKey) !== null
            || ($ipKey !== null && $ipRule->blocked($this->store, $ipKey) !== null)
        ) {
            return RateLimited::response(max(
                $this->store->ttl($emailKey),
                $ipKey !== null ? $this->store->ttl($ipKey) : 0
            ));
        }

        // Count this attempt against both windows before doing any work.
        $emailRule->record($this->store, $emailKey);
        if ($ipKey !== null) {
            $ipRule->record($this->store, $ipKey);
        }

        $row = $this->emails->findByEmail($email);
        if ($row !== null && $row['verified'] === false) {
            try {
                $this->provider->sendVerification((int) $row['profile_id'], $email);
                $this->audit->record('email.verification.requested', [
                    'tenant_id'   => self::SYSTEM_TENANT_ID,
                    'target_type' => 'profile_email',
                    'target_id'   => (int) $row['id'],
                    'ip_address'  => $ip,
                ]);
            } catch (\Throwable $e) {
                // Delivery/issuance failure must not change the response shape.
                error_log('[email-verify] request dispatch failed: ' . $e->getMessage());
            }
        }

        return Response::json([
            'data' => ['message' => 'If that address requires verification, a link has been sent.'],
        ], 202);
    }

    /**
     * POST /api/v1/email/verify — consume a verification token.
     *
     * Generic 400 for any unknown/expired/replayed token (no distinction that
     * could aid probing). 200 on success. The global pre-auth per-IP limiter
     * backstops brute force (a 256-bit token is infeasible to guess); the
     * per-address confirm limit keeps failed tries from flooding the audit log.
     */
    public function confirm(Request $request): Response
    {
        // #2190: every failed try writes an audit row, so each address gets a
        // limit of its own before the token is looked at.
        $ip = ClientIp::fromRequest($request);
        if ($ip !== null
            && ($limited = RateLimits::emailVerificationConfirmPerAddress()->take($this->store, 'emailverify:confirm:ip:' . $ip)) !== null
        ) {
            return $limited;
        }

        // #2174: the token is read exactly as sent; the sentence and status are unchanged.
        $body    = JsonBody::parsed($request);
        $checked = FieldValidator::check($body, ['token' => Field::secret()->required()]);
        if (!$checked->ok()) {
            return $checked->refusal('A verification token is required');
        }
        $token = $checked->value('token');

        $result = $this->service->confirm($token);

        if ($result === null) {
            $this->audit->record('email.verification.failed', [
                'tenant_id'  => self::SYSTEM_TENANT_ID,
                'ip_address' => ClientIp::fromRequest($request),
            ]);

            return Response::error('This verification link is invalid or has expired', 400);
        }

        $this->audit->record('email.verification.confirmed', [
            'tenant_id'     => self::SYSTEM_TENANT_ID,
            'actor_user_id' => $result['profile_id'],
            'target_type'   => 'profile_email',
            'target_id'     => $result['profile_email_id'],
            'ip_address'    => ClientIp::fromRequest($request),
        ]);

        // Apply the tenant email-domain policy now that the address is genuinely
        // verified (WC-9b87): accept a pending invite or auto-provision a
        // membership for any tenant that owns the domain. Runs AFTER the token
        // is committed — a policy failure must not undo the verification (the
        // join can be retried), and this is deliberately gated on real
        // verification so nobody joins a tenant by an unproven address.
        try {
            $this->domainPolicy->applyToVerifiedEmail($result['email'], $result['profile_id']);
        } catch (\Throwable $e) {
            error_log('[email-verify] domain-policy application failed: ' . $e->getMessage());
        }

        return Response::json([
            'data' => ['verified' => true, 'email' => $result['email']],
        ], 200);
    }
}
