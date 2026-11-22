<?php

declare(strict_types=1);

namespace Whity\Core\Theme;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * The `themes` table (migration 323): theme files a workspace imported,
 * made in the editor or installed from the store, one row each with the
 * version in use. And `theme_versions` (347): every version of each, for the
 * editor's history, compare and restore (TH.25). Every query binds the tenant.
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
        } else {
            $insert = $this->db->prepare('INSERT INTO themes (tenant_id, theme_id, version, source, document, signature_verified, created_by_profile_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$tenantId, $theme['id'], $theme['version'], $source, $document, $signatureVerified ? 1 : 0, $profileId]);
        }
        $this->keepVersion($tenantId, (string) $theme['id'], (string) $theme['version'], $source, $document, $profileId);
    }

    /**
     * Keeps this version in the history: a new version number adds a row,
     * the same number saved again replaces its document (TH.25).
     */
    private function keepVersion(int $tenantId, string $themeId, string $version, string $source, string $document, ?int $profileId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM theme_versions WHERE tenant_id = ? AND theme_id = ? AND version = ?');
        $stmt->execute([$tenantId, $themeId, $version]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $update = $this->db->prepare('UPDATE theme_versions SET source = ?, document = ?, created_by_profile_id = ?, created_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?');
            $update->execute([$source, $document, $profileId, $id, $tenantId]);

            return;
        }
        $insert = $this->db->prepare('INSERT INTO theme_versions (tenant_id, theme_id, version, source, document, created_by_profile_id) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$tenantId, $themeId, $version, $source, $document, $profileId]);
    }

    /**
     * A theme's history, newest first: each version, how it came in, when,
     * and who saved it (their name, never an id).
     *
     * @return list<array{version: string, source: string, saved_at: string, saved_by: ?string}>
     */
    public function versions(int $tenantId, string $themeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.version, v.source, v.created_at, p.display_name AS saved_by
               FROM theme_versions v
               LEFT JOIN profiles p ON p.id = v.created_by_profile_id
              WHERE v.tenant_id = ? AND v.theme_id = ?'
        );
        $stmt->execute([$tenantId, $themeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) ($row['saved_by'] ?? ''));
            $out[] = [
                'version' => (string) $row['version'],
                'source' => (string) $row['source'],
                'saved_at' => (string) $row['created_at'],
                'saved_by' => $name !== '' ? $name : null,
            ];
        }
        // Newest version first, by version number (1.10.0 after 1.9.0).
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }

    /**
     * One version's theme file, or null.
     *
     * @return array<string, mixed>|null
     */
    public function version(int $tenantId, string $themeId, string $version): ?array
    {
        $stmt = $this->db->prepare('SELECT document FROM theme_versions WHERE tenant_id = ? AND theme_id = ? AND version = ?');
        $stmt->execute([$tenantId, $themeId, $version]);
        $document = $stmt->fetchColumn();
        $theme = is_string($document) ? json_decode($document, true) : null;

        return is_array($theme) && ThemeValidator::validate($theme) === [] ? $theme : null;
    }

    public function delete(int $tenantId, string $themeId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM themes WHERE tenant_id = ? AND theme_id = ?');
        $stmt->execute([$tenantId, $themeId]);
        // The history goes with the theme.
        $history = $this->db->prepare('DELETE FROM theme_versions WHERE tenant_id = ? AND theme_id = ?');
        $history->execute([$tenantId, $themeId]);

        return $stmt->rowCount() > 0;
    }
}
