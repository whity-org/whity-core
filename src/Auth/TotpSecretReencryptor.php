<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * Data migration: re-encrypt stored TOTP secrets into the current key-ring format, under the
 * current key. Covers the legacy unauthenticated AES-256-CBC scheme (WC-158), bare
 * authenticated ciphertext written before TOTP used the key ring, and values under a key
 * that a rotation has since retired.
 *
 * Idempotent and self-detecting: a row that already decrypts under the current scheme is
 * left untouched; a row that does not is treated as legacy ciphertext, decrypted with the
 * old scheme, and re-stored under the new one. A row that can be read by neither scheme is
 * counted as failed and left as-is (never destroyed).
 *
 * This is a data migration, kept deliberately separate from structural schema migrations.
 */
final class TotpSecretReencryptor
{
    public function __construct(
        private readonly TotpService $totpService,
    ) {
    }

    /**
     * Re-encrypt every legacy TOTP secret in the `profiles` table.
     *
     * @return array{migrated: int, skipped: int, failed: int}
     */
    public function reencrypt(PDO $pdo): array
    {
        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1); platform-maintenance re-encryption sweep runs across all profiles by design (no tenant context)
        $select = $pdo->query('SELECT id, two_factor_secret FROM profiles WHERE two_factor_secret IS NOT NULL');
        if ($select === false) {
            return ['migrated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        /** @var list<array{id: mixed, two_factor_secret: mixed}> $rows */
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        // Profiles is a sanctioned GLOBAL table (ADR 0005 §1); PK-targeted write inside the platform-maintenance sweep
        $update = $pdo->prepare('UPDATE profiles SET two_factor_secret = ? WHERE id = ?');

        foreach ($rows as $row) {
            $stored = (string) $row['two_factor_secret'];

            // Decrypts under the key ring: leave it when it is already on the
            // current key, otherwise move it there (a bare pre-key-ring value,
            // or one written under a key that is now retired).
            try {
                $plaintext = $this->totpService->decryptSecret($stored);
                if ($this->totpService->isOnCurrentKey($stored)) {
                    $skipped++;
                } else {
                    $update->execute([$this->totpService->encryptSecret($plaintext), $row['id']]);
                    $migrated++;
                }
                continue;
            } catch (\Throwable) {
                // Not readable by the key ring: counted, and left exactly as it
                // is, never destroyed.
                $failed++;
            }
        }

        return ['migrated' => $migrated, 'skipped' => $skipped, 'failed' => $failed];
    }
}
