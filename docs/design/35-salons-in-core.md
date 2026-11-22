# 35 · Walk-ins, commission and the price list in core

**Status: decided by the owner on 21 November 2026 (WHIT-1334): "Accept all", every question in section 8 as recommended; the live retirement (section 7) "Yes, after deploy".** Lane L4 of the business model (section 33 §3.5 and §6, step 9). The owner decided on 21 November 2026 that the Salons plugin's walk-ins, commission, price list and onboarding become core and that a chair is a staff seat (BM.15, Q3), and that paid industry plugins retire (BM.19). The register rows are SL.1–SL.12 (section "Walk-ins, commission and the price list").

Salons is already free for every workspace on app.whity.dev (`free_for_all`, 21 November 2026). Once this lane is on `develop` and deployed, Salons is unpublished from whity-plugins and its add-on plans and per-chair prices are archived on app.whity.dev (section 7).

## 1. Scope

- **Walk-in queue.** Someone who came in without a booking joins a queue at the front desk and is handed to a free staff member, which makes a checked-in booking in the core calendar and finds or adds the customer in Contacts. Today: `salons_walkins`, `WalkinQueue`.
- **Commission rates.** Each staff member's share of a booking's price, with per-service overrides, and a report by period from the calendar's done bookings. Today: `salons_commission_rates`, `Commission`. New: closing a period hands the totals to accounting as an expense through a hook.
- **The price list.** Every bookable service with its length and price, printed in English and Arabic. Today it reads `calendar_services`; in core it reads the workspace's **service items** (inventory, `inv_items.kind = 'service'`), which every calendar service is linked to.
- **The chair price.** Retired. A chair is a staff seat, counted by the seat meter (BM.3, BM.17) from L1's daily seat snapshot (`UsageSnapshots`, `SeatService::used()`). Core reads no chair count.

Not in scope: the six-step salon setup and its ready menus (question 1), Today's bookings on the dashboard (core's calendar widget already does this), deposits (a Whity Pay plugin), product sales at the desk (a point of sale plugin on inventory).

## 2. Words

The features are for any business that takes bookings (salons, barbers, clinics, studios), so core's words are general. The salon words stay in the salon starter menus and workspace template.

