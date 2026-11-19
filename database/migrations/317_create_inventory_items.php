<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateInventoryItems — what a workspace buys, sells, makes or uses
 * (WHIT-1232, design section 31, register IV.3–IV.6).
 *
 * - `inv_units`: units of measure per workspace (piece, kg, box). A unit's
 *   `decimals` is how many places its quantities keep; every quantity in
 *   inventory is a whole number of that unit's smallest step (IV.3), so a kg
 *   with 3 decimals is stored in grams. `to_base_num / to_base_den` converts
 *   to the dimension's base unit (kg for mass, l for volume) as an exact
 *   fraction, never a float.
 * - `inv_categories`: a simple tree for grouping items.
 * - `inv_items`: the item. Its `name` is the original text (sorted and
 *   searched); `names` carries every language as LocalizedText JSON.
 * - `inv_variants`: the stock-keeping unit (SKU). Every item has at least one;
 *   levels, moves and lines point at the variant (IV.4).
 * - `inv_item_units`: other units an item is bought or sold in, as a fraction
 *   of the item's base unit (a 25 kg bag).
 * - `inv_barcodes`: barcodes, each meaning a variant in a unit, unique per
 *   workspace.
 *
 * Every table is tenant-owned. Each name is spelled out literally because
 * TenantOwnedTablesTest and CoreTablesTest re-derive their registries by
 * scanning this source. Idempotent and reversible.
 */
final class CreateInventoryItems
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_units (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                code                  VARCHAR(20)   NOT NULL,
                name                  VARCHAR(100)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                dimension             VARCHAR(10)   NOT NULL DEFAULT \'count\',
                decimals              INTEGER       NOT NULL DEFAULT 0,
                to_base_num           BIGINT        NOT NULL DEFAULT 1,
                to_base_den           BIGINT        NOT NULL DEFAULT 1,
                is_seeded             BOOLEAN       NOT NULL DEFAULT FALSE,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_units_tenant_code ON inv_units(tenant_id, code)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_categories (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                parent_id             INTEGER                REFERENCES inv_categories(id) ON DELETE SET NULL,
                name                  VARCHAR(100)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_categories_tenant ON inv_categories(tenant_id, parent_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_items (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)        ON DELETE CASCADE,
                name                  VARCHAR(200)  NOT NULL,
                names                 TEXT          NOT NULL DEFAULT \'{}\',
                description           VARCHAR(4000) NOT NULL DEFAULT \'\',
                kind                  VARCHAR(12)   NOT NULL DEFAULT \'stocked\',
                category_id           INTEGER                REFERENCES inv_categories(id) ON DELETE SET NULL,
                base_unit_id          INTEGER       NOT NULL REFERENCES inv_units(id),
                tracking              VARCHAR(8)    NOT NULL DEFAULT \'none\',
                expires               BOOLEAN       NOT NULL DEFAULT FALSE,
                shelf_life_days       INTEGER,
                sale_price_minor      BIGINT,
                purchase_price_minor  BIGINT,
                currency              VARCHAR(3),
                tax_category          VARCHAR(40)   NOT NULL DEFAULT \'\',
                -- @reference-lint-ignore: a stored file id; files are kept by the storage service, which outlives an item
                image_file_id         INTEGER,
                owner_plugin          VARCHAR(100),
                created_by_profile_id INTEGER                REFERENCES profiles(id)       ON DELETE SET NULL,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_items_tenant_name ON inv_items(tenant_id, name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_items_tenant_category ON inv_items(tenant_id, category_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_variants (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                item_id               INTEGER       NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
                sku                   VARCHAR(64)   NOT NULL,
                options               TEXT          NOT NULL DEFAULT \'{}\',
                sale_price_minor      BIGINT,
                purchase_price_minor  BIGINT,
                sort_order            INTEGER       NOT NULL DEFAULT 0,
                archived_at           TIMESTAMP,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_variants_tenant_sku ON inv_variants(tenant_id, sku)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_variants_tenant_item ON inv_variants(tenant_id, item_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_item_units (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)   ON DELETE CASCADE,
                item_id               INTEGER       NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
                unit_id               INTEGER       NOT NULL REFERENCES inv_units(id),
                num                   BIGINT        NOT NULL,
                den                   BIGINT        NOT NULL DEFAULT 1,
                purpose               VARCHAR(8)    NOT NULL DEFAULT \'both\',
                is_default            BOOLEAN       NOT NULL DEFAULT FALSE,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_item_units ON inv_item_units(tenant_id, item_id, unit_id)');

        $db->exec('
            CREATE TABLE IF NOT EXISTS inv_barcodes (
                id                    SERIAL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id)      ON DELETE CASCADE,
                variant_id            INTEGER       NOT NULL REFERENCES inv_variants(id) ON DELETE CASCADE,
                unit_id               INTEGER                REFERENCES inv_units(id),
                code                  VARCHAR(64)   NOT NULL,
                symbology             VARCHAR(10)   NOT NULL DEFAULT \'other\',
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_barcodes_tenant_code ON inv_barcodes(tenant_id, code)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_inv_barcodes_tenant_variant ON inv_barcodes(tenant_id, variant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS inv_barcodes');
        $db->exec('DROP TABLE IF EXISTS inv_item_units');
        $db->exec('DROP TABLE IF EXISTS inv_variants');
        $db->exec('DROP TABLE IF EXISTS inv_items');
        $db->exec('DROP TABLE IF EXISTS inv_categories');
        $db->exec('DROP TABLE IF EXISTS inv_units');
    }
}
