<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * The per-profile floor that makes a TOTP code single-use (WC-security-audit).
 *
 * A code stays valid for its whole period, so every check of a code must also
 * consume its step. Login and the signed-in proof on the 2FA routes share this
 * floor, so a code used for one cannot be replayed on the other.
 */
final class TotpStepFloor
{
    /**
     * Atomically advance `profiles.two_factor_last_used_step` to $step, but only
     * when it is NULL or strictly below $step. The `WHERE` guard means only the
     * request that flips the row wins, even under concurrent workers.
     *
     * @return bool True when this step was newly accepted; false when it, or a
     *   later one, was already consumed for this profile.
     */
    public static function advance(PDO $db, int $profileId, int $step): bool
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $db->prepare(
            'UPDATE profiles
                SET two_factor_last_used_step = :step, updated_at = CURRENT_TIMESTAMP
              WHERE id = :pid
                AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < :step2)'
        );
        $stmt->execute([':step' => $step, ':pid' => $profileId, ':step2' => $step]);

        return $stmt->rowCount() === 1;
    }
}
