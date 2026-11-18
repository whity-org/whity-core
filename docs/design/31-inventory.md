# 31 · Inventory in core

**Status: decided by the owner on 19 November 2026.** All recommendations in section 9 were accepted; D9 is "Free up to 100 items". Built phase by phase from 0.2.31. The register rows are IV.1–IV.24 (section "Inventory in core"). Ticket WHIT-1228.

On 19 November 2026 the owner lifted the WHIT-667 freeze for inventory: "Core basics now, spec first." Core gets items, units, places, stock moves, levels and reorder points, and simple purchase and sales documents, all reachable through the plugin SDK from day one. Costing, accounting and production stay with plugins or come later.

This spec turns the inventory designs that already exist (IN.1, section 27; N4 and N5, section 28; cards `TplInventory`, `TplRecipe`, `TplReceiving`, `TplAdjustments`, `TplDocPurchaseOrder`, `TmInventoryMobile`, `TmOpsMobile`) into a data model, permissions, an SDK surface and a build plan.

## 1. Scope

**In core:**
- Items, with variants, units and conversions, SKUs and barcodes.
- Places: warehouses, shops and branches, with optional areas and shelves.
- An append-only ledger of stock moves. The ledger is the source of truth; levels are a projection of it.
- Stock levels, reservations, and reorder points per item and place.
- Lots, serial numbers and expiry dates, each turned on per item.
- Adjustments (with waste), counts and transfers.
- Simple purchase orders with receipts, and simple sales orders with deliveries.
- One-level recipes, to be confirmed by the owner (D3): make a batch, and consumables used by a service.
- Approvals through the existing routing, printing through the existing document themes, an audit trail, Arabic, and MCP tools for the Stock assistant.

**Not in core** (plugins, or later):
- Stock valuation methods beyond one simple cost (average or FIFO): a costing plugin.
- Journal entries and accounting export: an accounting plugin, Money (MO.1), or a later release.
- Multi-level production, work orders, MRP and planning: a production plugin.
- The point-of-sale screen, payment taking, receipts and cash drawers: a POS plugin (Front Office or its own), using Whity Pay.
- Supplier bills and three-way match money: these need invoices, so they wait for Money. The receipt side of `TplReceiving` (ordered against received) is built here; the billed column waits.
- Landed costs, consignment, drop shipping, kits sold as bundles with their own pricing, and multi-currency purchase prices beyond storing the currency: later.

**Why it fits WHIT-667.** The freeze's question was "which existing engine needs extending?" Here inventory reuses the existing engines: Contacts for suppliers and customers, routing for approvals, documents and themes for printing, custom fields, import, search, MCP and agents' proposals. What is new is one domain: items, places, moves and two order types. Money stays outside core: orders carry prices and totals, but taking payment is Whity Pay's job, and books are Money's or a plugin's.

## 2. Words

The screens use plain words; the code uses the terms in brackets.

| On screen (en / ar) | Code | Meaning |
| --- | --- | --- |
| Item / صنف | `item` | Anything you buy, sell, make or use. With variants, the item groups them. |
| Variant / نوع | `variant` | One stock-keeping unit: one SKU with its barcodes, such as "T-shirt, red, M". An item without variants has exactly one, hidden on screen. |
| Unit / وحدة | `unit` | Piece, kg, box of 12. |
| Place / موقع | `location` | A warehouse, shop, branch, van, or a shelf inside one. |
| Stock move / حركة مخزون | `move` | Quantity going from one place to another. Never edited and never deleted. |
| Stock / المخزون | `level` | How much of a variant (and a lot) is in a place now. |
| Batch or lot / دفعة | `lot` | A group with one expiry or production date. |
| Serial number / رقم تسلسلي | `serial` | A lot that holds exactly one piece. |
| Reserved / محجوز | `reservation` | Promised to an order but not yet gone. |
| Reorder level / حد إعادة الطلب | `reorder_rule` | Below this, order more. |
| Purchase order / أمر شراء | `purchase_order` | What we asked a supplier for. |
| Receipt / استلام | `operation` kind `receipt` | What actually arrived. |
| Sales order / أمر بيع | `sales_order` | What a customer asked for. |
| Delivery / تسليم | `operation` kind `delivery` | What actually left. |
| Adjustment / تسوية | `operation` kind `adjustment` | Waste, damage, found stock, or a correction, with a reason. |
| Count / جرد | `operation` kind `count` | People counting what is on the shelf. |
| Transfer / نقل | `operation` kind `transfer` | Moving stock between places. |
| Recipe / وصفة | `recipe` | What goes into one batch of something made, or into one service (D3). |

## 3. Data model

All tables are tenant-owned, carry `tenant_id`, and get rows in `CrossTenantRejectionRealEngineTest`. Every query carries the tenant predicate, as everywhere else. The prefix is `inv_`.

### 3.1 Quantities without floats

A quantity is a **whole number in the base unit's smallest step**, stored as `BIGINT`, the same idea as `Money`'s minor units. Each unit has `decimals`: 0 for pieces, 3 for kg (stored as grams), 3 for litres. A `Quantity` value object (PHP and TypeScript twins) does the arithmetic and the formatting. Conversions between units are whole-number fractions (`numerator / denominator`), never floats, so 1 box = 12 pieces and 1 lb = 45359237 / 100000000 kg both stay exact. A conversion that cannot be exact is rounded once, half away from zero, at the edge (when a person types it), never inside the ledger.

This works the same on PostgreSQL and SQLite, needs no `bcmath`, and lets sums run in SQL.

### 3.2 Tables

