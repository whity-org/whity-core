<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateAccountingInvoices — tax codes, sales invoices and credit notes, and
 * what settles them (WHIT-1318, design section 32 §3.2, §5.2, §7, register
 * AC.10–AC.12, AC.17–AC.20). Phase A2a.
 *
 * - `acc_tax_codes`: a rate, a direction (output on sales, input on
 *   purchases), a treatment and the account the tax goes to. Rates change by
 *   date: a new row with `valid_from`, never an edited rate. The Jordan pack's
 *   codes are made when accounting is first opened, by AccountingWorkspace.
 * - `acc_invoices`: invoices, credit notes, and later bills and supplier
 *   credits (`kind`), one table because they share lines, tax, allocation and
 *   the aged reports. A number is given when issued, so numbers have no gaps;
 *   once issued an invoice never changes except its projections (`paid_minor`,
 *   `credited_minor`, `status`) and the e-invoice columns, which the
 *   e-invoicing point fills.
 * - `acc_invoice_lines`: each line's net, tax and total, its income account
 *   and its tax code.
 * - `acc_allocations`: what settles what. A credit note settles its invoice
 *   here now; payments (phase A2b) settle invoices here too. Invoices'
 *   `paid_minor` and `credited_minor` are sums of these rows.
 *
 * Amounts are minor units of the document's currency. Every document and line
 * stores `currency` with `rate_num`/`rate_den` (1/1 in the base currency
 * until multi-currency, A7), so A7 needs no migration.
 *
 * Permissions `invoices:read` and `invoices:write`, granted by capability:
 * read to the roles that run the workspace or post to the books, write to
 * those holding `settings:write` or `accounting:post` (so each workspace's
 * Accountant role gets both).
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateAccountingInvoices
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::INVOICES_READ => 'See invoices and credit notes',
        CorePermissions::INVOICES_WRITE => 'Draft, issue and credit invoices',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::INVOICES_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE, CorePermissions::ACCOUNTING_POST],
        CorePermissions::INVOICES_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ACCOUNTING_POST],
    ];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_tax_codes (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                code                  VARCHAR(30)   NOT NULL,
                name                  VARCHAR(120)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                pack                  VARCHAR(40)   NOT NULL DEFAULT \'jo\',
                direction             VARCHAR(10)   NOT NULL,
                rate_bp               INTEGER       NOT NULL DEFAULT 0,
                treatment             VARCHAR(20)   NOT NULL DEFAULT \'standard\',
                category              VARCHAR(40)   NOT NULL DEFAULT \'\',
                account_id            INTEGER                REFERENCES acc_accounts(id) ON DELETE SET NULL,
                return_box            VARCHAR(40)   NOT NULL DEFAULT \'\',
                einvoice_category     VARCHAR(10)   NOT NULL DEFAULT \'\',
                valid_from            DATE,
                valid_to              DATE,
                is_seeded             BOOLEAN       NOT NULL DEFAULT FALSE,
                owner_plugin          VARCHAR(100),
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                CONSTRAINT ck_acc_tax_codes_rate CHECK (rate_bp >= 0 AND rate_bp <= 10000)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_tax_codes_code ON acc_tax_codes(tenant_id, code)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_tax_codes_category ON acc_tax_codes(tenant_id, direction, category)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_invoices (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)       ON DELETE CASCADE,
                kind                  VARCHAR(20)   NOT NULL,
                number                VARCHAR(40),
                supplier_number       VARCHAR(80),
                contact_id            INTEGER       NOT NULL REFERENCES contacts(id),
                issue_date            DATE          NOT NULL,
                supply_date           DATE,
                due_date              DATE,
                payment_terms_days    INTEGER       NOT NULL DEFAULT 0,
                status                VARCHAR(20)   NOT NULL DEFAULT \'draft\',
                currency              VARCHAR(3)    NOT NULL,
                rate_num              BIGINT        NOT NULL DEFAULT 1,
                rate_den              BIGINT        NOT NULL DEFAULT 1,
                prices_include_tax    BOOLEAN       NOT NULL DEFAULT FALSE,
                subtotal_minor        BIGINT        NOT NULL DEFAULT 0,
                discount_minor        BIGINT        NOT NULL DEFAULT 0,
                tax_minor             BIGINT        NOT NULL DEFAULT 0,
                total_minor           BIGINT        NOT NULL DEFAULT 0,
                paid_minor            BIGINT        NOT NULL DEFAULT 0,
                credited_minor        BIGINT        NOT NULL DEFAULT 0,
                credits_invoice_id    INTEGER                REFERENCES acc_invoices(id),
                -- @reference-lint-ignore: the posted entry; entries live forever and are never deleted
                entry_id              BIGINT,
                -- @reference-lint-ignore: the printed copy in issued_documents, once printing is wired
                document_id           INTEGER,
                -- @reference-lint-ignore: the approval route in document_routes, removed with its subject only by workspace purge
                route_id              INTEGER,
                payment_link_ref      VARCHAR(200),
                einvoice_status       VARCHAR(20)   NOT NULL DEFAULT \'none\',
                einvoice_uuid         VARCHAR(80),
                einvoice_ref          VARCHAR(200),
                einvoice_qr           TEXT,
                einvoice_counter      BIGINT,
                einvoice_message      VARCHAR(500)  NOT NULL DEFAULT \'\',
                notes                 VARCHAR(2000) NOT NULL DEFAULT \'\',
                external_ref          VARCHAR(160),
                -- @reference-lint-ignore: the quote it came from, kept after the quote is gone
                quote_id              INTEGER,
                issued_at             TIMESTAMP,
                issued_by_profile_id  INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed the invoice, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                owner_plugin          VARCHAR(100),
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                CONSTRAINT ck_acc_invoices_amounts CHECK (total_minor >= 0 AND paid_minor >= 0 AND credited_minor >= 0)
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_invoices_number ON acc_invoices(tenant_id, kind, number) WHERE number IS NOT NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_acc_invoices_external ON acc_invoices(tenant_id, external_ref) WHERE external_ref IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoices_status ON acc_invoices(tenant_id, kind, status, due_date)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoices_contact ON acc_invoices(tenant_id, contact_id, kind)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoices_issued ON acc_invoices(tenant_id, issued_at) WHERE issued_at IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_invoice_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)        ON DELETE CASCADE,
                invoice_id            INTEGER       NOT NULL REFERENCES acc_invoices(id)   ON DELETE CASCADE,
                line_no               INTEGER       NOT NULL,
                -- @reference-lint-ignore: the item sold, kept as written after the item is removed
                variant_id            INTEGER,
                -- @reference-lint-ignore: the unit it was sold in, kept as written
                unit_id               INTEGER,
                description           VARCHAR(500)  NOT NULL DEFAULT \'\',
                quantity              BIGINT        NOT NULL,
                quantity_decimals     INTEGER       NOT NULL DEFAULT 0,
                unit_price_minor      BIGINT        NOT NULL DEFAULT 0,
                discount_bp           INTEGER       NOT NULL DEFAULT 0,
                net_minor             BIGINT        NOT NULL DEFAULT 0,
                tax_code_id           INTEGER       NOT NULL REFERENCES acc_tax_codes(id),
                tax_minor             BIGINT        NOT NULL DEFAULT 0,
                total_minor           BIGINT        NOT NULL DEFAULT 0,
                account_id            INTEGER       NOT NULL REFERENCES acc_accounts(id),
                -- @reference-lint-ignore: the branch, kept as written after the unit is removed
                ou_id                 INTEGER,
                credits_line_id       INTEGER                REFERENCES acc_invoice_lines(id),
                CONSTRAINT ck_acc_invoice_lines_amounts CHECK (quantity > 0 AND unit_price_minor >= 0 AND discount_bp >= 0 AND discount_bp <= 10000)
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoice_lines_invoice ON acc_invoice_lines(tenant_id, invoice_id, line_no)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_invoice_lines_credits ON acc_invoice_lines(tenant_id, credits_line_id) WHERE credits_line_id IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS acc_allocations (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)        ON DELETE CASCADE,
                invoice_id            INTEGER       NOT NULL REFERENCES acc_invoices(id),
                credit_invoice_id     INTEGER                REFERENCES acc_invoices(id),
                -- @reference-lint-ignore: acc_payments arrives in phase A2b; until then always NULL
                payment_id            INTEGER,
                amount_minor          BIGINT        NOT NULL,
                allocated_on          DATE          NOT NULL,
                -- @reference-lint-ignore: the entry the allocation posted, when it posts one; entries live forever
                entry_id              BIGINT,
                reversed_at           TIMESTAMP,
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                CONSTRAINT ck_acc_allocations_amount CHECK (amount_minor > 0),
                CONSTRAINT ck_acc_allocations_source CHECK ((credit_invoice_id IS NULL) <> (payment_id IS NULL))
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_allocations_invoice ON acc_allocations(tenant_id, invoice_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_acc_allocations_credit ON acc_allocations(tenant_id, credit_invoice_id) WHERE credit_invoice_id IS NOT NULL');

        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }
        foreach (self::AUDIENCES as $slug => $audience) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audience) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId !== null) {
                $db->query('DELETE FROM role_permissions WHERE permission_id = :id', [':id' => $permissionId]);
                $db->query('DELETE FROM permissions WHERE id = :id', [':id' => $permissionId]);
            }
        }
        $db->exec('DROP TABLE IF EXISTS acc_allocations');
        $db->exec('DROP TABLE IF EXISTS acc_invoice_lines');
        $db->exec('DROP TABLE IF EXISTS acc_invoices');
        $db->exec('DROP TABLE IF EXISTS acc_tax_codes');
    }

    /**
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
