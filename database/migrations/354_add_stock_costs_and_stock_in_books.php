<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddStockCostsAndStockInBooks — the moving average cost and stock in the
 * books (WHIT-1318 A4a, design section 31 D1 and §6.3, section 32 §6,
 * register IV.18, IV.22, AC.18).
 *
 * - `inv_costs`: per variant, the stock quantity across real places and its
 *   value in the workspace currency. The average cost is value / quantity.
 *   Kept by the stock ledger in the same transaction as the moves; `value_minor`
 *   is NULL while the cost is unknown (nothing to value it from).
 * - `inv_moves.value_minor`: what each move was worth at the time, in the
 *   workspace currency: the receipt's cost coming in, the average going out,
 *   the original's value on a reversal; NULL when unknown. Moves stay
 *   append-only: the value is written with the move, never after.
 * - `acc_books.stock_in_books` (on by default, Q3) and `stock_cutover_at`:
 *   when stock first posted to the books. Turning it off clears the cutover;
 *   turning it on again posts only the difference between the stock value and
 *   the Stock account.
 *
 * Tenant-owned; named literally for TenantOwnedTablesTest and CoreTablesTest.
 * Idempotent and reversible.
 */
final class AddStockCostsAndStockInBooks
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_costs (
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)     ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id) ON DELETE CASCADE,
                quantity              BIGINT        NOT NULL DEFAULT 0,
                value_minor           BIGINT,
                currency              VARCHAR(3)    NOT NULL,
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, variant_id)
            )
        ');
        $db->exec('ALTER TABLE inv_moves ADD COLUMN IF NOT EXISTS value_minor BIGINT');
        $db->exec('ALTER TABLE acc_books ADD COLUMN IF NOT EXISTS stock_in_books BOOLEAN NOT NULL DEFAULT TRUE');
        $db->exec('ALTER TABLE acc_books ADD COLUMN IF NOT EXISTS stock_cutover_at TIMESTAMP');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE acc_books DROP COLUMN IF EXISTS stock_cutover_at');
        $db->exec('ALTER TABLE acc_books DROP COLUMN IF EXISTS stock_in_books');
        $db->exec('ALTER TABLE inv_moves DROP COLUMN IF EXISTS value_minor');
        $db->exec('DROP TABLE IF EXISTS inv_costs');
    }
}