| Word (English / Arabic) | Code | Means |
| --- | --- | --- |
| Walk-in / زبون بدون حجز | `walkin` | Someone who came in without a booking and is waiting. |
| Front desk / الاستقبال | `front_desk` | Whoever adds walk-ins and hands them over. |
| Staff member / الموظف | `profile_id` | A bookable member of staff (the calendar's staff). Salons call them stylists. |
| Commission / العمولة | `commission` | A staff member's share of a done booking's price. |
| Done booking / حجز منتهٍ | | Confirmed and already over. |
| Service item / خدمة | `inv_items.kind = 'service'` | An item with a price and no stock. |

## 3. Walk-ins

### 3.1 Table

`calendar_walkins`, per workspace, holds only the wait; the customer lives in Contacts and the visit in the calendar.

| Column | Notes |
| --- | --- |
| `id`, `tenant_id` | |
| `name`, `phone`, `note` | As given at the desk; `phone` may be empty |
| `service_id` | `calendar_services.id` |
| `preferred_profile_id` | Who they asked for, or null |
| `status` | `waiting`, `assigning` (claimed by one desk), `served`, `left` |
| `added_at`, `added_by_profile_id` | |
| `assigned_at`, `assigned_profile_id`, `booking_id`, `contact_id` | Set when served |
| `left_at` | |
| `legacy_id` | The `salons_walkins.id` it was copied from (section 6), unique per workspace; null for core rows |

Index `(tenant_id, status, added_at)`. Rows are never deleted: a row ends as `served` or `left`.

### 3.2 Behaviour

Unchanged from Salons 0.7.1, but calling core's services directly instead of core's HTTP routes:

- **Next free staff member** picks the one they asked for if free, else the first with no booking right now. **Choose…** picks anyone, busy or not.
- Handing over claims the row (`waiting → assigning`) so two desks can't serve it, finds the contact by phone digits or adds a customer, books the service now for that staff member through `BookingService` (`notify: false`, `book_anyway: true`), checks it in, and marks the row served, in one transaction; on failure the row goes back to waiting.
- **Left** takes them off the queue.

Routes under `/api/calendar/walkins` (list, add, `{id}/assign`, `assign` with a chosen staff member, `{id}/left`). The screen is a core block screen in the Calendar group, like Convening's (`ConveningFeatures`), with the same English and Arabic as Salons.

### 3.3 Switch and permission

- Setting `calendar.walkins_enabled` (workspace, off by default). The salon workspace template turns it on; the copy in section 6 turns it on for every workspace that had walk-ins.
- Permission `calendar:front_desk`, granted by capability to roles that hold `calendar:write` and `contacts:write`, since the booking and contact are made as that person.

## 4. Commission

### 4.1 Table

`calendar_commission_rates`, per workspace: `profile_id`, `service_id` (0 means every service without its own rate), `percent_bp` (basis points, 0–10,000), `updated_at`, `updated_by_profile_id`. Unique `(tenant_id, profile_id, service_id)`.

### 4.2 Report

From the calendar each time, nothing stored: for a period (this month, last month, this week, the last 7 or 30 days, in the workspace's time zone rather than Salons' UTC), each staff member's done bookings, sales and commission, one row per currency. A booking's base is its own price; a booking with several services (`calendar_booking_services`) uses each service's rate for its own line. "No rate set" where there is none.

### 4.3 To accounting (the hook)

Accounting's bills and expenses (A3) are not built yet. Core defines the hook now so A3 only has to implement it:

- **Close a period** (on the Commission screen, permission `calendar:commission`): only a period that has ended, and never one touching a closed day. It freezes the totals of everyone with a rate: `calendar_commission_closes` (`period_start`, `period_end`, `closed_by_profile_id`, `closed_at`, `expense_ref`, `booked_at`) and `calendar_commission_close_lines` (one per person and currency: `bookings`, `sales_minor`, `commission_minor`), so a later price or rate change never moves a paid month. A close is never reopened.
- On close, core fires `calendar.commission.closed` (notification, with `.async` twin, in the webhook catalogue) and calls `Contribute\WorkspaceCommissionExpenses::record(close)` if one is bound. Accounting A3 binds it and posts an expense to "Commission" with a payable to each staff member, answering the reference core keeps as `expense_ref`. With nothing bound, or the books refusing for now, the close stands, shows "Ready for the books", and can be sent again.

### 4.4 Permission

`calendar:commission`, granted by capability to roles that hold `calendar:manage`. Reading the report also needs `calendar:read`.

## 5. The price list

### 5.1 Service items

Every `calendar_services` row gets an `item_id` to an `inv_items` row with `kind = 'service'`: the item holds the name (bilingual through `localized_texts`), `sale_price_minor` and `currency`; the calendar service keeps length, buffers, staff, room and online booking.

- **One price.** The item's price is the price. `calendar_services.price_minor` and `currency` stay as a copy, written in the same transaction by `ServiceRepository` and by `ItemService` when a linked item changes, so the calendar's readers and the public booking page change nothing.
- Creating a calendar service creates its service item (`InventoryWorkspace` makes the units and the rest on first use). Archiving one archives the other.
- Service items don't count on the items meter (question 2): `PlanLimitsReport::liveItems()` counts `kind <> 'service'` only.
- Existing services are linked by a migration (section 6).

### 5.2 The page

`GET /api/calendar/price-list`: a whole A4 HTML page with its own strict CSP (as Salons), every active service item linked to a bookable service, in English and Arabic, with its length and price, under the workspace's name, phone and brand colour. Permission `calendar:read`. It moves to a document starter (`editor.price_list`, section 16) when the brand team's card arrives. The booking QR poster stays in Calendar › Settings. Both open from a "Print for the front desk" block screen, in the menu beside Walk-ins while walk-ins are on.

## 6. Moving the data

One idempotent migration copies Salons' rows into core for every workspace that has them (on app.whity.dev, tenants 5 and 9). It does nothing when `salons_walkins` or `salons_commission_rates` don't exist (a fresh or self-hosted install without Salons).

- `salons_walkins → calendar_walkins`, keyed by `(tenant_id, legacy_id)`; a row already copied is skipped. `assigning` rows are copied as `waiting`.
- `salons_commission_rates → calendar_commission_rates`, `ON CONFLICT (tenant_id, profile_id, service_id) DO NOTHING`.
- Every role holding `salons:front_desk` gets `calendar:front_desk`; every role holding `salons:commission` gets `calendar:commission`.
- `calendar.walkins_enabled` is set for each workspace that had walk-in rows or the Salons plugin on.
- The same code runs as `whity-cli calendar:copy-salons` so rows added between the deploy and Salons' removal are copied again before it goes (section 7). Salons' tables are not dropped by core; they go when the plugin is uninstalled.

The chair count needs no copy: chairs were the team count, and the seat meter reads the team.

## 7. Retiring Salons

After the core PRs deploy, on app.whity.dev, through whity-local-deploy's README procedure (dated entry, backup first, a scratchpad script calling core's services inside frankenphp, dry run, one transaction):

