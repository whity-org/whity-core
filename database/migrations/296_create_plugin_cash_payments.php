<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Cash payments for paid plugin plans (WHIT-1153). Owner, 2026-11-16: "we
 * accept a cash override for local customers".
 *
 * Only the operator records one, from the system workspace. Each row covers
 * one workspace for one add-on plan (`plugin.<slug>.<plan>`) from
 * `period_start` to `period_end`, for `quantity` seats. While a row covers now,
 * the plugin is on in that workspace exactly as if it had been bought online.
 * After the last row ends: 7 days of grace, then read-only.
 *
 * - `amount_minor` is in the currency's minor units (JOD 15.000 is 15000).
 * - A voided row covers nothing; it keeps who voided it, when and why.
 * - `reminder_sent_at` is set once the renewal reminder went out.
 * - Nothing is ever deleted: it is the workspace's billing history.
 */
final class CreatePluginCashPayments
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS plugin_cash_payments (
                id                    BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plan_key              VARCHAR(140) NOT NULL,
                quantity              INTEGER      NOT NULL DEFAULT 1,
                amount_minor          BIGINT       NOT NULL,
                currency              VARCHAR(3)   NOT NULL,
                receipt_number        VARCHAR(64)  NOT NULL,
                note                  TEXT         NULL,
                paid_on               DATE         NOT NULL,
                period_start          TIMESTAMP    NOT NULL,
                period_end            TIMESTAMP    NOT NULL,
                recorded_by_profile_id INTEGER     NULL REFERENCES profiles(id) ON DELETE SET NULL,
                recorded_at           TIMESTAMP    NOT NULL DEFAULT NOW(),
                voided_at             TIMESTAMP    NULL,
                voided_by_profile_id  INTEGER      NULL REFERENCES profiles(id) ON DELETE SET NULL,
                void_reason           TEXT         NULL,
                reminder_sent_at      TIMESTAMP    NULL,
                CHECK (quantity > 0),
                CHECK (amount_minor >= 0),
                CHECK (period_end > period_start)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_cash_payments_tenant ON plugin_cash_payments (tenant_id, plan_key)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_plugin_cash_payments_receipt ON plugin_cash_payments (tenant_id, receipt_number)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_plugin_cash_payments_receipt');
        $db->exec('DROP INDEX IF EXISTS idx_plugin_cash_payments_tenant');
        $db->exec('DROP TABLE IF EXISTS plugin_cash_payments');
    }
}
