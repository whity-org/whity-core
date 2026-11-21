# 34 · Quotes, receipts, trading emails and import

**Status: proposed, 21 November 2026 (WHIT-1320).** Lane L2 of the business model (section 33 §6, step 5). The owner decided on 21 November 2026 that Front Office's parts become free core features (BM.15) and that "Front Office" stops being a product name (BM.19). The inventory lane (section 31) and the accounting lane (section 32) already rebuild most of it in core. This section covers the four parts no lane covers yet: **quotes**, the **80 mm receipt**, the **trading emails** and **import**. The register rows are QT.1–QT.12, TR.1–TR.4 and IM.1–IM.9 (section "Quotes, receipts, trading emails and import"). Section 9 lists the owner's questions; nothing here is built before they are answered.

Once these four parts are on `develop`, Front Office is unpublished from the store and the `plugin.front-office.*` mapping comes out of core (section 8). app.whity.dev holds no `fo_*` data (section 33 §6, checked 21 November 2026), so no import from Front Office's tables is written.

## 1. Scope

- **Quotes.** A priced offer to a customer, sent by email or link, accepted or declined online, then turned into a sales order (IV.13) or an invoice (AC.11). Core, every plan, every workspace, with or without inventory.
- **The 80 mm receipt.** A seeded document starter on the 80 mm roll core already prints (`PageSizes`), drawn from the document themes in English and Arabic (`TplReceipt80`, roadmap N8). It prints a paid sale.
- **Trading emails.** The quote, invoice, receipt and payment reminder emails a workspace sends its customers, from `handoff/emails/` (EM.1), in its brand, in English and Arabic. Core sends the quote email from the start; the invoice, receipt and reminder emails are ready for accounting's A2 to call.
- **Import.** One import engine for every kind of record, grown from the contact import core already has (WHIT-953, `src/Core/Contacts/Import`): customers and suppliers (built), inventory items and opening stock, supplier prices, and later accounting's opening balances. CSV and Excel, upload → match columns → check → import → undo for 24 hours (`TplImport`, roadmap N3).

Not in scope: price lists (a plugin through `WorkspacePriceList`, section 31 §6.3), a point of sale (a plugin on `Stock` and `Orders`), the Tax invoice and JoFotara (AC.22 and the JoFotara integration plugin), payment links (Whity Pay).

## 2. Words

| Word (English / Arabic) | Code | Means |
| --- | --- | --- |
| Quote / عرض سعر | `quote` | A priced offer to a customer with a date it is valid until. Not a promise of stock, not a debt. |
| Revision / تعديل | `revision` | A changed copy of a sent quote, with the same number and the next revision (QUO-0318 rev 2). |
| Accept / قبول | `accepted` | The customer said yes, online or told a person. |
| Turn into / تحويل إلى | `convert` | Make a sales order or an invoice from an accepted quote. |
| Receipt / إيصال | `receipt` | Proof of a payment; the 80 mm slip is one printed form of it. |
| Import / استيراد | `import` | Bringing records in from a spreadsheet, in one undoable step. |

Numbers follow section 16's story: QUO-0318 → INV-1042 → RCT-0207.

## 3. Quotes

### 3.1 Tables

Tables are prefixed `sales_`, because quotes belong to selling, not to stock (`inv_`) or the books (`acc_`).

