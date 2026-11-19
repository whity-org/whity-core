<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateInventoryOrders — purchase orders, what suppliers sell, and receiving
 * against an order (WHIT-1234, design section 31 §3.7, register IV.12).
 *
 * - `inv_orders`: purchase orders now, sales orders in P5 (`kind`). Numbered
 *   from the workspace's sequence (`PO-0001`), a supplier contact, the place it
 *   is received into, and a status: `draft`, `waiting_approval` (its route),
 *   `approved`, `partly_done` (some received), `done`, `cancelled`. Totals are
 *   minor units in the order's currency (CU.1-CU.4).
 * - `inv_order_lines`: what is ordered, in the unit it is bought in, at that
 *   unit's price. `quantity` and `done_quantity` are base-unit steps, as on the
 *   ledger (IV.3); `is_closed` marks a short line that won't come.
 * - `inv_supplier_items`: what a supplier sells us, at what price, in which
 *   unit; the preferred one fills a new order's price.
 * - `inv_operations.order_id` and `inv_operation_lines.order_line_id`: a
 *   receipt is an operation (kind `receipt`) against an order.
 *
 * Also grants `purchases:read` and `purchases:write` to the roles that run the
 * workspace (anchors `settings:write`, `roles:write`), by capability as
 * migration 110 set and ci-grant-by-role-name-guard enforces.
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateInventoryOrders
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::PURCHASES_READ => 'See purchase orders and what suppliers sell',
        CorePermissions::PURCHASES_WRITE => 'Draft, send and receive purchase orders',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::PURCHASES_READ => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
        CorePermissions::PURCHASES_WRITE => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
    ];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_orders (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                kind                  VARCHAR(10)   NOT NULL DEFAULT \'purchase\',
                number                VARCHAR(30)   NOT NULL,
                status                VARCHAR(20)   NOT NULL DEFAULT \'draft\',
                contact_id            INTEGER       NOT NULL REFERENCES contacts(id),
                location_id           INTEGER       NOT NULL REFERENCES inv_locations(id),
                expected_on           DATE,
                currency              VARCHAR(3)    NOT NULL,
                subtotal_minor        BIGINT        NOT NULL DEFAULT 0,
                tax_minor             BIGINT        NOT NULL DEFAULT 0,
                total_minor           BIGINT        NOT NULL DEFAULT 0,
                notes                 VARCHAR(2000) NOT NULL DEFAULT \'\',
                external_ref          VARCHAR(100)  NOT NULL DEFAULT \'\',
                route_id              BIGINT                 REFERENCES document_routes(id) ON DELETE SET NULL,
                document_id           BIGINT                 REFERENCES documents(id) ON DELETE SET NULL,
                payment_status        VARCHAR(20),
                idempotency_key       VARCHAR(128),
                source_plugin         VARCHAR(60),
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed it, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                approved_by_profile_id INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                submitted_at          TIMESTAMP,
                approved_at           TIMESTAMP,
                done_at               TIMESTAMP,
                cancelled_at          TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_orders_number ON inv_orders(tenant_id, kind, number)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_orders_status ON inv_orders(tenant_id, kind, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_orders_contact ON inv_orders(tenant_id, contact_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_orders_idempotency ON inv_orders(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_order_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)    ON DELETE CASCADE,
                order_id              INTEGER       NOT NULL REFERENCES inv_orders(id) ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id),
                unit_id               INTEGER       NOT NULL REFERENCES inv_units(id),
                quantity              BIGINT        NOT NULL,
                entered_quantity      BIGINT        NOT NULL,
                done_quantity         BIGINT        NOT NULL DEFAULT 0,
                price_minor           BIGINT        NOT NULL DEFAULT 0,
                discount_bp           INTEGER       NOT NULL DEFAULT 0,
                tax_bp                INTEGER       NOT NULL DEFAULT 0,
                line_total_minor      BIGINT        NOT NULL DEFAULT 0,
                is_closed             BOOLEAN       NOT NULL DEFAULT FALSE,
                note                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_order_lines_order ON inv_order_lines(tenant_id, order_id, sort_order)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_order_lines_variant ON inv_order_lines(tenant_id, variant_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_supplier_items (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id) ON DELETE CASCADE,
                contact_id            INTEGER       NOT NULL REFERENCES contacts(id)     ON DELETE CASCADE,
                supplier_sku          VARCHAR(64)   NOT NULL DEFAULT \'\',
                unit_id               INTEGER       NOT NULL REFERENCES inv_units(id),
                price_minor           BIGINT        NOT NULL,
                currency              VARCHAR(3)    NOT NULL,
                min_quantity          BIGINT,
                lead_days             INTEGER,
                is_preferred          BOOLEAN       NOT NULL DEFAULT FALSE,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_supplier_items ON inv_supplier_items(tenant_id, variant_id, contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_supplier_items_contact ON inv_supplier_items(tenant_id, contact_id)');

        $db->exec('ALTER TABLE inv_operations ADD COLUMN IF NOT EXISTS order_id INTEGER REFERENCES inv_orders(id) ON DELETE SET NULL');
        $db->exec('ALTER TABLE inv_operation_lines ADD COLUMN IF NOT EXISTS order_line_id INTEGER REFERENCES inv_order_lines(id) ON DELETE SET NULL');
        // migration-lint: allow index-not-concurrent (inv_operations arrived in migration 320 one phase ago and holds a handful of rows; there are no customers yet)
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_operations_order ON inv_operations(tenant_id, order_id)');

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

        $db->exec('DROP INDEX IF EXISTS idx_inv_operations_order');
        $db->exec('ALTER TABLE inv_operation_lines DROP COLUMN IF EXISTS order_line_id');
        $db->exec('ALTER TABLE inv_operations DROP COLUMN IF EXISTS order_id');
        $db->exec('DROP TABLE IF EXISTS inv_supplier_items');
        $db->exec('DROP TABLE IF EXISTS inv_order_lines');
        $db->exec('DROP TABLE IF EXISTS inv_orders');
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
