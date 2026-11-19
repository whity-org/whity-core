<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * The `themes` table (migration 323): theme files a workspace imported or
 * installed from the store. Every query binds the tenant.
 */
final class ThemeRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{theme: array<string, mixed>, source: string, signature_verified: bool}> */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT document, source, signature_verified FROM themes WHERE tenant_id = ? ORDER BY theme_id');
        $stmt->execute([$tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $theme = json_decode((string) $row['document'], true);
            if (is_array($theme) && ThemeValidator::validate($theme) === []) {
                $out[] = ['theme' => $theme, 'source' => (string) $row['source'], 'signature_verified' => DbBool::of($row['signature_verified'])];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $theme A valid theme. */
    public function save(int $tenantId, array $theme, string $source, bool $signatureVerified, ?int $profileId): void
    {
        $document = json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('SELECT id FROM themes WHERE tenant_id = ? AND theme_id = ?');
        $stmt->execute([$tenantId, $theme['id']]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $update = $this->db->prepare('UPDATE themes SET version = ?, source = ?, document = ?, signature_verified = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?');
            $update->execute([$theme['version'], $source, $document, $signatureVerified ? 1 : 0, $id, $tenantId]);

            return;
        }
        $insert = $this->db->prepare('INSERT INTO themes (tenant_id, theme_id, version, source, document, signature_verified, created_by_profile_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$tenantId, $theme['id'], $theme['version'], $source, $document, $signatureVerified ? 1 : 0, $profileId]);
    }

    public function delete(int $tenantId, string $themeId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM themes WHERE tenant_id = ? AND theme_id = ?');
        $stmt->execute([$tenantId, $themeId]);

        return $stmt->rowCount() > 0;
    }
}
