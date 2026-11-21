# The business model: everything in core, pay for seats, volume and outside services

Status: **decided by the owner on 21 November 2026.** The direction, then the answers to section 7 the same day: Q1 "Into core", Q2 and Q12 "Yes, now", Q5 "Accept, all warn", and Q3, Q4, Q6–Q11, Q13 and Q14 "Accept all" (every recommendation as written). Register rows BM.13–BM.20 record the decisions (WHIT-1319). Nothing is built by this document.

## 1. What the owner decided

The owner (Amro), 21 November 2026:

> "I want to start a new approach... everything is in whity... and core supports almost everything that it makes sense to support... extra usage and users is what is paid, and outside integration plugins are paid like payment gateways, jofotara, etc..."

Answers to the follow-up questions, the same day:

| Question | Owner's answer | What it means |
| --- | --- | --- |
| Studio, Front Office and the accounting extras | "All into core" | Whity Studio (theme editor, custom records, views, formulas, page builder), Front Office (until now a $29 add-on with a 14-day trial) and accounting's multi-currency, fixed assets, budgets and consolidation become free core features. |
| What stays paid | Usage, seats, and plugins that connect outside services | Payment gateways (Whity Pay with the installer's Stripe, Polar or Paddle), JoFotara, live bank feeds, WhatsApp, SMS and calendar sync. Hosted services keep their own pricing: Whity ID, hosted Whity Render, Whity Cloud. |
| Usage | "Seats and volume" | People pay for more staff, storage, records (customers, items, invoices), messages, and API or agent usage. Free keeps a generous base, and limits only warn until paid plans charge for them. |
| Rollout | "Register PR, then lanes" | This document and the register rows come first; the lanes follow. The accounting and theme lanes adjust their own rows (AC.\*, TH.15 and after) in parallel. |

## 2. The placement test (BM.14)

Every new feature goes into core, free, on every plan and on a self-hosted install, **unless** it is one of these:

1. **Usage.** A counted volume of something that costs Whity Cloud to run or that grows with the business: staff seats, storage, records, messages, API and agent calls. The feature itself stays free; only the volume above the base is paid.
2. **An outside service.** It talks to a service outside Whity on the workspace's behalf: a payment gateway, a tax authority, a bank, WhatsApp, an SMS gateway, an outside calendar. These are paid integration plugins. Core keeps the extension point and a manual path, so nothing in core stops working without them.
3. **A hosted Whity service.** Whity ID, hosted Whity Render, Whity Cloud, dedicated hosting. They keep their own pricing (BM.7, BM.8).

A feature is never paid for being advanced, for serving one industry, for being new, or for being a "pro" version of something free. When unsure, it goes in core. The test applies to Whity's own work; third-party publishers may still sell their plugins in the store (S.2), which is open question 10.

Worked examples:

| Feature | Test | Placement |
| --- | --- | --- |
| Quotes, invoices, receipts, purchasing, stock | Not usage, no outside service | Core |
| Multi-currency, fixed assets, budgets, consolidation | Not usage, no outside service | Core (was the paid accounting plugin) |
| Currency rates fetched from a central bank | Talks to an outside service | A rates plugin; typing a rate by hand stays in core. Open question 11 asks whether a free public source counts |
| JoFotara e-invoicing | Talks to the tax authority | Paid integration plugin; core keeps the e-invoicing point, the fields and the Tax invoice slots (AC.22) |
| Bank statement import | A file the person uploads | Core |
| Live bank feeds | Talks to a bank or an aggregator | Paid integration plugin |
| WhatsApp and SMS reminders | Talk to Meta or an SMS gateway | Paid integration plugins; email and in-app reminders stay in core |
| Theme editor, custom records, page builder | Not usage, no outside service | Core (was Whity Studio) |
| 5,000 inventory items | Volume | Core feature; items past the base are a meter |
| A salon's walk-in queue and commission | Not usage, no outside service | Core (was part of the paid Salons plugin) |

## 3. What moves into core

### 3.1 Front Office

**What it is today** (whity-plugins `front-office`, 0.8.2): 34 PHP files, about 356 KB, 4 migrations creating 25 `fo_*` tables, routes under `/api/front-office/*`, five screens (stock, purchasing, bank, reports, sell), six `front_office:*` permissions and two agent tools for bank matching. It covers stock and batches, purchasing with a three-way match, bank reconciliation, a ledger and reports, sales (invoice, quote, 80 mm receipt), trading emails and CSV import. Paid access is declared through `PluginAccessInterface` (`front_office.access`, product `plugin.front-office`, 14-day trial, `src/FrontOfficePlugin.php:57-67`). It never calls JoFotara itself: it stores the UUID and QR that something else reports back (`recordJoFotara`). There are no leads in it, and stock is part of it, not a separate add-on.

**What it means.** Most of Front Office is already being rebuilt in core by two decided lanes: inventory (section 31, IV.\*; `src/Core/Inventory` exists) and accounting (section 32, AC.\*). Moving Front Office into core is therefore mostly **retiring it in favour of those lanes**, not copying its code. The parts no core lane covers yet are:

| Front Office part | Where it goes in core | Covered today by |
| --- | --- | --- |
| Stock items, batches, movements, adjustments | `src/Core/Inventory` (IV.1–IV.24) | Inventory lane, P1–P7 |
| Purchase orders, three-way match, supplier bills | Inventory purchasing (IV.12) and accounting bills (AC.13) | Inventory and accounting lanes |
| Ledger, periods, reports | Core accounting (AC.3, AC.7, AC.23) | Accounting lane |
| Bank accounts, statement import, reconciliation, match suggestions | Core accounting bank (AC.16) and the Bookkeeping assistant | Accounting lane |
| Invoices, payments | Core accounting (AC.11, AC.14) | Accounting lane |
| **Quotes** | Core sales documents, beside sales orders (IV.13) and invoices (AC.11) | **Not covered.** Needs a row and an owner (section 6, step 5) |
| **80 mm receipt** | A document starter through the document themes (6.8 lists it as "later") | **Not covered** |
| **Trading emails** (invoice, quote, reminder) | Core email templates (EM.1 already lists them) | Partly: the emails exist as designs |
| **CSV import** of items, contacts, opening balances | Core spreadsheet import (R.1, A8) | Partly |
| JoFotara UUID and QR | Core e-invoicing point (AC.22); the JoFotara plugin fills it | Accounting lane |

**Data.** There are no real customers on app.whity.dev (owner, 16 November 2026), so no compatibility layer is needed. What must carry over is the demo data the owner named: the AAU IRB demo and the salon demo (tenant 5). Step 1 of the move is to check which workspaces have Front Office on and hold `fo_*` rows. If any do, core ships a one-off, idempotent import command that reads `fo_*` tables when they exist and writes core inventory and accounting records, then marks the import done per workspace; it runs once the A2 (invoices) and inventory P2 phases are on develop. If none do, the plugin is simply uninstalled (its data is kept 30 days, S.7) and no import is written.

**The store and existing installs.** On app.whity.dev the operator sets Front Office to "Free for every workspace" as soon as the owner agrees (question 2), so nobody meets a trial or a read-only state while the move happens. The add-on price rows (`plugin.front-office.*`) are archived, not deleted, so past checkouts still resolve. Once core covers every row of the table above, Front Office is unpublished from the store and uninstalled from app.whity.dev. The `plugin.front-office.*` mapping in `src/Core/Plan/HostWorkspaceAddons.php:73` is then removed.

**The name.** "Whity Front Office" stops being a product name (11-plans, 07-voice); its parts are features with plain names (quotes, invoices, receipts, stock). Question 12.

### 3.2 Whity Studio and the theme editor

**What it is today.** There is no Studio plugin. The pieces are:

- `theme-creator` in whity-plugins (0.1.1): edits and approves design-token colours with history, through the deprecated route-based `PluginThemeInterface`; 1 migration, 2 tables, 1 screen; free.
- The theme engine in core (`src/Core/Theme`, section 30): applying, tweaks and export are free in core already (TH.14–TH.16).
- Custom fields in core (`src/Core/CustomFields`, the "Studio lite" of N2).
- The declarative plugin host in core (records, rules, automations, block screens, versions; WHITY-BUILDER.md section 2.3), which runs what Whity Builder makes.

**What moves.** The theme editor (live editor, seeds from the logo, presets, importers, previews, versions) becomes core; the theme lane owns that change in section 30 and TH.15 onwards. `theme-creator` is retired once the core editor ships, and its saved colours become a workspace theme. Custom records, views, formulas and the page builder (R.2's Studio, roadmap L1) become core features built on the declarative host. Their editor is the open question about Whity Builder (question 1), because Whity Builder already is that editor.

### 3.3 Whity Builder

**What it is today.** `whity-org/whity-builder` (Laravel, 183 files) is the hosted, paid no-code builder at builder.whity.dev: people and their AI change record types, fields, rules, automations and screens as operations on a draft manifest; validation, impact, tests and publishing run in core. `whity-builder-connector` is a closed plugin that adds 8 `builder_*` MCP tools behind the `whity-builder.access` entitlement and forwards calls, signed, to the builder. Its four meters (workspace, published plugin, AI use, seat) are designed but not built.

**Under the test,** the visual builder is not usage and talks to no outside service, so it belongs in core; its AI use is agent usage, which is a meter. The recommendation (question 1) is:

1. The editor screens move into core as Studio, on top of the declarative host that is already there. Drafts and history move into core tables beside `plugin_manifest_versions`.
2. AI authoring becomes ordinary agent work in core: the `builder_*` operations become core MCP tools, so a person's own AI or a Whity agent can call them, counted by the agent and API meters (section 5).
3. builder.whity.dev and the connector are retired once core has the editor. The four builder meters collapse into core's seat and agent meters.

This is the largest move and the least urgent; it starts after Front Office.

### 3.4 Accounting extras

Multi-currency, fixed assets, budgets and consolidation move from the planned paid accounting plugin into core accounting. The accounting lane owns this in section 32 and the AC.\* rows. What stays outside is what talks to an outside service: JoFotara and other countries' e-invoicing, live bank feeds, and (question 11) fetched currency rates.

### 3.5 Salons and the industry packs

`salons` in whity-plugins is paid today (`salons.access`, product `plugin.salons`, 14-day trial, priced per chair as seats; `src/SalonsPlugin.php:39-44`). Its walk-in queue, commission rates, price list and onboarding pass the test as core features (or stay a free pack like `appointments`). Its per-chair price is a seat price, which BM.17 keeps as the seat meter. Question 3.

`appointments` and `workspace-templates` are free already and stay as they are; `workspace-templates` stops offering a vertical plugin "with a 30-day trial" once the vertical is free.

### 3.6 What stays in plugins without being paid

The per-workspace switch (migrations 287, 288; `PluginWorkspaceGate`) stays: integration plugins and third-party plugins still turn on per workspace. `PluginAccessInterface`, trials, grace and read-only stay in the SDK for integration plugins and third-party publishers; only Whity's own feature plugins stop using them.

## 4. Paid integration plugins

| Plugin | Outside service | Status | Core's free side |
| --- | --- | --- | --- |
| **Whity Pay** | Stripe, Polar, Paddle with the installer's own account | Built (0.3.6), licensed in live mode through the store | Mark paid by hand, cash payments, payment slots (AC.14, IV.13) |
| **JoFotara** | Jordan's e-invoicing system | **Needed.** No connector exists in any repo: Front Office only stores the UUID and QR | E-invoicing point, gap-free numbers, e-invoice fields, Tax invoice slots, retry queue (AC.22) |
| **Live bank feeds** | Banks or an aggregator | Needed (AC.16) | Statement file import and matching |
| **WhatsApp** | Meta WhatsApp Business | Needed (decided as paid, `whity-messages-appointments`) | Email and in-app messages, one chat (UC.\*) |
| **SMS** | An SMS gateway | Needed | Email and in-app |
| **Calendar sync** | Google, Microsoft | Needed (CV.12) | Core calendar, .ics links |
| **Deposits** | Through Whity Pay | Needed; part of Pay or its own plugin | Bookings without deposits |
| **Other countries' e-invoicing** | Their tax authorities | Later | The same e-invoicing point |
| **Currency rates** | A rates source | Question 11 | Typing the rate |

These are sold through the store (S.2) and licensed through Whity ID (`store-in-whity-id`). On Whity Cloud the price is per workspace; on a self-hosted install, per installation licence (BM.3). Their prices stay `[price]`.

Hosted services keep their own pricing and are not plugins: Whity ID, hosted Whity Render, Whity Cloud plans, dedicated hosting (BM.7), the on-premises licence (BM.8), and the own app in the stores (M.16, question 6).

## 5. Usage meters

"Seats and volume" means core has to count each of these reliably, per workspace, with history a bill can be made from. Today only staff, customers and items are counted from real data; the rest are short-lived counters or nothing.

| Meter | Key today | How it is counted today | Free base today | What is missing |
| --- | --- | --- | --- | --- |
| **Staff seats** | `members.max` | Live count of members, not customers or service accounts; invitations count (`src/Core/Seat/SeatService.php:125-141`) | 2 in the register (BM.3, owner 17 Nov); not seeded in core. The salon runbook (WHIT-1131) sets Free to 5 with `seats.enforcement = block` on app.whity.dev | Agree the number (question 5); seats warn, not block (BM.17); a monthly snapshot for billing |
| **Customers a month** | `customers.per_month` | Real table `tenant_monthly_customers` (migration 242; `CustomerUsage`) | 100 (BM.3); the runbook says 500 | Agree the number |
| **Inventory items** | `inventory.items.max` | Live count of variants that aren't archived (`src/Core/Usage/PlanLimitsReport.php:119-129`) | 100 (IV.24) | Nothing; warn-only already |
| **Invoices** | none | Not counted | Suggested 50 a month (AC.29) | A key and a count once accounting A2 ships |
| **Other records** (contacts, documents, form responses, custom records) | none | Not counted; contacts are "always unlimited" (BM.3) | none | Decide which records count (question 4) |
| **Storage** | `storage.quota_bytes` | **Not counted**: the key is seeded (migration 152) but nothing reads it; no total of file sizes | none | A per-workspace byte total kept as files are added and removed, and a rebuild job |
| **Messages** | `messages.outbound.per_month` | Short-lived counter, one per message queued, no split by channel (`src/Core/Messaging/MessagingService.php:430-441`) | none | A durable count per channel (email, WhatsApp, SMS); chat is not counted (`src/Core/Chat`) |
| **Agent usage** | `agents.runs.per_month` | Short-lived counter, one per agent MCP tool call (`src/Mcp/Tools/ToolsCallHandler.php:590-601`) | none (catalogue example 200) | A durable count; model tokens if Whity supplies the model (question 8) |
| **API and MCP calls** | `ratelimit.rpm`, `mcp.access` | Only a per-minute rate limit; people's MCP calls are never counted | none | A durable monthly count per workspace |
| **Document renders** | `documents.render.per_day`, `per_month` | Short-lived counter; **refuses** past the limit because `meters.enforcement` defaults to `block` (`src/Core/Settings/SettingsRegistry.php:1330`) | seeded per old plan | Warn instead of refuse (BM.17); hosted Render is a service with its own price (BM.18) |
| **Owned workspaces** | `workspaces.max_owned_per_person` (a global setting, not an entitlement) | Count of workspaces owned | First free, more on a trial then paid (WHIT-1203); max 3 on app.whity.dev | Whether this is a meter under the new model (question 9) |

**The common gap: no usage ledger.** `MeterService` keeps counters in `SharedStore` keyed by window, so they vanish when the window ends. Charging for volume needs a durable, per-workspace, per-period usage table (one row per meter, workspace and period, plus snapshots for live counts like seats and items) that Billing, the limits banner (`GET /api/billing/limits`) and Whity Pay's metered lines all read. That is the first build step for meters (section 6, step 7).

**Warn, never block, until paid plans charge (BM.17, SC.14).** Two places block today and change to warn: document renders (`meters.enforcement = block` by default) and seats where `seats.enforcement = block` is set (app.whity.dev, by the salon runbook). How enforcement works once plans charge is question 7.

**Docs out of step with the code**, to fix with the meter work: `docs/wiki/Monetization-and-Metering.md` and ADR 0010 say plan values are copied onto the workspace at subscribe time, but the code reads the tier live (`EntitlementService.php`, migration 151); the wiki misses add-on packs, `MeterService`, warn-only keys, seat enforcement, customer usage, the limits API and plugin entitlements, and says there is no payment provider or `plan_prices`, which exist (migrations 140, 153).

## 6. Order

Each step is its own lane and PR, with its own WHIT id, after this register PR merges. Migration numbers and SDK minors come from the orchestrator before each PR.

1. **This register PR** (WHIT-1319). No code.
2. **Stop charging for features on app.whity.dev** (operator, no code; owner's go-ahead, question 2): Front Office and Salons "Free for every workspace", archive their add-on price rows, list which workspaces have them on and hold data. Set `seats.enforcement` back to warn if the owner agrees (question 5).
3. **Accounting extras into core**: the accounting lane updates section 32 and AC.\* (multi-currency, fixed assets, budgets, consolidation in core; JoFotara and bank feeds as integration plugins). In flight.
4. **Theme editor into core**: the theme lane updates section 30 and TH.15 onwards; `theme-creator` retires after. In flight.
5. **Front Office gaps into core**: a register row and a small spec for quotes, then the 80 mm receipt starter, trading emails and the CSV import, in the inventory and accounting lanes' order. Front Office stays installed, free, until these land.
6. **Front Office retired**: the import command if step 2 found data worth keeping, then unpublish and uninstall; remove the `plugin.front-office.*` mapping; update 16-documents, 11-plans, 07-voice.
7. **Usage ledger and meters**: the durable usage table, storage bytes, messages by channel, API and MCP calls, invoices once A2 ships; renders and seats to warn; the Billing meters read from the ledger; the wiki and ADR 0010 brought up to date.
8. **The JoFotara integration plugin**, then WhatsApp, SMS, calendar sync and bank feeds, each its own plugin in whity-plugins, sold through the store.
9. **Salons features into core** (or a free pack), with the chair price folded into the seat meter (question 3).
10. **Studio records, views, formulas and the page builder in core**, and Whity Builder's future (question 1). Largest and last.

Steps 3 and 4 are already running. Steps 5, 7 and 8 can run in parallel; 6 waits for 5; 9 and 10 wait for the owner's answers.

## 7. Questions for the owner

**Answered 21 November 2026.** Q1: "Into core". Q2 and Q12: "Yes, now" (done on app.whity.dev the same day). Q5: "Accept, all warn", with the suggested numbers. Every other question: "Accept all", the recommendation as written. The register rows BM.3–BM.5, BM.15–BM.19, M.16, S.2, FP.2 and FP.4 carry the answers.

1. **Whity Builder.** Does it move into core as Studio's editor, with its AI authoring as core agent tools counted by the agent and API meters, and builder.whity.dev and the connector retired? Or does it stay a hosted, paid service? Recommended: **into core**, after Front Office.
2. **Front Office on app.whity.dev now.** May the operator set Front Office (and Salons) to "Free for every workspace" and archive the `plugin.front-office.*` price rows ($29 a month, 14-day trial) now, before core covers every part? Any workspace that bought or started a trial keeps everything; no refunds are due because there are no real customers. Recommended: **yes, now**.
3. **Salons.** Its features (walk-ins, commission, price list, onboarding) become core or a free pack, and the per-chair price becomes the seat meter? Recommended: **yes**; the chair is a staff seat.
4. **Which records count.** Customers a month, items and invoices are named. Should contacts stay "always unlimited" (BM.3), and do documents, form responses and custom records count? Recommended: count customers a month, items and invoices only, keep contacts unlimited, and let storage cover the rest.
5. **The Free base.** The register says 2 staff and 100 customers a month (BM.3, 17 Nov); app.whity.dev was set to 5 staff and 500 customers with seat blocking for salons (WHIT-1131). "Free keeps a generous base": which numbers, and does seat blocking go back to warn? Suggested starting point, all warn-only: 3 staff, 200 customers a month, 500 items, 100 invoices a month, 2 GB storage, 500 messages a month (email; WhatsApp and SMS are paid plugins), 300 agent runs a month, 10,000 API and MCP calls a month.
6. **White-label (BM.5, M.16).** Its own web address, sign-in page, email sender and removing "Built on Whity" are features, not usage. Do they become free on every plan? Recommended: removing "Built on Whity" and the sign-in page free; the own address and email sender priced as part of Whity Cloud (they use Whity's certificates and mail reputation); the own app in the stores stays a paid hosted service.
7. **Once plans charge.** Past the base, does the workspace buy packs (BM.4's packs), get billed per unit automatically, or keep warning until someone upgrades? Recommended: packs per meter, chosen by an owner, with the warning staying until then; never lock out or delete (BM.11).
8. **Agent usage.** Count agent runs and tool calls only, or also model tokens when Whity supplies the model? Bring-your-own AI is counted as API calls. Recommended: runs and tool calls now; tokens only when Whity supplies a model.
9. **Extra workspaces (WHIT-1203).** Each workspace after the first starts on a paid plan with a 30-day trial, then read-only. Does that stay as a volume charge, or does every workspace get the free base and pay only its own meters? Recommended: every workspace gets the free base; the cap of 3 owned on app.whity.dev stays as abuse protection.
10. **Third-party plugins.** May outside publishers still sell feature plugins in the store (S.2), with BM.14 binding only Whity's own work? Recommended: **yes**.
11. **Currency rates.** Is fetching exchange rates from a public source an outside integration (paid) or core? Recommended: core fetches from one free public source; paid sources are plugins.
12. **The name Front Office.** Retire it as a product name, with its parts named as features (quotes, invoices, receipts, stock)? Recommended: **yes**.
13. **Form printouts (FP.2, FP.4).** Customising printouts is a plan feature today (paid plans and trials). Free on every plan under BM.14? Recommended: **yes**.
14. **Self-hosted.** BM.3 stays: no plan limits; a self-hosted install pays only for integration plugins and hosted services it uses? Recommended: **yes**.
