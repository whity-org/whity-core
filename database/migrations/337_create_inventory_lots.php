<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Database\Migration\SafeIndex;

/**
 * Inventory P4: lots, serial numbers and expiry (WHIT-1235, design 31 §3.2
 * and §3.5, register IV.1).
 *
 * - `inv_lots`: a group of one variant with one expiry or production date, or
 *   a serial number (a lot that holds exactly one piece). `code` is unique per
 *   variant. `warned_on` and `expired_on_notice` remember that the daily job
 *   already said the lot is expiring or has expired, so each is said once.
 * - `inv_operation_lines` gains what is typed on a receipt for a lot that
 *   doesn't exist yet (`lot_code`, `expires_on`, `made_on`); the lot row is
 *   made when the operation posts.
 *
 * `inv_moves.lot_id`, `inv_levels.lot_id` (0 for none) and
 * `inv_operation_lines.lot_id` were kept for this since migrations 318 and
 * 320. Tenant-owned and named literally for TenantOwnedTablesTest and
 * CoreTablesTest. Idempotent and reversible.
 */
final class CreateInventoryLots
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_lots (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id),
                code                  VARCHAR(64)   NOT NULL,
                is_serial             BOOLEAN       NOT NULL DEFAULT FALSE,
                made_on               DATE,
                expires_on            DATE,
                supplier_contact_id   INTEGER                REFERENCES contacts(id)     ON DELETE SET NULL,
                notes                 VARCHAR(500)  NOT NULL DEFAULT \'\',
                warned_on             DATE,
                expired_on_notice     DATE,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        SafeIndex::create($db, 'uq_inv_lots_code', 'inv_lots', 'tenant_id, variant_id, code', unique: true);
        SafeIndex::create($db, 'idx_inv_lots_expires', 'inv_lots', 'tenant_id, expires_on', where: 'expires_on IS NOT NULL');

        $db->exec('ALTER TABLE inv_operation_lines ADD COLUMN IF NOT EXISTS lot_code VARCHAR(64)');
        $db->exec('ALTER TABLE inv_operation_lines ADD COLUMN IF NOT EXISTS expires_on DATE');
        $db->exec('ALTER TABLE inv_operation_lines ADD COLUMN IF NOT EXISTS made_on DATE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE inv_operation_lines DROP COLUMN IF EXISTS made_on');
        $db->exec('ALTER TABLE inv_operation_lines DROP COLUMN IF EXISTS expires_on');
        $db->exec('ALTER TABLE inv_operation_lines DROP COLUMN IF EXISTS lot_code');
        $db->exec('DROP INDEX IF EXISTS idx_inv_lots_expires');
        $db->exec('DROP INDEX IF EXISTS uq_inv_lots_code');
        $db->exec('DROP TABLE IF EXISTS inv_lots');
    }
}