| Table | Holds | Key columns | Notes |
| --- | --- | --- | --- |
| `inv_units` | Units of measure | `code`, `name` (bilingual), `dimension` (`count`, `mass`, `volume`, `length`, `area`, `time`), `decimals`, `to_base_num`, `to_base_den`, `archived_at` | Seeded per workspace: piece, kg, g, l, ml, m, box, pack. Conversions within a dimension are global (kg to g); `inv_item_units` covers conversions between dimensions. |
| `inv_items` | Items | `name` (bilingual; original in the column, other languages in `localized_texts`), `kind` (`stocked`, `consumable`, `service`, `made`), `category_id`, `base_unit_id`, `tracking` (`none`, `lot`, `serial`), `expires` (bool), `shelf_life_days`, `sale_price_minor`, `purchase_price_minor`, `currency`, `tax_category`, `image_file_id`, `archived_at`, `created_by`, `owner_plugin` | Custom fields through the record type `inventory.item` (N2). A `service` has no stock but can carry a recipe of consumables (D3). |
| `inv_variants` | SKUs | `item_id`, `sku` (unique per tenant), `options` (JSON: `{"colour":"red","size":"M"}`), `sale_price_minor`, `purchase_price_minor` (both nullable: fall back to the item), `archived_at` | Every item has at least one. Levels, moves and lines point at the variant. |
| `inv_item_options` | Variant axes | `item_id`, `name` (bilingual), `values` (JSON, bilingual) | At most 3 axes and 100 variants per item in the UI. |
| `inv_item_units` | Units an item is bought or sold in | `item_id`, `unit_id`, `num`, `den` (to the base unit), `purpose` (`purchase`, `sale`, `both`), `is_default` | "Flour: base kg; bought in 25 kg bags; sold by kg." |
| `inv_barcodes` | Barcodes | `variant_id`, `unit_id` (scanning the box barcode means 12), `code`, `symbology` (`ean13`, `ean8`, `upca`, `code128`, `qr`, `other`) | `UNIQUE (tenant_id, code)`. Several per variant. |
| `inv_categories` | A simple tree for grouping | `parent_id`, `name` (bilingual) | Shown as a filter; plugins may hang settings on it. |
| `inv_locations` | Places | `parent_id`, `kind` (`warehouse`, `area`, `virtual`), `virtual_role` (`supplier`, `customer`, `loss`, `found`, `production`, `consumed`, `opening`, `transit`), `name` (bilingual), `code`, `ou_id` (optional organisational unit, the branch), `address`, `is_active` | Virtual places are seeded once per workspace and never shown as stock. They make every move a "from → to" pair (3.3). |
| `inv_lots` | Lots and serials | `variant_id`, `code`, `is_serial`, `made_on`, `expires_on`, `supplier_contact_id`, `notes` | `UNIQUE (tenant_id, variant_id, code)`. |
| `inv_moves` | **The ledger** | `id` (bigint), `variant_id`, `lot_id`, `from_location_id`, `to_location_id`, `quantity` (base steps, always > 0), `entered_unit_id`, `entered_quantity`, `unit_cost_minor`, `currency` (nullable: cost known at the time, for costing plugins), `source_type`, `source_id`, `source_line_id`, `reason_code`, `note`, `occurred_at`, `posted_at`, `posted_by` (profile), `agent_id`, `on_behalf_of`, `reverses_move_id`, `batch_key`, `idempotency_key` | Append-only (3.3). `UNIQUE (tenant_id, idempotency_key)` where set. |
| `inv_levels` | **The projection**: stock now | `variant_id`, `location_id`, `lot_id` (0 for none, so the unique key works on both engines), `on_hand`, `reserved`, `last_move_id`, `updated_at` | `UNIQUE (tenant_id, variant_id, location_id, lot_id)`. Rows for virtual places are not kept. Available = `on_hand − reserved`. |
| `inv_reservations` | Promises | `variant_id`, `location_id`, `lot_id`, `quantity`, `source_type`, `source_id`, `source_line_id`, `status` (`active`, `used`, `released`, `expired`), `expires_at` | Feeds `inv_levels.reserved` in the same transaction. |
| `inv_reorder_rules` | Reorder levels | `variant_id`, `location_id`, `min_quantity`, `order_up_to` (or `order_quantity`), `supplier_contact_id`, `lead_days`, `is_active` | One per variant and place. |
| `inv_supplier_items` | What a supplier sells us | `variant_id`, `contact_id`, `supplier_sku`, `unit_id`, `price_minor`, `currency`, `min_quantity`, `lead_days`, `is_preferred` | The supplier is a contact with `is_supplier`. |
| `inv_orders` | Purchase and sales orders | `kind` (`purchase`, `sale`), `number` (from `DocumentNumbering`, e.g. `PO-0151`, `SO-0042`), `contact_id`, `location_id` (receive into or ship from), `status`, `expected_on`, `currency`, `subtotal_minor`, `tax_minor`, `total_minor`, `notes`, `route_id`, `document_id` (the printed copy), `payment_status` (sales, mirrored from Whity Pay), `created_by`, `agent_id`, `on_behalf_of`, `external_ref` | Status: `draft → waiting_approval → approved → partly_done → done`, or `cancelled`. Sales use `confirmed` for `approved`. |
| `inv_order_lines` | Lines | `order_id`, `variant_id`, `unit_id`, `quantity`, `done_quantity`, `price_minor`, `discount_bp`, `tax_bp`, `line_total_minor`, `note` | `done_quantity` is the sum of the posted operation lines. |
| `inv_operations` | Things that move stock | `kind` (`receipt`, `delivery`, `transfer`, `adjustment`, `count`, `production`, `consumption`, `opening`), `number`, `order_id`, `from_location_id`, `to_location_id`, `status` (`draft`, `waiting_approval`, `ready`, `done`, `cancelled`), `reason_code`, `route_id`, `document_id`, `done_at`, `done_by`, `agent_id`, `on_behalf_of`, `source_plugin` | One screen and one document per operation. Becoming `done` posts its moves in one transaction. |
| `inv_operation_lines` | Lines | `operation_id`, `order_line_id`, `variant_id`, `lot_id`, `lot_code`, `expires_on`, `unit_id`, `quantity`, `expected_quantity` (counts: what the system thought), `counted_at` | Lot details are typed on receipt; the lot row is created when the operation posts. |
| `inv_recipes`, `inv_recipe_lines` | One-level recipes (D3) | `output_variant_id` or `service_item_id`, `output_quantity`, then input `variant_id`, `unit_id`, `quantity`, `waste_bp` | Only if the owner keeps recipes in core. |
| `inv_level_snapshots` | Month-end stock | `variant_id`, `location_id`, `lot_id`, `on_hand`, `as_of` | Written by a monthly job so "stock on 31 March" reads one month of moves, not all of them. |
| `inv_reasons` | Adjustment reasons | `code`, `name` (bilingual), `direction` (`out`, `in`, `both`), `needs_approval_above_minor`, `is_waste` | Seeded: expired, damaged, spoiled, used in-house, sample, theft, found, count correction. |

