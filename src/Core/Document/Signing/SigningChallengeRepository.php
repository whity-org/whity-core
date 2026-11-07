<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;

/**
 * One-time confirmations for signing (WHIT-922, #1726, migration 228).
 *
 *  - `email_code`: a six-digit code sent to the signer's address. Valid for
 *    {@see EMAIL_CODE_TTL} seconds, {@see MAX_ATTEMPTS} wrong tries, used once.
 *    Sending a new code retires the earlier ones.
 *  - `fresh_sign_in`: the record that the signer has just signed in again
 *    through their sign-in provider. Valid for {@see FRESH_SIGN_IN_TTL}
 *    seconds, used once.
 *
 * Codes are stored as a salted SHA-256, never in the clear. Times are UTC to
 * the second, written by this class, so every comparison is between the same
 * spelling.
 *
 * TENANT-OWNED: every statement binds a literal `tenant_id` predicate.
 */
final class SigningChallengeRepository
{
    public const EMAIL_CODE = 'email_code';
    public const FRESH_SIGN_IN = 'fresh_sign_in';

    public const EMAIL_CODE_TTL = 600;
    public const FRESH_SIGN_IN_TTL = 300;
    public const MAX_ATTEMPTS = 5;
    /** At most this many codes per person in {@see SEND_WINDOW} seconds. */
    public const MAX_SENDS = 5;
    public const SEND_WINDOW = 900;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Issue a new email code and return it, or null when too many were sent
     * recently.
     */
    public function issueEmailCode(int $tenantId, int $profileId): ?string
    {
        $now = time();
        $recent = $this->db->prepare(
            'SELECT COUNT(*) FROM signing_challenges
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND method = :method
                AND created_at > :since'
        );
        $recent->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => self::EMAIL_CODE,
            ':since' => gmdate('Y-m-d H:i:s', $now - self::SEND_WINDOW),
        ]);
        $count = (int) $recent->fetchColumn();
        $recent->closeCursor();
        if ($count >= self::MAX_SENDS) {
            return null;
        }

        $this->retire($tenantId, $profileId, self::EMAIL_CODE, $now);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'INSERT INTO signing_challenges (tenant_id, profile_id, method, code_hash, attempts, expires_at, created_at)
             VALUES (:tenant_id, :profile_id, :method, :code_hash, 0, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => self::EMAIL_CODE,
            ':code_hash' => $salt . ':' . hash('sha256', $salt . ':' . $code),
            ':expires_at' => gmdate('Y-m-d H:i:s', $now + self::EMAIL_CODE_TTL),
            ':created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);

        return $code;
    }

    /** Use the current email code. True only when it matched and was not used before. */
    public function consumeEmailCode(int $tenantId, int $profileId, string $code): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT id, code_hash, attempts FROM signing_challenges
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND method = :method
                AND consumed_at IS NULL AND expires_at > :now
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => self::EMAIL_CODE,
            ':now' => $now,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!is_array($row) || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        [$salt, $hash] = array_pad(explode(':', (string) $row['code_hash'], 2), 2, '');
        $matches = preg_match('/^\d{6}$/', $code) === 1
            && hash_equals($hash, hash('sha256', $salt . ':' . $code));

        if (!$matches) {
            $this->db->prepare(
                'UPDATE signing_challenges SET attempts = attempts + 1
                  WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([':id' => (int) $row['id'], ':tenant_id' => $tenantId]);

            return false;
        }

        return $this->consume($tenantId, (int) $row['id'], $now);
    }

    /** Record that the signer has just signed in again through their provider. */
    public function recordFreshSignIn(int $tenantId, int $profileId): void
    {
        $now = time();
        $this->retire($tenantId, $profileId, self::FRESH_SIGN_IN, $now);
        $this->db->prepare(
            'INSERT INTO signing_challenges (tenant_id, profile_id, method, code_hash, attempts, expires_at, created_at)
             VALUES (:tenant_id, :profile_id, :method, NULL, 0, :expires_at, :created_at)'
        )->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => self::FRESH_SIGN_IN,
            ':expires_at' => gmdate('Y-m-d H:i:s', $now + self::FRESH_SIGN_IN_TTL),
            ':created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    /** Whether a fresh sign-in is waiting to be used. */
    public function hasFreshSignIn(int $tenantId, int $profileId): bool
    {
        return $this->openFreshSignIn($tenantId, $profileId) !== null;
    }

    /** Use the waiting fresh sign-in. True only when there was one. */
    public function consumeFreshSignIn(int $tenantId, int $profileId): bool
    {
        $id = $this->openFreshSignIn($tenantId, $profileId);

        return $id !== null && $this->consume($tenantId, $id, gmdate('Y-m-d H:i:s'));
    }

    private function openFreshSignIn(int $tenantId, int $profileId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM signing_challenges
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND method = :method
                AND consumed_at IS NULL AND expires_at > :now
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => self::FRESH_SIGN_IN,
            ':now' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $id === false ? null : (int) $id;
    }

    /** Mark one challenge used. The guard makes only one caller win. */
    private function consume(int $tenantId, int $id, string $now): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE signing_challenges SET consumed_at = :now
              WHERE id = :id AND tenant_id = :tenant_id AND consumed_at IS NULL'
        );
        $stmt->execute([':now' => $now, ':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }

    private function retire(int $tenantId, int $profileId, string $method, int $now): void
    {
        $this->db->prepare(
            'UPDATE signing_challenges SET consumed_at = :now
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND method = :method
                AND consumed_at IS NULL'
        )->execute([
            ':now' => gmdate('Y-m-d H:i:s', $now),
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':method' => $method,
        ]);
    }
}
