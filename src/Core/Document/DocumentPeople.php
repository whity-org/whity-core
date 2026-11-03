<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * The names of the people a document names (S5, #1019).
 *
 * WHY THE CALLER NEVER SUPPLIES AN ID. #1019 sketched `POST /labels/resolve` with
 * a list of ids, gated on `documents:read`. Anybody holding that permission could
 * then walk the integers and read out the workspace's people, which is the
 * directory listing `users:read` exists to guard, reached by a longer road. So the
 * ids come from the rows the caller was already allowed to see: the page of the
 * library they were served, or a record that passed
 * {@see DocumentVisibilityPolicy::canView()}. Seeing a record is what entitles a
 * reader to know who raised it and who it passed through, and nothing here can
 * name anybody else.
 *
 * NAMES ONLY. The email address is the directory's and is never returned. A
 * profile with no display name falls back to the part before the `@`, the same
 * `name` `GET /api/users` gives, and the domain never leaves this class.
 *
 * ONLY THIS WORKSPACE'S MEMBERS. A person who has since left has no membership
 * here, so they get no entry and the client shows "A former member". A profile is
 * global across tenants, and reading the name without the membership join would
 * let one workspace read a name it may only know from another.
 *
 * SCANNERS ARE NAMED ONLY TO A READER OF THE DIRECTORY. A person who scanned a
 * document took no part in it, and who looks at what is not the reader's to learn
 * from the record alone. So `users:read` is what names them, as it always has.
 */
final class DocumentPeople
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Display names for these profiles, keyed by id, for this tenant's members.
     *
     * One statement, whatever the number of ids. An id with no name is absent.
     *
     * @param list<int> $profileIds
     * @return array<int, string>
     */
    public function names(int $tenantId, array $profileIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $profileIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.id, p.display_name, pe.email
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
               LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
              WHERE m.tenant_id = ?
                AND m.profile_id IN ({$placeholders})"
        );
        $stmt->execute([$tenantId, ...$ids]);

        $names = [];
        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = self::displayName($row['display_name'] ?? null, $row['email'] ?? null);
            if ($name !== null) {
                $names[(int) $row['id']] = $name;
            }
        }

        return $names;
    }

    /**
     * Everybody who took part in this document: who raised and withdrew it, who
     * sent, acted on and received its routes. `$withCodes` adds who issued and
     * retired its codes, and `$withScanners` who scanned them.
     *
     * @return list<int>
     */
    public function idsOnRecord(int $tenantId, int $documentId, bool $withCodes, bool $withScanners = false): array
    {
        $codes = $withCodes
            ? 'UNION SELECT issued_by FROM document_qr_tokens WHERE tenant_id = :tenant_id AND document_id = :document_id
               UNION SELECT revoked_by FROM document_qr_tokens WHERE tenant_id = :tenant_id AND document_id = :document_id'
            : '';
        $scanners = $withScanners
            ? 'UNION SELECT scanner_profile_id FROM document_qr_scans WHERE tenant_id = :tenant_id AND document_id = :document_id'
            : '';

        $stmt = $this->db->prepare(
            "SELECT created_by AS profile_id FROM documents WHERE tenant_id = :tenant_id AND id = :document_id
             UNION SELECT withdrawn_by FROM documents WHERE tenant_id = :tenant_id AND id = :document_id
             UNION SELECT created_by FROM document_routes WHERE tenant_id = :tenant_id AND document_id = :document_id
             UNION SELECT actor_profile_id FROM document_route_events WHERE tenant_id = :tenant_id AND document_id = :document_id
             UNION SELECT profile_id FROM document_route_recipients WHERE tenant_id = :tenant_id AND document_id = :document_id
             {$codes}
             {$scanners}"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private static function displayName(mixed $displayName, mixed $email): ?string
    {
        if (is_string($displayName) && trim($displayName) !== '') {
            return trim($displayName);
        }
        if (!is_string($email) || $email === '') {
            return null;
        }
        $localPart = strstr($email, '@', true);

        return $localPart !== false && $localPart !== '' ? $localPart : null;
    }
}
