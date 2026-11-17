<?php

declare(strict_types=1);

namespace Whity\Core\Security;

/**
 * The one contract for gated approvals (AG.1): every approval that changes who
 * can sign in or what runs as someone with the most sensitive permissions asks
 * the approver to confirm it's them, freshly, on the server, right before it
 * takes effect.
 *
 * Gated today:
 *  - {@see self::TWO_FACTOR_RECOVERY}: clearing someone's two-step (each of the
 *    two admins), and starting one for a named person;
 *  - {@see self::PASSWORD_RESET}: applying a password reset that waits for an
 *    admin (`auth.password_reset_approval_required`);
 *  - {@see self::WORKSPACE_OWNERSHIP}: offering ownership of a workspace or
 *    co-ownership of it, and accepting such an offer (WHIT-1170);
 *  - {@see self::AGENT_PROPOSAL}: approving an agent's proposal that is "Needs
 *    verifying" (tier 3, {@see \Whity\Core\Agent\ProposalTier}).
 *
 * The check itself is {@see IdentityCheck}: an authenticator code when the
 * approver has two-step, otherwise a code emailed to them. A passkey or Face ID
 * becomes the first check there once the platform has passkeys, with no change
 * here.
 *
 * WHAT IS BEING APPROVED IS PART OF THE CHECK. A purpose is the kind plus the
 * subject ("password-reset:12"), so a code sent to approve one thing can't
 * approve another, even another kind with the same id.
 *
 * FAIL CLOSED. A handler built without a gate refuses every gated approval
 * (503) rather than letting it through unchecked.
 */
final class GatedApproval
{
    public const TWO_FACTOR_RECOVERY = '2fa-recovery';
    public const PASSWORD_RESET = 'password-reset';
    public const AGENT_PROPOSAL = 'agent-proposal';
    public const WORKSPACE_OWNERSHIP = 'workspace-ownership';

    private const KINDS = [self::TWO_FACTOR_RECOVERY, self::PASSWORD_RESET, self::AGENT_PROPOSAL, self::WORKSPACE_OWNERSHIP];

    /** What the emailed code says it is for, per kind. */
    private const WHAT = [
        self::TWO_FACTOR_RECOVERY => 'approving a two-step reset',
        self::PASSWORD_RESET => 'approving a password reset',
        self::AGENT_PROPOSAL => 'approving what an agent asked to do',
        self::WORKSPACE_OWNERSHIP => 'changing who owns a workspace',
    ];

    public function __construct(private readonly IdentityCheck $identity)
    {
    }

    /** "password-reset:12": what a code is bound to. */
    public static function purpose(string $kind, string $subject): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown gated approval kind: ' . $kind);
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('A gated approval needs a subject');
        }

        return $kind . ':' . $subject;
    }

    /**
     * Start the check for this approval: names the method and, for an email
     * code, sends one. Null when too many codes were sent lately or there is no
     * address to send to.
     *
     * @return array{method: string, email_hint: ?string}|null
     */
    public function start(int $tenantId, int $approverId, string $kind, string $subject): ?array
    {
        return $this->identity->start($tenantId, $approverId, self::purpose($kind, $subject), self::WHAT[$kind]);
    }

    /**
     * Make the check, using up the code. Returns the method that proved it, or
     * null when the code is missing, wrong, used or expired.
     */
    public function confirm(int $tenantId, int $approverId, string $kind, string $subject, ?string $code): ?string
    {
        return $this->identity->verify($tenantId, $approverId, self::purpose($kind, $subject), $code);
    }
}
