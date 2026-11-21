# Products, names and plans

Whity is a branded house: one name and one plain noun that says what each thing does.

| Name | What it is | Licence and price | Status |
| --- | --- | --- | --- |
| Whity Core | The platform: identity, tenants, permissions, documents, forms, plugins, MCP server, and since 21 Nov 2026 everything a business needs (BM.13): stock, sales, accounting, themes and Studio | AGPL-3.0 with the plugin exception, free | Built, growing |
| Whity SDK | The plugin SDK and UI kit | MIT, free | Built |
| Whity Cloud | Whity hosted for many companies at app.whity.dev, with Whity's own apps | Free up to the limits, then plans | Live |
| Dedicated hosting | A company's own installation, hosted by Whity and managed by the company; needed for plugins and custom code | Flat monthly fee by size | Planned |
| Whity Render | Pixel-exact PDF and image export for documents, invoices, labels (the 13 document templates in section 16) | Closed, paid service, hosted by Whity | Inside Core today; to split out |
| Whity ID | Single sign-on and identity provider (OIDC) | Closed, paid service, hosted by Whity | Planned |
| Whity Pay | Payments and subscriptions through the installer's own Stripe, Polar or Paddle | Paid integration plugin (BM.16) | Built |
| Integration plugins | JoFotara, live bank feeds, WhatsApp, SMS, calendar sync | Paid plugins, one per outside service (BM.16) | Planned |
| Whity Builder | No-code plugin builder at builder.whity.dev | Closed, paid service today; moves into core as Studio's editor after Front Office, then builder.whity.dev retires (owner, section 33 Q1) | Built |
| Whity Marketplace | Partners and developers sell plugins | Revenue share | Later |

Front Office, until 21 Nov 2026 a closed, paid app, is retired: its parts are core features with plain names (stock, purchasing, quotes, invoices, receipts, import; BM.15, BM.19, sections 31, 32 and 34). Whity Studio is not a paid product (BM.15). Section 33 has the plan.

When a plan doesn't include a feature, the screen shows it greyed behind a `PlanGate` card naming the plan: owners can upgrade, everyone else can ask an owner. Never hide it (decision 2.1d). Since 21 Nov 2026 plans differ only in seats and volume (BM.17), so `PlanGate` is for what still differs: hosted services and the white-label items under review (section 33 Q6).

## Rules

- Whity + one noun, in English in every interface language. No "AI", "Pro", "Plus" or version numbers in a product name.
- Third-party plugins name the job first: "Inventory for Whity", "WhatsApp Connector for Whity".
- Partner products carry the partner's own name, with the optional Built on Whity badge.
- Features are lower-case nouns, not brands: approvals, scopes, activity, branding, delegations.
- Agents are named by role, by the customer: "Billing assistant", "Front desk". Never a human first name.

## Positioning

Company-first (BM.1): **"Your company's work in one place. Agents prepare it, you approve it."** Builders and agencies come second: **"Run it, or sell it as your own."**

## Three ways to run Whity (BM.2)

| | Whity Cloud | Dedicated hosting | Own server |
| --- | --- | --- | --- |
| Where | app.whity.dev, shared | Its own installation, hosted by Whity | The company's servers |
| Who runs it | Whity | Whity hosts; the company manages its Whity | The company |
| Apps | Whity's own apps | Plus Marketplace plugins and custom code | Plus Marketplace plugins and custom code |
| Pays for | Seats and volume above the free base, integration plugins | A flat monthly fee by size (BM.7), plus services above the free limits and integration plugins | Integration plugins and hosted services only; no plan limits (owner 2026-11-17; BM.16) |

## Free, then plans (BM.3, BM.4)

The plans and their limits are only for Whity Cloud (app.whity.dev). A self-hosted installation has no plan limits of any kind, including on Whity's services; it pays only for paid plugins (owner 2026-11-17). **Staff** are the company's own accounts; agents don't count. **Customers** are people outside the company who sign in with Whity ID, book, use the customer app or portal, or pay online, counted per month; contacts stored in records are always unlimited.

| Plan | Staff | Customers a month | White-label |
| --- | --- | --- | --- |
| Free | 2 | 100 | Logo and colour |
| Team | 25 | 5,000 | Plus own web address, sign-in page and email sender, no "Built on Whity" |
| Business | 100 | 25,000 | Plus own app in the stores (M.16) |
| Larger | Quote | Quote | Everything, with an on-premises licence if needed (BM.8) |

Extra staff or customers come in packs. These numbers are the starting point to test with first customers. Agent work is base plus usage. **Since 21 Nov 2026 (BM.17, "Seats and volume")** the meters are staff, storage, records (customers, items, invoices), messages, and API and agent usage; plans differ in nothing else, every limit warns until paid plans charge, and the Free base is being re-set (section 33 Q5). Plan names and prices stay `[plan name]` and `[price]` in every mockup until they are set.

Plans are entitlement bundles (ADR 0010) with a payment wall that defaults to "warn" (ADR 0011). When a plan doesn't include a feature, the screen shows it greyed behind a `PlanGate` card naming the plan (2.1d).

## White-label on every level (BM.5, BM.6)

Logo and colour are free. Above that, every Whity service is white-labelled down to the domain: sign-in at `id.examplebakery.jo`, documents and their QR check links, emails from the company's own sender, notifications and the customer app. When it's on, Whity's name never shows to the company's customers.

## Who hosts the services (BM.8, BM.9)

Whity hosts Whity ID and Whity Render for every installation by default. Regulated companies (banks, clinics, government) and large partners can buy an on-premises licence instead (minimum contract, usage reported back). Self-hosted installations keep working for a while if Whity ID can't be reached. Hosted services run in the EU (servers, database, files and backups; WS.18). A company that needs its data inside its own country gets a dedicated installation there for a fee, or runs Whity on its own servers, with no plan limits, and pays only for the paid plugins it uses.

## Partners (BM.10)

Agencies resell Whity to their clients under their own brand, at a partner price they mark up and invoice themselves, or for a monthly share of what their clients pay Whity.
