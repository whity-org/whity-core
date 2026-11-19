<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * CreateInventoryStockLedger — places, the stock ledger and stock levels
 * (WHIT-1232, design section 31, register IV.2, IV.7).
 *
 * - `inv_locations`: places (warehouse, shop, van, a shelf inside one). Places
 *   with `kind = 'virtual'` are the other end of every move that enters or
 *   leaves real stock (`supplier`, `customer`, `loss`, `found`, `production`,
 *   `consumed`, `opening`, `transit`); they hold no levels and are never shown
 *   as stock. A place may belong to an organisational unit (its branch).
 * - `inv_moves`: THE LEDGER. One row per quantity going from one place to
 *   another. Append-only: a trigger refuses UPDATE, and DELETE unless the
 *   workspace itself is being deleted (its `tenants` row is already gone when
 *   the cascade reaches here). Corrections are reversing moves
 *   (`reverses_move_id`). `quantity` is in the variant's base-unit steps and
 *   always > 0. `lot_id` points at `inv_lots` from migration 322.
 * - `inv_levels`: the projection, stock now per variant, place and lot
 *   (`lot_id` 0 for none, so one unique key works on both engines). Kept in
 *   step by the posting service in the same transaction as the moves, and
 *   rebuildable from the ledger (`inventory:rebuild-levels`).
 *
 * Every table is tenant-owned and named literally for TenantOwnedTablesTest
 * and CoreTablesTest. Idempotent and reversible.
 */
final class CreateInventoryStockLedger
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_locations (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)       ON DELETE CASCADE,
                parent_id             INTEGER                REFERENCES inv_locations(id) ON DELETE SET NULL,
                kind                  VARCHAR(10)   NOT NULL DEFAULT \'warehouse\',
                virtual_role          VARCHAR(12),
                name                  VARCHAR(150)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                code                  VARCHAR(20)   NOT NULL DEFAULT \'\',
                ou_id                 INTEGER                REFERENCES organizational_units(id) ON DELETE SET NULL,
                address               VARCHAR(1000) NOT NULL DEFAULT \'\',
                is_active             BOOLEAN       NOT NULL DEFAULT TRUE,
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_locations_tenant ON inv_locations(tenant_id, kind, parent_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_locations_virtual ON inv_locations(tenant_id, virtual_role) WHERE virtual_role IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_moves (
                id                    BIGSERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id),
                -- @reference-lint-ignore: inv_lots arrives in migration 322 (P4); until then always NULL
                lot_id                INTEGER,
                from_location_id      INTEGER       NOT NULL REFERENCES inv_locations(id),
                to_location_id        INTEGER       NOT NULL REFERENCES inv_locations(id),
                quantity              BIGINT        NOT NULL,
                entered_unit_id       INTEGER                REFERENCES inv_units(id),
                entered_quantity      BIGINT,
                unit_cost_minor       BIGINT,
                currency              VARCHAR(3),
                source_type           VARCHAR(60)   NOT NULL,
                -- @reference-lint-ignore: the record that caused the move, whose table depends on source_type
                source_id             BIGINT,
                -- @reference-lint-ignore: the line of that record, whose table depends on source_type
                source_line_id        BIGINT,
                reason_code           VARCHAR(40)   NOT NULL DEFAULT \'\',
                note                  VARCHAR(500)  NOT NULL DEFAULT \'\',
                batch_key             VARCHAR(64)   NOT NULL,
                idempotency_key       VARCHAR(128),
                -- @reference-lint-ignore: the move this one reverses; both rows live forever, so the reference cannot dangle
                reverses_move_id      BIGINT,
                posted_by_profile_id  INTEGER                REFERENCES profiles(id) ON DELETE SET NULL,
                -- @reference-lint-ignore: the agent that proposed the move, kept for the trail even after the agent is removed
                agent_id              INTEGER,
                on_behalf_of_profile_id INTEGER              REFERENCES profiles(id) ON DELETE SET NULL,
                occurred_at           TIMESTAMP     NOT NULL,
                posted_at             TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_moves_variant ON inv_moves(tenant_id, variant_id, occurred_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_moves_to ON inv_moves(tenant_id, to_location_id, occurred_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_moves_from ON inv_moves(tenant_id, from_location_id, occurred_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_moves_source ON inv_moves(tenant_id, source_type, source_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_moves_batch ON inv_moves(tenant_id, batch_key)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_moves_idempotency ON inv_moves(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_levels (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)       ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id)  ON DELETE CASCADE,
                location_id           INTEGER       NOT NULL REFERENCES inv_locations(id) ON DELETE CASCADE,
                -- @reference-lint-ignore: 0 means no lot; inv_lots arrives in migration 322
                lot_id                INTEGER       NOT NULL DEFAULT 0,
                on_hand               BIGINT        NOT NULL DEFAULT 0,
                reserved              BIGINT        NOT NULL DEFAULT 0,
                -- @reference-lint-ignore: the last ledger row folded into this level, for the rebuild check
                last_move_id          BIGINT,
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_levels ON inv_levels(tenant_id, variant_id, location_id, lot_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_levels_location ON inv_levels(tenant_id, location_id)');

        self::appendOnly($db);
    }

    public static function down(Database $db): void
    {
        if (self::isPostgres($db)) {
            $db->exec('DROP TRIGGER IF EXISTS trg_inv_moves_append_only ON inv_moves');
            $db->exec('DROP FUNCTION IF EXISTS inv_moves_append_only()');
        } else {
            $db->exec('DROP TRIGGER IF EXISTS trg_inv_moves_no_update');
            $db->exec('DROP TRIGGER IF EXISTS trg_inv_moves_no_delete');
        }
        $db->exec('DROP TABLE IF EXISTS inv_levels');
        $db->exec('DROP TABLE IF EXISTS inv_moves');
        $db->exec('DROP TABLE IF EXISTS inv_locations');
    }

    /**
     * The ledger refuses UPDATE always, and DELETE while its workspace
     * exists. Deleting a workspace cascades here after its `tenants` row is
     * gone, so the purge still works.
     */
    private static function appendOnly(Database $db): void
    {
        if (self::isPostgres($db)) {
            $db->exec(<<<'SQL'
                CREATE OR REPLACE FUNCTION inv_moves_append_only() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id) THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'inv_moves is append-only: post a reversing move instead';
                END;
                $$ LANGUAGE plpgsql
                SQL);
            $db->exec('DROP TRIGGER IF EXISTS trg_inv_moves_append_only ON inv_moves');
            $db->exec('CREATE TRIGGER trg_inv_moves_append_only BEFORE UPDATE OR DELETE ON inv_moves FOR EACH ROW EXECUTE FUNCTION inv_moves_append_only()');

            return;
        }

        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_inv_moves_no_update BEFORE UPDATE ON inv_moves
            BEGIN SELECT RAISE(ABORT, 'inv_moves is append-only: post a reversing move instead'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_inv_moves_no_delete BEFORE DELETE ON inv_moves
            WHEN EXISTS (SELECT 1 FROM tenants WHERE id = OLD.tenant_id)
            BEGIN SELECT RAISE(ABORT, 'inv_moves is append-only: post a reversing move instead'); END");
    }

    private static function isPostgres(Database $db): bool
    {
        return $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
