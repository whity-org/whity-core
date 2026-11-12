<?php

declare(strict_types=1);

namespace Whity\Core\Security;

use PDO;
use Whity\Auth\TotpService;
use Whity\Auth\TotpStepFloor;
use Whity\Core\Db\DbBool;
use Whity\Core\Document\Signing\SigningChallengeRepository;

/**
 * "Confirm it's you" before a gating approval (AG.1): a sign-up, a password
 * reset or clearing someone's two-step.
 *
 * The account decides the check, as for signing (C1), so nobody can pick a
 * weaker one than they have:
 *  - an authenticator code, used once (the sign-in step floor, so a code used
 *    to sign in can't be used again here), when two-step is on;
 *  - otherwise a one-time code sent to their email address.
 *
 * The design asks for a passkey or Face ID. The platform has no passkeys yet;
 * when it does, they become the first check here and the screens need no change
 * beyond the button's words.
 *
 * Email codes reuse the signing challenges (`signing_challenges`), keyed by a
 * hash of what is being approved instead of a route, with route 0: a code sent
 * to approve one request can't approve another, and the send and try limits
 * are the signing ones.
 */
class IdentityCheck
{
    public const TOTP = 'totp';
    public const EMAIL_CODE = SigningChallengeRepository::EMAIL_CODE;

    /** Email codes for gating carry no route. */
    private const NO_ROUTE = 0;

    public function __construct(
        private readonly PDO $db,
        private readonly SigningChallengeRepository $challenges,
        private readonly ?TotpService $totp = null,
        private readonly ?IdentityCheckMailer $mailer = null,
    ) {
    }

    /** The check this person confirms with. */
    public function methodFor(int $profileId): string
    {
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT two_factor_enabled, two_factor_secret FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $row = is_array($row) ? $row : [];

        return $this->totp !== null
            && DbBool::of($row['two_factor_enabled'] ?? false)
            && (string) ($row['two_factor_secret'] ?? '') !== ''
            ? self::TOTP
            : self::EMAIL_CODE;
    }

    /**
     * Start the check: for an email code, send one for this purpose. Returns
     * the method and, for an email code, a masked hint of where it went; null
     * when too many codes were sent lately or there is no address to send to.
     *
     * @param string $purpose what is being approved, e.g. "2fa-recovery:12"
     * @param string $what    the plain words for the email: "approving a two-step reset"
     * @return array{method: string, email_hint: ?string}|null
     */
    public function start(int $tenantId, int $profileId, string $purpose, string $what): ?array
    {
        $method = $this->methodFor($profileId);
        if ($method === self::TOTP) {
            return ['method' => $method, 'email_hint' => null];
        }

        $email = $this->primaryEmail($profileId);
        if ($email === null) {
            return null;
        }
        $code = $this->challenges->issueEmailCode($tenantId, $profileId, self::NO_ROUTE, self::key($purpose));
        if ($code === null) {
            return null;
        }
        $this->mailer?->send($email, $code, $what);

        return ['method' => $method, 'email_hint' => self::mask($email)];
    }

    /**
     * Make the check, using up the code. Returns the method that proved it,
     * or null when the code is wrong, used or expired.
     */
    public function verify(int $tenantId, int $profileId, string $purpose, ?string $code): ?string
    {
        $code = preg_replace('/\s+/', '', (string) $code) ?? '';
        if ($code === '') {
            return null;
        }
        $method = $this->methodFor($profileId);

        $ok = $method === self::TOTP
            ? $this->totpProves($profileId, $code)
            : $this->challenges->consumeEmailCode($tenantId, $profileId, $code, self::NO_ROUTE, self::key($purpose));

        return $ok ? $method : null;
    }

    private static function key(string $purpose): string
    {
        return hash('sha256', 'gating:' . $purpose);
    }

    private function totpProves(int $profileId, string $code): bool
    {
        if ($this->totp === null) {
            return false;
        }
        // profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT two_factor_secret FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $secret = $stmt->fetchColumn();
        $stmt->closeCursor();
        if (!is_string($secret) || $secret === '') {
            return false;
        }
        $step = $this->totp->matchedStep($secret, $code);

        return $step !== null && TotpStepFloor::advance($this->db, $profileId, $step);
    }

    private function primaryEmail(int $profileId): ?string
    {
        // profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT email FROM profile_emails WHERE profile_id = :id AND is_primary = TRUE LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $email = $stmt->fetchColumn();
        $stmt->closeCursor();

        return is_string($email) && $email !== '' ? $email : null;
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }
}
