<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * RetitleGuidelineInvoiceStarters (WHIT-843).
 *
 * The free starter is an Invoice; the Tax invoice is what Front Office adds
 * (owner, 2 Nov 2026). Migration 189 seeded `editor.invoice` as "Tax invoice"
 * and `editor.invoice.ar` as "فاتورة ضريبية", so this renames those copies.
 *
 * It changes a copy only while it is still as seeded: at its first draft, never
 * published, and with the old title. A copy someone changed, published or
 * renamed is theirs, and a deleted one stays deleted.
 *
 * The titles are written here rather than read from the starter class, so this
 * keeps meaning the same thing when the starters change. Idempotent. down()
 * puts the old titles back on copies still as seeded.
 */
class RetitleGuidelineInvoiceStarters
{
    private const SYSTEM_TENANT_ID = 0;

    /** starter key => [title 189 gave it, title now]. */
    private const TITLES = [
        'editor.invoice' => ['Tax invoice', 'Invoice'],
        'editor.invoice.ar' => ['فاتورة ضريبية', 'فاتورة'],
    ];

    public static function up(Database $db): void
    {
        self::retitle($db->getPdo(), 0, 1);
    }

    public static function down(Database $db): void
    {
        self::retitle($db->getPdo(), 1, 0);
    }

    private static function retitle(PDO $pdo, int $from, int $to): void
    {
        $tenants = $pdo->prepare('SELECT id FROM tenants WHERE id <> :system ORDER BY id');
        $tenants->execute([':system' => self::SYSTEM_TENANT_ID]);
        $update = $pdo->prepare(
            'UPDATE document_sources SET title = :new_title, updated_at = NOW()
              WHERE tenant_id = :tenant_id AND starter_key = :starter_key AND title = :old_title
                AND draft_version = 1 AND published_version IS NULL'
        );
        foreach ($tenants->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
            foreach (self::TITLES as $key => $titles) {
                $update->execute([
                    ':new_title' => $titles[$to],
                    ':tenant_id' => (int) $tenantId,
                    ':starter_key' => $key,
                    ':old_title' => $titles[$from],
                ]);
            }
        }
    }
}
