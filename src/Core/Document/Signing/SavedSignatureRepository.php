<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;

/**
 * A person's drawn signature, saved for reuse (WHIT-922, #1726, migration 232).
 *
 * A preference, not evidence: each signature record keeps its own copy of the
 * drawing it was signed with, so replacing or removing the saved one changes
 * no signature already made.
 *
 * TENANT-OWNED: every statement binds a literal `tenant_id` predicate.
 */
final class SavedSignatureRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{width: int, height: int, strokes: list<list<array{0: float|int, 1: float|int}>>}|null */
    public function find(int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT appearance FROM saved_signatures WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $raw = $stmt->fetchColumn();
        $stmt->closeCursor();
        if (!is_string($raw)) {
            return null;
        }

        try {
            return SignatureAppearance::normalise(json_decode($raw, true));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array{width: int, height: int, strokes: list<list<array{0: float|int, 1: float|int}>>} $appearance */
    public function save(int $tenantId, int $profileId, array $appearance): void
    {
        $encoded = SignatureAppearance::encode($appearance);
        $now = gmdate('Y-m-d H:i:s');
        $update = $this->db->prepare(
            'UPDATE saved_signatures SET appearance = :appearance, updated_at = :now
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $update->execute([':appearance' => $encoded, ':now' => $now, ':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        if ($update->rowCount() > 0) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO saved_signatures (tenant_id, profile_id, appearance, created_at, updated_at)
             VALUES (:tenant_id, :profile_id, :appearance, :now, :now2)
             ON CONFLICT (tenant_id, profile_id) DO UPDATE SET appearance = excluded.appearance,
                 updated_at = excluded.updated_at'
        )->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':appearance' => $encoded,
            ':now' => $now,
            ':now2' => $now,
        ]);
    }

    public function delete(int $tenantId, int $profileId): void
    {
        $this->db->prepare(
            'DELETE FROM saved_signatures WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
        )->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
    }
}