Settings (workspace settings, not tables): negative stock policy (D2), default place, whether reservations expire, the expiry warning window (7 days, from IN.1), FEFO on or off, the order number prefixes, and the waste approval threshold (N5: $25 a line).

### 3.3 The ledger is the source of truth

- **Every change in stock is a move** from one place to another. Receiving is `supplier → Central kitchen`; selling is `Abdoun → customer`; waste is `Sweifieh → loss`; found stock is `found → Sweifieh`; opening stock is `opening → place`; making a batch moves the ingredients to `production` and the product from `production`. Adding up moves into a place minus moves out of it gives its stock, always, by construction.
- **Append-only.** The repository has no update or delete for `inv_moves`. A database trigger refuses `UPDATE` and `DELETE` on the table on both engines (PostgreSQL `BEFORE UPDATE OR DELETE ... RAISE`, SQLite `BEFORE UPDATE/DELETE ... RAISE(ABORT)`). Workspace deletion is the one exception, and it goes through the tenant purge path, which drops the trigger guard for that tenant's rows only.
- **Corrections are reversing moves.** Undoing an operation posts the same moves the other way, with `reverses_move_id` set. The original stays. The undo window (section 21's confirm-by-undoability) is 24 hours for adjustments and transfers; receipts and deliveries are undone by a return.
- **Posting is one transaction:** insert the moves, update `inv_levels` with `SELECT ... FOR UPDATE` on the level rows (locked in a fixed order, variant then place then lot, so two postings cannot deadlock), update reservations and order `done_quantity`, then commit. The `stock.moving` filter hook runs before, inside the transaction, and may veto (negative stock policy, a locked period, a plugin rule). `stock.moved` fires after; `stock.moved.async` fires after commit.
- **Idempotent.** A move or operation posted with an `idempotency_key` (an MCP call key, a scanner's offline queue, a plugin's `external_ref`) posts once; the second call returns the first result. This is what lets the phone queue counts offline.
- **Levels can be rebuilt.** `whity-cli inventory:rebuild-levels [--workspace=]` recomputes `inv_levels` from the ledger and reports any difference. A nightly job checks a sample of rows and records drift in the health page. Reservations are rebuilt from active `inv_reservations`.
- **Back-dating.** `occurred_at` can be earlier than `posted_at`, for stock counted yesterday and typed today. Levels do not care about the order. Snapshots after a back-dated move are marked stale and rewritten by the job. A workspace can lock a period (D7), after which `stock.moving` refuses moves dated before it.

### 3.4 Reservations

A confirmed sales order reserves its lines at its place. A delivery uses the reservation. Cancelling the order releases it. A reservation can expire (setting, off by default). `available = on_hand − reserved` is what "can I sell this?" reads. Plugins reserve too (Appointments reserves a service's consumables for a booking, D3; a POS holds a basket). Reserving more than is available follows the negative stock policy (D2).

### 3.5 Lots, serials and expiry

Off by default, turned on per item (`tracking`, `expires`). When on:
- A receipt asks for the lot code and expiry date (the phone's scanner reads GS1 barcodes that carry both).
- Serial items ask for one code per piece; a serial can be in one place only, and its level is 0 or 1.
- Deliveries, transfers and consumption pick lots **first expiring, first out** (IN.1). The suggested lot can be changed, and the change is kept on the line.
- A daily job fires `stock.lot.expiring` for lots inside the warning window and `stock.lot.expired` the day after, which the Inventory page's "Expiring soon" reads.
- An item's tracking can be tightened (none → lot) only while it has no stock; existing stock is moved into a lot called `OPENING` first.

### 3.6 Reorder points

After each posting, `ReorderWatcher` compares the new available quantity with the active rule for that variant and place. Crossing below fires `stock.level.below_reorder` once, until it rises above again (`stock.level.restored`), so a busy item does not repeat the event on every sale. The Stock assistant (AI.2 preset) listens and **proposes** purchase orders grouped by supplier, with why ("at this week's pace, Sweifieh runs out on Wednesday"), as on `TplInventory`. A person approves; nothing is ordered by the agent alone.

### 3.7 Purchase orders and receipts

1. Draft a purchase order: a supplier (contact), a place, lines with the supplier's price filled in from `inv_supplier_items`.
2. Send it for approval when the workspace's purchase route says so (4.2). Once approved it prints from the purchase order template (`TplDocPurchaseOrder`) through the workspace's document theme and can be sent to the supplier by email or WhatsApp.
3. Receive against it, on the web or the phone: what arrived per line, lots and expiry, a photo of the delivery note. Partial receipts leave the order `partly_done`. A short line can be closed without waiting ("won't come").
4. Each receipt posts `supplier → place` moves with the order's unit cost on the move.
5. Receiving more than ordered is allowed, flagged on the line, up to a tolerance (setting, default 0%: anything over asks).

The supplier's bill and the three-way match on `TplReceiving` arrive when Money does; the receipt is ready for it (`order_line_id` on each line, cost on each move).

### 3.8 Sales orders and deliveries

1. Draft a sales order for a customer (contact), from a place, with prices from the item (a plugin may provide price lists, 6.3).
2. Confirm: lines are reserved. Big orders can route for approval (discount above a limit, credit limit) with the same routing.
3. Deliver, in one go or in parts: a delivery note prints from its template; delivered lines post `place → customer` moves and use the reservation.
4. Returns post `customer → place` (or `customer → loss`), linked to the original delivery.
5. Payment is not core's: with Whity Pay installed, "Ask for payment" creates a payment link for the order total, and Pay's payment-succeeded event sets `payment_status` to `paid` (4.5).

### 3.9 Adjustments, waste, counts and transfers

- **Adjustment:** lines with a reason; `out` reasons post `place → loss`, `in` reasons post `found → place`. The register's N5 values apply: reasons from a short list, approval above $25 a line by the branch manager, and an end-of-day waste reminder at closing time.
- **Count:** choose a place (and optionally a category or some items); the count freezes "expected" when each line is counted, not when the count starts, so sales during a long count do not show as differences. Blind counts (expected hidden) are a per-count choice. Finishing posts one correction move per line that differs, reason `count correction`. A count with differences above a value routes for approval before it posts.
- **Transfer:** `place A → transit → place B` in two steps when the transfer is "sent" and later "received", or one step when both sides are the same person or the setting says so. Transit stock shows as "on the way".

### 3.10 Indexes and size

| Table | Indexes |
| --- | --- |
| `inv_moves` | `(tenant_id, variant_id, occurred_at)`, `(tenant_id, to_location_id, occurred_at)`, `(tenant_id, from_location_id, occurred_at)`, `(tenant_id, source_type, source_id)`, `(tenant_id, lot_id)` where lot_id is set, `UNIQUE (tenant_id, idempotency_key)` where set. PostgreSQL also gets a BRIN index on `posted_at`. |
| `inv_levels` | `UNIQUE (tenant_id, variant_id, location_id, lot_id)`, `(tenant_id, location_id)` |
| `inv_variants` | `UNIQUE (tenant_id, sku)`, `(tenant_id, item_id)` |
| `inv_barcodes` | `UNIQUE (tenant_id, code)` |
| `inv_orders` | `UNIQUE (tenant_id, kind, number)`, `(tenant_id, kind, status)`, `(tenant_id, contact_id)` |
| `inv_operations` | `UNIQUE (tenant_id, kind, number)`, `(tenant_id, status)`, `(tenant_id, order_id)` |
| `inv_reservations` | `(tenant_id, variant_id, location_id, status)`, `(tenant_id, source_type, source_id)` |

**How big the ledger gets.** A busy shop with 300 sales a day of 4 lines each, plus receipts, transfers and counts, writes about 1,500 moves a day, or 550,000 a year. At about 180 bytes a row plus about 250 bytes of indexes, that is roughly 240 MB a year for one busy workspace. A typical small company is a tenth of that. Whity Cloud at 1,000 such workspaces is in the tens of gigabytes after a few years: fine for PostgreSQL with these indexes, because no screen reads the ledger whole.
- Screens read `inv_levels` (one row per variant, place and lot), never sums over moves.
- History screens are per item or per place, newest first, keyset-paginated on `(occurred_at, id)`.
- "Stock on a date" starts from the month's snapshot and reads one month of moves.
- If one table outgrows that, PostgreSQL range partitioning of `inv_moves` by `posted_at` is a later, PostgreSQL-only step (D8). Nothing in the model stops it: no foreign key points at `inv_moves` except `reverses_move_id`, which becomes a plain column then.
- A load test in the build (phase 1) posts 1 million moves into one workspace and checks the item and place history pages stay under 200 ms on the CI PostgreSQL.

## 4. How it fits what exists

### 4.1 Permissions

Added to `CorePermissions`, granted by a forward idempotent migration, and described in plain words on the roles page.

| Permission | Allows | Default roles |
| --- | --- | --- |
| `inventory:read` | See items, places, stock, history | admin, manager, member |
| `inventory:write` | Create and change items, variants, units, barcodes, places, reorder levels, supplier prices | admin, manager |
| `stock:adjust` | Adjustments and waste | admin, manager |
| `stock:count` | Take part in counts (counting) | admin, manager, member |
| `stock:transfer` | Send and receive transfers | admin, manager |
| `purchases:read` / `purchases:write` | See / draft and receive purchase orders | admin, manager |
| `sales_orders:read` / `sales_orders:write` | See / draft, confirm and deliver sales orders | admin, manager |
| `inventory:settings` | Workspace inventory settings, reasons, locking a period | admin |

**Approving is not a permission.** Who approves comes from routing rules (the branch manager, a role, a person), as for documents and forms. A place can be scoped to an organisational unit, and stock screens then show a person the places of their own units unless they hold the permission workspace-wide (D6).

### 4.2 Approvals through routing

Purchase orders, adjustments, counts with differences, and sales orders are **route subjects** (`RouteSubjectProviderInterface`, already used by documents, form submissions and customer sign-ups): `inventory.purchase_order`, `inventory.sales_order`, `inventory.adjustment`, `inventory.count`. The workspace picks a route template per kind in Flows, with conditions such as "total above $500" or "any line above $25" (FL.1). The route's recipients come from routing rules, as everywhere. Approved fires `RouteSubjectApprovedInterface` and the order or operation moves on; rejected or sent back returns it to draft with the comments. With no route set, a person with the write permission confirms directly.

Agents' changes are proposals (AI.x): the Stock assistant proposes purchase orders and counts; approving the proposal creates the draft, which then follows its own route if one is set. The proposal's diff shows the lines. Tier: purchase orders are "Check first" (tier 2), adjustments proposed by an agent are tier 2, reading is free.

### 4.3 Documents and printing

The printed copies are documents issued through the existing issuing service with the workspace's document theme:
- Purchase order (`TplDocPurchaseOrder`, already drawn), goods received note, sales order or quotation, delivery note, pick list, transfer note, count sheet, and labels (batch and shelf labels with a barcode or QR, using the editor's label sheets).
- Each is a seeded starter template in English and Arabic (two starters per document, as decided for seeded starters), editable in the document editor, with merge fields from a `DocumentFieldProvider` for orders and operations.
- Numbers come from `DocumentNumbering` (`PO-0151`).
- A printed copy never changes (FP rules). Reprinting after a change issues a new version.
- The 80 mm receipt (N8) is the POS plugin's, from the same themes.

### 4.4 Contacts

Suppliers and customers are core contacts (`is_supplier`, `is_customer`). Inventory adds to the contact page: "Supplies" (supplier items and prices), "Purchase orders" and "Sales orders" tabs, as tabs on the contact record page. No second address book. Archiving a contact with open orders asks first. KeyHub's component suppliers (already contacts, WHIT-1209) can link to items with no changes.

### 4.5 Whity Pay

Core does not take money. Orders store prices, tax and totals in the row's currency, typed and shown by CU.1–CU.4.
- With Whity Pay installed, a sales order offers **Ask for payment** (a Pay payment link for the total), and Pay's payment events set `payment_status`. Without it, a person marks the order paid by hand.
- A POS plugin sells through `Stock` and `Orders` (6.1) and takes the payment through Whity Pay.
- Pay's subscriptions and plans are not items; nothing here touches them.

### 4.6 Appointments and salons

A service can use consumables: a colour service uses 30 ml of dye and a pair of gloves. Two ways, depending on D3:
- **With core recipes:** a service item has a recipe. Appointments (a plugin) calls `Stock::consumeRecipe()` when a booking is checked out, and the moves post `place → consumed` with the booking as the source. A plugin can reserve them at booking time.
- **Without:** the Appointments or Salons plugin keeps its own "uses" list and posts moves through `Stock::move()`. Same ledger, more work for each plugin.

Salons' retail products (shampoo sold at the till) are ordinary items sold through a sales order or the POS plugin.

### 4.7 Custom fields, import and search

- Items get the record type `inventory.item` in `CustomFieldRecordTypes`: the twelve field kinds, usable in lists, filters, merge fields and flow conditions (N2). Plugins add their own item fields the way `PluginContactFieldsInterface` adds contact fields (6.3).
- Import (N3) gains three targets: items (with variants, units and barcodes), opening stock (posts `opening → place` moves in one operation, undoable for 24 hours like any import), and supplier prices.
- Search gets an `ItemsSearchSource`: name in both languages (folded by `SearchText`, so "طحين" finds "الطحين"), SKU and barcode exact match. Scanning a barcode in the search field opens the item.

### 4.8 Audit

The ledger is its own audit of stock: each move names who posted it, the agent and the person it acted for, the source, and the time it happened and was recorded. Master data (items, places, prices, reorder levels, settings) writes `AuditTrail` entries with before and after. Approvals are in the route trail. Nothing in inventory is ever hard-deleted except by workspace purge; items and places are archived.

### 4.9 Arabic and right to left

- Names of items, variants' options, units, places, categories and reasons are bilingual through `LocalizedText` (WHIT-1038): the original in the column, other languages in `localized_texts`, one fallback rule.
- Seeded units come with Arabic names: قطعة، كغ، غ، لتر، مل، م، علبة، كيس.
- SKUs, barcodes, lot codes, quantities and amounts stay Western digits, left to right, isolated in RTL text (`<bdi>`, `dir="ltr"` on the cell content), in Geist Mono. Arabic-Indic digits typed or scanned are folded to Western ones.
- Quantity with unit reads number first in both languages ("24 كيس").
- Every screen and printed document gets an Arabic card check before it is called done; the purchase order already has `TplDocInvoiceAr`'s pattern to follow.

### 4.10 MCP and agents

Core MCP tools, derived like the others and listed in `mcp-tool-names.json`:
- Read: `inventory_items_search`, `inventory_item_get`, `inventory_stock_levels`, `inventory_stock_history`, `inventory_orders_list`, `inventory_order_get`, `inventory_expiring`.
- Write, always as proposals under Ask first: `inventory_purchase_order_propose`, `inventory_adjustment_propose`, `inventory_count_propose`, `inventory_transfer_propose`.

The Stock assistant preset (AI.2) gets these tools, the below-reorder and expiring events as triggers, and a starter instruction. Write tools use MCP call keys for idempotency (WHIT-931).

## 5. Screens

The designs already cover most of it. What is missing goes to the brand team as one ask (`brand-asks/brand-ask-inventory.md`, 19 November 2026).

| Screen | Card | State |
| --- | --- | --- |
| Inventory: Stock, Batches, Recipes, Counts, Transfers tabs, tiles, Stock assistant, Expiring soon | `TplInventory` | Drawn |
| A recipe | `TplRecipe` | Drawn |
| Inventory on the phone: stock by place with a scanner, an item and its batches, make a batch, transfers | `TmInventoryMobile` | Drawn |
| Stock count on the phone | `TmFormsMobile` | Drawn |
| Receiving against a purchase order (and the bill, later) | `TplReceiving` | Drawn |
| Waste and adjustments | `TplAdjustments` | Drawn |
| Receiving and waste on the phone | `TmOpsMobile` | Drawn |
| Printed purchase order | `TplDocPurchaseOrder` | Drawn |
| Import | `TplImport` | Drawn (add the three new targets) |
| **Items list** (search, category filter, scan, archived) | — | **Ask** |
| **An item's page**: header, Variants, Units and barcodes, Stock by place, History (the ledger), Suppliers, Reorder levels, custom fields | — | **Ask** |
| **New item** (simple, with "has variants" and "track batches") and the variant grid | — | **Ask** |
| **Places**: list, tree with areas, a place's page, linking a branch (unit) | — | **Ask** |
| **Purchase orders list** and **new purchase order** | — | **Ask** (the order tab of `TplReceiving` covers one order) |
| **Sales orders list**, **a sales order** (reserve, deliver, ask for payment), **delivery** | — | **Ask** |
| **Count on the web**: set up, progress, differences to approve | — | **Ask** |
| **Transfer on the web**: send, on the way, receive | — | **Ask** |
| **Inventory settings**: negative stock, expiry window, FEFO, numbering, reasons, waste threshold, lock a period | — | **Ask** (inside workspace settings, 2.9 pattern) |
| Printed goods received note, sales order, delivery note, pick list, transfer note, count sheet, labels | — | **Ask** (from the document theme pattern) |
| Contact page: Supplies and orders tabs | `TplContacts` | Small ask |
| Phone: sales order and delivery | — | **Ask** |

Until a card arrives, a screen is built from the section 21 patterns (list page, record page) and the existing cards' components, and checked against the card when it lands.

## 6. Plugin SDK

Everything core does with stock, a plugin can do through the SDK, from the first phase.

**The workspace comes from the call, never from an argument** (owner decisions D2 and D3 for the SDK, 19 November 2026). A plugin method runs in the workspace of the route, MCP tool or job that called it; with no workspace (or the system workspace) it refuses with reason `no_workspace`. No plugin-facing method takes a workspace id, so a plugin cannot reach another workspace's stock even by mistake. Core's own code calls the services in `Whity\Core\Inventory` with an explicit workspace; those forms stay host-side and move to `Whity\Sdk\Host\` in SDK 2.0.

The services are host services, resolved from the container (`\Whity\app(Items::class)`, null on an older host). Once `PluginContext` lands they are also on the context: `$ctx->inventory()->items()`, `->locations()`, `->stock()`, `->units()`. Reads are not permission-checked (the plugin is trusted with its workspace's data). Writes act as a person (`$actingProfileId`) and go through the same validation and permissions as the API. Refusals throw `InventoryRejected`, which extends `\RuntimeException`, will implement `Whity\Sdk\SdkException` once that lands, and carries a stable `reason` (`no_workspace`, `forbidden`, `not_found`, `invalid`, `conflict`, `item_limit`, `vetoed`), an HTTP-like `status` and the `field` at fault.

### 6.1 Services (`Whity\Sdk\Inventory\...`)

| Service | Methods (P1 shipped in SDK 1.125; the rest arrive with their phase) |
| --- | --- |
| `Items` | `list(filter, limit, offset)`, `get(itemId)`, `findBySku(sku)`, `findByBarcode(code)` (the item, the variant and the unit the barcode means), `create(data, actingProfileId)`, `update(itemId, changes, actingProfileId)`, `archive(itemId, actingProfileId)` |
| `Locations` | `list(includeVirtual)`, `get(locationId)`, `virtual(role)`, `create(data, actingProfileId)` |
| `Stock` | `levels(filter)` (variant, place, lot, on hand, reserved, available), `available(variantId, locationId)`, `history(filter, limit)` (keyset by `before_id`), `move(plugin, MoveRequest)` (one or many lines, one transaction, idempotency key, source type and id), `reverse(plugin, batchKey, actingProfileId, note)`; P5 adds `reserve(plugin, ReservationRequest)` and `release(plugin, reservationId)`; P6 adds `consumeRecipe(...)` |
| `Units` | `list()`, `convert(quantity, fromUnit, toUnit, itemId)` |
| `Lots` (P4) | `get(lotId)`, `findByCode(variantId, code)`, `expiring(days)` |
| `Orders` (P3, P5) | `list`, `get`, `createPurchase`, `createSale`, `addLines`, `submit` (sends to its route or confirms), `cancel` |
| `Operations` (P2) | `create(kind, ...)`, `addLines`, `post` (to done), `get`, `list` |

Value objects: `Quantity` (whole steps and decimals, `parse()`, `toDecimal()`, `convert()`), `Item`, `Variant`, `Unit`, `Location`, `StockLevel`, `Move`, `MoveRequest`, `MoveLine`, `InventoryRejected`; later `Reservation`, `Order`, `OrderLine`, `Operation`, `Lot`. Writes that move stock take the plugin object (`$this`): the host prefixes the request's source type with the name the loader bound to that object (`pos.sale`, `appointments.booking`), never with anything the plugin says, the same namespace rule as routing kinds, and namespaces its idempotency keys the same way.

### 6.2 Events (`Whity\Sdk\Hooks\Events`)

Filter hooks, which can veto with `HookVetoException` (the caller gets 409 with the reason):
- `stock.moving`: the lines about to post, with their source. Used for negative stock rules, locked periods, a plugin's own rule.
- `inventory.order.submitting`: before an order goes to its route or is confirmed.

Notification hooks, each with an `.async` twin after commit:
- `inventory.item.created`, `inventory.item.updated`, `inventory.item.archived`
- `stock.moved` (the move ids, variant, places, quantity, source; one event per posting, listing all its moves)
- `stock.level.below_reorder`, `stock.level.restored`
- `stock.reserved`, `stock.reservation.released`
- `stock.lot.expiring`, `stock.lot.expired`
- `inventory.operation.done` (receipt, delivery, transfer, adjustment, count, production, consumption, opening; kind in the payload)
- `inventory.order.created`, `inventory.order.approved`, `inventory.order.rejected`, `inventory.order.partly_done`, `inventory.order.done`, `inventory.order.cancelled`
- `inventory.count.differences` (a finished count with its differences, before or after approval)

All are added to the webhook event catalogue (ADR-0008), so outside systems can subscribe without a plugin.

### 6.3 Extension points

| Interface | Lets a plugin |
| --- | --- |
| `Contribute\WorkspaceCostingMethod` | Provide a valuation method (FIFO, average, standard, landed costs). Core asks the active method for the cost of each outgoing move and for stock value; core's own method is the simple one decided in D1. One method is active per workspace. |
| `Contribute\WorkspaceAccountingExport` | Turn operations and moves into journal lines for an accounting system (Money later, Odoo, QuickBooks, a local Jordanian system). Core gives the operations and costs; the plugin owns accounts and posting. |
| `Contribute\StockOperationTypes` | Declare its own operation kinds (a POS sale, a production run, a rental out and back), with a name, icon and the virtual places they move between, so they show in history with the right words. |
| `Contribute\ItemFields` | Add fields to items, made per workspace and archived when the plugin is off, exactly as `PluginContactFieldsInterface` does for contacts. |
| `Contribute\BarcodeParser` | Read barcodes core doesn't understand: weighed-item barcodes (price or weight in the code), GS1 with application identifiers beyond lot and expiry, a supplier's own codes. Returns variant, quantity, lot, expiry. |
| `Contribute\WorkspacePriceList` | Provide the price of a variant for a customer, quantity and date (price lists, customer groups, promotions). Core uses the item's price when no plugin answers. |
| `Contribute\ReorderPolicy` | Suggest how much to order (seasonality, sales pace) for the Stock assistant to propose. Core's default: up to `order_up_to`. |
| Route subjects and routing rules | Already in the SDK: a plugin's approval rules (credit limit, buyer's budget) resolve recipients for inventory routes. |
| Document blocks and field providers | Already in the SDK: a plugin adds blocks and merge fields to inventory documents. |
| MCP tools | Already in the SDK: a plugin adds its own inventory tools. |
| Dashboard and home cards | Already in the SDK: a plugin adds stock cards. |

POS and barcode scanners are not their own interfaces: a POS plugin uses `Stock`, `Orders`, `Items::findByBarcode` and its own operation kind; a hardware scanner is a keyboard to the browser and a camera on the phone, both handled in core's scan field.

### 6.4 SDK numbers and names

P1 shipped as SDK 1.125 (one minor per pull request while 0.2.31 lasts; from 0.2.32 one minor per core release, owner decision D7). Each later phase asks lane sdk-0231 for its number before it opens its pull request. Contributions a plugin makes (the interfaces in 6.3) live under `Whity\Sdk\Contribute\` with "workspace" in their names and parameters, never "tenant" (owner decision D6); their exact names are settled with sdk-0231 when each phase opens. `docs/sdk-coverage/coverage.json` lists every service, event and interface with the version it arrived in.

## 7. What it takes from Taskaat's "Frozen by WHIT-667"

None of the 39 open tasks in that section are inventory. They are billing (WHIT-98, 103, 105, 109, 129, 139, 141, 146, 152, 166, 177, 181, 186, 191, 429, 523) and the plugin marketplace and licensing (WHIT-447–467, 531). Those belong to Whity Pay and to the store inside Whity ID, so they stay frozen in core. The prior thinking that matters here:
- **WHIT-129** (product catalogue) was about plans sold as subscriptions, not stocked goods. Items here are not plans, and Pay's plans are not items.
- **WHIT-141** (invoices) and **WHIT-152** (tax) stay out. Items carry a `tax_category` and order lines a tax rate, so invoices and tax returns can be built on them later.
- **WHIT-667** gets a note that inventory is lifted by the owner's 19 November decision; the rest of the freeze stands.

The build work goes into a new Taskaat section, "Inventory in core", one task per phase (8), created once this spec is approved.

## 8. Build plan, before v0.2.40

Each phase is one pull request (or two where marked), merged on green, with its migration numbers from the reserved set and its SDK minor from the orchestrator. Every phase ships its SDK surface, its MCP read tools, tests (unit, real-engine on both dialects, cross-tenant rows, RBAC on every route) and English and Arabic strings. Release numbers are a target; a phase can land in the next release.

| Phase | Release | What | Migrations | Screens |
| --- | --- | --- | --- | --- |
| **P0** | 0.2.31 | This spec and the register rows (no code) | — | — |
| **P1 Items and stock** | 0.2.31–0.2.32 | Units, items, variants, item units, barcodes, categories, places (with virtual ones), the ledger with its trigger, levels, posting service, `stock.moving` and `stock.moved`, opening stock, simple adjustments (no approval yet), rebuild command, permissions. SDK `Items`, `Locations`, `Stock` (levels, history, move, reverse), `Units`, `Quantity`. Search source. Items custom fields. 1M-move load test. | 317 (units, items, variants, barcodes, categories), 318 (places, ledger, levels, trigger), 319 (permissions grant, seeds) | Items list, item page, new item, Places, Inventory Stock tab (`TplInventory` minus batches and recipes) |
| **P2 Counts, transfers, waste, reorder** | 0.2.33 | Operations table, counts (web and phone), transfers with transit, adjustment reasons, waste threshold and route subjects for adjustment and count, reorder rules, `ReorderWatcher`, below-reorder events, Stock assistant tools as proposals. SDK `Operations`. | 320 | `TplAdjustments`, counts, transfers, `TmInventoryMobile` stock and count |
| **P3 Purchasing** | 0.2.34–0.2.35 (two PRs) | Orders and lines (purchase), supplier items, purchase route subject, receiving (partial, over-receipt tolerance), PO, goods received note and label documents, contact page tabs, import targets (items, opening stock, supplier prices). SDK `Orders` (purchase). | 321 | Purchase orders list, new PO, `TplReceiving` (order and receiving tabs), `TmOpsMobile` receiving, `TplDocPurchaseOrder` |
| **P4 Lots and expiry** | 0.2.36 | Lots and serials, receipt capture, FEFO picking, expiring jobs and events, batch labels, GS1 parsing in the scan field. SDK `Lots`, `PluginBarcodeParserInterface`. | 322 | Batches tab, Expiring soon, phone batches |
| **P5 Sales** | 0.2.37 | Sales orders, reservations, deliveries and returns, delivery note and pick list, sales route subject, Whity Pay "Ask for payment" and payment status. SDK `Orders` (sale), `reserve`/`release`, `PluginPriceListInterface`. | 325 | Sales orders list and record, delivery, phone sales |
| **P6 Recipes and consumables** (if D3 is yes) | 0.2.38 | One-level recipes, make a batch (production operation), service consumables, `consumeRecipe`, Appointments wiring in the Appointments plugin. | 326 | `TplRecipe`, make a batch on web and phone |
| **P7 Extension points and polish** | 0.2.38–0.2.39 | `PluginCostingMethodInterface` with core's simple method (D1), stock value tile, `PluginAccountingExportInterface`, `PluginStockOperationTypesInterface`, `PluginItemFieldsInterface`, `PluginReorderPolicyInterface`, month-end snapshots, period lock, webhook catalogue entries, reference plugin example (HelloWorld gains a tiny "stock move on an event"), docs and the plugin guide chapter. | 327 | Settings, stock value |
| Buffer | 0.2.39 | Arabic and phone pass on every inventory screen, the card comparisons, performance checks, fixes. | — | — |

Reserved migrations: 317–322 for P1–P4 and 325–327 for P5–P7.

## 9. Decisions for the owner

| # | Question | Options | Recommendation |
| --- | --- | --- | --- |
| D1 | Which cost does core keep, for the stock value tile and order lines? | (a) none, value only with a costing plugin; (b) moving average per item, updated on receipts; (c) last purchase price | **(b) moving average.** `TplInventory` shows "Stock value at cost", which needs a cost; average is the simplest correct one and what most small shops expect. FIFO and the rest are costing plugins. |
| D2 | May stock go below zero? | (a) never; (b) allowed, shown red, fixed by the next count; (c) a workspace setting, default (b) | **(c), default allowed.** Small shops often record a sale before the receipt; blocking the till is worse than a red number. Serial items never go below zero. |
| D3 | Recipes in core? | (a) yes, one level: make a batch and service consumables; (b) no, a production plugin | **(a) one level.** IN.1 already designs recipes and batches, Appointments needs consumables, and one level is small. Multi-level production, work orders and planning stay for a plugin. |
| D4 | Sales orders in core, or only purchase? | (a) both; (b) purchase only, sales through a POS or orders plugin | **(a) both.** The owner's decision named "simple purchase and sales documents"; plugins (POS, online shop) then share one reservation model. |
| D5 | Variants from the start? | (a) yes; (b) later | **(a) yes.** Adding them later means migrating every move to point at a variant. The UI hides them for items with one. |
| D6 | Can people be limited to their own branch's stock? | (a) yes, places scoped to units; (b) no, workspace-wide | **(a) yes**, through the place's organisational unit, as routing already does for branch managers. |
| D7 | Lock a period (no moves dated before it)? | (a) yes, a setting for admins; (b) no | **(a) yes.** Counts and month-end figures stay true; it is a few lines in `stock.moving`. |
| D8 | Plan ledger partitioning now? | (a) design for it, build only when needed; (b) build now | **(a)**: not needed below tens of millions of rows per table; the model leaves room. |
| D9 | Is inventory free core on every plan, including Free (2 staff, 100 customers)? | (a) yes, all of it; (b) basics free, lots, recipes and sales on paid plans; (c) a limit on items for Free | **Decided: (c).** Everything works on Free with up to 100 items, each variant counted separately; Team and above have no item limit. |
| D10 | Sidebar | Operations: Contacts, Inventory, Purchases, Sales, Money and expenses, Subscriptions, Reports | **Inventory with Purchases and Sales as tabs inside it at first**, so the sidebar grows by one item; split out when Money arrives. |
| D11 | The 39 frozen tasks | Leave them frozen (none are inventory); add a note to WHIT-667 | **Leave frozen, add the note.** |

## 10. Register rows proposed

IV.1–IV.24 in the register, section "Inventory in core (decided 19 November 2026)". The owner accepted D1–D8, D10 and D11 as recommended and chose "Free up to 100 items" for D9. Migrations: 317–322 for P1–P4, 325–327 for P5–P7.
