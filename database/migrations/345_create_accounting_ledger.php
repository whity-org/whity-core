<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateAccountingLedger — the double-entry ledger, its monthly balances and
 * manual entries (WHIT-1318, design section 32 §3.3, register AC.3, AC.4,
 * AC.10).
 *
 * - `acc_entries` and `acc_lines`: THE LEDGER. An entry is two or more lines
 *   whose debits equal their credits; each line is a debit or a credit, never
 *   both. Append-only: a trigger refuses UPDATE, and DELETE unless the
 *   workspace itself is being deleted (its `tenants` row is already gone when
 *   the cascade reaches here). Corrections are reversing entries
 *   (`reverses_entry_id`). Each entry carries `hash`, chained to the one before
 *   it in the same workspace (`prev_hash`), so an export can be verified.
 *   On PostgreSQL a deferred constraint trigger also refuses, at commit, an
 *   entry whose lines don't balance; the posting service checks the same on
 *   both engines.
 *   Who posted is kept as profile ids with no foreign key: the trail must
 *   outlive a removed person, and a SET NULL would be an UPDATE the ledger
 *   refuses.
 * - `acc_balances`: the projection, debits and credits per account, period and
 *   branch (`ou_id` 0 for none, so one unique key works on both engines). Kept
 *   in step by the posting service in the same transaction, and rebuildable
 *   from the lines (`accounting:rebuild-balances`).
 * - `acc_manual_entries` and `acc_manual_entry_lines`: drafts people write. The
 *   ledger only ever receives posted entries; a draft points at its entry once
 *   posted.
 *
 * Lines keep `contact_id` and `ou_id` with no foreign key, like the people:
 * what was posted stays as it was posted. Every table is tenant-owned.
 * Idempotent and reversible.
 */
