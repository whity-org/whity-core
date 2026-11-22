<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateAccountingChart — a workspace's books, its chart of accounts, its
 * journals and its periods (WHIT-1318, design section 32, register AC.6–AC.9).
 *
 * - `acc_books`: one row per workspace that has opened accounting. Holds the
 *   base currency (fixed at the first posting, AC.6), the tax pack, the month
 *   the fiscal year starts, the lock date shared with stock (AC.7), and the
 *   head of the entries' hash chain (AC.4), which posting locks so entries are
 *   chained one after another.
 * - `acc_accounts`: the chart, a tree. `name` is the original text; `names`
 *   carries every language as LocalizedText JSON. `role` marks the accounts
 *   posting rules use (receivable, payable, sales tax, stock, retained
 *   earnings...), one per role per workspace. Accounts with lines are
 *   archived, never deleted.
 * - `acc_journals`: sales, purchases, bank, cash, general, stock, opening, and
 *   plugins' own. Each numbers its entries.
 * - `acc_fiscal_years`, `acc_periods`: twelve months per year, each open,
 *   closed or locked.
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateAccountingChart
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_books (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                base_currency         VARCHAR(3)    NOT NULL,
                currency_fixed_at     TIMESTAMP,
                tax_pack              VARCHAR(20)   NOT NULL DEFAULT \'jo\',
                year_start_month      INTEGER       NOT NULL DEFAULT 1,
                lock_date             DATE,
                -- @reference-lint-ignore: the newest entry in the hash chain; entries live forever, so it cannot dangle
                chain_entry_id        BIGINT,
                chain_hash            VARCHAR(64)   NOT NULL DEFAULT \'\',
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_books_tenant ON acc_books(tenant_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_accounts (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                parent_id             INTEGER                REFERENCES acc_accounts(id) ON DELETE SET NULL,
                code                  VARCHAR(20)   NOT NULL,
                name                  VARCHAR(150)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                type                  VARCHAR(10)   NOT NULL,
                subtype               VARCHAR(20)   NOT NULL DEFAULT \'other\',
                is_group              BOOLEAN       NOT NULL DEFAULT FALSE,
                role                  VARCHAR(40),
                allows_manual         BOOLEAN       NOT NULL DEFAULT TRUE,
                ou_id                 INTEGER                REFERENCES organizational_units(id) ON DELETE SET NULL,
                currency              VARCHAR(3),
                is_seeded             BOOLEAN       NOT NULL DEFAULT FALSE,
                owner_plugin          VARCHAR(100),
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_accounts_code ON acc_accounts(tenant_id, code)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_accounts_role ON acc_accounts(tenant_id, role) WHERE role IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_accounts_parent ON acc_accounts(tenant_id, parent_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_journals (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                code                  VARCHAR(12)   NOT NULL,
                name                  VARCHAR(100)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                kind                  VARCHAR(10)   NOT NULL,
                default_account_id    INTEGER                REFERENCES acc_accounts(id) ON DELETE SET NULL,
                owner_plugin          VARCHAR(100),
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_journals_code ON acc_journals(tenant_id, code)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_fiscal_years (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                starts_on             DATE          NOT NULL,
                ends_on               DATE          NOT NULL,
                status                VARCHAR(10)   NOT NULL DEFAULT \'open\',
                -- @reference-lint-ignore: the year-end closing entry (phase A5); entries live forever
                closing_entry_id      BIGINT,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_fiscal_years ON acc_fiscal_years(tenant_id, starts_on)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_periods (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)          ON DELETE CASCADE,
                fiscal_year_id        INTEGER       NOT NULL REFERENCES acc_fiscal_years(id) ON DELETE CASCADE,
                starts_on             DATE          NOT NULL,
                ends_on               DATE          NOT NULL,
                status                VARCHAR(10)   NOT NULL DEFAULT \'open\',
                closed_at             TIMESTAMP,
                closed_by_profile_id  INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                reopened_at           TIMESTAMP,
                reopened_by_profile_id INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                reopen_reason         VARCHAR(500)  NOT NULL DEFAULT \'\',
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_periods ON acc_periods(tenant_id, starts_on)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS acc_periods');
        $db->exec('DROP TABLE IF EXISTS acc_fiscal_years');
        $db->exec('DROP TABLE IF EXISTS acc_journals');
        $db->exec('DROP TABLE IF EXISTS acc_accounts');
        $db->exec('DROP TABLE IF EXISTS acc_books');
    }
}
