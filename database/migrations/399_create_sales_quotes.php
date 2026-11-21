<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateSalesQuotes — quotes to customers (WHIT-1321, design section 34 §3,
 * register QT.1-QT.12).
 *
 * - `sales_quotes`: one row per revision. A quote takes its number
 *   (`QUO-0318`) on first send; a changed sent quote is a new row with the
 *   same number and the next `revision`, pointing at the one it replaces.
 *   Status: `draft`, `waiting_approval` (its route), `ready`, `sent`,
 *   `accepted`, `declined`, `expired`, `converted`, `cancelled`, or
 *   `replaced` (a later revision exists). Totals are minor units in the
 *   quote's currency (CU.1-CU.4). The customer's link is stored only as the
 *   hash of its token (`public_token_hash`, unique across workspaces, as the
 *   booking links of migration 248 are).
 * - `sales_quote_lines`: an item variant (inventory, any kind including a
 *   service) or a free line with only a description. `quantity` is whole
 *   steps of `quantity_decimals` (IV.3: no floats); for an item line
 *   `base_quantity` is the same in the item's base unit, ready for a sales
 *   order. `tax_category` is kept so accounting's tax engine can fill
 *   `tax_bp` from a tax code without a migration (QT.2).
 * - `sales_quote_conversions`: what an accepted quote turned into (a sales
 *   order, an invoice, a plugin's own target), once per target (QT.6).
 *
 * Grants `quotes:read` and `quotes:write` by capability to the roles that run
 * the workspace (anchors `settings:write`, `roles:write`), as migration 321
 * does and ci-grant-by-role-name-guard enforces.
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateSalesQuotes
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::QUOTES_READ => 'See quotes to customers',
        CorePermissions::QUOTES_WRITE => 'Draft, send and turn quotes into orders or invoices',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::QUOTES_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
        CorePermissions::QUOTES_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
    ];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS sales_quotes (
                id                      SERIAL PRIMARY KEY,
                tenant_id               INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                number                  VARCHAR(30),
                revision                INTEGER       NOT NULL DEFAULT 1,
                replaces_id             INTEGER                REFERENCES sales_quotes(id) ON DELETE SET NULL,
                status                  VARCHAR(20)   NOT NULL DEFAULT \'draft\',
                contact_id              INTEGER       NOT NULL REFERENCES contacts(id),
                issue_date              DATE,
                valid_until             DATE          NOT NULL,
                currency                VARCHAR(3)    NOT NULL,
                prices_include_tax      BOOLEAN       NOT NULL DEFAULT FALSE,
                subtotal_minor          BIGINT        NOT NULL DEFAULT 0,
                tax_minor               BIGINT        NOT NULL DEFAULT 0,
                total_minor             BIGINT        NOT NULL DEFAULT 0,
                notes                   VARCHAR(2000) NOT NULL DEFAULT \'\',
                terms                   VARCHAR(4000) NOT NULL DEFAULT \'\',
                route_id                BIGINT                 REFERENCES document_routes(id) ON DELETE SET NULL,
                document_id             BIGINT                 REFERENCES documents(id) ON DELETE SET NULL,
                public_token_hash       VARCHAR(64),
                idempotency_key         VARCHAR(128),
                source_plugin           VARCHAR(60),
                created_by_profile_id   INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed it, kept for the trail even after the agent is removed
                agent_id                INTEGER,
                on_behalf_of_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                approved_by_profile_id  INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                decided_by_name         VARCHAR(200)  NOT NULL DEFAULT \'\',
                decided_by_profile_id   INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                decided_online          BOOLEAN       NOT NULL DEFAULT FALSE,
                decline_reason          VARCHAR(1000) NOT NULL DEFAULT \'\',
                submitted_at            TIMESTAMP,
                approved_at             TIMESTAMP,
                sent_at                 TIMESTAMP,
                decided_at              TIMESTAMP,
                converted_at            TIMESTAMP,
                cancelled_at            TIMESTAMP,
                created_at              TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quotes_number ON sales_quotes(tenant_id, number, revision) WHERE number IS NOT NULL');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_quotes_status ON sales_quotes(tenant_id, status, valid_until)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_quotes_contact ON sales_quotes(tenant_id, contact_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quotes_token ON sales_quotes(public_token_hash) WHERE public_token_hash IS NOT NULL');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quotes_idempotency ON sales_quotes(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS sales_quote_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                quote_id              INTEGER       NOT NULL REFERENCES sales_quotes(id) ON DELETE CASCADE,
                position              INTEGER       NOT NULL DEFAULT 0,
                variant_id            INTEGER                REFERENCES inv_variants(id),
                unit_id               INTEGER                REFERENCES inv_units(id),
                description           VARCHAR(500)  NOT NULL DEFAULT \'\',
                quantity              BIGINT        NOT NULL,
                quantity_decimals     SMALLINT      NOT NULL DEFAULT 0,
                base_quantity         BIGINT,
                price_minor           BIGINT        NOT NULL DEFAULT 0,
                discount_bp           INTEGER       NOT NULL DEFAULT 0,
                tax_category          VARCHAR(40)   NOT NULL DEFAULT \'\',
                tax_bp                INTEGER       NOT NULL DEFAULT 0,
                line_total_minor      BIGINT        NOT NULL DEFAULT 0,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_quote_lines_quote ON sales_quote_lines(tenant_id, quote_id, position)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_quote_lines_variant ON sales_quote_lines(tenant_id, variant_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS sales_quote_conversions (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                quote_id              INTEGER       NOT NULL REFERENCES sales_quotes(id) ON DELETE CASCADE,
                target                VARCHAR(60)   NOT NULL,
                -- @reference-lint-ignore: the record the target made (a sales order, an invoice, a plugin record); its table depends on the target
                target_id             BIGINT        NOT NULL,
                target_label          VARCHAR(100)  NOT NULL DEFAULT \'\',
                converted_by_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quote_conversions ON sales_quote_conversions(tenant_id, quote_id, target)');

        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
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
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query(
                'DELETE FROM permissions
                  WHERE name = :name
                    AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id)',
                [':name' => $name]
            );
        }

        $db->exec('DROP TABLE IF EXISTS sales_quote_conversions');
        $db->exec('DROP TABLE IF EXISTS sales_quote_lines');
        $db->exec('DROP TABLE IF EXISTS sales_quotes');
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
