<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;
use Whity\Auth\LoginThrottleService;
use Whity\Auth\TotpService;
use Whity\Auth\TotpStepFloor;
use Whity\Core\Db\DbBool;
use Whity\Core\Identity\AuthMethod;

/**
 * The second check a signature needs, made at the moment of signing (WHIT-922,
 * #1726, owner decision C1).
 *
 * WHICH CHECK. The signer does not choose; the account decides, so nobody can
 * pick a weaker check than the one they have:
 *  - an authenticator app, when the person has turned on two-step sign-in: a
 *    current code, used once (the same step floor as sign-in, so a code used
 *    to sign in cannot be used again here);
 *  - a fresh sign-in through their sign-in provider, when the account signs in
 *    only that way: they sign in again, and the provider must say the sign-in
 *    happened in the last {@see SigningChallengeRepository::FRESH_SIGN_IN_TTL}
 *    seconds;
 *  - otherwise a one-time code sent to their email address.
 *
 * Passkeys are named in the design; the platform has none yet, so they are not
 * offered.
 */
final class SigningSecondFactor
{
    public const TOTP = 'totp';
    public const EMAIL_CODE = SigningChallengeRepository::EMAIL_CODE;
    public const FRESH_SIGN_IN = SigningChallengeRepository::FRESH_SIGN_IN;

    public function __construct(
        private readonly PDO $db,
        private readonly SigningChallengeRepository $challenges,
        private readonly ?TotpService $totp = null,
        private readonly ?LoginThrottleService $throttle = null,
    ) {
    }

    /**
     * The check this person signs with, and for a fresh sign-in the provider to
     * sign in through.
     *
     * @return array{method: string, provider_key: ?string}
     */
    public function methodFor(int $profileId): array
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare(
            'SELECT two_factor_enabled, two_factor_secret, auth_method FROM profiles WHERE id = :id'
        );
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $row = is_array($row) ? $row : [];

        if ($this->totp !== null && DbBool::of($row['two_factor_enabled'] ?? false)
            && (string) ($row['two_factor_secret'] ?? '') !== ''
        ) {
            return ['method' => self::TOTP, 'provider_key' => null];
        }

        if ((string) ($row['auth_method'] ?? '') === AuthMethod::IDP) {
            // @tenant-guard-ignore: external_identities is a sanctioned GLOBAL identity table (WC-7ad4)
            $link = $this->db->prepare(
                'SELECT provider_key FROM external_identities WHERE profile_id = :id ORDER BY last_login_at DESC, id DESC LIMIT 1'
            );
            $link->execute([':id' => $profileId]);
            $providerKey = $link->fetchColumn();
            $link->closeCursor();
            if (is_string($providerKey) && $providerKey !== '') {
                return ['method' => self::FRESH_SIGN_IN, 'provider_key' => $providerKey];
            }
        }

        return ['method' => self::EMAIL_CODE, 'provider_key' => null];
    }

    /**
     * Make the check, using up whatever it used.
     *
     * @throws SigningRefusedException
     */
    public function confirm(int $tenantId, int $profileId, string $method, ?string $code, ?string $ip): string
    {
        $expected = $this->methodFor($profileId)['method'];
        if ($method !== $expected) {
            throw new SigningRefusedException(
                SigningRefusedException::SECOND_FACTOR_REQUIRED,
                'Confirm it is you with the check this account uses.',
            );
        }

        if ($this->throttle?->isThrottled($profileId, $ip) === true) {
            throw new SigningRefusedException(
                SigningRefusedException::THROTTLED,
                'Too many tries. Wait a few minutes and try again.',
                429,
            );
        }

        $code = $code === null ? '' : preg_replace('/\s+/', '', $code) ?? '';

        $ok = match ($method) {
            self::TOTP => $code !== '' && $this->totpProves($profileId, $code),
            self::EMAIL_CODE => $code !== '' && $this->challenges->consumeEmailCode($tenantId, $profileId, $code),
            self::FRESH_SIGN_IN => $this->challenges->consumeFreshSignIn($tenantId, $profileId),
            default => false,
        };

        if (!$ok) {
            $this->throttle?->recordFailure($profileId, $ip);

            throw new SigningRefusedException(
                SigningRefusedException::SECOND_FACTOR_INVALID,
                $method === self::FRESH_SIGN_IN
                    ? 'Sign in again to confirm it is you, then sign within five minutes.'
                    : 'That code is not correct or has expired.',
            );
        }

        return $method;
    }

    private function totpProves(int $profileId, string $code): bool
    {
        if ($this->totp === null) {
            return false;
        }
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
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
}