| Table | Holds | Key columns |
| --- | --- | --- |
| `sales_quotes` | One row per quote revision | `number` (`QUO-0318`, from the sequence allocator with the key `sales:quote`, as purchase orders use `inventory:purchase_order`), `revision` (1, 2, …), `replaces_id` (the revision before), `contact_id` (a contact with `is_customer`), `status`, `issue_date`, `valid_until`, `currency`, `prices_include_tax`, `subtotal_minor`, `discount_minor`, `tax_minor`, `total_minor`, `notes`, `terms`, `route_id`, `document_id` (the printed copy), `public_token_hash`, `sent_at`, `decided_at`, `decided_by_name`, `decline_reason`, `created_by`, `agent_id`, `on_behalf_of` |
| `sales_quote_lines` | Lines | `quote_id`, `position`, `variant_id` (nullable: a line may be free text or a service with no item), `unit_id`, `description` (bilingual through `LocalizedText`), `quantity` (base-unit steps, IV.3; free lines use thousandths), `price_minor`, `discount_bp`, `tax_category`, `tax_bp`, `line_total_minor` |
| `sales_quote_conversions` | What a quote turned into | `quote_id`, `target` (`sales_order`, `invoice`, or a plugin's target key), `target_id`, `converted_by`, `converted_at`; `UNIQUE (tenant_id, quote_id, target)` so a quote turns into each kind once |

Amounts are minor units of the row's currency (CU.1–CU.4). Tax on a line is the rate in basis points until accounting's tax engine ships (A2); then `tax_category` maps to a tax code through `Taxes::codeFor()` and `tax_bp` is filled from it, so quotes need no migration when A2 lands. With `prices_include_tax`, tax is split per rate on the total, as Front Office did, so the printed tax equals the tax on the sum.

### 3.2 How a quote moves

```
draft ─► waiting_approval ─► ready ─► sent ─► accepted ─► converted
  │            │                        │  └─► declined
  │            └─► (sent back) draft    └─► expired
  └─► cancelled (any time before accepted)
```

1. **Draft.** A person (or an agent, as a proposal: section 3.7) picks the customer, adds lines from items (price from the item or a `WorkspacePriceList` plugin) or free lines, and sets the valid-until date (default: today plus the workspace's `sales.quote_valid_days`, 30).
2. **Approval** when the workspace has a route for quotes: the route subject `sales.quote`, with conditions like "total above $500" or "any discount above 10%" (FL.1). With no route, a person with `quotes:write` marks it ready.
3. **Send.** The quote takes its number on first send, its printed copy is issued through the issuing service (never changed afterwards, FP rules), and the quote email (TR.2) goes to the customer with a link to the quote's public page. "Copy link" and "Print" work without email.
4. **Accept or decline online** (section 16: "The quote's QR opens it online to accept"). The public page needs no sign-in, wears the workspace's brand (SU.1, SU.10), shows the printed quote, and offers **Accept** (the customer types their name) and **Decline** (an optional reason). A person can also record "Accepted by phone" with the name. Past `valid_until` the page says the quote has expired and how to ask for a new one; it never says "error".
5. **Revise.** Changing a sent quote makes a new revision with the same number; the earlier revision stays as it was, marked "replaced", and its link opens the newest revision with a note.
6. **Expire.** A daily job marks sent quotes past their date as `expired` and fires `sales.quote.expired`. An expired quote can be revised (a new date) or copied.
7. **Turn into** a sales order or an invoice (3.3).

The public link is a random token; only its hash is stored. The link opens one quote revision, read-only apart from Accept and Decline, and is rate-limited like the other public pages.

### 3.3 Turning a quote into an order or an invoice: the hook

Quotes ship before inventory's sales orders (P5) and before accounting's invoices (A2). So core does not call either directly. It asks a **conversion target**:

```php
namespace Whity\Sdk\Contribute;

interface WorkspaceQuoteConversion
{
    /** Stable key: 'sales_order', 'invoice', or a plugin's own ('rental'). */
    public function key(): string;
    /** Button label, English and Arabic. */
    public function label(): LocalizedText;
    /** Whether this quote can be turned into this target now, with a plain reason when not. */
    public function canConvert(QuoteSnapshot $quote): ConversionCheck;
    /** Make the target from the quote, in the caller's transaction; returns its id and a link. */
    public function convert(QuoteSnapshot $quote, int $actingProfileId): ConversionResult;
}
```

- `QuoteSnapshot` is read-only: number, revision, customer, currency, the lines with variant, unit, quantity, price, discount and tax, the totals, notes and terms.
- Core keeps a registry of targets. The quote page shows one **Turn into …** button per target whose `canConvert()` says yes; with none registered the quote stays `accepted` and the page says what will be possible ("Invoices arrive with Money").
- **Inventory P5** registers `sales_order`: a draft sales order with the quote's lines (variant lines only; free lines are listed back as "not stock" and kept on the order's notes).
- **Accounting A2** registers `invoice`: a draft customer invoice in its `acc_` tables (not platform billing's `invoices`) with every line (AC.11's "from items, sales orders and deliveries, or free lines" gains "or a quote"). This is the hook A2 fills; nothing in quotes changes when it does.
- Converting runs in one transaction: the target is made, a `sales_quote_conversions` row is written, the quote becomes `converted` (it stays `converted` if a second target is added later), and `sales.quote.converted` fires with the target key and id. The unique key makes a retried click harmless.
- A plugin (a rental or project plugin) may register its own target through the same interface.

Accepting a quote reserves no stock (question Q2). Stock is reserved when the sales order it turns into is confirmed (IV.13).

### 3.4 Permissions

`quotes:read` (see quotes), `quotes:write` (draft, send, revise, record a decision, cancel, convert). Converting also needs the target's own permission (`sales_orders:write`, `invoices:write`). The migration grants them by capability, as migration 321 grants `purchases:*` (to roles holding `settings:write` or `roles:write`), never by role name; the Accountant role (AC.24) gets `quotes:read` when accounting seeds it. Approving is never a permission: it is the route.

### 3.5 Printing

The guideline starter `editor.quote` (English and Arabic, `GuidelineStarters`) becomes record-driven the way inventory's purchase order is (`InventoryStarters`, `InventoryDocuments`): seeded and published the first time a quote prints, from `TplDocQuote`, through the workspace's document theme, with merge fields from a `QuoteFieldProvider` (number, revision, customer, lines, totals, valid until, terms, the QR to the public page). Amount in words follows CU.3. Issuing a revision issues a new printed copy; earlier copies never change.

### 3.6 Events and SDK

- Filter hook (can veto with `HookVetoException`): `sales.quote.sending`.
- Notification hooks, each with an `.async` twin: `sales.quote.created`, `sales.quote.sent`, `sales.quote.accepted`, `sales.quote.declined`, `sales.quote.expired`, `sales.quote.converted`, `sales.quote.cancelled`. All added to the webhook event catalogue.
- Service `Whity\Sdk\Sales\Quotes`: `list(filter, limit, offset)`, `get(quoteId)`, `createDraft(data, actingProfileId)`, `addLines`, `send(quoteId, actingProfileId)` (to its route or sends), `recordDecision(quoteId, accepted, name, reason, actingProfileId)`, `convert(quoteId, targetKey, actingProfileId)`, `cancel`. The workspace comes from the call (section 31 §6), never from an argument.
- Extension point `Contribute\WorkspaceQuoteConversion` (3.3).
- One SDK minor, asked of the orchestrator at PR time.

### 3.7 Agents and MCP

Read tools: `quotes_list`, `quotes_get`. Write tools make **proposals** a person accepts (CLAUDE.md, agents never change anything directly): an agent can draft a quote and propose sending it, attributed to the agent and the person it acts for. Sending to a customer is Check first (tier 2). Arabic tool names in the tool catalogue.

### 3.8 Screens

| Screen | Card | Status |
| --- | --- | --- |
| Quotes list (Sales › Quotes) | none; list page pattern (section 21) | **Ask** the brand team |
| A quote (record page: header, lines, totals, status, Send, Turn into, history) | none; record page pattern | **Ask** |
| New quote | none | **Ask** |
| The public quote page (Accept, Decline) | none; public page pattern (SU.1, SU.10) | **Ask** |
| Printed quote | `TplDocQuote` | Drawn |
| Phone: quotes list and a quote | none | **Ask** |

Until the cards arrive, screens follow section 21's list and record patterns and the six states. Where quotes sit in the sidebar is question Q4.

## 4. The 80 mm receipt

- A seeded starter, English and Arabic, from `TplReceipt80` (beside the A5 `editor.receipt` guideline starter, which stays): page size `80mm` (core prints the roll already, `src/Core/Document/Print/PageSizes.php`), 72 mm printable, Geist Mono, through the workspace's document theme (logo, name, colours where a thermal printer can show them: black only).
- Content: business name, branch and phone, tax number; receipt number (sequence key `sales:receipt`, `RCT-0207`), date, time, cashier; lines (quantity × item, amount), subtotal, sales tax per rate, the total in the currency's decimals, how it was paid; the e-invoicing QR slot with "scan to verify" (empty until the JoFotara plugin fills AC.22's slot, and then the slip says "Not yet reported" until it has); a thank-you line; "Built on Whity" by BM.5's rule. Arabic mirrors the whole slip; numbers stay Western digits; "Built on Whity" stays in Latin letters.
- A `SaleReceiptFieldProvider` (inventory's `ReceiptFieldProvider` is the goods received note) reads a **paid sale**. Its sources arrive with the lanes: a sales order marked paid (inventory P5) and a payment received (accounting A2, AC.14, AC.26). Until then the starter previews with Example Bakery's sample data in the document editor and can be customised (FP.2: free).
- Printing: the browser's print dialog on the 80 mm page (a till PC or a phone's printer app), or a PDF from Whity Render. Sending it as an image by WhatsApp is the WhatsApp plugin's (BM.16).

