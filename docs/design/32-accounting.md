# 32 · Accounting in core

**Status: decided by the owner on 21 November 2026, then changed the same day: all of accounting is core.** The owner's new approach: "I want to start a new approach... everything is in whity... and core supports almost everything that it makes sense to support... extra usage and users is what is paid, and outside integration plugins are paid like payment gateways, jofotara, etc..." Asked about accounting's extras, the owner answered "All into core": multi-currency, fixed assets, budgets and consolidation are free core features, and there is no paid accounting plugin. What is paid is "Seats and volume" (invoices a month is the accounting meter) and outside integration plugins: JoFotara and live bank feeds here. Q3–Q10 and Q12–Q14 stand as recommended ("Accept all"); Q1 and Q2 changed with the new approach (section 12); for Q11 the owner will name a Jordanian accountant, and A1 does not merge until that person has reviewed the chart, nor A6 until they have reviewed the tax pack and return. Built phase by phase. The register rows are AC.1–AC.30 (section "Accounting in core"). Ticket WHIT-1318.

On 21 November 2026 the owner placed accounting: **"Core basics, paid plugin"**. Core gets a double-entry ledger, the chart of accounts, journals, invoices and bills, the tax return, and SDK hooks. A paid plugin later adds multi-currency, bank feeds, fixed assets, budgets, consolidation and e-invoicing. Later that day the owner changed this: "All into core". Core now also gets multi-currency, fixed assets, budgets and consolidation; JoFotara and live bank feeds are paid integration plugins, because outside integrations are what is paid. For tax the owner chose **"Jordan first"**: Jordan's 16% general sales tax and JoFotara e-invoicing first; other MENA countries come later as plugin packs.