final class CreateAccountingLedger
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_entries (
                id                    BIGSERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                journal_id            INTEGER       NOT NULL REFERENCES acc_journals(id),
                period_id             INTEGER       NOT NULL REFERENCES acc_periods(id),
                number                VARCHAR(40)   NOT NULL,
                entry_date            DATE          NOT NULL,
                memo                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                source_type           VARCHAR(60)   NOT NULL,
                -- @reference-lint-ignore: the record that caused the entry, whose table depends on source_type
                source_id             BIGINT,
                -- @reference-lint-ignore: the entry this one reverses; both rows live forever, so the reference cannot dangle
                reverses_entry_id     BIGINT,
                currency              VARCHAR(3)    NOT NULL,
                total_minor           BIGINT        NOT NULL,
                idempotency_key       VARCHAR(160),
                -- @reference-lint-ignore: kept for the trail after the person is removed; a SET NULL would be an UPDATE the ledger refuses
                posted_by_profile_id  INTEGER,
                -- @reference-lint-ignore: the agent that proposed the entry, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                -- @reference-lint-ignore: the person an agent acted for, kept for the trail after they are removed
                on_behalf_of_profile_id INTEGER,
                prev_hash             VARCHAR(64)   NOT NULL DEFAULT \'\',
                hash                  VARCHAR(64)   NOT NULL,
                posted_at             TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_entries_number ON acc_entries(tenant_id, journal_id, number)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_entries_journal ON acc_entries(tenant_id, journal_id, entry_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_entries_date ON acc_entries(tenant_id, entry_date, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_entries_source ON acc_entries(tenant_id, source_type, source_id)');
        // An entry is reversed once, even when two people press Reverse together.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_entries_reverses ON acc_entries(tenant_id, reverses_entry_id) WHERE reverses_entry_id IS NOT NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_entries_idempotency ON acc_entries(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_lines (
                id                    BIGSERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                entry_id              BIGINT        NOT NULL REFERENCES acc_entries(id)  ON DELETE CASCADE,
                line_no               INTEGER       NOT NULL,
                account_id            INTEGER       NOT NULL REFERENCES acc_accounts(id),
                period_id             INTEGER       NOT NULL REFERENCES acc_periods(id),
                entry_date            DATE          NOT NULL,
                debit_minor           BIGINT        NOT NULL DEFAULT 0,
                credit_minor          BIGINT        NOT NULL DEFAULT 0,
                currency              VARCHAR(3)    NOT NULL,
                amount_minor          BIGINT        NOT NULL,
                rate_num              BIGINT        NOT NULL DEFAULT 1,
                rate_den              BIGINT        NOT NULL DEFAULT 1,
                -- @reference-lint-ignore: kept as written after the contact is removed; a SET NULL would be an UPDATE the ledger refuses
                contact_id            INTEGER,
                -- @reference-lint-ignore: the branch, kept as written after the unit is removed, for the same reason
                ou_id                 INTEGER,
                -- @reference-lint-ignore: acc_tax_codes arrives in phase A2; until then always NULL
                tax_code_id           INTEGER,
                tax_base_minor        BIGINT,
                description           VARCHAR(500)  NOT NULL DEFAULT \'\',
                source_line_type      VARCHAR(60),
                -- @reference-lint-ignore: the line of the source record, whose table depends on source_line_type
                source_line_id        BIGINT,
                CONSTRAINT ck_acc_lines_one_side CHECK (
                    (debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0)
                )
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_lines_entry ON acc_lines(tenant_id, entry_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_lines_account ON acc_lines(tenant_id, account_id, entry_date, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_lines_contact ON acc_lines(tenant_id, contact_id, account_id) WHERE contact_id IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_balances (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                account_id            INTEGER       NOT NULL REFERENCES acc_accounts(id) ON DELETE CASCADE,
                period_id             INTEGER       NOT NULL REFERENCES acc_periods(id)  ON DELETE CASCADE,
                -- @reference-lint-ignore: 0 means no branch, so one unique key works on both engines
                ou_id                 INTEGER       NOT NULL DEFAULT 0,
                debit_minor           BIGINT        NOT NULL DEFAULT 0,
                credit_minor          BIGINT        NOT NULL DEFAULT 0,
                -- @reference-lint-ignore: the last ledger line folded into this balance, for the rebuild check
                last_line_id          BIGINT,
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_balances ON acc_balances(tenant_id, account_id, period_id, ou_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_balances_period ON acc_balances(tenant_id, period_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_manual_entries (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                journal_id            INTEGER       NOT NULL REFERENCES acc_journals(id),
                entry_date            DATE          NOT NULL,
                memo                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                status                VARCHAR(20)   NOT NULL DEFAULT \'draft\',
                entry_id              BIGINT                 REFERENCES acc_entries(id),
                -- @reference-lint-ignore: the approval route (A1b); routes are kept with their history
                route_id              INTEGER,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed the entry, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_manual_entries_status ON acc_manual_entries(tenant_id, status, entry_date)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_manual_entry_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)            ON DELETE CASCADE,
                manual_entry_id       INTEGER       NOT NULL REFERENCES acc_manual_entries(id) ON DELETE CASCADE,
                line_no               INTEGER       NOT NULL,
                account_id            INTEGER       NOT NULL REFERENCES acc_accounts(id),
                debit_minor           BIGINT        NOT NULL DEFAULT 0,
                credit_minor          BIGINT        NOT NULL DEFAULT 0,
                contact_id            INTEGER                REFERENCES contacts(id) ON DELETE SET NULL,
                ou_id                 INTEGER                REFERENCES organizational_units(id) ON DELETE SET NULL,
                description           VARCHAR(500)  NOT NULL DEFAULT \'\'
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_manual_entry_lines ON acc_manual_entry_lines(tenant_id, manual_entry_id)');

        self::appendOnly($db, 'acc_entries');
        self::appendOnly($db, 'acc_lines');
        self::balancedAtCommit($db);
    }

    public static function down(Database $db): void
    {
        if (self::isPostgres($db)) {
            $db->exec('DROP TRIGGER IF EXISTS trg_acc_lines_balanced ON acc_lines');
            $db->exec('DROP FUNCTION IF EXISTS acc_lines_balanced()');
            foreach (['acc_entries', 'acc_lines'] as $table) {
                $db->exec("DROP TRIGGER IF EXISTS trg_{$table}_append_only ON {$table}");
            }
            $db->exec('DROP FUNCTION IF EXISTS acc_ledger_append_only()');
        } else {
            foreach (['acc_entries', 'acc_lines'] as $table) {
                $db->exec("DROP TRIGGER IF EXISTS trg_{$table}_no_update");
                $db->exec("DROP TRIGGER IF EXISTS trg_{$table}_no_delete");
            }
        }
        $db->exec('DROP TABLE IF EXISTS acc_manual_entry_lines');
        $db->exec('DROP TABLE IF EXISTS acc_manual_entries');
        $db->exec('DROP TABLE IF EXISTS acc_balances');
        $db->exec('DROP TABLE IF EXISTS acc_lines');
        $db->exec('DROP TABLE IF EXISTS acc_entries');
    }

    /**
     * The ledger refuses UPDATE always, and DELETE while its workspace exists.
     * Deleting a workspace cascades here after its `tenants` row is gone, so
     * the purge still works.
     */
    private static function appendOnly(Database $db, string $table): void
    {
        if (self::isPostgres($db)) {
            $db->exec(<<<'SQL'
                CREATE OR REPLACE FUNCTION acc_ledger_append_only() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION '% is append-only: post a reversing entry instead', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql
                SQL);
            $db->exec("DROP TRIGGER IF EXISTS trg_{$table}_append_only ON {$table}");
            $db->exec("CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION acc_ledger_append_only()");

            return;
        }

        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$table}_no_update BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$table} is append-only: post a reversing entry instead'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_{$table}_no_delete BEFORE DELETE ON {$table}
            WHEN EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id)
            BEGIN SELECT RAISE(ABORT, '{$table} is append-only: post a reversing entry instead'); END");
    }

    /** PostgreSQL only: at commit, every entry that gained lines must balance. */
    private static function balancedAtCommit(Database $db): void
    {
        if (!self::isPostgres($db)) {
            return;
        }
        $db->exec(<<<'SQL'
            CREATE OR REPLACE FUNCTION acc_lines_balanced() RETURNS trigger AS $$
            DECLARE
                diff BIGINT;
            BEGIN
                SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) INTO diff
                  FROM acc_lines WHERE entry_id = NEW.entry_id;
                IF diff <> 0 THEN
                    RAISE EXCEPTION 'acc_entries % does not balance (debits minus credits = %)', NEW.entry_id, diff;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $db->exec('DROP TRIGGER IF EXISTS trg_acc_lines_balanced ON acc_lines');
        $db->exec('CREATE CONSTRAINT TRIGGER trg_acc_lines_balanced AFTER INSERT ON acc_lines
            DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION acc_lines_balanced()');
    }

    private static function isPostgres(Database $db): bool
    {
        return $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
