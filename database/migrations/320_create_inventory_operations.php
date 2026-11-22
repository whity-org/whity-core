<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateInventoryOperations — adjustments, counts and transfers as records,
 * the reasons they take, and reorder levels (WHIT-1233, design section 31,
 * register IV.11, IV.14, IV.15, IV.16).
 *
 * - `inv_reasons`: why stock changed outside a sale, delivery or transfer
 *   (expired, damaged, found, count correction, ...). Seeded per workspace the
 *   first time inventory runs there; `direction` says whether a reason takes
 *   stock out, in, or both, and `is_waste` marks the ones the waste figures add
 *   up. Archived, never deleted: old moves keep their code.
 * - `inv_operations`: one adjustment, count or transfer, with its number
 *   (`ADJ-0001`, `CNT-0001`, `TRF-0001`), its state (`draft`,
 *   `waiting_approval`, `in_transit`, `done`, `cancelled`) and the batch of
 *   moves it posted. Above the workspace's value an adjustment or a count's
 *   differences travel a route first (IV.14, IV.15); `route_id` is that route.
 * - `inv_operation_lines`: its lines. For an adjustment `quantity` is the
 *   signed change; for a transfer the amount sent; for a count what was counted,
 *   with `expected_quantity` taken when that line was counted (IV.15).
 *   Quantities are base-unit steps, as on the ledger (IV.3).
 * - `inv_reorder_rules`: a reorder level per variant and place (IV.11).
 *   `is_below` remembers that the level is under it, so going below fires one
 *   event until stock comes back.
 *
 * Also grants `stock:count` (every staff role: anchor `documents:read`) and
 * `stock:transfer` (the roles that run the workspace), by capability as
 * migration 110 set and ci-grant-by-role-name-guard enforces.
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateInventoryOperations
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::STOCK_COUNT => 'Count stock and finish counts',
        CorePermissions::STOCK_TRANSFER => 'Send and receive stock between places',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCES = [
        CorePermissions::STOCK_COUNT => [CorePermissions::DOCUMENTS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::STOCK_TRANSFER => [CorePermissions::SETTINGS_WRITE, CorePermissions::ROLES_WRITE],
    ];

    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_reasons (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                code                  VARCHAR(40)   NOT NULL,
                name                  VARCHAR(150)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                direction             VARCHAR(4)    NOT NULL DEFAULT \'both\',
                is_waste              BOOLEAN       NOT NULL DEFAULT FALSE,
                is_seeded             BOOLEAN       NOT NULL DEFAULT FALSE,
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_reasons_code ON inv_reasons(tenant_id, code)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_operations (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                kind                  VARCHAR(20)   NOT NULL,
                number                VARCHAR(30)   NOT NULL,
                status                VARCHAR(20)   NOT NULL DEFAULT \'draft\',
                from_location_id      INTEGER                REFERENCES inv_locations(id),
                to_location_id        INTEGER                REFERENCES inv_locations(id),
                category_id           INTEGER                REFERENCES inv_categories(id) ON DELETE SET NULL,
                reason_code           VARCHAR(40)   NOT NULL DEFAULT \'\',
                note                  VARCHAR(1000) NOT NULL DEFAULT \'\',
                is_blind              BOOLEAN       NOT NULL DEFAULT FALSE,
                value_minor           BIGINT,
                currency              VARCHAR(3),
                route_id              BIGINT                 REFERENCES document_routes(id) ON DELETE SET NULL,
                batch_key             VARCHAR(64),
                sent_batch_key        VARCHAR(64),
                idempotency_key       VARCHAR(128),
                source_plugin         VARCHAR(60),
                created_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed it, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                done_by_profile_id    INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                occurred_at           TIMESTAMP,
                submitted_at          TIMESTAMP,
                sent_at               TIMESTAMP,
                done_at               TIMESTAMP,
                cancelled_at          TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_operations_number ON inv_operations(tenant_id, kind, number)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_operations_status ON inv_operations(tenant_id, status, kind)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_operations_idempotency ON inv_operations(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_operation_lines (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)        ON DELETE CASCADE,
                operation_id          INTEGER       NOT NULL REFERENCES inv_operations(id) ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id),
                -- @reference-lint-ignore: inv_lots arrives in migration 322 (P4); until then always NULL
                lot_id                INTEGER,
                quantity              BIGINT,
                entered_unit_id       INTEGER                REFERENCES inv_units(id),
                entered_quantity      BIGINT,
                expected_quantity     BIGINT,
                counted_at            TIMESTAMP,
                counted_by_profile_id INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                received_quantity     BIGINT,
                reason_code           VARCHAR(40)   NOT NULL DEFAULT \'\',
                note                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                value_minor           BIGINT,
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_operation_lines_op ON inv_operation_lines(tenant_id, operation_id, sort_order)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_operation_lines_variant ON inv_operation_lines(tenant_id, variant_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_reorder_rules (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)       ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id)  ON DELETE CASCADE,
                location_id           INTEGER       NOT NULL REFERENCES inv_locations(id) ON DELETE CASCADE,
                min_quantity          BIGINT        NOT NULL,
                order_up_to           BIGINT,
                supplier_contact_id   INTEGER                REFERENCES contacts(id)      ON DELETE SET NULL,
                lead_days             INTEGER,
                is_active             BOOLEAN       NOT NULL DEFAULT TRUE,
                is_below              BOOLEAN       NOT NULL DEFAULT FALSE,
                below_since           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_reorder_rules ON inv_reorder_rules(tenant_id, variant_id, location_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_reorder_rules_below ON inv_reorder_rules(tenant_id, is_below)');

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

        $db->exec('DROP TABLE IF EXISTS inv_reorder_rules');
        $db->exec('DROP TABLE IF EXISTS inv_operation_lines');
        $db->exec('DROP TABLE IF EXISTS inv_operations');
        $db->exec('DROP TABLE IF EXISTS inv_reasons');
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
