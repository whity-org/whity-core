<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateUsageLedger: what each workspace used, per meter and per month
 * (business model, section 33 §5, BM.17).
 *
 * The meters that existed before this kept their counters in the shared store,
 * keyed by window, so they were gone when the window ended. A bill for "seats
 * and volume" needs a history that survives, so every meter writes here too.
 *
 * One row per workspace, meter, month and dimension:
 *
 * - `meter` is the ledger's own name (`messages.outbound`, `api.calls`,
 *   `seats`…; {@see \Whity\Core\Usage\UsageMeters}), not the entitlement key,
 *   because one entitlement can be fed by several dimensions.
 * - `period` is the month in the workspace's own time zone, `YYYY-MM`, the
 *   same month the plan's allowance resets on.
 * - `dimension` splits a meter without a second table: the channel for
 *   messages (`email`, `whatsapp`, `sms`…), `api` or `mcp` for calls,
 *   `runs`, `tool_calls`, `tokens_in`, `tokens_out` for agents. '' when the
 *   meter has no split.
 * - `kind` is `counted` (added to as things happen: messages, calls, agent
 *   work) or `snapshot` (a live total read once a day: seats, items, storage,
 *   customers, invoices). A snapshot keeps the last value in `quantity` and the
 *   month's highest in `peak`, which is what a pack is sized against.
 *
 * Rows are kept: they are what a monthly bill is checked against. Table name
 * spelled out literally for TenantOwnedTablesTest and CoreTablesTest. Safe to
 * run twice.
 */
final class CreateUsageLedger
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS usage_ledger (
                tenant_id   INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                meter       VARCHAR(64) NOT NULL,
                period      CHAR(7)     NOT NULL,
                dimension   VARCHAR(64) NOT NULL DEFAULT '',
                kind        VARCHAR(16) NOT NULL DEFAULT 'counted',
                quantity    BIGINT      NOT NULL DEFAULT 0,
                peak        BIGINT      NOT NULL DEFAULT 0,
                first_at    TIMESTAMP   NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, meter, period, dimension),
                CHECK (kind IN ('counted', 'snapshot'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_usage_ledger_period ON usage_ledger (period, meter)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS usage_ledger');
    }
}
