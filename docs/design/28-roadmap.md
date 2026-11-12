# Roadmap

Where Whity stands against its aim — a full ERP, inventory and project management solution for small companies, with custom logic and low-code/no-code customization — and what comes next. Set on 8 November 2026 (R.1, R.2). The design is ahead of the build: about two-thirds of a small company's ERP and project tool is designed; the platform layer is built, the business modules aren't yet.

**Near term** is the low-hanging fruit: work that reuses what's already designed or built, small enough to finish soon, and that makes Whity usable by a first company sooner. **Long term** is everything else, in the order we expect to take it.

## Near term

### Design (designed 8 November 2026, version 3.2)

| # | What | Why it's low-hanging |
| --- | --- | --- |
| N1 | **Contacts:** customers and suppliers as one record, with tags and a record page | The record-page pattern exists (section 21); invoices, purchase orders, appointments and subscriptions already need it |
| N2 | **Custom fields on existing records** ("Studio lite"): add a field to items, contacts, tasks; it shows in lists, filters, document merge fields and flow conditions | The form builder's question kinds and settings are reused as field kinds |
| N3 | **Import from a spreadsheet:** CSV or Excel with column matching, for contacts, items, opening stock and opening balances | The editor's label sheets already match CSV columns (section 18) |
| N4 | **Receiving goods and supplier bills:** receive against a purchase order (with the phone's scanner), match the supplier's bill to the order and the receipt | Purchase orders, the reorder agent, the scanner and stock are designed |
| N5 | **Waste and stock adjustments:** a reason, approval above a value, end-of-day waste | Reuses the stock count screens |
| N6 | **Bank reconciliation:** match bank lines to invoices, bills and claims | The Bookkeeping assistant already sorts bank lines (MO.1) |
| N7 | **Basic reports:** profit and loss, balance sheet, who owes us and whom we owe (by age), stock value | Read-only views of accounts that exist, with the chart and table components |
| N8 | **The 80 mm thermal receipt** | A Whity Render template beside the 13 documents |
| N9 | **Agent notifications by email and WhatsApp** | The last item left on the 2.9 app map; push is done (`TmPush`) |

Cards: N1 `TplContacts`, `TmOpsMobile`; N2 `TplCustomFields`, `TmFinanceMobile`; N3 `TplImport`, `TmFinanceMobile`; N4 `TplReceiving`, `TmOpsMobile`; N5 `TplAdjustments`, `TmOpsMobile`; N6 `TplBankRec`, `TmFinanceMobile`; N7 `TplReports`, `TmFinanceMobile`; N8 `TplReceipt80`; N9 `TplAgentNotify`. The sidebar's Operations group gains Contacts and Reports.

### Build (after A6 in the build plan)

| # | Milestone | Why it's low-hanging |
| --- | --- | --- |
| A7 | **Quick wins on built pages:** the Approvals list with bulk approval for tier 1 (AQ.1); approval gating with request dates, identity checks and a second admin for two-step resets (AG.1); Delegations in names with I'm away (OR.1); the 80 mm receipt | The pages, data and permissions exist; these are changes to them |
| A8 | **Contacts and import** (N1, N3) | One new record type; the column matcher exists |
| A9 | **Custom fields** (N2) | Extends existing records rather than creating a new engine |
| A10 | **Tasks and boards on Taskaat** (T.1–T.4) | Taskaat is the engine and speaks MCP; Whity builds the pages and the link, with Taskaat's team |
| A11 | **Receiving, waste and bank** (N4–N6) | Purchase orders, stock and the Bookkeeping assistant exist |
| A12 | **Reports and agent notifications** (N7, N9) | Read-only views; notifications reuse push's rules |
| W1–W5 | **The websites** (section 29): whity.dev, health, id, brand, docs | Static pages are ready to port |

## Long term

In expected order. Studio comes first because the business modules should be built on it, so customers can extend every module without code.

| # | What | Status |
| --- | --- | --- |
| L1 | **Studio:** custom record types, relations, list, board and calendar views, formulas, validation, a page builder from the plugin renderer's blocks | To design; the foundation for L3–L7 |
| L2 | **Flows engine** (FL.1): triggers across modules, people and agent steps, conditions, waits; code steps in a sandbox for custom logic; test runs and versions | Designed (section 27); to build |
| L3 | **Sales:** leads and pipeline, sales orders, **point of sale** (working offline), price lists, deliveries | To design |
| L4 | **Inventory and production:** stock, transfers, counts, recipes and batches (designed); valuation (average or first in, first out), unit conversions, production planning, serial numbers | Partly designed |
| L5 | **Accounting:** the light accounting of MO.1 (designed); closing a period, fixed assets, several currencies, the accountant's export formats | Partly designed |
| L6 | **HR and payroll:** employee files, shifts and attendance, running payroll, social security contributions | To design |
| L7 | **Projects:** projects with budgets, time tracking, a timeline, invoicing from time or milestones (with what Taskaat offers) | To design with Taskaat |
| L8 | **The 3.1 modules:** chat with people and agents, meetings, appointments and the booking page, applications, subscriptions a tenant sells, money and expenses | Designed (section 27); to build |
| L9 | **Whity mobile** (Flutter) | Designed (section 26); to build |
| L10 | **Reporting:** a report builder, custom dashboards, scheduled reports | To design |
| L11 | **Integrations:** API and webhooks in the interface, banks, payment gateways, online shops, WhatsApp Business | To design |
| L12 | **Platform:** several companies under one owner, several currencies, test and live versions of customizations, packaging customizations as plugins for the Marketplace, industry packs (bakery, clinic, trading) | To design |
| L13 | **Live collaboration** in the document editor: cursors, presence, version history (E.3) | Designed as "after M8" |

## How this changes

Near-term items move into the build plan as milestones when their design is done; long-term items move to near term when their dependencies are in. Each change is recorded in the decision register.