1. Backup dump.
2. Dry run: list `plan_prices` for the `plugin.salons.*` add-on plans (expected plans 2–4, six per-chair prices), any `subscriptions` on them (expected none active), the `code_plugin_access` row, and row counts of `salons_walkins` and `salons_commission_rates` against `calendar_walkins` (with `legacy_id`) and `calendar_commission_rates` per tenant.
3. `whity-cli calendar:copy-salons`, then the counts again: every Salons row has its core row.
4. In one transaction: `PlanPriceRepository::deactivate()` for the six prices and `PlanService::retirePlan()` for plans 2–4. Rows stay, for history.
5. `CodePluginActivation::setEnabled($t, 'Salons', false)` for tenants 5 and 9, then remove the Salons mount from `docker-compose.local.yml`, move `plugins/Salons` to `backups/`, restart frankenphp and the four workers, `migrate run`.
6. Check: health 200; tenant 5's Walk-ins and Commission screens (core) show the copied rows; Settings › Plugins no longer lists Salons; no active `plugin.salons.*` price.
7. Roll back: restore the plugin folder and mount, `updatePlan(id, is_active: true)` and the prices' `is_active`, or restore the dump.

In whity-plugins: `salons/` is removed from the store catalogue and marked retired in its README (a final 0.8.0 that only says "moved into core" is not published); `workspace-templates`' salon template stops offering Salons "with a 30-day trial" and turns on `calendar.walkins_enabled` instead. In core, the `plugin.salons` mapping and the Salons comments in `PluginSeats` and `PlanPriceRepository` are reworded; `PluginSeats` itself stays for third-party plugins (BM.19).

## 8. Questions for the owner

**Answered 21 November 2026: "Accept all".** Every recommendation below stands; register rows SL.3, SL.6, SL.8 and SL.11 record them.

1. **Salon setup.** The six-step setup and the ready bilingual menus (men, women, kids): core's own setup for every booking business, or the salon workspace template (free, in `workspace-templates`)? Recommended: **the workspace template**: the steps are mostly core's generic setup already (hours, team, services, booking rules, link), and the menus are salon content, like the template's other examples.
2. **Service items and the items meter.** Should linked service items count on the inventory items meter (500 free)? Recommended: **no**, only stocked, consumable and made items count; services are not stock.
3. **Commission to the books.** Post commission as an expense when a period is closed by hand (as in section 4.3), or automatically for each done booking? Recommended: **closed by hand per period**, like a payroll run; it freezes the totals and matches how salons pay.
4. **Where it shows.** Walk-ins as a switch any booking business can turn on (off by default, on in the salon template), and Commission shown to whoever holds the permission? Recommended: **yes**.

## 9. Build order and migrations

| PR | What | Migrations (numbers from the orchestrator) |
| --- | --- | --- |
| 1 | This spec and SL.\* rows | none |
| 2 | Walk-ins: table, routes, block screen, permission, setting | 1 |
| 3 | Commission: rates, report, closes, hook and event | 1 |
| 4 | Price list: `calendar_services.item_id`, linking existing services, page, items meter | 1 |
| 5 | Copy from Salons and `calendar:copy-salons` | 1 |
| — | Live: section 7, through the orchestrator | none |
| 6 | whity-plugins: Salons retired, salon template updated | none |

SDK minors: PR 3 (`Contribute\WorkspaceCommissionExpenses`, the event) and PR 4 (service item link on `Items`), asked for at PR time.
