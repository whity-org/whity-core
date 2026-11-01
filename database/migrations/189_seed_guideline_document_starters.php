<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Database\Database;

/**
 * SeedGuidelineDocumentStarters (WHIT-843).
 *
 * The 13 document templates of the guideline, in English and Arabic, reach
 * every existing workspace; a new workspace gets them when it is provisioned.
 *
 * - Only the keys new here are added, so a starter a workspace deleted before
 *   this migration stays deleted.
 * - The old Letter and Invoice are brought up to the guideline only where the
 *   workspace never saved or published them. A copy someone changed is theirs.
 * - Strict, as migration 179 is: a starter that can't be written stops the
 *   upgrade instead of being logged.
 *
 * Forward and idempotent. down() removes the new starters only where nobody
 * has touched them, and leaves the refreshed Letter and Invoice as they are.
 */
class SeedGuidelineDocumentStarters
{
    private const SYSTEM_TENANT_ID = 0;

    /** The starters that existed before this migration. */
    private const OLDER = [
        'editor.letter',
        'editor.invoice',
        'editor.exam',
        'editor.production-note',
        'editor.shipping-labels',
        'editor.certificate',
    ];

    /** The older starters that the guideline redraws. */
    private const REFRESHED = ['editor.letter', 'editor.invoice'];

    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();
        $seeder = new DocumentSourceStarterSeeder(new DocumentSourceRepository($pdo));
        $new = array_values(array_diff(array_keys($seeder->starters()), self::OLDER));

        foreach (self::tenants($pdo) as $tenantId) {
            $seeder->seedForTenant($tenantId, strict: true, only: $new);
            $seeder->refreshUntouched($tenantId, self::REFRESHED);
        }
    }

    public static function down(Database $db): void
    {
        $pdo = $db->getPdo();
        $seeder = new DocumentSourceStarterSeeder(new DocumentSourceRepository($pdo));
        $new = array_values(array_diff(array_keys($seeder->starters()), self::OLDER));
        $delete = $pdo->prepare(
            'DELETE FROM document_sources
              WHERE tenant_id = :tenant_id AND starter_key = :starter_key AND draft_version = 1 AND published_version IS NULL'
        );
        foreach (self::tenants($pdo) as $tenantId) {
            foreach ($new as $key) {
                $delete->execute([':tenant_id' => $tenantId, ':starter_key' => $key]);
            }
        }
    }

    /** @return list<int> */
    private static function tenants(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT id FROM tenants WHERE id <> :system ORDER BY id');
        $stmt->execute([':system' => self::SYSTEM_TENANT_ID]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
