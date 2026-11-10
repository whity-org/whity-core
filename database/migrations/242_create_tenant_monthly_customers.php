<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The customers a workspace served, one row per customer per month (WHIT-932).
 *
 * `customers.per_month` is a count of DISTINCT people, which a counter cannot
 * give: the same customer sending three forms is one customer. So each is
 * recorded once per month, and the count is the number of rows.
 *
 * - `period` is the month in the workspace's own time zone, `YYYY-MM`.
 * - `customer_ref` is `profile:<id>` for a signed-in customer, or
 *   `submission:<id>` for an anonymous form, which cannot be told apart from
 *   another anonymous one. No name or email is stored.
 * - Rows are kept: they are what a monthly bill would be checked against.
 */
final class CreateTenantMonthlyCustomers
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenant_monthly_customers (
                tenant_id     INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                period        CHAR(7)     NOT NULL,
                customer_ref  VARCHAR(80) NOT NULL,
                first_seen_at TIMESTAMP   NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, period, customer_ref)
            )
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_monthly_customers');
    }
}