## 5. Trading emails

The four "Money" emails of EM.1 (`invoice`, `quote`, `payment-reminder`, `receipt`), built from `handoff/emails/` on core's `BrandedEmailSender` (the workspace's brand, language and white-label rule), English and Arabic:

| Email | Sent by | Main button | Attachment |
| --- | --- | --- | --- |
| Quote | Sending a quote (3.2) | Review and accept (the public page) | The quote PDF |
| Invoice | Issuing an invoice, by accounting A2 | Pay now with Whity Pay's link, else View the invoice | The invoice PDF |
| Receipt | A payment received, by accounting A2 | View your receipt | The receipt PDF |
| Payment reminder | A person, or a reminder the Billing assistant proposed and a person accepted (AC.11) | Pay now, else View the invoice | none |

- One service, `TradingEmails`, owns the words and the layout; accounting calls it from A2 and needs to draw nothing.
- Amounts in normal units with the currency code, inside `dir="ltr"` (EM.1 rules). Reminders assume the customer may have paid.
- Replies go to the workspace (the reply-to that lands in Chat, UC.\*).
- Each email is on by default and the workspace can turn each off (EM.1's "The workspace chooses"), in Settings › Emails.
- Messages sent count on the messages meter (BM.17), warn only.

## 6. Import

### 6.1 One engine

Core's contact import (WHIT-953) becomes the engine for every kind. Its four steps stay exactly as built and as `TplImport` draws them: **upload** (CSV or Excel; the header row is found), **match columns** (by name in English or Arabic and by content; tidied values are listed), **check** (create, update by a match key, or skip with a reason; skipped rows download as CSV; nothing changes), **import** (the plan is worked out again against the data as it is now and applied in one transaction, each change recorded with the values before), and **undo** for 24 hours.

What changes is that the import knows its **kind**:

| Table | Replaces | Key columns |
| --- | --- | --- |
| `import_jobs` | `contact_imports` | `kind`, `file_name`, `status` (`uploaded`, `checked`, `running`, `imported`, `undone`, `failed`), `header_json`, `rows_json`, `options_json` (the column mapping and choices), `summary_json`, `progress` (rows done, for background runs), `created_by_profile_id`, `imported_at`, `undone_at` |
| `import_job_changes` | `contact_import_changes` | `job_id`, `entity_type`, `entity_id`, `action` (`created`, `updated`, `posted`), `before_json`, `extra_json` (tags added, the stock operation or journal entry made) |

The migration creates the new tables, copies the rows of the old ones (idempotent: it skips rows already copied), and a second migration drops the old tables. app.whity.dev holds no real customers (owner, 16 November 2026), so nothing older than 24 hours needs to survive as undoable.

### 6.2 Kinds

| Kind | Match key | What import does | Undo | Ships with |
| --- | --- | --- | --- | --- |
| `contacts` (customers and suppliers) | tax number, mobile, email, name | Creates or updates contacts, tags, custom fields | Removes made ones, restores changed ones | Built (WHIT-953); moves onto the engine |
| `inventory.items` | SKU, then barcode | Items with their variants (one row per variant, the option columns name the axes), base unit, sale and purchase price, category, barcode, tax category, reorder level; Arabic name column | Archives made items, restores changed ones | L2 import PR |
| `inventory.opening_stock` | SKU (+ lot) | One `opening` operation: `opening → place` moves at the given unit cost, lots and expiry when the item tracks them | Reverses the moves if none of that stock has moved since | L2 import PR |
| `inventory.supplier_prices` | supplier + SKU | Supplier items and prices (`inv_supplier_items`) | Restores | L2 import PR, or inventory P3 if it lands first |
| `accounting.opening_balances` | account code | One opening journal entry dated the opening date, against the opening balance equity account | Reverses the entry if the period is open | Accounting lane (its A2 or A4), on this engine |
| `accounting.chart`, open invoices and bills, bank statements | as section 32 §9.6 | | | Accounting lane |

A kind is a class behind one interface, registered with the engine:

```php
namespace Whity\Sdk\Contribute;

interface WorkspaceImportKind
{
    public function key(): string;                 // 'inventory.items'
    public function label(): LocalizedText;
    public function requiredPermission(): string;  // 'inventory:write'
    /** @return list<ImportTarget> the columns it can fill, with English and Arabic headings that mean each one */
    public function targets(): array;
    /** Work out one row: create, update or skip with a reason, and what was tidied. Changes nothing. */
    public function plan(ImportRow $row, ImportPlanContext $ctx): ImportRowPlan;
    /** Apply the planned rows, inside the engine's transaction; record each change through the context. */
    public function apply(array $plans, ImportApplyContext $ctx): void;
    /** Put back what apply() recorded, or refuse with a plain reason. */
    public function undo(array $changes, ImportUndoContext $ctx): void;
}
```

Core's kinds implement it, and the accounting lane and plugins add theirs the same way. Custom fields (`CustomFieldImportTargets`) keep adding the workspace's own fields to every kind whose record type has them (contacts now, `inventory.item` too).

### 6.3 Size and background runs

- Up to **5,000 rows** (5 MB, `SpreadsheetReader`'s limits today) check and import while the person waits, as today.
- Larger files, up to **100,000 rows** and 20 MB, run as a background job on core's queue (`QueueService::dispatch('core.import.run', …)`, the `queue:work` worker): the screen shows progress, the person can leave, and a notification says when it is done (with the summary and the skipped-rows file). Apply still runs in batches of 1,000 inside one job, so a failure leaves the job `failed` with nothing half-applied in the failing batch, and undo covers the batches already applied.
- Uploaded rows are kept on the job for 7 days, then cleared (the summary stays).

### 6.4 Permissions, meters and audit

- Importing needs the kind's write permission (`contacts:import`, `inventory:write` for items, opening stock and supplier prices, `accounting:post` for opening balances), checked when uploading and again when importing.
- Imports never refuse because of a meter (BM.17, warn only). The check step says when the import will take the workspace past its base ("This adds 640 items; your plan includes 500").
- Every import writes one audit entry with the counts, and each record it makes or changes is attributed to the import.

### 6.5 Screens

One import screen for every kind, from `TplImport` (drawn), opened from the list page of what is imported (Contacts › Import, Items › Import, Money › Import) and from Settings › Import with a kind picker. Phone: `TmFinanceMobile`'s import is view-only (check results and undo); uploading is a desktop task. The kind picker and the progress view are **Ask** for the brand team.

## 7. How it fits what exists

| Existing | How L2 uses it |
| --- | --- |
| Sequence allocator (`SequenceCounters`) | Keys `sales:quote` (QUO-) and `sales:receipt` (RCT-) |
| Issuing service and document themes | Printed quotes and receipts, never changed once issued |
| Routing (`RouteSubjectProviderInterface`) | Route subject `sales.quote` |
| `BrandedEmailSender` and `EmailLayout` (EM.1) | The trading emails |
| Public pages (SU.\*) | The quote acceptance page |
| Contact import (WHIT-953) | Becomes the import engine; `SpreadsheetReader` and `ColumnMatcher` are kept |
| Queue (`QueueService`, `JobRegistry`, `queue:work`) and scheduler (`schedule:run`) | Background imports (`core.import.run`) and the daily quote expiry |
| Platform billing's `invoices` and `QuoteService` | Untouched: those are Whity billing workspaces. Customer quotes live in `src/Core/Sales` and `sales_*` tables so the names never meet |
| `PageSizes` (80 mm roll) | The receipt |
| `PluginWorkspaceGate`, `HostWorkspaceAddons` | Front Office's mapping comes out (section 8) |

## 8. Retiring Front Office

When quotes, import, the receipt and the trading emails are on `develop`:

1. **whity-plugins:** Front Office is marked retired and unpublished from the store: it is taken out of the store's catalogue and the release workflow, and its README says where each part now lives in core. The folder stays in the repository's history.
2. **Core:** the `plugin.front-office.*` mapping in `src/Core/Plan/HostWorkspaceAddons.php` is removed, with its tests; comments that name Front Office as the owner of the Tax invoice, the receipt or trading emails (`GuidelineStarters`, `PageSizes`, `BrandedEmailSender`, `ChatBots`) are corrected to name the core feature or the JoFotara plugin.
3. **Docs:** 16-documents, 11-plans and 07-voice stop using "Front Office" as a product name (BM.19).
4. **No core migration.** app.whity.dev has no `fo_*` tables and no `plugin.front-office.*` price or plan rows (checked 21 November 2026). A self-hosted install that had Front Office keeps its `fo_*` tables untouched (S.7); a one-off import command is written only if one turns up.

## 9. Questions for the owner

1. **Quote acceptance.** The public page asks the customer to type their name to accept; there is no drawn signature. Enough, or should accepting use core's e-signature (sign request) when the workspace turns it on? Recommended: **typed name**, with e-signature as a workspace setting later.
2. **Reserving stock.** Does an accepted quote reserve its items? Recommended: **no**; stock is reserved when the sales order it turns into is confirmed (IV.13).
3. **Revisions.** A changed sent quote keeps its number with "rev 2", rather than taking a new number. Recommended: **keep the number, add the revision**.
4. **Where quotes live.** AC.30 keeps Sales inside Inventory. Quotes are also for workspaces that sell only services and never open Inventory. Recommended: **Operations › Sales** with tabs Quotes and Sales orders, shown to every workspace; Sales orders appear when inventory is on.
5. **Receipt before accounting.** Ship the 80 mm starter now with sample data and a paid sales order as its first source, and payments as a source when A2 lands? Recommended: **yes**.
6. **Import limits.** 5,000 rows while waiting, up to 100,000 rows in the background, rows kept 7 days. Recommended: **as written**.
7. **Old contact import tables.** Copy into `import_jobs` and drop `contact_imports` in the same release. Recommended: **yes** (no real customers, owner 16 November 2026).
8. **Quote validity.** 30 days by default, set per workspace. Recommended: **30 days**.

## 10. Build plan

Each step is one pull request to `develop`, merged on green CI, with migration numbers and the SDK minor from the orchestrator.

| Step | What | Migrations | Ticket |
| --- | --- | --- | --- |
| Spec | This section and the register rows | — | WHIT-1320 |
| Quotes | Tables, service, numbering, route subject, permissions, quote starters, public page, the quote email, expiry job, conversion registry and `WorkspaceQuoteConversion`, SDK `Quotes`, MCP tools, screens | 2 (tables; permissions and settings) | WHIT-1321 |
| Import | `import_jobs` engine, contacts moved onto it, `inventory.items`, `inventory.opening_stock`, `inventory.supplier_prices`, background runs, `WorkspaceImportKind` | 2 (new tables and copy; drop old) | WHIT-1322 |
| Receipt and emails | The 80 mm receipt starter and field provider, `TradingEmails` (invoice, receipt, reminder ready for A2), Settings › Emails switches | 0–1 | WHIT-1323 |
| Retire Front Office | whity-plugins unpublish, core mapping and comments, docs | 0 | WHIT-1324 |

## 11. Register rows

QT.1–QT.12, TR.1–TR.4 and IM.1–IM.9, in the register's section "Quotes, receipts, trading emails and import".