This spec turns the Money designs that already exist (MO.1, section 27; cards `TplMoney`, `TplLedger`, `TmMoneyMobile`, `TplDocInvoice`, `TplDocInvoiceAr`, `TplDocInvoiceLong`, `TplDocCreditNote`, `TplDocReceipt`, `TplReceiving`'s billed column, `TplReports`) into a data model, posting rules, permissions, an SDK surface and a build plan. It follows the shape of section 31 (inventory), and it closes what inventory left waiting for Money: supplier bills, the three-way match, and posting stock to the books (IV.12, IV.22, IV.23).

It is a different thing from **platform billing** (`src/Core/Billing`, `src/Core/Payment`), where the operator bills workspaces for Whity itself. That code keeps its tables and words. Accounting is a workspace's own books, its tables are prefixed `acc_`, and nothing here changes how Whity bills its workspaces.

## 1. Scope

**In core (free):**
- A double-entry ledger: append-only entries with balanced lines, reversals instead of edits, periods, closing, and an audit trail.
- The chart of accounts, with a Jordan default template, editable per workspace.
- Journals (sales, purchases, bank, cash, general, stock, opening).
- Sales invoices, credit notes, supplier bills, supplier credits, payments received and made, and allocations between them.
- Bank and cash accounts with manual entries and a statement file import with matching (Q4).
- The tax engine: tax codes and rates, tax on every line, and the Jordan pack (16% general sales tax, the reduced, zero and exempt rates, the two-month return).
- Posting stock moves through IV.22's accounting-export extension point (valuation stays the simple cost of IV.18).
- How Whity Pay payments post.
- Reports: trial balance, general ledger and account statement, profit and loss, balance sheet, aged receivables and payables, customer and supplier statements, and the sales tax return.
- Export for your accountant.
- Permissions, routing for approvals, documents through the document themes, Arabic first, MCP tools, the Bookkeeping assistant as proposals, and everything in the plugin SDK from the first phase.

**Also in core** (owner, 21 November 2026: "All into core"), in later phases (section 11):
- Multi-currency: foreign-currency invoices, bills and bank accounts, rates, realised and unrealised exchange differences, revaluation.
- Fixed assets and depreciation.
- Budgets (MO's budgets per team).
- Consolidation of several workspaces.
- The Bookkeeping assistant's rules for statement lines.

**Paid integration plugins** (outside integrations, priced with the other integrations such as payment gateways), in whity-plugins, licensed through Whity ID:
- JoFotara: reporting invoices and credit notes to Jordan's Income and Sales Tax Department (section 7.4).
- Live bank feeds: connections to banks and providers that write statement lines (statement file import and matching stay in core).
- Later, other countries' e-invoicing systems (Saudi Arabia's ZATCA, Egypt's ETA and others), each through the same core extension point.

**Tax packs** for other countries (codes, return layout, chart template) are plugin packs, as the owner decided for tax ("Jordan first"); a pack is not an outside integration.

**What is paid in accounting:** seats and volume ("Seats and volume", owner, 21 November 2026). The accounting meter is invoices issued a month; how it is counted and charged is set by the business-model decision, not here.

**Not in core accounting, for now:** payroll (a later HR plugin posts through the SDK), point of sale (a POS plugin posts through the SDK), cost centres beyond the branch dimension, projects accounting, and recurring invoices with their own schedules (Whity Pay's subscriptions already invoice).

## 2. Words

The screens use plain words with the accountant's term on hover or in the details; the code uses the terms in brackets. MO.1 already set this tone: "Customers owe us", "Sales tax we can reclaim", codes beside the names.

| On screen (en / ar) | Code | Meaning |
| --- | --- | --- |
| Account / حساب | `account` | One line in the chart, such as "Customers owe us (1200)". |
| Chart of accounts / دليل الحسابات | `chart` | All the accounts as a tree. |
| Entry / قيد | `entry` | One posting: two or more lines whose debits equal their credits. Never edited and never deleted. |
| Line / سطر | `line` | One account, a debit or a credit, and optionally a contact, a tax code and a branch. |
| Journal / دفتر يومية | `journal` | A stream of entries of one kind (sales, bank, general) with its own numbers. |
| Period / فترة | `period` | One month. Open, closed or locked. |
| Year / سنة مالية | `fiscal_year` | Twelve periods; closing it moves profit to retained earnings. |
| Invoice / فاتورة | `invoice` kind `sale` | What a customer owes us. Called "Tax invoice / فاتورة ضريبية" when e-invoicing issues it. |
| Credit note / إشعار دائن | `invoice` kind `sale_credit` | Takes back all or part of an invoice. |
| Bill / فاتورة مورد | `invoice` kind `purchase` | What we owe a supplier. |
| Supplier credit / إشعار دائن من مورد | `invoice` kind `purchase_credit` | A supplier takes back all or part of a bill. |
| Payment / دفعة | `payment` | Money in (received, `in`) or out (made, `out`). |
| Cheque / شيك | `payment` method `cheque` | With a due date; post-dated cheques wait in "Cheques to collect" or "Cheques we wrote" until due (Q7). |
| Tax code / رمز ضريبي | `tax_code` | A rate and where it goes on the return: "Sales tax 16%". |
| Sales tax return / إقرار ضريبة المبيعات | `tax_return` | The figures for one tax period, prepared by Whity and filed by the company. |
| Owed to us / ذمم مدينة | `receivable` | Customers' unpaid invoices. |
| We owe / ذمم دائنة | `payable` | Suppliers' unpaid bills. |

## 3. Data model

All tables are tenant-owned, carry `tenant_id`, and get rows in `CrossTenantRejectionRealEngineTest`. Every query carries the tenant predicate. The prefix is `acc_`.

### 3.1 Amounts

An amount is a **whole number of minor units** in `BIGINT`, the same as `Money` (`src/Core/Money`): fils for JOD (3 decimals), cents for USD (2). No floats, no `bcmath`, sums in SQL, the same on PostgreSQL and SQLite.

- Every workspace has one **base currency**: `billing.default_currency` at the moment of the first posting, then fixed (AC.6). Until the multi-currency phase (A7) the books are in the base currency only (Q14); A7 adds foreign-currency documents in core.
- **Ready for multi-currency from day one.** Documents and lines store `currency`, the transaction amount, the base amount and the rate as a whole-number fraction (`rate_num / rate_den`), as inventory did with variants (IV.4): core writes the base currency with rate 1/1 until A7 fills them in, with no migration.
- **Rounding** happens once, at the edge, half away from zero, to the currency's decimals: per line for tax (JoFotara's rule, to be confirmed by the accountant, Q11), never inside the ledger. A document whose lines round to a total one minor unit away from a printed total posts the difference to the rounding account.

### 3.2 Tables

| Table | Holds | Key columns | Notes |
| --- | --- | --- | --- |
| `acc_accounts` | The chart | `code` (unique per tenant), `name` (bilingual through `LocalizedText`), `type` (`asset`, `liability`, `equity`, `income`, `expense`), `subtype` (`bank`, `cash`, `receivable`, `payable`, `tax`, `inventory`, `cost_of_sales`, `fixed_asset`, `current_asset`, `current_liability`, `long_term_liability`, `other`), `parent_id`, `is_group` (headings hold no lines), `role` (nullable system role, see 3.4), `allows_manual` (bool), `ou_id` (optional default branch), `currency` (nullable: plugin), `archived_at`, `owner_plugin` | A tree of at most 5 levels. An account with lines is archived, never deleted; its code cannot change once it has lines (Q12 option). |
| `acc_journals` | Journals | `code` (`SAL`, `PUR`, `BNK1`, `CSH`, `GEN`, `STK`, `OPN`), `name` (bilingual), `kind` (`sale`, `purchase`, `bank`, `cash`, `general`, `stock`, `opening`, `plugin`), `default_account_id` (bank and cash journals: their account), `numbering_key` | Each bank or cash account has its journal. Plugins add journals of kind `plugin`. |
| `acc_fiscal_years` | Years | `starts_on`, `ends_on`, `status` (`open`, `closed`), `closing_entry_id` | Default calendar year; the first year may be short. |
| `acc_periods` | Months | `fiscal_year_id`, `starts_on`, `ends_on`, `status` (`open`, `closed`, `locked`), `closed_at`, `closed_by`, `reopened_at`, `reopened_by`, `reopen_reason` | Created a year ahead by a job. |
| `acc_entries` | **The ledger, headers** | `id` (bigint), `journal_id`, `number` (from `DocumentNumbering` per journal, e.g. `GEN-2026-0042`), `date` (the accounting date), `period_id`, `memo`, `source_type`, `source_id`, `reverses_entry_id` (only the reversal points back, so nothing is updated; 3.3), `posted_at`, `posted_by` (profile), `agent_id`, `on_behalf_of`, `idempotency_key`, `prev_hash`, `hash` (3.5) | Append-only (3.3). `UNIQUE (tenant_id, idempotency_key)` where set. |
| `acc_lines` | **The ledger, lines** | `entry_id`, `line_no`, `account_id`, `debit_minor`, `credit_minor` (exactly one is above zero), `currency`, `amount_minor` (transaction currency, signed), `rate_num`, `rate_den`, `contact_id`, `tax_code_id`, `tax_base_minor` (the net the tax was on, for the return), `ou_id` (branch), `description`, `invoice_id`, `payment_id`, `source_line_type`, `source_line_id`, `date` (copy of the entry's, for indexes) | Append-only. A CHECK keeps one side zero and the other positive. |
| `acc_balances` | **The projection**: monthly totals | `account_id`, `period_id`, `ou_id` (0 for none), `debit_minor`, `credit_minor`, `last_line_id` | Rebuildable from the ledger (3.3). Reports read this, never sums over all lines. |
| `acc_manual_entries`, `acc_manual_entry_lines` | Drafts of manual entries | `journal_id`, `date`, `memo`, `status` (`draft`, `waiting_approval`, `posted`, `cancelled`), `route_id`, `entry_id` (once posted), `created_by`, `agent_id`, `on_behalf_of`; lines as `acc_lines` | Drafts live here; the ledger only ever receives posted entries. Recurring templates are a later step. |
| `acc_tax_codes` | Tax codes | `code` (`JO-GST-16`, `JO-GST-0`, `JO-EXEMPT`, `JO-GST-IN-16`, …), `name` (bilingual), `pack` (`jo`, a plugin's pack), `direction` (`output`, `input`), `rate_bp` (basis points: 1600 = 16%), `treatment` (`standard`, `reduced`, `zero`, `exempt`, `out_of_scope`, `reverse_charge`), `account_id` (where the tax goes), `return_box` (the box on the return), `einvoice_category` (the code e-invoicing sends), `valid_from`, `valid_to`, `archived_at`, `owner_plugin` | Rates change by date: a new code row, never an edited rate. |
| `acc_invoices` | Invoices, credit notes, bills, supplier credits | `kind` (`sale`, `sale_credit`, `purchase`, `purchase_credit`), `number` (ours, given when issued), `supplier_number` (bills: theirs), `contact_id`, `issue_date`, `supply_date`, `due_date`, `payment_terms_days`, `status` (`draft`, `waiting_approval`, `issued`, `partly_paid`, `paid`, `cancelled`), `currency`, `rate_num`, `rate_den`, `subtotal_minor`, `discount_minor`, `tax_minor`, `total_minor`, `paid_minor`, `credited_minor`, `credits_invoice_id` (a credit note's invoice), `order_id` (inventory order), `operation_ids` (receipts or deliveries it bills), `entry_id`, `document_id` (the printed copy), `route_id`, `payment_link_ref` (Whity Pay), `einvoice_status` (`none`, `pending`, `accepted`, `rejected`, `not_required`), `einvoice_uuid`, `einvoice_ref`, `einvoice_qr`, `einvoice_counter`, `einvoice_payload_file_id`, `notes`, `created_by`, `agent_id`, `on_behalf_of`, `external_ref`, `owner_plugin` | One table for four kinds: they share lines, tax, allocation and the aged reports. An issued invoice never changes (3.6). The e-invoice columns are filled by the e-invoicing extension point (section 7). |
| `acc_invoice_lines` | Lines | `invoice_id`, `line_no`, `variant_id` (nullable: an item), `description` (bilingual), `quantity` (inventory's `Quantity` steps), `unit_id`, `unit_price_minor`, `discount_bp`, `net_minor`, `tax_code_id`, `tax_minor`, `total_minor`, `account_id` (income or expense; from the item's category or the default), `ou_id`, `order_line_id`, `operation_line_id` | Prices include or exclude tax by a per-document switch (`prices_include_tax`), stored on the invoice. |
| `acc_payments` | Money in and out | `direction` (`in`, `out`), `number`, `contact_id` (nullable: a transfer or a bank fee), `date`, `amount_minor`, `currency`, `rate_num`, `rate_den`, `method` (`cash`, `bank_transfer`, `cliq`, `card`, `cheque`, `whity_pay`, `other`), `account_id` (bank, cash, cheques or a provider's clearing account), `reference`, `cheque_number`, `cheque_bank`, `cheque_due_on`, `cheque_status` (`waiting`, `deposited`, `cleared`, `bounced`), `fee_minor`, `status` (`draft`, `posted`, `cancelled`), `entry_id`, `external_ref` (Whity Pay), `idempotency_key`, `created_by`, `agent_id`, `on_behalf_of`, `owner_plugin` | A payment can be recorded with no invoice (an advance) and allocated later. |
| `acc_allocations` | What settles what | `payment_id` or `credit_invoice_id`, `invoice_id`, `amount_minor`, `date`, `entry_id` (when the allocation itself posts, as for cheques), `reversed_at` | Invoices' `paid_minor` and `credited_minor` are projections of this. |
| `acc_bank_statements`, `acc_bank_lines` | Imported statement lines | statements: `account_id`, `file_id`, `starts_on`, `ends_on`, `opening_minor`, `closing_minor`, `source` (`file`, a plugin feed); lines: `date`, `amount_minor`, `description`, `reference`, `counterparty`, `status` (`unmatched`, `proposed`, `matched`, `ignored`), `matched_line_ids`, `proposed_by_agent_id` | Matching links statement lines to ledger lines; it never edits either. The paid plugin's feeds write here too. |
| `acc_tax_returns` | Returns | `pack`, `starts_on`, `ends_on`, `status` (`draft`, `prepared`, `filed`), `figures` (JSON: each box with its amount and the line count), `einvoice_counts` (JSON), `file_id` (the download), `prepared_at`, `prepared_by`, `filed_at`, `filed_by`, `filed_reference`, `payment_id` (paying the tax) | Preparing freezes the figures; lines posted later into the period show as "after preparing" and go on the next return as an adjustment (the period is closed anyway, 3.4). |
| `acc_account_mappings` | Which account a thing posts to | `subject_type` (`item_category`, `item`, `tax_code`, `location`, `payment_method`, `plugin_source`), `subject_id`, `purpose` (`income`, `expense`, `inventory`, `cost_of_sales`, `stock_loss`, `stock_found`), `account_id` | Fallbacks are the roles in 3.4. |

Settings (workspace settings, not tables): base currency (read-only after the first posting), fiscal year start, the tax pack and tax registration number, the tax period length (two months for Jordan, Q10), prices include tax by default, the default payment terms, whether stock posts to the books (Q3), the approval thresholds, the lock date, the document number prefixes, and the legal fields printed on invoices (from tenant settings, as rule 6.7 requires).

### 3.3 The ledger is the source of truth

- **Every money fact is an entry.** An issued invoice, a posted bill, a payment, an allocation of a cheque, a stock operation, a manual entry, a closing: each posts one entry whose lines balance. A document that is still a draft has no entry.
- **Balanced by construction.** The posting service refuses an entry whose debits and credits differ, whose accounts are headings or archived, or whose date is in a closed period. A deferred constraint checks the sum per entry on PostgreSQL; SQLite runs the same check in the service and a test proves both engines refuse an unbalanced entry.
- **Append-only.** No update or delete for `acc_entries` and `acc_lines`. A database trigger refuses `UPDATE` and `DELETE` on both engines, exactly as `inv_moves` (IV.2). Workspace purge is the one exception, through the tenant purge path.
- **Corrections are reversals.** Undoing an entry posts its mirror (debits and credits swapped) with `reverses_entry_id`, dated the original date when that period is open, otherwise the first day of the first open period. Cancelling an issued invoice is a credit note, never a reversal of the invoice entry (the tax authority sees the credit note, section 7). Correcting a draft is just editing the draft.
- **Posting is one transaction:** filter `accounting.entry.posting` (may veto: a closed period, a plugin rule), insert the entry and lines, update `acc_balances` with `SELECT ... FOR UPDATE` in a fixed order (account, period, branch), update the source document's status and projections, compute the hash (3.5), commit. `accounting.entry.posted` fires after; `.async` after commit.
- **Idempotent.** An entry or payment posted with an `idempotency_key` (an MCP call key, Whity Pay's payment id, a plugin's `external_ref`) posts once.
- **Balances can be rebuilt.** `whity-cli accounting:rebuild-balances [--workspace=]` recomputes `acc_balances` from the lines and reports any difference; a nightly job checks a sample and records drift on the health page; the same job checks the trial balance is zero.
- **One lock date for the books and the stock.** Closing a period also refuses stock moves dated in it: inventory's period lock (IV D7) and accounting's close become one lock date, checked in both `stock.moving` and `accounting.entry.posting`.

### 3.4 Periods, closing and system accounts

- **Periods** are months inside a fiscal year. `open` takes postings. `closed` refuses them; a person with `accounting:close` can reopen with a reason, which is audited and notifies the other closers. `locked` (after a filed tax return or a closed year) can be reopened only by an admin, as a route subject (`accounting.period_reopen`) so a second person agrees.
- **Closing a month** checks: no drafts dated in it that are waiting, bank accounts matched up to its end (a warning, not a block), stock posted, and the trial balance zero. It then closes the period.
- **Closing a year** posts one closing entry in the general journal moving income and expense balances to retained earnings, and opens the next year. Reports for a closed year still show income and expenses (they read lines before the closing entry, which is flagged).
- **System roles.** Some accounts have a `role` that posting rules use: `receivable`, `payable`, `sales_tax_output`, `sales_tax_input`, `sales_tax_payable` (the settlement), `inventory`, `goods_received_not_billed`, `cost_of_sales`, `stock_loss`, `stock_found`, `price_difference`, `opening_balance`, `retained_earnings`, `current_year_earnings`, `rounding`, `cash`, `bank`, `cheques_to_collect`, `cheques_written`, `provider_clearing`, `payment_fees`, `customer_advances`, `supplier_advances`, `exchange_difference` (plugin). Each role has exactly one account per workspace; the account can be renamed or recoded but not deleted, and its role moves only to another account of the same type.

### 3.5 Audit trail

- Every entry names who posted it, the agent and the person it acted for, the source document, the time it happened (`date`) and the time it was recorded (`posted_at`).
- **Tamper evidence** (Q12): each entry stores `hash = sha256(prev_hash ‖ canonical entry and lines)`, chained per workspace. `whity-cli accounting:verify-chain` and the health page say whether the chain is whole. It costs one hash per posting and lets an auditor trust an export.
- Master data (accounts, tax codes, mappings, settings, period status) writes `AuditTrail` entries with before and after. Approvals are in the route trail. Nothing is hard-deleted except by workspace purge; accounts and tax codes are archived.

### 3.6 Documents never change once issued

A sales invoice gets its number when it is issued, not when drafted, so numbers have no gaps (JoFotara's invoice counter needs this). Once issued, its lines, totals, customer and dates are frozen; the printed copy is a document issued through the document themes and never changes (FP rules). Fixing it means a credit note and a new invoice. Bills are the same once posted, except that `supplier_number` and the attached file can be corrected (audited), because the supplier's paper is the source.

### 3.7 Indexes and size

| Table | Indexes |
| --- | --- |
| `acc_lines` | `(tenant_id, account_id, date)`, `(tenant_id, contact_id, account_id)` where contact is set, `(tenant_id, invoice_id)`, `(tenant_id, tax_code_id, date)` where set, `(tenant_id, entry_id)`. PostgreSQL also gets BRIN on `date`. |
| `acc_entries` | `(tenant_id, journal_id, date)`, `(tenant_id, source_type, source_id)`, `UNIQUE (tenant_id, journal_id, number)`, `UNIQUE (tenant_id, idempotency_key)` where set |
| `acc_balances` | `UNIQUE (tenant_id, account_id, period_id, ou_id)` |
| `acc_invoices` | `UNIQUE (tenant_id, kind, number)` where number is set, `(tenant_id, kind, status, due_date)`, `(tenant_id, contact_id, kind)` |
| `acc_payments` | `(tenant_id, direction, date)`, `(tenant_id, contact_id)`, `UNIQUE (tenant_id, idempotency_key)` where set, `(tenant_id, cheque_status, cheque_due_on)` where method is cheque |
| `acc_bank_lines` | `(tenant_id, statement_id, status)` |

A busy shop's 300 invoices a day with their payments and stock postings write about 3,000 lines a day, roughly 1.1 million a year, about the size of the inventory ledger (section 31, 3.10). Reports read `acc_balances` (accounts × months × branches) and per-contact sums use the contact index; history screens are keyset-paginated on `(date, id)`. A load test in A1 posts 1 million lines into one workspace and checks the trial balance and an account's history stay under 200 ms on the CI PostgreSQL.

## 4. The chart of accounts

Each workspace gets the chart of its tax pack when accounting is first opened (not at sign-up, so workspaces that never use it carry nothing). The Jordan pack's template is below; it is the core default for every workspace until other packs exist, with names a person understands and the accountant's codes beside them. A workspace can add, rename, recode (until used), archive and regroup accounts. Plugins add accounts they own (`owner_plugin`), archived when the plugin is turned off and the account is empty.

**Jordan default template** (reviewed by the Jordanian accountant the owner named, Bader Ahmad Tamimi, Q11; A1 merges first and the review tunes the chart afterwards, owner, 22 Nov 2026: "yes merge tuning can come later"). Four-digit codes; the first digit is the type.

| Code | English (plain) | Arabic | Type · role |
| --- | --- | --- | --- |
| 1000 | What we own | الأصول | asset · heading |
| 1100 | Cash | الصندوق | asset · `cash` |
| 1110 | Bank | البنك | asset · `bank` |
| 1150 | Cheques to collect | شيكات برسم التحصيل | asset · `cheques_to_collect` |
| 1160 | Money at payment providers | أموال لدى مزودي الدفع | asset · `provider_clearing` |
| 1200 | Customers owe us | الذمم المدينة | asset · `receivable` |
| 1250 | Advances to suppliers | دفعات مقدمة للموردين | asset · `supplier_advances` |
| 1300 | Stock | المخزون | asset · `inventory` |
| 1400 | Sales tax we can reclaim | ضريبة المبيعات على المشتريات | asset · `sales_tax_input` |
| 1500 | Equipment and furniture | الأثاث والمعدات | asset · fixed asset |
| 1590 | Accumulated depreciation | مجمع الاستهلاك | asset · fixed asset (contra) |
| 2000 | What we owe | الالتزامات | liability · heading |
| 2100 | We owe suppliers | الذمم الدائنة | liability · `payable` |
| 2150 | Cheques we wrote | شيكات آجلة الدفع | liability · `cheques_written` |
| 2160 | Goods received, not yet billed | بضاعة مستلمة لم تصل فواتيرها | liability · `goods_received_not_billed` |
| 2200 | Sales tax we collected | ضريبة المبيعات المستحقة | liability · `sales_tax_output` |
| 2210 | Sales tax to pay | ضريبة المبيعات واجبة الدفع | liability · `sales_tax_payable` |
| 2250 | Advances from customers | دفعات مقدمة من العملاء | liability · `customer_advances` |
| 2300 | Salaries to pay | رواتب مستحقة | liability |
| 2310 | Social security to pay | اقتطاعات الضمان الاجتماعي المستحقة | liability |
| 2320 | Income tax withheld to pay | ضريبة دخل مقتطعة مستحقة | liability |
| 2400 | Loans | القروض | liability |
| 3000 | Owners' money | حقوق الملكية | equity · heading |
| 3100 | Capital | رأس المال | equity |
| 3200 | Owners' drawings | المسحوبات الشخصية | equity |
| 3300 | Profit kept from past years | الأرباح المدورة | equity · `retained_earnings` |
| 3400 | This year's profit | أرباح السنة الحالية | equity · `current_year_earnings` |
| 3900 | Opening balances | أرصدة افتتاحية | equity · `opening_balance` |
| 4000 | Income | الإيرادات | income · heading |
| 4100 | Sales | المبيعات | income |
| 4200 | Services | إيرادات الخدمات | income |
| 4900 | Other income | إيرادات أخرى | income |
| 4910 | Stock found | زيادة المخزون | income · `stock_found` |
| 5000 | Cost of sales | تكلفة المبيعات | expense · heading |
| 5100 | Cost of goods sold | تكلفة البضاعة المباعة | expense · `cost_of_sales` |
| 5150 | Stock lost and wasted | تالف وفاقد المخزون | expense · `stock_loss` |
| 5160 | Purchase price differences | فروقات أسعار الشراء | expense · `price_difference` |
| 6000 | Running costs | المصاريف | expense · heading |
| 6100 | Salaries | الرواتب والأجور | expense |
| 6110 | Social security (company share) | حصة الشركة من الضمان الاجتماعي | expense |
| 6200 | Rent | الإيجار | expense |
| 6300 | Electricity and water | الكهرباء والمياه | expense |
| 6400 | Phone and internet | الهاتف والإنترنت | expense |
| 6500 | Marketing | التسويق والإعلان | expense |
| 6600 | Fuel and transport | المحروقات والمواصلات | expense |
| 6700 | Bank and payment fees | عمولات بنكية ورسوم الدفع | expense · `payment_fees` |
| 6800 | Software and subscriptions | البرمجيات والاشتراكات | expense |
| 6900 | Other costs | مصاريف أخرى | expense |
| 6950 | Rounding | فروقات التقريب | expense · `rounding` |
| 6960 | Depreciation | الاستهلاك | expense |

Workspace templates (section 25, the seven starters) may pick a variant of the chart: the salon adds "Products sold" and "Services", the clinic "Consultations". A workspace that turns accounting on with existing stock or open invoices is walked through **opening balances**: one opening entry in the opening journal, balancing to `opening_balance`, with per-customer and per-supplier opening amounts so the aged reports start right.

## 5. Journals, invoices, bills and payments

### 5.1 Journals and manual entries

Every entry belongs to a journal; the journal gives it a number series and a place in the books. Manual entries are drafts (`acc_manual_entries`) that show **Balanced** at the foot (`TplLedger`), with the source and who proposed it. Posting a manual entry needs `accounting:post`; above a workspace threshold, or when an agent proposed it, it routes for approval (`accounting.manual_entry`). Manual entries cannot use accounts with `allows_manual = false` (receivables, payables, stock and tax by default), so the sub-ledgers stay true; an admin can turn that off per account.

### 5.2 Sales invoices and credit notes

1. **Draft** an invoice for a customer (a contact with `is_customer`): lines from items (price from the item, a price-list plugin, or the sales order), from a sales order or its deliveries (IV.13), or free text with an income account. Tax per line from the item's `tax_category`, mapped to a tax code by the pack.
2. **Send for approval** when the workspace's invoice route says so (a discount above a limit, a credit limit plugin); otherwise **Issue**. Issuing takes the number, freezes the invoice, posts the entry, and asks e-invoicing to submit it when a connector is active (section 7).
3. The printed copy comes from the Invoice template (the free starter, with no fiscal stamp) or, with e-invoicing, the Tax invoice template with its QR and reference (6.8). It is sent by email or WhatsApp with **Ask for payment** when Whity Pay is installed.
4. **Credit note**: from an issued invoice, all or some lines and quantities; posts the mirror of those lines and allocates itself to the invoice. A return of goods links the credit note to the inventory return (customer → place).
5. Statuses come from allocations: `issued`, `partly_paid`, `paid`; overdue is a view (due date past and not paid), with the Billing assistant's proposed reminder (MO designs) using the Payment reminder starter.

**Entry for an invoice:** Dr Customers owe us (total), Cr each line's income account (net), Cr Sales tax we collected (tax per code, with `tax_base_minor`). With prices including tax, the net is derived per line and rounded once.

### 5.3 Supplier bills and supplier credits

1. A bill is typed, created from a purchase order and its receipts (inventory P3's `TplReceiving`: ordered, received, **billed**), or proposed by the Bookkeeping or Expense assistant from a photo or a PDF (tier 2, a person posts it).
2. **Three-way match:** each bill line from a receipt line shows ordered, received and billed quantities and the price against the order. A difference above the tolerance (setting, default 0) flags the line and, when a route is set, sends the bill for approval (`accounting.bill`).
3. Posting the bill posts the entry; paying it is a payment. A supplier credit is the mirror.

**Entry for a bill** of stocked items already received: Dr Goods received, not yet billed (the receipt's value), Dr Purchase price differences (bill minus receipt value, either sign), Dr Sales tax we can reclaim, Cr We owe suppliers. For services and non-stock lines: Dr the line's expense account instead.

**Expense claims and petty cash** (MO.1) are bills whose supplier is the shop and whose "payable" is the employee who paid, posted to that person's own payable (a contact for the employee). Whether they are core is Q5.

### 5.4 Payments and allocations

- A **payment received** posts Dr Bank, Cash, Cheques to collect or Money at payment providers / Cr Customers owe us, and allocates to one or more invoices; what is not allocated sits as the customer's credit on Customers owe us until allocated (or on Advances from customers when it is paid before any invoice, a setting).
- A **payment made** is the mirror against We owe suppliers.
- **Cheques** (Q7): a received cheque with a future date posts to Cheques to collect; on its due date the Bookkeeping assistant proposes depositing it, which posts Cheques to collect → Bank; a bounced cheque reverses the payment and its allocations and reopens the invoices, with a fee line if the bank charged one. Written cheques mirror this through Cheques we wrote. Post-dated cheques are how much of Jordanian trade pays; the Money overview shows cheques due this week.
- **Bank and cash** accounts each have a journal. Transfers between them are payments with no contact. A **statement file** (CSV, Excel, OFX, MT940; Q4) imports into `acc_bank_lines`; the Bookkeeping assistant proposes a match or an account for each line ("matched lines can be posted together, guesses need a choice", MO designs); a person accepts. Matching never edits the ledger: an unmatched bank fee becomes a new entry.

### 5.5 How Whity Pay payments post

Whity Pay is a paid plugin; it takes the customer's card through the workspace's own provider. Core defines the contract, Pay calls it.

1. **Ask for payment** on an issued invoice (or a sales order, IV.13) asks Pay for a payment link; the link's reference is stored on the invoice (`payment_link_ref`).
2. When Pay learns the payment succeeded (from the provider's API, never from the webhook payload alone, as Pay already works), it calls `Payments::recordReceived()` through its plugin context with its payment id as the idempotency key, the amount, the provider's fee and the invoice. Core posts **Dr Money at payment providers / Cr Customers owe us**, and, when a fee is reported, **Dr Bank and payment fees / Cr Money at payment providers**, then allocates the payment to the invoice, which turns `paid`.
3. When the provider pays out to the bank, Pay reports the payout (`Payments::recordTransfer()`, provider clearing → bank), or the statement import matches it. Either way, Money at payment providers goes back to zero when everything has arrived.
4. Refunds through Pay post a payment out against the customer with a credit note proposed for the refunded lines. Disputes post to the clearing account and are proposed for a person to settle.
5. Subscriptions a workspace sells (SU.1, Whity Pay's subscriptions) issue an invoice per renewal through `Invoices::issue()` and pay it as above, so every renewal shows in sales and in the tax return.
6. **Merchant of record providers.** Polar (and Paddle) sell as the merchant of record: legally the customer buys from them and they handle the customer's tax. For those, the accountant may want the sale recorded as a sale to the provider net of their tax. The default above (sale to the customer, the provider as a payment method) is right for card processors such as Stripe; for a merchant of record, Pay passes `merchant_of_record = true` and the workspace's setting decides the treatment (Q9).

Without Whity Pay, people record payments by hand, and statements import and match the same way.

## 6. Inventory posting

Inventory already reserves the extension point: `WorkspaceAccountingExport` (IV.22, section 31, 6.3), "turn operations and moves into journal lines". Core accounting is **the default implementation** of that point inside the same workspace. A plugin that exports to an outside system (Odoo, QuickBooks, a local Jordanian package) implements the same point; one is active per workspace, as for costing (IV.18). When an outside export is active, core's books do not post stock, and the Money pages say so.

**Perpetual, with the simple cost.** When "Record stock in the books" is on (Q3), each `inventory.operation.done` posts one entry in the stock journal, valued by the active costing method (core: moving average per item, IV.18). The move's `unit_cost_minor` is the cost; nothing else values stock. Valuation stays simple: no landed costs, no revaluation, no standard cost in core.

| Operation (IV) | Entry |
| --- | --- |
| Receipt against a purchase order (`supplier → place`) | Dr Stock / Cr Goods received, not yet billed, at the order's unit cost |
| Bill for that receipt (5.3) | Dr Goods received, not yet billed / Cr We owe suppliers; the difference to Purchase price differences |
| Delivery (`place → customer`) | Dr Cost of goods sold / Cr Stock, at average cost |
| Customer return (`customer → place`) | The mirror, at the cost of the original delivery |
| Adjustment out, waste (`place → loss`) | Dr Stock lost and wasted / Cr Stock |
| Adjustment in, found (`found → place`) | Dr Stock / Cr Stock found |
| Count correction | As adjustment out or in, per line |
| Opening stock (`opening → place`) | Dr Stock / Cr Opening balances |
| Transfer (`place → transit → place`) | No entry, unless the two places belong to different branches, then a branch-to-branch pair on Stock |
| Make a batch (`production`) | Dr Stock (the product, at the sum of its inputs' cost) / Cr Stock (the inputs); waste to Stock lost and wasted |
| Service consumables (`place → consumed`) | Dr Cost of goods sold (or the mapped expense) / Cr Stock |
| A plugin's own operation kind | The kind declares its entry pattern when it declares its virtual places (`StockOperationTypes`, IV.22); without one, it posts like an adjustment and flags the entry for review |

Accounts come from `acc_account_mappings` (item category, item, place) with the roles as fallbacks. Moves with no known cost post at zero and are listed on Money's "Waiting for you" so a person fixes the cost; fixing posts a correcting entry. A move dated in a closed period is refused by the shared lock date (3.3). When stock posting is turned on for a workspace that already has stock, the opening walk-through posts the current stock value once, from the costing method, against Opening balances.

**What inventory needs from its plan:** `WorkspaceAccountingExport` (inventory P7) and the moving average cost (IV.18) must land before A4. If inventory P7 is later than accounting's A4, A4 ships the extension point itself, in the shape section 31 describes, and inventory P7 drops it.

## 7. The tax engine and the Jordan sales-tax return

### 7.1 The engine

- **Tax codes** carry a rate, a direction, a treatment and the return box. Lines carry one tax code; a line's tax = round(net × rate_bp / 10000) to the currency's decimals, half away from zero.
- **Tax categories on items** (inventory's `tax_category`) map to tax codes through the pack: `standard`, `reduced_X`, `zero`, `exempt`. A contact can carry an exemption (a certificate number and dates), which swaps the code for that customer.
- **Tax packs** are how countries arrive: a pack declares its tax codes, return layout and boxes, chart template, invoice legal fields and period length. Core ships the **Jordan pack**; others come as plugin packs through the `WorkspaceTaxPack` extension point (owner: "other MENA countries come later as plugin packs"). One pack per workspace.
- **Changing rates:** a new code with `valid_from`; documents use the code valid on their supply date.

### 7.2 The Jordan pack

To be confirmed against the Income and Sales Tax Department's rules by the Jordanian accountant the owner named, Bader Ahmad Tamimi, before A6 ships (Q11):
- **General sales tax 16%** on most goods and services: `JO-GST-16` (output) and `JO-GST-IN-16` (input).
- **Reduced rates** that Jordan applies to listed goods (the pack includes the rates in force; a workspace adds a code with `valid_from` when one changes).
- **Zero-rated** (`JO-GST-0`, exports and listed goods: tax deductible on inputs) and **exempt** (`JO-EXEMPT`: no tax and input tax not reclaimable on related purchases).
- **Special sales tax** on listed goods (tobacco, some drinks, cars, phones) is out of the first pack; a workspace that sells them is told the pack does not cover it.
- **Two-month periods** by default, aligned January–February, March–April and so on, with a monthly option; the return is due within the month after the period ends (the due date shown on the Overview).
- The workspace's tax number and its name in Arabic are required on invoices once the pack is on; the invoice's legal fields come from tenant settings (6.7).

### 7.3 The return

1. At the end of a period, the Money Overview shows **Sales tax due** with its period and date (`TplMoney`), and the Sales tax tab offers **Prepare the return**.
2. Preparing reads the period's lines by tax code, fills the return's boxes (sales at 16%, at reduced rates, zero-rated, exempt; tax on sales; purchases and tax on purchases; tax to pay or to carry forward), shows each box's lines on a click, and freezes the figures. It also shows how many invoices were reported to JoFotara and how many are not (when e-invoicing is on).
3. **Download the return** produces the file and a PDF summary in the layout the department's portal asks for; the company files it there.
4. **Mark as filed** with the department's reference; this posts the settlement entry (Sales tax we collected and Sales tax we can reclaim → Sales tax to pay) and locks the period's tax lines (the period becomes `locked`).
5. **Pay the tax** is a payment out against Sales tax to pay.
6. Lines posted later into a filed period cannot happen (locked); a correction posts in an open period and shows as an adjustment on the next return.

Filing directly with the department is not in scope; Whity prepares, the company files (MO designs).

### 7.4 E-invoicing and JoFotara

**What goes to JoFotara.** JoFotara (the National Invoicing System of the Income and Sales Tax Department) receives every invoice and credit note a registered seller issues: the invoice as structured data (UBL 2.1 XML in the department's profile), with the seller's tax number, the buyer (with their tax or national number above the threshold the department sets), each line's quantity, price, discount and tax category, the totals, the invoice type (cash or on credit; general sales tax or special), the unique id and the invoice counter. JoFotara answers with acceptance, its reference and the QR code that must print on the invoice. Credit notes reference the original invoice. Supplier bills are not sent. The exact field list and the API come from the department's specification and are confirmed in the plugin's own spec.

**Core or paid: a paid integration plugin** (decided). JoFotara is an outside integration, and outside integration plugins are what is paid: "outside integration plugins are paid like payment gateways, jofotara, etc." (owner, 21 November 2026). Each country's connector (JoFotara, ZATCA, Egypt's ETA) is kept up as its authority changes its specification.

**Core makes it possible**, from A2: the `WorkspaceEInvoicing` extension point (a connector declares its country, is asked to `submit()` when an invoice or credit note is issued, may hold the issue until accepted, and writes the result back to the invoice's e-invoice columns), the gap-free numbering, the e-invoice columns, the tax-category codes on tax codes, the Tax invoice template slots for the QR and reference, a queue with retries for when the authority is down, and the counts on the tax return. A free workspace without the plugin issues invoices with `einvoice_status = none`, and the invoice and the Sales tax tab say plainly that Jordanian tax-registered sellers must also report each invoice to JoFotara (on its own portal, or by adding the JoFotara plugin).

There is one JoFotara integration; everything in Whity that issues a tax invoice uses it. The owner first chose "Paid plugin" for Q1 (the accounting plugin), then moved the connector to its own integration plugin with the new approach, knowing that reporting is mandatory for registered sellers in Jordan; the notice above is what keeps a workspace without it informed.

## 8. Reports

All reports read `acc_balances` and the indexed lines, filter by period or date range, branch (organisational unit) and, where it makes sense, compare with the previous period or year. Every figure opens the lines behind it. They export to PDF (through the Report document template and the workspace's theme), CSV and Excel, in English or Arabic.

| Report | What | Notes |
| --- | --- | --- |
| Trial balance | Every account's debit and credit for a range, with opening and closing | Shows whether the books balance; the health check uses it |
| General ledger and account statement | One account's lines with a running balance | Keyset-paginated |
| Profit and loss | Income, cost of sales, gross profit, running costs, net profit | Monthly columns option; by branch |
| Balance sheet | What we own, what we owe, owners' money, at a date | This year's profit computed until the year closes |
| Aged receivables | Per customer: current, 1–30, 31–60, 61–90, over 90 days | By due date (or invoice date, a choice); cheques to collect shown apart |
| Aged payables | The same for suppliers | |
| Customer and supplier statements | A contact's invoices, payments and balance | The Statement of account starter (WHIT-843) gets its merge fields |
| Sales tax report | The return's boxes with their lines, for any range | Also the tax return itself (7.3) |
| Cash flow (simple) | Money in and out of bank and cash accounts by month | The Overview's chart |

**Export for your accountant** (MO designs): a period's entries (CSV in a documented layout and an Excel workbook), the trial balance, the sales tax summary, and a zip of the attached bills and receipts. Other formats (an accounting package's import) come from `WorkspaceAccountingExport` plugins.

## 9. How it fits what exists

### 9.1 Permissions

Added to `CorePermissions`, granted by a forward idempotent migration, and described in plain words on the roles page.

| Permission | Allows | Default roles |
| --- | --- | --- |
| `accounting:read` | See accounts, entries, reports | admin, manager, accountant |
| `invoices:read` / `invoices:write` | See / draft, issue and credit sales invoices | admin, manager, accountant / admin, accountant |
| `bills:read` / `bills:write` | See / draft and post supplier bills | admin, manager, accountant / admin, accountant |
| `payments:read` / `payments:write` | See / record payments and allocations, import statements and match | admin, manager, accountant / admin, accountant |
| `accounting:post` | Post manual entries, reverse entries | admin, accountant |
| `accounting:close` | Close and reopen periods, prepare and file tax returns | admin, accountant |
| `accounting:settings` | The chart, tax codes, mappings, journals, numbering, opening balances, stock posting | admin |

A seeded **Accountant** role (Q8) holds all of these except settings, so a company can invite its outside accountant as a member. **Approving is not a permission**: invoices above a limit, bills that don't match, payments above a limit, manual entries and period reopening are route subjects (`accounting.invoice`, `accounting.bill`, `accounting.payment`, `accounting.manual_entry`, `accounting.period_reopen`), with recipients from routing rules, as for inventory (IV.19). Branch scoping follows organisational units: a person who holds a permission only for their unit sees and posts with that branch (D6 of section 31).

### 9.2 Agents and MCP

- Read tools: `accounting_accounts_list`, `accounting_account_statement`, `accounting_report` (trial balance, profit and loss, balance sheet, aged), `accounting_invoices_list`, `accounting_invoice_get`, `accounting_payments_list`, `accounting_tax_return_get`. Each has its Arabic name in `mcp-tool-names.json` (ToolCatalogueTest).
- Write tools, always proposals under Ask first: `accounting_invoice_propose`, `accounting_bill_propose` (from a photo or PDF), `accounting_entry_propose`, `accounting_payment_propose`, `accounting_bank_match_propose`. MCP call keys make them idempotent (WHIT-931).
- The **Bookkeeping assistant** preset (AI.2, MO designs) sorts bank lines and proposes accounts and matches; the **Billing assistant** proposes reminders; the **Expense assistant** reads receipts into bills or claims. Tier: entries and bills proposed by an agent are Check first (tier 2); reminders are tier 1. Nothing posts without a person.

### 9.3 Documents and printing

Through the existing issuing service and document themes, numbered by `DocumentNumbering`, never changed once issued: Invoice and Credit note (the free starters, WHIT-843), Tax invoice and fiscal credit note (with e-invoicing, `TplDocInvoice`, `TplDocInvoiceAr`, `TplDocInvoiceLong`, `TplDocCreditNote`), Receipt (A5, `TplDocReceipt`), Statement of account, Payment reminder, Payment voucher (new starter), Journal entry print (new starter), and report PDFs. Merge fields come from a `DocumentFieldProvider` for invoices, payments and statements. Amount in words follows CU.3.

### 9.4 Contacts

Customers and suppliers are core contacts (`is_customer`, `is_supplier`). The contact page gains **Invoices**, **Bills**, **Payments** and **Statement** tabs and the balance in the header; contacts gain `tax_number`, `payment_terms_days`, `credit_limit_minor` (shown, enforced only by a route rule or plugin), and the exemption certificate. No second address book.

### 9.5 Arabic and right to left

- Account names, journal names, tax code names and line descriptions are bilingual through `LocalizedText` (WHIT-1038). The Jordan chart ships with Arabic names (section 4); Arabic is the leading language of the printed tax invoice when the workspace's document language is Arabic.
- Codes, numbers and amounts stay Western digits, left to right, isolated in RTL (`<bdi>`, `dir="ltr"`), in Geist Mono (CU.2). Debit and credit columns keep their order in the reading direction (debit first, at the start side) in both languages.
- Plain words first in both languages ("Customers owe us" / "مستحق لنا على العملاء" on screen, "الذمم المدينة" as the accountant's term).
- Every screen and printed document gets an Arabic card check before it is called done.

### 9.6 Search, import and custom fields

Search gets `InvoicesSearchSource` (number, customer, amount) and `AccountsSearchSource` (code, name in both languages). Import (N3) gains targets: chart of accounts, opening balances, open invoices and bills (for the aged reports), and bank statements. Invoices and bills get record types (`accounting.invoice`, `accounting.bill`) for custom fields.

### 9.7 Plans

Everything in core accounting works on every plan. What is paid is seats and volume ("Seats and volume", owner, 21 November 2026): accounting's volume meter is invoices issued a month. How it is counted and charged is set by the business-model decision, which owns the open-core rows; this section only names the meter. The paid integration plugins (JoFotara, live bank feeds) are priced with the other integrations.

## 10. Plugin SDK

Everything core does with the books, a plugin can do through the SDK, from the first phase. The rules of section 31, 6, apply unchanged: **the workspace comes from the call, never from an argument**; services are host services (`\Whity\app(Ledger::class)`, null on an older host) and on the plugin context (`$ctx->accounting()->ledger()`, `->accounts()`, `->invoices()`, `->payments()`, `->taxes()`, `->periods()`, `->reports()`); writing goes only through the context, which stamps the plugin as the source (`pos.sale`, `payroll.run`) and namespaces idempotency keys; the container's services read and refuse writes with `use_plugin_context`. Writes act as a person and pass the same validation and permissions as the API. Refusals throw `AccountingRejected` (extends `\RuntimeException`, implements `Whity\Sdk\SdkException`) with a stable `reason` (`no_workspace`, `forbidden`, `not_found`, `invalid`, `unbalanced`, `period_closed`, `conflict`, `vetoed`), a `status` and the `field` at fault.

### 10.1 Services (`Whity\Sdk\Accounting\...`)

| Service | Methods (phase) |
| --- | --- |
| `Accounts` | `list(filter)`, `get(accountId)`, `byCode(code)`, `byRole(role)`, `create(data, actingProfileId)` (plugin-owned), `archive(...)` (A1) |
| `Ledger` | `post(EntryRequest)` (journal, date, lines, source, idempotency key), `reverse(entryId, actingProfileId, note)`, `entry(entryId)`, `entries(filter, limit)` (keyset), `balance(accountId, asOf, ouId)` (A1) |
| `Periods` | `current()`, `isOpen(date)`, `lockDate()` (A1) |
| `Invoices` | `list`, `get`, `createDraft(kind, data, actingProfileId)`, `addLines`, `issue(invoiceId, actingProfileId)` (sends to its route or issues), `credit(invoiceId, lines, actingProfileId)`, `cancelDraft` (A2 sales, A3 purchases) |
| `Payments` | `recordReceived(PaymentRequest)`, `recordMade(PaymentRequest)`, `allocate(paymentId, invoiceId, amount)`, `recordTransfer(fromAccount, toAccount, amount, ...)`, `get`, `list` (A2) |
| `Taxes` | `codes(filter)`, `codeFor(taxCategory, direction, date, contactId)`, `compute(lines)` (A2) |
| `Reports` | `trialBalance(range, ouId)`, `profitAndLoss(...)`, `balanceSheet(asOf, ...)`, `aged(direction, asOf)`, `statement(contactId, range)` (A1 trial balance, A5 the rest) |

Value objects: `Amount` (minor units, currency, `parse()`, `format()`), `Account`, `Entry`, `EntryLine`, `EntryRequest`, `Invoice`, `InvoiceLine`, `Payment`, `PaymentRequest`, `TaxCode`, `Period`, `ReportTable`, `AccountingRejected`.

### 10.2 Events (`Whity\Sdk\Hooks\Events`)

Filter hooks (can veto with `HookVetoException`; the caller gets 409 with the reason):
- `accounting.entry.posting`: the entry about to post (locked periods, a plugin's rule).
- `accounting.invoice.issuing`: before an invoice or credit note is issued (a credit limit plugin).

Notification hooks, each with an `.async` twin after commit:
- `accounting.entry.posted`, `accounting.entry.reversed`
- `accounting.invoice.issued`, `.paid`, `.partly_paid`, `.overdue` (daily job, once), `.credited`, `.einvoice_accepted`, `.einvoice_rejected`
- `accounting.bill.posted`, `accounting.bill.paid`
- `accounting.payment.recorded`, `accounting.payment.allocated`, `accounting.cheque.due`, `accounting.cheque.bounced`
- `accounting.period.closed`, `accounting.period.reopened`, `accounting.year.closed`
- `accounting.tax_return.prepared`, `accounting.tax_return.filed`

All are added to the webhook event catalogue (ADR-0008).

### 10.3 Extension points (`Whity\Sdk\Contribute\`, workspace in the names, never tenant)

| Interface | Lets a plugin |
| --- | --- |
| `WorkspaceEInvoicing` | Report invoices and credit notes to a tax authority: `country()`, `submit(invoice)`, `status(invoice)`, whether issuing waits for acceptance; writes the reference, QR and status back. JoFotara is the first (a paid integration plugin). |
| `WorkspaceTaxPack` | Bring a country: tax codes, return layout and boxes, chart template, invoice legal fields, period length, file format for the return. |
| `WorkspaceChartTemplate` | Offer charts for an industry or a country. |
| `WorkspaceBankFeed` | Write statement lines into `acc_bank_lines` from a live connection (the paid bank feeds integration). |
| `WorkspaceCurrencyRates` | Provide exchange rates from an outside source. Core's multi-currency (A7) keeps rates typed by people and a default source; a plugin adds others. |
| `WorkspaceAccountingExport` | Already planned in IV.22: export entries and stock postings to another system; core's books are the default implementation (section 6). |
| `WorkspaceReports` | Add reports to the Reports tab, reading through `Reports` and `Ledger`. |
| `WorkspaceDocumentPosting` | Declare how a plugin's own documents post (a payroll run, a POS day, a depreciation run), so their entries show with the plugin's words and links. |
| Route subjects, document blocks and field providers, MCP tools, dashboard and home cards | Already in the SDK. |

### 10.4 SDK numbers

Each phase asks the orchestrator for its SDK minor before it opens its pull request (next free today is 1.131). `docs/sdk-coverage/coverage.json` lists every service, event and interface with the version it arrived in. Exact interface names are settled with the SDK lane when each phase opens.

## 11. Build plan

Each phase is one pull request (two where marked), merged on green with auto-merge armed, with its migration numbers and SDK minor from the orchestrator at PR time. Every phase ships its SDK surface, its MCP read tools, tests (unit, real-engine on both dialects, cross-tenant rows, RBAC on every route, the append-only trigger, balance and idempotency), and English and Arabic strings. Release numbers are set by the orchestrator.

| Phase | What | Migrations (count) | Screens |
| --- | --- | --- | --- |
| **A0** | This spec and the register rows (no code) | — | — |
| **A1 Ledger and chart** (merges after the accountant's review of the chart, Q11) | Accounts, journals, fiscal years and periods, entries and lines with the append-only trigger, balances projection, the hash chain, posting service with `accounting.entry.posting`/`.posted`, manual entries (draft, approve, post, reverse), opening balances, the Jordan chart template, permissions and the Accountant role, trial balance and account statement, rebuild and verify commands, 1M-line load test. SDK `Accounts`, `Ledger`, `Periods`, `Reports::trialBalance`. | 3 (chart and journals; ledger, balances and trigger; permissions and seeds) | Money › Accounts (`TplLedger`), a journal entry, trial balance, accounting settings |
| **A2 Sales, tax and payments in** | Tax codes and the Jordan pack, the tax engine, sales invoices and credit notes, payments received, allocations, cheques received, `WorkspaceEInvoicing` point and e-invoice columns, invoice route subject, Invoice and Credit note starters wired, Receipt, Whity Pay contract (`recordReceived`, `recordTransfer`), aged receivables, customer statement, contact tabs. SDK `Invoices` (sale), `Payments`, `Taxes`. (Two PRs: tax and invoices; payments and Pay.) | 2 | Money › Invoices, an invoice, new invoice, Payments, contact tabs, phone invoice |
| **A3 Purchases and payments out** | Supplier bills and credits, bill route subject, payments made and cheques written, aged payables, supplier statement, core's `WorkspaceCommissionExpenses` (salon commission, SL.6). SDK `Invoices` (purchase), `Payments::recordMade`. Expense claims and petty cash follow as A3b (Q5). Until A4 a bill line for an item posts to its cost or stock account with no matching. (A3a, A3b; the three-way match moved to A4, orchestrator for the owner, 22 Nov 2026.) | 1 | Money › Bills, a bill, Expenses (Q5) |
| **A4 Stock in the books** | Core's `WorkspaceAccountingExport` implementation, account mappings, the posting table of section 6, the shared lock date, opening stock value, "waiting for you" for moves with no cost, and the three-way match of bills with inventory receipts (`TplReceiving` billed column, AC.13), which needs receipts in the books. Needs inventory P7's extension point and IV.18's average cost (or ships them, section 6). | 1 | Settings › Stock in the books, `TplReceiving` billed column |
| **A5 Reports and closing** | Profit and loss, balance sheet, aged reports polish, simple cash flow, comparisons and branches, month close checklist, year close, export for your accountant, Money Overview (`TplMoney`), `WorkspaceReports`. | 1 | Money › Overview, Reports, close a month, close a year, `TmMoneyMobile` |
| **A6 Sales tax return and bank** (merges after the accountant's review of the pack and return, Q11) | The Jordan return (prepare, download, mark filed, pay), `WorkspaceTaxPack`, statement import and matching, the Bookkeeping assistant's proposals, `WorkspaceBankFeed` point. | 1 | Money › Sales tax, bank matching, assistant proposals |
| Buffer | Arabic and phone pass on every Money screen, the card comparisons, the accountant's review fixes, performance checks. | — | — |
| **A7 Multi-currency** | Foreign-currency invoices, bills, payments and bank accounts; rates (typed, and a default source); realised exchange differences on payment, unrealised revaluation at month end; `WorkspaceCurrencyRates`. | 1 | Currency on documents, rates, revaluation |
| **A8 Fixed assets and budgets** | Assets with straight-line and declining depreciation posted monthly, disposal; budgets per account, team and month with actual against budget and the over-budget approval change (MO designs). | 2 | Assets, Budgets |
| **A9 Consolidation** | Combined reports across workspaces the same owners hold, with eliminations between them. | 1 | Group reports |
| **Integrations** (whity-plugins, paid) | JoFotara first (after A2), then live bank feeds (after A6); each its own spec. | their own | their own |

About 13 core migration numbers in all; each is asked for at PR time.

Screens without a card (Money › Bills, a bill, new invoice, an invoice record, Payments, bank matching, close a month, year close, accounting settings, opening balances walk-through, Reports tab layouts) go to the brand team as one ask (`brand-asks/brand-ask-accounting.md`) when this spec is approved. Until a card arrives a screen is built from the section 21 patterns and the existing cards.

## 12. Questions for the owner (answered 21 November 2026)

Answers: Q1 "Paid plugin", then, with the owner's new approach the same day, a paid JoFotara integration plugin rather than an accounting plugin; Q2 dropped, as there is no accounting plugin (the JoFotara plugin is priced with the other integrations); Q6 became a volume meter (invoices a month), counted and charged by the business-model decision; Q14's "only with the paid plugin" became core's multi-currency phase A7; Q11 "I'll name someone" (A1 and A6 wait at a draft PR for the review), and on 22 November 2026 "the accountant will be "Bader Ahmad Tamimi"" and, for A1, "yes merge tuning can come later" (the chart review becomes tuning after A1 merges; A6 still waits); all others "Accept all", as recommended.

| # | Question | Options | Recommendation |
| --- | --- | --- | --- |
| Q1 | JoFotara: core or the paid plugin? And does Front Office keep its own? | (a) paid accounting plugin, Front Office uses it; (b) core; (c) paid, Front Office keeps its own | **(a).** You listed e-invoicing as paid, 6.8 already puts the tax invoice in a paid product, and one connector is easier to keep current. Core ships the extension point, gap-free numbering and a clear notice for free Jordanian workspaces (7.4). |
| Q2 | The paid plugin's name and price | a name, and `[price]` like other add-ons ($29 like Front Office?) | Ask the brand team for the name; price is the owner's call. |
| Q3 | Stock in the books | (a) on by default when a workspace uses inventory and accounting; (b) off by default; (c) always on | **(a)**, with a switch in settings; tiny shops can turn it off and still get sales and tax right. |
| Q4 | Bank in core | (a) statement file import and matching in core, live feeds paid; (b) all bank work paid | **(a).** Matching is what makes the books trustworthy; live feeds are the paid convenience. |
| Q5 | Expense claims, petty cash and company cards (MO.1) | (a) claims and petty cash in core (A3), cards later; (b) all in the paid plugin | **(a).** They are bills to an employee; the Expense assistant's receipt reading is already designed. |
| Q6 | Plans | (a) all of core accounting on every plan, Free included, with a warn-only limit (for example 50 invoices a month); (b) Free gets invoices and payments, the ledger and tax return on paid plans; (c) no limit | **(a)**, mirroring IV.24: warn, never block. |
| Q7 | Cheques and post-dated cheques in core? | (a) yes; (b) plugin | **(a).** Most Jordanian trade pays by post-dated cheque; it is a payment method with a due date and two accounts. |
| Q8 | A seeded Accountant role for an outside accountant | (a) yes; (b) no, use custom roles | **(a).** |
| Q9 | Merchant-of-record providers (Polar, Paddle) through Whity Pay | (a) record the sale to the customer, the provider as a payment method, a setting for the accountant to switch; (b) record sales to the provider | **(a)**, with the accountant confirming the Jordanian treatment. |
| Q10 | Tax period | (a) two months by default, monthly as a setting; (b) two months only | **(a).** |
| Q11 | Who reviews the Jordan chart, the tax codes and the return layout? | an accountant you name; a paid review | A Jordanian accountant reviews the chart and the pack and return before A6 merges (register rule 6.7 already asks for this). The owner named Bader Ahmad Tamimi (22 Nov 2026); A1 merges first and the chart review becomes tuning ("yes merge tuning can come later", 22 Nov 2026). |
| Q12 | Tamper-evident hash chain on entries | (a) yes; (b) no | **(a).** One hash per posting, and an auditor can trust an export. |
| Q13 | Sidebar | (a) Operations › Money with tabs Overview, Invoices, Bills, Payments, Accounts, Sales tax, Reports; Purchases and Sales stay inside Inventory; (b) split Purchases and Sales out now | **(a)** until A3 lands, then revisit IV.23. |
| Q14 | Base currency | (a) fixed at the first posting, foreign currencies only with the paid plugin; (b) core allows other currencies without exchange differences | **(a).** |

## 13. Register rows

AC.1–AC.30 in the register, section "Accounting in core (decided 21 November 2026)", and changes to MO.1, R.2, IV.1, IV.12, IV.23 and 6.8 quoting the owner's 21 November decisions, including the change of direction the same day ("All into core").
