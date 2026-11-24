# whity/plugin-sdk changelog

Every SDK minor, newest first: what it added for plugins and why it doesn't break them. This file replaces the ledger that was the docblock of `src/Sdk.php` (SDK 1.162, decision D7).

- **One entry per minor.** A pull request that changes the SDK adds its minor at the top, as `## 1.N`, and sets `Sdk::VERSION` and `composer.json` to `1.N.0`. The SDK lane hands out the number.
- **Additive.** Every 1.x minor only adds. An entry says so on its last line, and names what it deprecates and the replacement.
- **Read by CI.** `scripts/ci-sdk-version-collision.php` checks the top entry against `Sdk::VERSION` and the order of the entries; `scripts/sdk-coverage.php` checks that every minor here is named by a `since` in `docs/sdk-coverage/coverage.json`.

## 1.163

PLUGIN.JSON SCHEMA, PART 1 (decision D4): `plugin.json` also declares `settings`, `notifications`, `jobs` (a handler `class`, an optional cron `schedule`, `submittable`), `dashboard`, `onboarding`, `contactFields` and `themes` (theme files inside the plugin), each in the shape its PHP interface returns. `Manifest\ManifestValidator` checks them through the new `Manifest\SettingsValidator`, `Manifest\NotificationTypesValidator`, `Manifest\JobsValidator`, `Manifest\OnboardingValidator` and `Manifest\ThemeFilesValidator`, and the existing `DashboardValidator` and `ContactFieldValidator`; `resources/plugin-manifest.schema.json` describes them. `Manifest\ManifestPlugin` now implements the matching interfaces and answers each from the file, empty when the file says nothing. Core's `whity-cli plugin validate <dir>` checks a manifest offline, theme files included (WHIT-1389).

Purely additive: new optional manifest keys, new validators, and new interfaces on the abstract `ManifestPlugin`, whose answers are empty unless the manifest declares the part.

## 1.162

THE CHANGELOG MOVES HERE (decision D7): this file replaces the version ledger that was the docblock of `Sdk`, and the 640-line assertion message in core's `SdkPackageContractTest` that repeated it. `Sdk::VERSION` and this file's top entry are checked against each other, and the entries against their order, by `scripts/ci-sdk-version-collision.php`; `scripts/sdk-coverage.php` reads its minors from here (WHIT-1388).

Purely additive: no contract changes, only where the history is written.

## 1.161

THE 2.0 NAMES, ADDITIVELY: every contribution point also has its 2.0 name in `Whity\Sdk\Contribute\` (`Contribute\PluginDashboardInterface`, `Contribute\PluginSettingsInterface`, `Contribute\PluginTablesInterface` and the rest), a `class_alias` of the old name, so the two are one type and a plugin may implement either. `PluginMcpInterface` is `Contribute\PluginMcpPromptsInterface`, since it declares prompts only, and `Testing\TenantIsolationConformanceTestCase` is `Testing\WorkspaceIsolationConformanceTestCase` (D6). The old names are `@deprecated` and keep working through 2.0.x. The plugin context gives the explicit-workspace services without their workspace argument (D2): `Context\PluginScope::contacts()`, `timeOff()`, `away()` and `bookings()`, returning `Contacts\WorkspaceContacts`, `Calendar\WorkspaceTimeOff`, `Delegation\WorkspaceAway` and `Calendar\WorkspaceBookings`, with the service names `contacts`, `timeOff`, `away` and `bookings` for `Context\Service` (WHIT-1387, docs/wiki/Plugin-SDK-2.0-Plan.md).

Purely additive: 42 aliases, four new interfaces and four new methods on Context\PluginScope, which only the host implements. Nothing is removed and nothing logs.

## 1.160

WORKSPACE HANDLES: a workspace's `slug` is now its public @handle, unique on the installation, and an owner may change it; the old one still leads to the workspace for 90 days. New event `tenant.handle_changed` (`id`, `old_handle`, `new_handle`, `by_profile_id`) so a plugin that stored a handle can update it (WHIT-1363, design section 36 §3, HD.4).

Purely additive: one new event.

## 1.159

STOCK IN THE BOOKS: extension point `Contribute\WorkspaceAccountingExport` (IV.22): core hands each posted stock batch, as an `Inventory\StockPosting` of `Inventory\StockPostingMove`s with their places, virtual roles, branches and values, to the one export bound in the container, inside the stock transaction, for each workspace whose `keepsStock()` answers true; throwing refuses the batch. Values come from core's moving average cost (IV.18). Core accounting is the default export and posts each batch to the stock journal (WHIT-1318 A4a, design section 31 §6.3, section 32 §6).

Purely additive: one new extension point and two value objects.

## 1.158

EXPENSE CLAIMS: `Accounting\Invoice` carries `claimantProfileId`, set on an expense claim (a supplier bill from the member of staff who paid, through their own contact). New event `accounting.expense.claimed`, with an `.async` twin, when a person files one (WHIT-1318 A3b, design section 32 §5.3, Q5).

Purely additive: one new optional constructor argument on Accounting\Invoice.

## 1.157

SUPPLIER BILLS AND PAYMENTS MADE: `Accounting\Invoices` drafts a supplier bill with `kind` `purchase` (a supplier, their own `supplierNumber`, input tax codes, cost or asset accounts) and `issue()` posts it (Dr costs and the tax to reclaim, Cr We owe suppliers); `credit()` makes a supplier credit. `Accounting\Payments::recordMade()` records money paid to a supplier, settling the bill named; a cheque dated later waits on Cheques we wrote. Writes on bills act as a person holding `bills:write`. New events `accounting.bill.posting` (a filter that can veto), `accounting.bill.posted` and `accounting.bill.paid`, each notification with an `.async` twin. Core accounting now implements `Contribute\WorkspaceCommissionExpenses`: a closed commission period posts Staff commission against Commission to pay (WHIT-1318 A3a, design section 32 §5.3, §5.4).

Purely additive: one new method on Accounting\Payments, which only the host implements.

## 1.156

PAYMENTS: host service `Accounting\Payments` records money a customer paid (`recordReceived()` with a `Accounting\PaymentRequest`: posted at once, settling an invoice when named, a provider's fee to Bank and payment fees), settles invoices with what a payment has left (`allocate()`), moves money between the workspace's own accounts (`recordTransfer()`, a payment provider's payout to the bank), and reads payments (`Accounting\Payment`). On the plugin context as `$ctx->accounting()->payments()`; its payments belong to the plugin and its external references are kept under its name, so Whity Pay's payment id records once. New events `accounting.payment.recorded`, `accounting.payment.allocated`, `accounting.invoice.paid`, `accounting.invoice.partly_paid` and `accounting.cheque.bounced`, each with an `.async` twin (WHIT-1318 A2b, design section 32 §5.4, §5.5).

Purely additive: one new method on Context\AccountingScope, which only the host implements.

## 1.155

SALES INVOICES AND TAXES: host services `Accounting\Invoices` (sales invoices and credit notes: draft, issue, credit, cancel a draft; read) and `Accounting\Taxes` (the workspace's tax codes, the code for an item's tax category, a line's net and tax), on the plugin context as `$ctx->accounting()->invoices()` and `->taxes()`. Drafts and credit notes made through the context belong to the plugin and its external references are kept under its name; the container's copy reads. Value objects `Accounting\Invoice`, `Accounting\InvoiceLine`, `Accounting\TaxCode`, `Accounting\EInvoiceResult`. Extension point `Contribute\WorkspaceEInvoicing`: one connector bound in the container reports issued invoices to a tax authority (JoFotara first). New events `accounting.invoice.issuing` (a filter that can veto), `accounting.invoice.issued` and `accounting.invoice.credited`, each notification with an `.async` twin (WHIT-1318 A2a, design section 32 §5.2, §7).

Purely additive: two new methods on Context\AccountingScope, which only the host implements.

## 1.154

ACCOUNTING: host services `Accounting\Accounts`, `Accounting\Ledger`, `Accounting\Periods` and `Accounting\Reports` read core's chart of accounts, its append-only double-entry ledger, its months and the trial balance without HTTP, and `Context\AccountingScope` puts them on the plugin context as `$ctx->accounting()`. An `Accounting\EntryRequest` posts balanced lines in the base currency's minor units, in an open month, under the plugin's own name, acting as a person holding `accounting:post`, idempotent by key, undone by `reverse()` (the mirror entry). Entries are hash-chained per workspace. A plugin adds accounts it owns through the context's `accounts()`; the container's copies only read (`use_plugin_context`). Refusals throw `Accounting\AccountingRejected` with a stable reason (`unbalanced`, `period_closed`...). New events `accounting.entry.posting` (a filter that can veto), `accounting.entry.posted`, `accounting.entry.reversed`, `accounting.period.closed` and `accounting.period.reopened`, each notification with an `.async` twin (WHIT-1318, design section 32).

Purely additive: one new method on Context\PluginScope, which only the host implements.

## 1.153

COMPANY STAMPS: `Hooks\Events::ROUTE_STAMPED` (`route.stamped`, with its `.async` twin) fires after a holder applied a company stamp on a route's stamp step, with ids only and the `stamp_id` (#1729).

Purely additive.

## 1.152

LOGS, ERRORS, ROUTE LIMITS AND INSTALL CALLBACKS (#1686, WHIT-1331)): `Context\PluginScope::log()` gives `Log\PluginLog` and `Context\PluginScope::errors()` gives `Log\ErrorReporter`; both pass everything through core's error scrubber and are tagged by the host with the plugin and workspace. A plugin route may declare `'rateLimit' => <calls a minute>` (1 to 10000), per person or, signed out, per address; past it the host answers 429. `PluginInstallLifecycleInterface` is told once per version per deployment that the plugin was installed or upgraded, after its migrations.

Purely additive: two new methods on PluginScope, which only the host implements; one optional route key; one optional interface.

## 1.151

DECLARED SERVICES AND WORKSPACE TABLES (#1693): `Contribute\PluginCapabilitiesInterface` lets a plugin say which `Context\PluginScope` services it uses (`Context\Service`) and which of its own tables it reaches; its context then gives only those, and anything else throws `Context\ServiceNotDeclared`. `Context\PluginScope::database()` gives `Database\WorkspaceTables`: select, count, insert, update, delete and transactions on the declared tables, the host adding `tenant_id` to every condition and row, so no call reaches another workspace; refusals throw `Database\TableRefused`. The operator sees each plugin's declaration in `GET /api/plugins` (WHIT-1330).

Purely additive: a plugin that declares nothing keeps every service as before; `database()` is new and needs the declaration. One new method on PluginScope, which only the host implements.

## 1.150

EMBEDDED SCREENS CALL THEIR OWN API (#1688): an `embed` screen's page talks to the app over a postMessage bridge, protocol `Frontend\FrameBridge::PROTOCOL`; `Frontend\FrameBridge::script()` is the page side (`whity.ready()`, `request()`, `resize()`, `navigate()`, `toast()`). The app makes the page's API calls with a five-minute token bound to the routes of the plugin that registered the screen, never with the person's cookies, and the route's permission is checked against the person as usual. A core route or another plugin's route refuses the token (WHIT-1329).

Purely additive: one new class; an embed page that does not use the bridge works as before.

## 1.148

COMMISSION TO THE BOOKS (WHIT-1334, design section 35 §4.3): when an owner closes a commission period, core freezes each member of staff's totals and hands the close (`Calendar\CommissionClose`, its `Calendar\CommissionCloseLine`s) to the one `Contribute\WorkspaceCommissionExpenses` bound in the container, which answers the reference it booked it under. New event `calendar.commission.closed`, with an `.async` twin.

Purely additive.

## 1.147

WALK-INS (WHIT-1334, design section 35 §3): a workspace template may set the calendar's `walkins_enabled`, which turns on the walk-in queue at the front desk, and give a role `calendar:front_desk`. Older hosts refuse a template that sets it, so a template plugin that does needs `^1.147`.

Purely additive.

## 1.141

NOTICES BY EMAIL: a type in `Notification\PluginNotificationsInterface::getNotificationTypes()` may carry `emailDefault` (bool, optional, false when left out). Every notice still lands in the inbox; with `emailDefault` it also goes by email to a person who never chose for the type. The person's own choice always wins, and the workspace's email switch applies as for core notices. The email carries the Notice's subject and body only, never `data` (WHIT-1250, #2209).

Purely additive: one optional key. Older hosts ignore it and keep notices in-app, so a plugin that needs the email needs `^1.141`.

## 1.137

LOTS, SERIAL NUMBERS AND EXPIRY: `Inventory\Lots` reads an item's batches and serial numbers (`Inventory\Lot`) and what is expiring, through `$ctx->inventory()->lots()` or the container. An item keeps batches when its `tracking` is `lot` (each batch with its expiry) or `serial`. A `Inventory\MoveLine` gains `lotId`, `lotCode`, `expiresOn` and `madeOn`: stock coming in names its batch (a new code makes it), stock leaving may name one or takes the batches that expire first. `Inventory\Move` gains `lotId`. New events, fired once per batch by a daily job: `stock.lot.expiring` and `stock.lot.expired`, each with an `.async` twin (WHIT-1235, design 31 §3.5).

Purely additive: one new method on Context\InventoryScope, which only the host implements, and new optional constructor arguments, last, on MoveLine and Move.

## 1.133

USAGE METERS, READ-ONLY (section 33 §5, BM.17): the host service `Usage\Usage` gives a plugin its workspace's usage meters for a month, as Billing > Usage shows them: seats, customers, items, invoices, storage, messages by channel, agent runs and tool calls, and API calls, each with what was used, its base and whether it is near or past it (`Usage\UsageMeter`). Past a base is a warning only; nothing is ever refused.

Purely additive: one new host service, null on older hosts.

## 1.132

QUOTES: `Contribute\WorkspaceQuoteConversion` turns an accepted quote into something else, read as a `Sales\QuoteSnapshot` with its `Sales\QuoteSnapshotLine`s; it answers a `Sales\ConversionCheck` and a `Sales\ConversionResult`. Core's inventory (sales orders) and accounting (invoices) are its first implementations. New events: the filter `sales.quote.sending` (can veto) and `sales.quote.created|sent|accepted|declined|expired|converted|cancelled`, each with an `.async` twin (WHIT-1321, design section 34 §3).

Purely additive.

## 1.131

A WORKSPACE TEMPLATE NAMES ITS THEME (WHIT-1225, 30-themes §14.6, TH.23): `Manifest\WorkspaceTemplateValidator` accepts an optional `theme`, a theme id such as `templates.market`, usually one the same plugin ships through `Contribute\PluginThemesInterface`. Choosing the template offers that theme, on by default; core sets it only while the workspace still has the installation's theme, and skips it when no theme by that id is installed.

Purely additive: one new optional manifest key. Older hosts refuse a manifest that carries it, so a plugin that sets `theme` needs `^1.131`.

## 1.130

CORE PERMISSIONS FOR A PLUGIN'S ROLES, with the operator's approval (#2256): `Contribute\CorePermissionRequestsInterface` lets a plugin say which core permissions its roles need (`['hr' => ['calendar:write', 'calendar:manage']]`). Nothing is granted until an operator approves it, per role and per workspace; an approval grants exactly what was asked, is recorded with the operator, can be revoked, and goes when the plugin is turned off. The host service `Contribute\CorePermissionGrants` answers whether a grant is in place. Also: a member of staff now puts time off on their own calendar through `Calendar\TimeOff` with no `calendar:write`.

Purely additive: one new optional interface and one new host service.

## 1.129

PURCHASE ORDERS: `Inventory\Orders` drafts a purchase order for a supplier contact, sends it (to the workspace's purchase route when one is chosen, else approved at once) and receives it in parts, each receipt an `Inventory\Operation` posting `supplier → place` at the order's cost; more than ordered past the workspace's tolerance is refused unless allowed. `Inventory\Order` and `Inventory\OrderLine` carry it. A plugin writes through `$ctx->inventory()->orders()`, bound to the loaded plugin; the container's copy only reads. New events: the filter `inventory.order.submitting` (can veto) and `inventory.order.created|approved|rejected|partly_done|done|cancelled`, each with an `.async` twin (WHIT-1234, design section 31 §3.7).

Purely additive: one new method on Context\InventoryScope, which only the host implements.

## 1.128

STOCK OPERATIONS: `Inventory\Operations` adjusts stock with a reason from the workspace's list, counts it (expected taken as each line is counted, blind counts optional) and moves it between places (sent, on the way, received), each as an `Inventory\Operation` with its `Inventory\OperationLine`s. An adjustment or a count's differences worth more than the workspace's value waits for approval on its route before it posts. A plugin writes through `$ctx->inventory()->operations()`, bound to the loaded plugin like `stock()`; the container's copy only reads. New events `inventory.operation.done`, `inventory.count.differences`, `stock.level.below_reorder` and `stock.level.restored`, each with an `.async` twin (WHIT-1233, design section 31 §3.6, §3.9).

Purely additive: one new method on Context\InventoryScope, which only the host implements.

## 1.127

INVENTORY: host services `Inventory\Items`, `Inventory\Locations`, `Inventory\Stock` and `Inventory\Units` read and change core's items, places and stock without HTTP, and `Context\InventoryScope` puts them on the plugin context as `$ctx->inventory()`. Stock is an append-only ledger of moves from one place to another; a plugin writes it only through the context, whose `stock()` is bound to the loaded plugin, so its moves are stored under its own name, act as a person holding `stock:adjust`, can be made idempotent, and are undone by reversing moves. `Inventory\Quantity` keeps quantities exact (whole steps of a unit, never floats). New events `stock.moving` (a filter that can veto), `stock.moved` and `inventory.item.created|updated|archived` (WHIT-1232, design section 31).

Purely additive.

## 1.125

THEMES: a plugin ships theme files with `Contribute\PluginThemesInterface`; the host checks each against the theme format (`schemas/theme/v1.json`) and lists them in the workspace's theme picker, where a person chooses one; a plugin never applies a theme. `Context\PluginScope::themes()` gives `Theme\WorkspaceTheme`, the workspace's compiled theme and the themes it can choose, read-only, with the workspace from the context (D2). `PluginThemeInterface` is @deprecated, working through 2.0.x (WHIT-1225, decisions TH.1-TH.14).

Purely additive: one new method on PluginScope, which only the host implements.

## 1.124

RELEASE 0.2.31, THE FIRST ONE-MINOR-PER-RELEASE ENTRY (decision D7): `PluginContext` is the one entry point (D3): `\Whity\app(PluginContext::class)->for($this)` gives a `Context\PluginScope` with settings, secrets (`Secrets\WorkspaceSecrets`, the workspace from the context, D2), files, documents, notify, jobs, audit and http; a service the host lacks throws `Context\ServiceUnavailable`. The seven `->for($this)` factories and `PluginHomeCardInterface` (D5) are @deprecated, working through 2.0.x. `submission.reviewed` fires again, from the route step where the office records its answers, with `route_id`, `step_id` and `decided` added (WHIT-1243). Other lanes' SDK additions in 0.2.31 join this entry.

Purely additive: deprecations only; every existing name keeps working.

## 1.123

ONE ERROR TYPE: every exception the SDK defines implements `SdkException`, so a plugin can catch "the host refused this" in one place. Each still extends \RuntimeException as before. The hook catalogue in docs/wiki/HOOK_SYSTEM.md lists every published event with its kind, and the reference page docs/wiki/Plugin-SDK-Reference.md names every SDK interface (WHIT-1230).

Purely additive: no class changes its parent and no signature changes.

## 1.122

OUTBOUND HEADERS AND ADDRESSES: `Http\Outbound\HttpResponse::$headers` and `header()` carry the answer's headers (`Retry-After`, `Location`), and `Testing\RecordedHttpClient::on()` scripts them. `Http\Outbound\AddressPolicy::publicOnly()` is an opt-in for `Http\Outbound\StreamHttpClient` when a person supplies the URL: the host is resolved once, refused when any address is not public, and the connection goes to the checked address (#2151).

Purely additive: the new constructor arguments are optional and nothing changes without a policy.

## 1.121

SEVERAL REFERENCES: a `referenceSelect` block takes `multiple: true` to pick several rows from its `source`. Its value is then a list of `valueField` strings shown as chips, and `required` means at least one (#1437).

Purely additive: without `multiple` a reference select submits one string, as before.

## 1.120

TIME OFF AND AWAY: `Calendar\TimeOff` is the host service that puts real time off on a person's calendar (create, cancel, forPerson, byExternalRef), as the acting person with the time-off route's checks, idempotent by the plugin's own reference; refusals throw `Calendar\TimeOffRejected`. `Delegation\Away::at()` reads whether a person is away at a moment (their "I'm away" period or time off) and who stands in for them; "I'm away" gains the `plugins` cover for a plugin's own approvals (WHIT-1210, issue #2132).

Purely additive.

## 1.119

CONTACTS: the host service `Contacts\Contacts` lists, reads, finds by a custom field, creates and updates core's customers and suppliers without HTTP, writes acting as a person who holds `contacts:write`, and `PluginContactFieldsInterface` declares a plugin's own fields on contacts (`keyhub.supplier_code`), checked by `Manifest\ContactFieldValidator`, made in each workspace where the plugin is on and archived with their values kept where it is off (WHIT-1209, #2131).

Purely additive.

## 1.118

TRIAL CHECKOUT: `Billing\TrialCheckout` is the optional portal interface for a checkout that takes the card now and charges when a free trial ends. The host uses it when a workspace already in a trial of a plan adds a card, so the trial is never cut short (WHIT-1207).

Purely additive.

## 1.117

DISPLAY NAMES: `PluginDisplayNameInterface` gives a plugin the name people read, in English and Arabic ("Whity for Salons", "Whity للصالونات"), shown on Settings › Plugins and in a workspace template's offer instead of its id. `getName()` stays the id. Without it, people see the id (WHIT-1198).

Purely additive.

## 1.116

WORKSPACE TEMPLATES: `PluginWorkspaceTemplatesInterface` lets a plugin offer ready starts for one kind of business, shown right after a workspace is created. A template is a versioned manifest, data only: workspace defaults, booking rules and hours, services with prices per currency, roles, forms, documents, example contacts and bookings (removed in one click), the steps of its guided setup, and the paid plugin it offers with a trial. Core applies it as the person choosing it, adding only what is missing, and grants a role only permissions that person holds. `Manifest\WorkspaceTemplateValidator` checks the shape (WHIT-1194).

Purely additive.

## 1.115

THE DASHBOARD: `PluginDashboardInterface` lets a plugin add setup points to the dashboard's "Finish setting up" card, each opening one of its guided-setup steps or screens and marked done by its own status route, and widgets of four kinds (stat, list, chart, actions) built from blocks, each with a permission, the sizes it fits and one data route. Core lists them only to people holding the permission in a workspace where the plugin is on, and people hide, show, move and resize widgets on their own dashboard. `Manifest\DashboardValidator` checks the shape (WHIT-1177).

Purely additive.

## 1.114

OUTBOUND HTTP: `Http\Outbound\HttpClient` is the one way plugin code calls another server, and `Http\Outbound\StreamHttpClient` implements it over PHP streams: https only unless asked (`allowHttp`), verified TLS, no redirects, timeouts, a size cap, and failures that name the host and nothing else. `Testing\RecordedHttpClient` answers from a script for tests. From Whity Pay's own client (WHIT-1058).

Purely additive.

## 1.113

SETUP INPUTS: block forms gain `timeInput` (HH:MM), `weeklyHours` (a week of opening or working hours, open/closed per day, breaks, copy to every day, submitted as the calendar's `{weekday, start_time, end_time}` rows) and `checkboxGroup` (several choices, submitted as a list). `colorInput` takes `swatches`, and `fieldArray` takes `default` rows and an `addLabel`, so a setup screen offers a ready list to tick and edit instead of text to type (WHIT-1172).

Purely additive.

## 1.112

WORKSPACE OWNERS: `Hooks\Events::WORKSPACE_OWNER_ADDED`, `Hooks\Events::WORKSPACE_OWNER_REMOVED` and `Hooks\Events::WORKSPACE_OWNERSHIP_TRANSFERRED`, when a workspace changes hands or gains or loses a co-owner (WHIT-1170).

Purely additive.

## 1.111

QR CODES: `Print\QrCode` encodes bytes as a QR code and draws it as SVG or a data URI, so a plugin prints a code without a vendor package. It moved here from Front Office's receipt (WHIT-1060).

Purely additive.

## 1.110

SOLD PER SEAT: a paid plugin's `PluginAccessInterface::getAccess()` may add `seats`, one of its own integer settings counting what the workspace has (Whity for Salons: chairs). The host buys that many, and changes the subscription's quantity when the count changes. `Billing\QuantityCheckout` is the optional portal interface for a checkout of several of one price (WHIT-1152). `Setup\WorkspaceSetup` also reaches bookings (list, create, check in) and contacts (list, create), for a salon's walk-ins and commission (WHIT-1154).

Purely additive.

## 1.109

GUIDED SETUP: `PluginOnboardingInterface` lets a plugin declare a step-by-step setup, each step a block screen with a status route saying which steps are done; core draws it as a stepper and opens it right after the plugin is turned on. `Setup\WorkspaceSetup` also reaches the workspace's people and staff invitations. A plugin's declared dependencies are turned on with it in a workspace (WHIT-1148).

Purely additive.

## 1.108

WORKSPACE SETUP FOR PACKS: `Setup\WorkspaceSetup` lets a plugin set a workspace up through core's own routes (calendar settings, services, staff and hours; workspace settings; forms), as the person acting there and with only that person's permissions, so an industry pack can prepare a workspace when it is turned on (WHIT-1129).

Purely additive.

## 1.107

PAID PLUGINS, SWITCHED ON PER WORKSPACE: `PluginAccessInterface` lets a plugin say what a workspace needs to use it: an entitlement its plan or add-on grants, the product it is sold as (`plugin.<slug>`) and an optional trial. When access ends it has 7 days of grace, then turns read-only. `PluginWorkspaceLifecycleInterface` tells a plugin a workspace turned it on or off, so it can set the workspace up. `Billing\WorkspaceAddons` is the host service a billing plugin calls to grant and revoke a workspace's add-on plans (WHIT-1125).

Purely additive.

## 1.106

ONE WHITY ID TOKEN VERIFIER: `Auth\Jwt\IssuerTokenVerifier` verifies a JWT from one issuer (Whity ID): issuer, `typ`, signature against the issuer's discovered key set, `exp`, `nbf`, `iat` and `aud`. `Auth\Jwt\JwsVerifier` checks a signature against a key set (RS256/384/512, ES256/384/512 only), `Auth\Jwt\JwksCache` caches key sets with a refetch for a rotated key, and `Auth\Jwt\HttpsJson` is a small https GET for metadata. Needs only PHP's openssl extension. The host, the store plugin and the builder connector share it (WHIT-1026).

Purely additive.

## 1.105

CUSTOMERS INVITE THEIR TEAM: `Hooks\Events::MEMBERSHIP_CUSTOMER_INVITING` runs before a customer's team invitation is written; a listener may refuse it with `Hooks\HookVetoException` or attach `annotations`. `Hooks\Events::MEMBERSHIP_CUSTOMER_JOINED` also fires when such an invitation is accepted, with `via` `invitation`, `invitation_id`, `invited_by` and the `annotations` (#1872, WHIT-996).

Purely additive.

## 1.104

CUSTOMER-GRADE PLUGIN PERMISSIONS: a plugin implements `PluginCustomerPermissionsInterface` to list those of its own permissions that reach only the caller's own records. A workspace's customers may then hold them, and Settings > Sign-up may offer a role made of them, whatever their action (`manage`, `upload`, `review`). The host refuses core and administrative resources, permissions the plugin does not own, and resources another plugin also declares, and forgets the list when the plugin is disabled (#1872, WHIT-995).

Purely additive.

## 1.103

DOCUMENT BLOCKS: a plugin adds blocks to the document editor (editor M7, E.17–E.20). A code plugin implements `PluginDocumentBlocksInterface` with `Documents\DocumentBlock` declarations; a workspace plugin lists the same shape under `documents.blocks` in its manifest (`Manifest\DocumentBlockValidator`). A block is a declaration, never code: its fields, a view and an optional print view in the screen vocabulary's display blocks, naming fields as `{{data.key}}`. Whity's own renderer draws it, and a Verified plugin's blocks carry the mark. Without a print view a block is for notes and wiki pages only (WHIT-993).

Purely additive.

## 1.102

WHITY'S OWN BILLING EMAILS: the host service `Mail\BillingEmails` sends a `Mail\WorkspaceEmail` in the INSTANCE's brand to a workspace's members who hold `billing:pay`, in the workspace's language (EM.1 `payment-failed`, `whity-receipt`). Only a plugin that declares `PluginBillingEmailsInterface` and that the operator granted (`plugins.billing_email_senders`, empty by default) may send, a few a day per workspace at most (WHIT-985).

Purely additive.

## 1.101

LOCALIZED TEXT IN BLOCK SCREENS: any text a person reads in a `screen: 'blocks'` descriptor (its label, and label, title, placeholder, emptyText, description, confirm, trigger and itemLabel anywhere in the tree, `value` on `text`, `text` on `heading`, `body` on `alert`) may be an `I18n\LocalizedText` value `{en, ar}`. The host serves it in the reader's language; renderers still receive strings. `Frontend\Blocks\BlockText` lists the fields and resolves a tree, and `Frontend\Blocks\BlockValidator` accepts the objects (WHIT-984).

Purely additive.

## 1.100

EMAIL AS THE WORKSPACE: the host service `Mail\WorkspaceEmails` sends a `Mail\WorkspaceEmail` as a workspace, in its brand and language and the EM.1 layout: heading, paragraphs, label-value rows, one button, a note and the footer notice, plain text only. Best effort: false, never an exception. For what a business sends its customers, such as Front Office's invoices and receipts (WHIT-978).

Purely additive.

## 1.99

CUSTOMER MESSAGES: the host service `Messaging\CustomerMessages` lets a plugin tell one of the workspace's customers something through Messages, trying channels in the plugin's order (`whatsapp` matches an installed WhatsApp channel, then `email`), in the customer's own conversation and marked as sent by the plugin (WHIT-967).

Purely additive.

## 1.98

CALENDAR AND BOOKINGS, ADR 0017: `PluginBookingPaymentInterface` gives the host a `Calendar\BookingPaymentStep` that can require a payment, such as a deposit, before a booking is confirmed; core holds the slot as `pending_payment` and the plugin settles it through the host service `Calendar\Bookings`. `PluginCalendarBusyInterface` adds busy times from outside core, read when free times are listed and again inside the person's lock, failing open. `Calendar\CalendarEvents` keeps outside events on a person's calendar. `Hooks\Events::BOOKING_CREATED` and the other calendar and booking events carry ids only (WHIT-943).

Purely additive.

## 1.97

MESSAGES AND INBOX SOURCES, ADR 0016: `Messaging\MessageChannel` and `PluginMessageChannelsInterface` let a plugin add a channel such as WhatsApp or SMS: send, receive (the channel verifies the provider), delivery status, capabilities and templates. The host namespaces it `<plugin>:<key>`, meters it and isolates its failures. `Inbox\InboxSource`, `Inbox\KeysetInboxSource` and `PluginInboxSourcesInterface` let a plugin's items reach the merged inbox, ordered by `Inbox\InboxOrder` (urgent first, then newest) and paged by `Inbox\InboxCursor`. `Inbox\InboxItem` moved here from core and gained `urgent` (#881, WHIT-937).

Purely additive.

## 1.94

ROUTE AND FORM EVENTS: `Hooks\Events::DOCUMENT_ROUTED` and `Hooks\Events::DOCUMENT_ROUTE_ACTED` are published constants for what core already fired. New: `route.sent_back`, `route.resubmitted`, `route.completed`, `route.signed`, `document.finalised`, `form.submitted`, `submission.reviewed` and `submission.withdrawn`, each after the change is saved and carrying ids only. A form submission's final document fires `document.issuing` and `document.issued` with `origin: route` (WHIT-923).

Purely additive.

## 1.93

TRANSLATABLE FIELDS: `I18n\LocalizedText` holds the `x-whity-localized-text` OpenAPI marker and the `{ar?, en?}` value contract the schema-driven screens read, with helpers to check a value and pick the text for a language (#521).

Purely additive.

## 1.92

PLUGIN AUDIT: the host service `Audit\PluginAudit` records an entry with a target and reads the plugin's own entries in the current workspace. The host attributes every entry: names go under the plugin's namespace, and the actor, IP address and workspace come from the request or job (WHIT-1032).

Purely additive.

## 1.91

PLUGIN JOBS: the host service `Jobs\PluginJobQueue` enqueues a plugin's own declared jobs in the current workspace and reads their status, and the optional `Jobs\PluginScheduledJobsInterface` declares cron schedules run once per workspace. A plugin can neither enqueue nor see another plugin's jobs (WHIT-1031).

Purely additive.

## 1.90

NOTIFIER: the optional `Notification\PluginNotificationsInterface` declares notification types with English and Arabic labels, and the host service `Notification\PluginNotifier` sends them to a person, role or unit in the current workspace, in each recipient's language. Only active members are reached, opt-outs apply, and types are namespaced under the plugin, so none can pose as a security or billing notice (WHIT-1030).

Purely additive.

## 1.89

OBSERVE-ONLY LISTENERS AND THE REST OF THE EVENT CATALOGUE: a `getHooks()` subscription with `'observe' => true` sees the event, but its return value is ignored and it cannot change the payload or veto. `Hooks\Events` publishes the membership, registration, federated sign-in, resource grant, period close report, data type lifecycle and boot-time events core already fired (WHIT-1033).

Purely additive.

## 1.88

PLUGIN SETTINGS AND SECRET FIELDS: the host service `Settings\PluginSettings` reads and writes a plugin's own declared keys for the current workspace, `Hooks\Events::PLUGIN_SETTINGS_CHANGED` says one changed, by key, and `Secrets\PluginSecretFieldsInterface` with a `secretField` block lets a person enter a secret the host stores and never sends back (WHIT-1016).

Purely additive.

## 1.87

REQUEST CONTEXT: `Http\Request` gains `getWorkspaceId()`, `getLanguage()`, `getActorKind()` (person, agent, system; null when nobody is signed in) and `isAgent()`. The host stamps them on every route, MCP tool and resource call, from its own authorization, never from what the caller sent (WHIT-1015).

Purely additive.

## 1.86

STORE LICENCE SALES: a billing plugin sells paid store plugin licences through `PluginLicenceSalesInterface`, and the store reaches it as `Billing\LicenceSales`: checkout() for an installation, status() for the truth. The host keeps ONE seller, stamped with its plugin name (`Billing\ProvidedBy`) and refuses a second, so no other plugin can answer in its place. `Billing\LicenceSaleStatus::$testMode` marks a licence bought in the provider's test mode, which is never active. And `Billing\AccessRefresh`: any plugin may ask the host to re-read one workspace's access through the active portal; only the subject goes in, never a state, throttled and audited (WHIT-1013).

Purely additive.

## 1.85

STORE LICENCES: the host service `Licensing\StoreLicence` answers whether a paid plugin from the Whity store is licensed on this installation, as a `Licensing\LicenceState`: `licensed`, `grace` with an until date, or `unlicensed`. The host fetches the store's signed statement over the installation's Whity ID connection, verifies it against the store's pinned key, caches it, and keeps answering through a 7-day offline window. A plugin never sees a token or a key (store licensing, PR-4).

Purely additive.

## 1.84

SERVICE ROUTES: a plugin route declared `'service' => '<scope>'` is called server to server with a client-credentials JWT from the deployment's trusted issuer (Whity ID), for an operator-allowed client, this deployment's audience, at most ten minutes long and carrying the scope. The handler reads the verified caller with `Http\ServiceCall::from()`. Only the issuer's own client may act for a person (`sub_on_behalf`); `Identity\LinkedPeople` resolves that person through that issuer, only for workspaces where they hold the asked permission. And `Billing\PlanCatalogue`: read-only plan prices, so a billing plugin prices a plan as the host does without reading its tables (WHIT-1011).

Purely additive.

## 1.83

BULK ISSUING: `Documents\Documents::issueMany()` takes a `Documents\BulkIssueRequest` (one template, up to 500 `Documents\BulkIssueItem`s, a batch key) and issues it in the background; `Documents\Documents::batch()` reads its `Documents\BulkIssueStatus` with a `Documents\BulkIssueItemResult` per item. The batch key makes a resend return the same batch; each item gets an origin key, so a resumed batch never issues twice. Each item is checked and preflighted as the submitter when it runs. New reasons `batch_key_conflict` and `forbidden` (WHIT-997). Documents is implemented by the host only, so the new methods are additive for plugins.

## 1.82

TEMPLATE READER: `Documents\Templates`, resolved as a host service, lists and reads the workspace's published templates (`Documents\TemplateInfo`) with the merge fields their published version prints (`Documents\TemplateField`: path, label, source template, record or host, required, sample). It answers for the caller Documents acts for: the person in a request, or a background plugin's own bound templates where allowed (WHIT-996).

Purely additive.

## 1.81

STARTER TEMPLATES: a workspace plugin ships templates under `documents.starters` in plugin.json (`Manifest\DocumentStarterValidator`, `PluginManifest::documentStarters()`). The host seeds each into a workspace when the plugin goes live there, published, and binds it to its slot when the slot has no template. Seeded once; a copy the workspace changed is never overwritten; an untouched copy follows the plugin's updates (WHIT-995).

Purely additive.

## 1.80

AGENT CONTEXT: the optional `PluginMcpResourcesInterface` offers MCP resources, `whity-plugin://<slug>/<path>`, read per call with the caller's `Mcp\McpToolContext`. A plugin's resources and its prompts (`PluginMcpInterface`, now served with their `messages` and `arguments`) reach agents only from a Verified plugin the operator grants agent context, install-wide and audited, and each must declare `requiredPermission`, checked per caller (WHIT-993).

Narrows 1.9: an ungranted plugin's prompts, and any plugin prompt without a `requiredPermission`, are no longer served.

## 1.79

AI CONNECTIONS: the host service `Mcp\McpConnections` lists the workspace's active MCP connections, as the person whose request it is (the whole workspace with `mcp:tokens:manage`, otherwise their own), never with a token, jti or hash. Events `Hooks\Events::MCP_CONNECTION_CREATED`, `MCP_CONNECTION_GRANT_CHANGED` and `MCP_CONNECTION_REVOKED`, by connection id, after the change (WHIT-992).

Purely additive.

## 1.78

PROPOSAL EVENTS AND PREVIEWS: `Hooks\Events::AGENT_PROPOSAL_CREATED`, `AGENT_PROPOSAL_DECIDED` (approved, declined or expired) and `AGENT_ACTION_UNDONE`, ids and the tool's name only, after the change, listeners unable to refuse or change it. An authored tool's optional `preview` (`PluginMcpToolsInterface`) shows the person deciding an agent's proposal what it would change, as label, before and after rows; the host runs it in a transaction it always rolls back, refuses mail, notifications, jobs and events while it runs, and gives it two seconds. Only the person who granted the agent decides a proposal (WHIT-991).

Purely additive.

## 1.77

A PLUGIN CAN BE THE DEPLOYMENT'S BILLING PORTAL: `PluginBillingPortalInterface` returns a `Billing\BillingPortal`, and the host routes its existing billing surface through it when no hosted billing service is configured: checkout, the return page, plan and quantity changes, receipts, the provider's customer portal, operator refunds, and provider notifications at `POST /api/billing/provider-webhook`. The plugin translates to one payment provider; the host still owns the subscription record, the plan and the entitlements, and reads them back from accessFor() rather than from a notification's payload (WHIT-1010).

Purely additive.

## 1.76

DOCUMENT RENDERING IN JOBS: `Render\DocumentRenderer` resolves in queue jobs and CLI commands too, not only in requests (#1151). Each process builds one per-tenant storage stack, so a job's document is stored where the same tenant's request would store it. No signature changes.

## 1.75

AGENTS FOR PLUGINS: the host service `Agents\Agents` lists the workspace's AI agents and reads their activity, as the person whose request it is: everything for somebody with `agents:manage`, otherwise only the agents they use and their own activity, and nothing with no person. Never a call's arguments. Lifecycle events `Hooks\Events::AGENT_CREATED`, `AGENT_UPDATED`, `AGENT_ARCHIVED`, `AGENT_RESTORED`, `AGENT_PAUSED` and `AGENT_RESUMED`, ids only, after the change (WHIT-990).

Purely additive.

## 1.74

NARROWING SCOPE FOR PLUGIN RESOURCE TYPES: `Rbac\ResourceScope` answers which records of a plugin's resource type a caller may act on, as a `Rbac\ResourceReach`. Record-level permission checks only ever widen, so a plugin that filtered a list with `hasPermission()` and a resource let every row through; the reach is the second check ANDed with the permission. It is always a subset of what `hasPermission()` allows, and unrestricted only when the caller holds the permission and a plugin-named "every record" permission tenant-wide (#1074).

Purely additive.

## 1.73

CUSTOMER SIGN-UP: `Hooks\Events::MEMBERSHIP_CUSTOMER_JOINED`, `membership.customer_joined`, fires when somebody joins a workspace as a customer through its own sign-up, at once when sign-up is Open or on an admin's approval. Ids only, after commit, and the return value is ignored (WHIT-915).

Purely additive.

## 1.72

PLUGIN ROLE GRANTS STAY THE PLUGIN'S OWN: `PluginRolesInterface` grants only permissions the plugin declares in `PluginInterface::getPermissions()`, never a core permission or one under a core resource, and only to a role the plugin created or one the operator approved for it. Uninstall removes only the grants the plugin made. A plugin that listed a core permission, or named a role it did not create, no longer gets that grant; the host logs it.

## 1.71

THE HOST DECIDES WHAT A TOOL WRITES: whether an AI agent may run a tool at once or must ask a person first starts from whether the tool only reads, and the host now decides that itself. A plugin route's tool reads only when its method is GET or HEAD; a `readOnlyHint: true` in its `mcp_annotations` on any other method is published as `false`. A hand-authored tool (`PluginMcpToolsInterface`) is a write for agents whatever its `annotations` say, so a person approves each agent call unless the tool declares an `undo` and the grant allows it. Expose a read an agent should run unasked as a GET route. Annotations stay hints for clients (WHIT-912).

No signature changes.

## 1.70

`user.creating` IS READ-ONLY: the payload is `email` and `role_id`, and the host ignores what a listener returns, so neither the email nor the role of the new user can be changed there. The password is no longer in the payload. A listener that must refuse a create throws `Hooks\HookVetoException`; the host writes nothing and answers `409 Conflict` with its `reason()` (WHIT-911).

Narrows the payload; a listener that read `password` or returned a changed `role_id` or `email` must stop relying on it.

## 1.69

PUBLIC READS: a route declared `'public' => 'read'` on GET `/api/<slug>/public/<name>/{key}` answers anybody holding the link. The host resolves the tenant from the same kind of key a public form uses, runs its limits and Origin check before the handler, which reads the result from `Http\PublicRead`, and serves it only when the operator has switched public reads on. On an older host the route is not public (WHIT-704).

Purely additive.

## 1.68

DOCUMENT REVISIONS: `Hooks\Events::DOCUMENT_REVISED` says a revision replaced a document (WHIT-888).

Purely additive.

## 1.67

PUBLIC FORMS: a route declared `'public' => 'form'` on POST `/api/<slug>/forms/<name>/{key}` takes anonymous browser submissions. The host resolves the tenant from the key and runs the limits, Origin check and bot check before the handler, which reads the result from `Http\PublicForm`. On an older host the route is not public (WHIT-906).

Purely additive.

## 1.66

DOCUMENT PREFLIGHT: `Documents\Documents::preflight()` and `Documents\PluginDocumentStore::preflight()` say whether a document would issue and every reason it wouldn't, writing nothing and allocating no number, in a `Documents\PreflightResult`. `document.issuing` listeners get `check_only` and can answer with `checks` (WHIT-892). Only the host implements these interfaces.

## 1.65

DOCUMENT NUMBERS: `Documents\DocumentNumberingRule` says when a document is numbered and what the number looks like. `Documents\TemplateSlot` and a manifest slot's `numbering` declare one, `Documents\IssueResult` returns the number, and `Hooks\Events::DOCUMENT_NUMBERED` says one was handed out at final approval (WHIT-684).

Purely additive.

## 1.64

PLUGIN AGENTS: the optional `PluginAgentsInterface` lets a plugin declare or customize agents under its own toolset root: a persona in English and Arabic, toolsets, where a person's grant starts, and the host feature flags it turns on (`Manifest\AgentFeatures`). Checked by `Manifest\AgentDeclarationValidator`, which refuses another plugin's or core's agent, a write at Allowed, and an unknown feature. Re-declaring a key with a higher version customizes it.

Purely additive.

## 1.63

PLUGIN FILES AND DOCUMENT TRACKING: the host services `Storage\PluginFiles` and `Documents\PluginDocuments` give a plugin its own files, kept per plugin and per workspace in the platform's storage layer, and its own documents, issued through `Documents` and recorded as the plugin's, with a QR verification code and the scan tracking for those documents only. `Storage\PluginFileController` is a generic upload and download endpoint a plugin wires into its own routes. `Storage\PluginFileStore::list()` is new and the storage driver gains a `list()` (WHIT-886).

Purely additive.

## 1.62

HOME CARDS: the optional `PluginHomeCardInterface` and `homeCard` in `plugin.json` add one card to the home page. It reads a GET route the plugin registered under the same permission, or counts a record type in a workspace plugin; `Manifest\HomeCardValidator` checks it and `Manifest\PluginManifest::homeCard()` reads it (WHIT-872).

Purely additive.) ->

## 1.61

AGENT PRESETS: the optional `PluginAgentPresetsInterface` and `agentPresets` in `plugin.json` ship ready-made agent templates: a persona in English and Arabic, toolsets and where a person's grant starts. Checked by `Manifest\AgentPresetValidator`, which refuses a preset that starts a write at Allowed; `Manifest\PluginManifest::agentPresets()` reads them (WHIT-818).

Purely additive.) ->

## 1.60

PLUGIN SECRETS: the host service `Secrets\PluginSecrets` gives a plugin its own `Secrets\SecretStore` (`for($this)`): `set`, `get` and `delete` a secret per workspace, encrypted with the host's key, kept apart by plugin, never read back by settings or the API, audited by key only. Resolvable in routes, MCP tools and jobs (WHIT-838).

Purely additive.) ->

## 1.59

WHO IS CALLING: `Mcp\McpToolContext` gains `actorKind` (person, agent or system), `principalKind`, and for an AI agent a stable `agentId` and a display `agentName`, plus the person's Whity ID `subject` when the call came in with a Whity ID token, so a tool can record "agent X for person Y" (WHIT-837).

Purely additive.) ->

## 1.58

BACKGROUND ISSUING: a job of a plugin that declares `issuesInBackground()` may issue through `Documents\Documents` with no person, where the workspace allowed it, only from its own slots and with only the permissions it declares. Otherwise it is refused with the new `Documents\DocumentNotIssuedException::BACKGROUND_NOT_ALLOWED` (WHIT-742).

Purely additive.) ->

## 1.57

ISSUE A DOCUMENT FROM AN AUTOMATION: the `issue_document` step joins the automation catalogue. It names one of the manifest's own `documents.slots` for the triggering record type, with optional merge values for the template's own fields as expressions over the record. The host issues through the workspace's binding for that slot, as the person the run acts for, under an origin key so a retry never issues twice (`Manifest\AutomationValidator`, WHIT-795).

Purely additive.) ->

## 1.56

DOCUMENTS FOR CODE PLUGINS: the optional `PluginDocumentsInterface` declares template slots (`Documents\TemplateSlot`) and the plugin's record types as merge fields (`Documents\DocumentFieldProvider`, `<plugin name>.<type>`), and says whether it would issue in the background. The host service `Documents\Documents` issues from a slot or a template for a record, as the person whose request it is, and refuses with `Documents\DocumentNotIssuedException` and a stable reason (WHIT-742).

Purely additive.) ->

## 1.55

TEMPLATE SLOTS: `plugin.json` gains `documents.slots`, the named places a workspace plugin needs a document template. Each has a label, a record type of the manifest's own and the document kinds that can fill it. The host addresses it as `<plugin>.<slot>`, a workspace binds one of its published templates to it, and issuing names the slot. Checked by `Manifest\DocumentSlotValidator`; `Manifest\PluginManifest::documentSlots()` reads them (WHIT-742).

Purely additive.) ->

## 1.54

MANIFEST TESTS: `plugin.json` gains `tests`, examples a record type's rules must keep: create a record, or from a given one update fields or make a move, acting with the roles `as` names, and expect it refused (on which fields) or the fields and state it leaves. Values are literals. Checked by `Manifest\ManifestTestValidator`; `Manifest\PluginManifest::tests()` reads them; the host runs them before a version can be published (WHIT-764).

Purely additive.) ->

## 1.53

AUTOMATIONS: `plugin.json` gains `automations`, what a workspace plugin does by itself when its records change. A trigger names a record type and an event (created, changed, a field changed, moved, moved into a state); an optional `when` expression; and steps from a closed catalogue (set fields, create a record, move, notify a role or a person field). Checked by `Manifest\AutomationValidator`, which refuses the designed steps this host doesn't run yet by name; `Manifest\PluginManifest::automations()` reads them (WHIT-763).

Purely additive.) ->

## 1.52

MCP TOOL SURFACE: `PluginMcpToolsInterface` descriptors take `toolset`, `visibility` (`always-on` | `discoverable`), `agentPolicy` (`allow` | `propose` | `suggest-only` | `refuse`) and `undo`; names must fit `[A-Za-z0-9_-]{1,64}` and descriptions 500 characters, a plugin gets one read-only always-on tool, and unprefixed names are deprecated. The new optional `PluginMcpToolsetsInterface` titles and describes a plugin's toolsets (WHIT-752, docs/design/MCP-TOOL-SURFACE.md §5.7).

Additive. A descriptor that already broke a budget (a name with a dot or over 64 characters, a description over 500 characters) is now refused at load; no plugin in this repository does.) ->

## 1.51

RECORD RULES: a record type may declare `rules` (`require`, an expression that must hold over the record as it will be, `when` it applies, and an English and Arabic `message`), and a field may declare `default`, `computed` and `lockedWhen`, all in the manifest expression language. Every expression is validated, and every `field` it names must be one of the record type's fields (WHIT-762).

Purely additive.) ->

## 1.50

MANIFEST RECORDS: `plugin.json` gains `records`, record types a workspace plugin keeps in the host's shared record store — fields of twelve types (text, numbers, money in minor units, dates, choices, links to people, units and other record types), an optional lifecycle of states and named moves, and read/create/edit/delete gated by permissions the manifest declares. Checked with the rest of the manifest by `Manifest\RecordTypeValidator`; `Manifest\PluginManifest::records()` reads them. An installed `Manifest\ManifestPlugin` refuses `records`, since only workspace plugins use the store (WHIT-761).

Purely additive.) ->

## 1.49

DOCUMENT EVENTS: `Hooks\Events::DOCUMENT_ISSUING`, where a listener may refuse a document with HookVetoException before anything is written, and `DOCUMENT_ISSUED` and `DOCUMENT_RENDERED` with their `.async` forms. Payloads carry ids, never field values. Every issued document now goes through the host's one issuing service, so these fire for documents a plugin issues too. `Render\DocumentRenderer::issue()` takes an optional origin key (issuing again with it returns the first document), and is deprecated in favour of `Documents::issue()`, which issues from a template the workspace publishes (DOCUMENT-AUTOMATION.md phase B). It keeps working and is removed in SDK 2.0.0.

Purely additive. On an older host the constants name events nobody dispatches.) ->

## 1.48

MANIFEST EXPRESSIONS: `Expression\Expression`, the closed expression language manifest rules, computed fields and conditions are written in — a JSON tree, one operator per object, no loops, no references outside the record, bounded by node, depth and step caps. With `Expression\ExpressionContext` for what it may read, `Expression\ExpressionError` for type and budget failures, and `Expression\ExpressionDescriber` for the English and Arabic words a person approving a rule reads. Held to `resources/expression-fixtures.json`, which the TypeScript twin in `@whity/features/expressions` passes too (WHIT-759).

Purely additive.) ->

## 1.47

PLUGIN MANIFEST: a plugin's declarations can live in `plugin.json`, format `whity.plugin/1` (`Manifest\ManifestValidator`, JSON Schema in `resources/plugin-manifest.schema.json`). Extending `Manifest\ManifestPlugin` with an empty class answers identity, requirements, permissions, roles, `blocks` screens, owned tables, data types and limits from the file, in the shapes the existing interfaces define, so the host registers them exactly as it registers PHP declarations. A subclass adds routes, hooks and migrations in PHP. The manifest is checked as a whole (a role may grant only declared permissions, a screen must be gated by one, a data type must live in a declared tenant table, every label carries English and Arabic, unknown keys are refused) and an invalid one refuses the whole plugin rather than loading part of it (WHIT-758).

Purely additive. Nothing changes for a plugin that doesn't use it.) ->

## 1.46

MCP TOOL CONTEXT: an authored tool's handler is called as `fn(array $arguments, Mcp\McpToolContext $context)`. The context carries the tenant the call is authorized in and the calling profile, so a tool can scope its queries the way a route handler does with its request. Before it a tool had no supported way to learn its tenant, and the only route was core's internal tenant state (#1419).

Purely additive. A one-parameter handler keeps working, since PHP ignores the extra argument; on an older host a two-parameter handler is called with one argument and fails, so a tool that needs the context should declare `^1.46`.) ->

## 1.45

MCP TOOL ANNOTATIONS: an authored tool descriptor (`PluginMcpToolsInterface`) may carry `annotations` — `title` plus the boolean `readOnlyHint`, `destructiveHint`, `idempotentHint` and `openWorldHint` of the MCP spec — and a route schema may carry `mcp_annotations` in the same shape to override what the host derives from the HTTP method. Clients read these to run reads without asking and to confirm before destructive calls. They are hints only: nothing about enforcement changes. Malformed entries are dropped, never passed on.

Purely additive. On an older host both keys are ignored.) ->

## 1.44

INLINE RUNS IN FLOWING TEXT: `Render\FlowDocument::paragraph()` and `Render\FlowDocument::heading()` take an optional `$runs` array, so a plugin can emit bold, italic and linked spans inside a paragraph instead of one undifferentiated string.

The gap: the CANVAS half of the designer has carried inline runs since it shipped — `TextRun`, with bold and italic over slices of a text element — and the FLOWING half never did. So a letter or a report, the very documents flow mode exists for, printed its emphasis away.

MIXED DIRECTION WAS ALREADY HANDLED, and is the constraint here rather than the feature. The renderer isolates opposite-direction runs in `<bdi>`, and the obvious implementation — escape the run, wrap it in `<strong>` — silently drops that, so a bolded Latin serial inside an Arabic sentence would stop being isolated the moment somebody bolded it. The isolate sits INSIDE the mark.

THE RUNS MUST RECONSTRUCT THE TEXT exactly when concatenated, and a mismatch is refused rather than repaired. `text` is what every plain-text consumer reads — `{{field}}` interpolation, the outline summary, the contents entry, an export — so runs that disagree with it would print one thing and export another, permanently and silently, and repairing it would mean guessing which half the caller meant.

AN `href` IS SCHEME-ALLOW-LISTED to http, https and mailto, because it is the one value in a body that is interpreted rather than displayed, and the flow HTML is loaded by a real browser in the render container in order to be printed.

Purely additive. A block without runs renders exactly as it always did.) ->

## 1.43

HAND-AUTHORED MCP TOOLS: `PluginMcpToolsInterface`, by which a plugin contributes tools it writes itself rather than tools the host derives from its routes.

The gap: every MCP tool was CRUD-shaped, one per endpoint, because derivation is all there was. Derived tools are the right default — near-zero authoring cost, and the list stays honest as routes change — but they can only ever describe an API surface. A workflow surface is a different thing: fewer tools, each carrying domain semantics, instructions and guardrails no route signature implies. Neither kind subsumes the other, and a platform hosting third-party plugins wants both.

A DESCRIPTOR MUST DECLARE ITS AUDIENCE — `requiredRole`, `requiredPermission` or an explicit `open: true`. A derived tool inherits its route's RBAC gate, and a route declaring no permission is visibly open in the route table and in the route-catalogue check; an authored tool has no route, so an omitted permission would be visible nowhere while being callable by every authenticated principal. A descriptor declaring none of the three is refused at load rather than defaulted.

A DERIVED TOOL WINS A NAME COLLISION, because a route-backed name is already published in the OpenAPI document and the generated clients, and letting an authored tool shadow it would leave two descriptions of one name disagreeing. `suppressesDerivedMcpTools()` is the deliberate way to take the name: it removes the competitor rather than hiding behind it, and it is scoped to the plugin's OWN routes so it can never silence core's or another plugin's.

A throwing tool costs its caller an error, not the dispatcher.

Purely additive. A plugin that does not implement the interface loads exactly as before and its routes are derived exactly as before.) ->

## 1.42

DISCRIMINATED SUB-FORMS: the `variant` and `variantCase` blocks. A form region whose SHAPE depends on another field's value — `variant` names a sibling input as its `discriminator`, each `variantCase` declares the value it answers to, and only the matching one renders.

The gap: a resource with type-dependent payloads had to be declared as one hidden section per type using `visibleWhen`, and hiding does not change what is submitted — a hidden input keeps its value and still sends it, on purpose, because the server is authoritative over what it accepts. So thirteen variants meant thirteen payloads at once. A discriminated union needs the branches that were not chosen to be ABSENT, which is a different meaning and therefore a different block rather than a flag on the existing one.

The cases are CHILD BLOCKS, not a `cases` prop, because a prop holding nested trees is invisible to every walker — including the host loader's ownership check, where an unwalked slot is a `source` that never got checked.

Sibling cases may reuse a field name: they are mutually exclusive, so `{kind:'number', value: 5}` and `{kind:'text', value: 'x'}` are the same field in two shapes, which is what a discriminated union is. A duplicate within one case, or one colliding with the enclosing form, is still refused.

Purely additive. Nothing declared before 1.42 changed.) ->

## 1.41

A RENDERING SEAM: `Render\DocumentRenderer`, resolved from the container, turning a plugin's structured content into a document. With it `Render\FlowDocument` (a builder — headings, paragraphs, tables, figures, generated contents/tables/figures lists, RTL and LTR), `Render\PageSpec`, and the two results: `Render\RenderedDocument` for bytes and `Render\IssuedDocument` for a first-class platform document with an id and an immutable artifact.

The gap this closes was total: the SDK had no rendering surface of any kind, so a plugin holding structured content — an invoice, a certificate, a statement of account, a compliance submission — either shipped JSON and asked someone to print a web page or built its own renderer. Neither is a plugin author's mistake; both are what a missing seam produces.

The signatures carry NO tenant id, which is a security property rather than an omission: the host reads the tenant and the actor from its own request-scoped context, so a document built from one tenant's content and filed in another's storage has no expression in this API.

VERIFICATION CODES ARE THE PLATFORM'S TO MINT. An issued document carries the same code a person-issued one does, placed by the host against the document's own id. FlowDocument publishes no way to author one, so a plugin cannot print a document that looks verified and resolves to nothing.

Purely additive. Nothing declared before 1.41 changed, and an instance with rendering disabled — the default — answers every call with `Render\RenderUnavailableException` rather than failing to load.) ->

## 1.40

THE FORM PRELOAD JOINS THE CONTRACT: `form.dataSource`, a `{method: 'GET', path}` spec the renderer has honoured since #949 and which the contract never declared.

NOT PURELY ADDITIVE, and the exception is the point. An undeclared prop is neither validated nor stripped — `BlockValidator::validateProps()` walks the DECLARED rules rather than the node's keys, and the loader's walk returns the node it was handed — so `dataSource` reached the client exactly as written, and its path was never checked against the routes the plugin registered. Alone among every endpoint a block can name: `submit`, every `source`, `inbox.actions` and every `rowActionList` were all ownership-checked.

So a tree that validated under 1.39 can be REFUSED under 1.40 — but only if its form preloaded a route the plugin does not own, or wrote a `dataSource` of the wrong shape. A declaration naming the plugin's own GET is unaffected, and now gets the version rewrite every other endpoint already got, which fixes the class of preload failure #957 traced to an unversioned path.

A minor rather than a major: nothing legitimate stops working, and the trees this refuses are the ones the ownership rule always claimed to cover.) ->

## 1.39

TIME-WINDOW TYPES: `TimeWindow\PluginWindowTypesInterface`, the contribution point for the KINDS of named period a deployment slices time into. #1070 puts a named, non-overlapping period that records can be scoped to and rolled up by — and that can be CLOSED, the way a set of books is closed — into core, because it is a primitive the platform did not have and everybody who needed one either built their own or did without. Two implementations of a period disagree the moment both exist, and nothing reports that they differ. THE VOCABULARY IS THE PART THAT CANNOT BE CORE'S. Two deployments slice time into words with nothing in common — one reasons in a crop year and the growing seasons inside it, another in a kiln campaign and the firing runs inside it — so a core enumeration would have to carry both and ship each deployment the other's. A plugin therefore declares KEYS and the defaults a tenant starts from, and an administrator ADOPTS one; declaring is a catalogue entry, never a write into anybody's tenant. BARE SLUGS ONLY, as with OU types: declare `growing_season`, get `acme:growing_season`. Two plugins may declare the same slug without colliding, and no plugin can mint a bare key, because the unprefixed namespace belongs to core and to the tenant's own vocabulary. NESTING IS DECLARABLE, BOUNDARIES ARE NOT. A declaration may name the type it nests inside — a sub-period sitting inside a period is structural and knowable — but it says nothing about when a period starts, how long it runs, or what fraction of its parent it occupies, because none of that is knowable and assuming it is the specific error this concept exists to avoid. Every boundary is authored per instance, in dates, by somebody who knows. A plugin may only nest inside its OWN declared types: it does not own another source's type and cannot know whether a given tenant adopted it. A malformed declaration costs that plugin its window vocabulary rather than one type — the one place this differs from the OU-type catalogue, and the nesting is why: the declarations are interdependent, so storing them one at a time would either reject a legal forward reference or leave half a hierarchy whose parents point at nothing. NO CONTRACT IS PUBLISHED HERE FOR THE CLOSE REPORT. What a close should say is still unfinished inside a period is contributed through the `time_window.close_report` FILTER HOOK rather than a typed interface, deliberately: the shape such an interface should take depends on an open question this release does not answer — what happens to a record mid-flight when its period closes — and publishing a vendored, version-pinned contract before that is publishing one that then has to break. Additive; every tree that validated under 1.38 still validates) ->

## 1.38

THE RULE VOCABULARY, GENERALISED OUT OF ROUTING: `Audience\AudienceRuleContext` and `Audience\AudienceRuleResolverInterface`. #999 adds NAMED USER GROUPS, and a group is not a membership table — it is one of these same rule expressions given a name and stored once, so that "the instructors" is ONE node referenced from many places rather than a thousand rows re-listed per place. A stored list goes stale the first time somebody is hired: it omits them, still renders, and still reports success. A rule includes them because they exist. The generalisation is a SUBTYPE, not a widening. `RoutingRuleContext extends AudienceRuleContext`, so every property a 1.37 resolver reads is still present with the same type — nothing became nullable — and every call site still compiles. A resolver that never touches the document, the route or the step declares `resolve(AudienceRuleContext)`, which satisfies BOTH interfaces from one body, and its kind then becomes usable as a group definition. The two alternatives were rejected for the same reason: making the routing fields nullable changes the type under plugin code already shipped, and passing zeros in them is a lie the type system would endorse. ONE REGISTRY STILL. `Routing\PluginRoutingRulesInterface` is unchanged and remains the only way to contribute a kind; there is no audience-only declaration, because a rule that can name a set of people can name the recipients of a step. Additive; every tree that validated under 1.37 still validates) ->

## 1.37

DOCUMENT ROUTING RULES: `Routing\PluginRoutingRulesInterface` joins the optional capability interfaces, with `Routing\RoutingRuleResolverInterface`, `Routing\RoutingRuleContext` and `Routing\ResolvedRecipient` as its contract. #947 item 3 puts a document routing engine in core, and the load-bearing decision is that a route STEP NAMES A RULE, NEVER A PERSON: the rule is resolved at send time, against the organisation as it stands then, so a unit created last week is included because it exists rather than because somebody remembered to add it. A stored recipient list omits it, the document still renders, every step still completes and the run reports SUCCESS — which is why the rule is the contract and the list is not offered. Core owns two kinds, `role` and `role_below_actor`, and both are generic in the strong sense: every deployment has roles and every deployment has a unit tree. Anything narrower is what one organisation happens to mean by "the next people" — a `supervisor` means three different things in three deployments — so it arrives through this interface instead of being guessed at in core. A RESOLVER RETURNS SUGGESTIONS AND WRITES NOTHING. It answers "who, and via which unit", and the host filters every profile against the ACTIVE MEMBERSHIPS of the tenant owning the route before a single inbox row exists — so a resolver, correct or buggy, cannot place a document in another tenant's inbox, and that check is not a rule every plugin author has to remember. `validate()` runs at authoring time and its message reaches the person composing the route verbatim; a throw from `resolve()` is plugin code misbehaving and its text is logged rather than shown. BARE SLUGS ONLY: declare `committee`, get `acme:committee`. Two plugins may both declare `committee` without colliding, and no plugin can mint a bare kind — so a step already stored naming `role` cannot be re-pointed by installing a plugin, which matters more here than in most catalogues because the step decides who receives a document. Additive; every tree that validated under 1.36 still validates) ->

## 1.36

THE DOCUMENT VIEWER: `documentViewer` joins `Frontend\Blocks\BlockContract`, declaring no `source` and no new prop-rule kind. #947 item 1 gave core an issued document — a record with an identity and one immutable artifact per render — and nothing in this contract could show one. A plugin whose record has an issued work order could link out to it at best, and a link out of a record is where the reader stops reading the record. COMPOSING WAS NOT AVAILABLE, and not for want of pieces. Every `source` and `recordPath` in this contract is ownership-checked against the routes the DECLARING plugin registered, so core's `/api/v1/documents/{id}` cannot be named by any plugin; the only composition that would work is a plugin republishing core's document reads through a route of its own, gated however that plugin chose. `ouScopePicker` already refused that trade for the OU tree and the refusal is worth more here, because the thing being republished is an audit record. So this type follows the same shape: no `source` prop at all, the host fetching core's own endpoints under the caller's session and the `documents:read` gate they already carry, and no prop with which to point it anywhere else. WHICH DOCUMENT is a `contextPath` (`documentIdFrom`) and there is deliberately NO literal twin. The `heading`/`text`/`badge`/`stat` rule — literal required, `...From` additive — exists because an unresolved binding should still render A title, a duller version of the right answer. Inverted here the fallback is not duller, it is A DIFFERENT DOCUMENT rendered with full confidence, so nothing renders until something has said which one and `emptyText` is what an author says in the meantime. WHICH ARTIFACT is the question a re-render creates, and the block answers it two ways. `artifactIdFrom` PINS one — the binding an append-only trail needs, so an event saying "this is what circulated on the 4th" can show what circulated on the 4th rather than what the document says today. Absent, the viewer shows the CURRENT artifact and says so on screen, with the count of the others and the way to reach them. Showing the newest SILENTLY was the alternative and is the one thing a viewer of an auditable record must not do: a corrected document then reads as though it had always said that. The renderer never falls back between the two — a pinned artifact that is not on the record is an explicit failure state, because quietly substituting the current one answers a question about the past with a fact about the present. NOT OFFERED, deliberately: a prop to hide the version history (a plugin deciding what a reader of an audit record may know is not a presentation choice), a prop to suppress the download (the bytes were already served), and any height/zoom prop (presentational, and the page's own aspect ratio is a fact the renderer can read). Additive; every tree that validated under 1.35 still validates) ->

## 1.35

THE GRAPH BLOCK: `flow` joins `Frontend\Blocks\BlockContract`, with a declared node ceiling (`Frontend\Blocks\BlockContract::FLOW_MAX_NODES`) and no new prop-rule kind. The whitelist covered tables, lists, timelines, inboxes, charts, forms, overlays and master-detail, and not one of its types could render a set of nodes and the edges between them. So anything modelling an ordered or branching process — a chain of steps, a state machine, a dependency graph — could be EDITED natively (`form`, `fieldArray`) and LISTED natively (`table`), while the DIAGRAM, which for that class of feature is usually the thing that makes it legible to a non-technical reader, was the one part every product had to ship as bespoke React. This is not a new renderer: core already runs `@xyflow/react` behind two screens, and both are the same composition the block reuses. ONE FETCH, AND ROWS THAT ARE NODES. `source` is an ordinary ownership-checked apiPath returning a collection, exactly as `dataTable`'s is, and a row IS a node. Edges are references off the node rows to OTHER nodes' ids — `edgeFromField` a predecessor pointer, `edgeToField` a successor pointer, either optionally holding a LIST so a step can branch — and with neither declared the nodes are a linear sequence in payload order, which costs an ordered process no edge modelling at all. A second source for edges was the obvious alternative and is deliberately not offered: it doubles the ownership checks and the loading states, and puts a join in the renderer that the plugin already made when it wrote the rows. A reference to an id no row declared is dropped rather than materialised, since a box the plugin never described, labelled with a raw id, is not information. READ-ONLY BY CONSTRUCTION, like `timeline`: no endpoint, no verb, nothing to submit. Editing is a form opened FROM a node, and the affordance is `nodeActions` of the SAME `rowActionList` kind `dataTable.rowActions` already uses — same validator, same renderer path — because a node's actions are a row's actions and a second spelling of one shape is two shapes that drift. Clicking a node runs its first `open` action; a diagram whose only affordance is a dropdown is one nobody clicks. THE NODE CEILING IS THE POINT OF DECLARING IT. #192 is open against the two existing graph screens because a canvas of several hundred boxes, most with no edges, has stopped conveying anything — and any block on the same renderer inherits that the moment a tenant's data grows. Carrying it silently would mean finding it in a plugin's production deployment, so the limit is in the contract and the behaviour above it is defined: draw the first `maxNodes` in payload order with the edges among them, and SAY on screen that the graph was truncated, because a partial graph that looks complete is worse than a tangle. `maxNodes` may LOWER the ceiling and not raise it: a plugin knows its labels are long or its target is a phone, which is knowledge worth applying, but it cannot know that 400 nodes are legible on every platform this tree may render on. Additive; every tree that validated under 1.34 still validates) ->

## 1.34

CONTEXT-AWARE `visibleWhen` and the `accessGate` container: a condition that can read the RECORD and the CALLER'S ACCESS, plus the `accessCheck` prop-rule kind and the first second child slot in the contract, `otherwise`. #883 made a record page describable and, in doing so, found the half it could not describe. `RecordPageShell` (#897) takes `main: {editor, readOnly}` — two renderings, and it picks — while `visibleWhen` matched a sibling FORM FIELD and nothing else. So a described record page rendered its editor unconditionally and a caller who may not write got a disabled Save button beside fully editable inputs: precisely the greyed-out form #882 exists to make unshippable. Three of the shell's properties followed from that one missing primitive — the read-only rendering, "which gate refused", and conditional notices. `visibleWhen` now names one of THREE subjects: `field` (a sibling input, unchanged), `from` (a master-detail reference — the record the page is about), or `access` (an `accessGate` id). It is also declared ONCE as a universal facet rather than type by type, so every block carries it. A facet only some types may carry is one that granular gating has to route around, and "some parts have permissions, not always everything is allowed" is the requirement this has to compose to. `accessGate` declares one question about the CALLER, publishes the host's answer under its `id`, and renders `children` when the answer is yes and `otherwise` when it is no. WHO ANSWERS IT is the whole design: `check` names a concrete `{method, endpoint}` request and the host resolves it through `POST /api/v1/me/permitted-actions` — the same route lookup feeding the same RoleChecker calls RbacMiddleware makes. A plugin therefore never states which permission gates a region, exactly as an `inbox` action never states the permission of the endpoint it calls, and there is no second copy of that answer to drift when someone re-gates the route. A permission SLUG was the shorter spelling and is deliberately not offered: `'permission' => 'acme:write'` beside a route gated on `acme:manage` hides a control the caller could have used, and nothing compares the two. TWO SLOTS RATHER THAN TWO NEGATED CONDITIONS. The pair could be written as siblings with opposite `visibleWhen` polarity, and that is the shape to avoid: conditions meant to be each other's negation drift, and when they drift it is the editable half that ends up showing. Declared as one node they cannot disagree — the same reason the shell takes both renderings as required props. hidden / read-only / editable composes as two NESTED gates: the outer on the read request with no `otherwise` (refused ⇒ absent), the inner on the write request with both. Nesting also yields #897's "first refusal wins" structurally, since an outer gate that refuses never renders the inner one. READING ACCESS DOES NOT RE-OPEN #895. #908 established that FACT bindings are guarded and CONTROL bindings are not; this is a control binding, and the seam is kept by construction rather than by rule. A gate's answer is published into a namespace of its own, which `resolveContextRef` — the single resolver behind `textFrom`/`valueFrom`/`labelFrom`/`hintFrom` and behind `defaultFrom`/ `params.from`/source tokens — does not read. `visibleWhen.access` is the only prop in the contract whose value names a gate, and all it can do is decide whether a subtree renders. So a page can ACT on what the caller may do and still cannot SAY it about the record. GET joined the methods `permitted-actions` accepts, because "may I see this at all?" is a read and is the only way the hidden state has an authority to ask. The endpoint's identity — allowed implies the middleware would admit exactly this request — is method-agnostic, so it promises no less than before. `otherwise` is the contract's first second child slot, and the slots are now DECLARED (`Frontend\Blocks\BlockContract::childSlots()`) rather than assumed, because several things walk a block tree and a walker that hard-codes `children` silently skips a slot — for the host loader that is a `source` that never got ownership-checked. Additive: every tree that validated under 1.33 still validates) ->

## 1.33

RECORD BLOCKS: `dataRecord` and `recordFields` join `Frontend\Blocks\BlockContract`, with the `recordPath` and `recordFactList` prop-rule kinds, plus optional `textFrom`/`valueFrom`/ `labelFrom`/`hintFrom` twins on `heading`/`text`/`badge`/`stat`. Every data-bound leaf in the contract assumed a COLLECTION at `source`, so a record page — the platform's standard for editing a record since #882 — could not be DESCRIBED at all, only hand-written in React. `keyValue` took literals baked into the declaration; `dataStat` yielded one scalar per block, so a twelve-field record header was twelve blocks and twelve fetches. `dataRecord` is the missing primitive: it fetches ONE resource, publishes it into the master-detail context under its `id`, and owns the loading and failure states for its whole subtree. It is a CONTAINER rather than a leaf so it can be all three at once, and publishing through the EXISTING context is what collapses the second gap into the first. `defaultFrom` and `params.from` already address a row as `{targetId}.{field}`; a record published under a `dataRecord`'s id is addressed the same way by the same resolver, so no second mechanism was invented for the page-level record. Its `source` is a `recordPath` — an owned apiPath that may carry `{token}` segments in that same addressing — which is how the block says WHICH record: from a `selector`, from the row an overlay was opened with, or from `{record}`, the one reserved name a host seeds with the record its ROUTE is about. A `selector` may not claim that name. The block does not fetch until every token resolves, because a half-substituted path requests a different resource than the one meant and would render it as the record. `fields` IS THE #895 GUARD, and it is the reason for this shape rather than a simpler one. #895: the roles record page derived "is this a global role" from `manageable` — the server's answer to "may YOU write this?" — and for a tenant-0 caller that is true of every role, so the system tenant, the one caller whose edit reaches every tenant, saw a deployment-wide role labelled "Your tenant's role". #897 made it unwriteable in TypeScript by splitting the payload into what the record IS and what the caller MAY DO, and refusing a fields type that carries a caller flag. A block tree is runtime data rather than a type, so the same split is enforced twice here. Structurally: a `dataRecord` publishes ONLY the fields its declaration names, so a payload's `manageable`, `canEdit` or `mayModify` is unreachable from the tree because it was never published — whatever it is called, and whether or not the author considered it. By name: a declaration that names one of #897's eleven caller-decision words as a fact, or binds one through a `...From` twin, is REFUSED and drops the feature, so the author who tries is told which word and why instead of watching it silently vanish. Matching is on a case- and separator-folded form, since the same flag arrives as `canEdit` from one serializer and `can_edit` or `is_editable` from another. `defaultFrom` and `params.from` are deliberately NOT guarded: seeding a control the server re-validates, or narrowing a fetch, is plumbing rather than a statement about the record, and #897 draws the line in exactly the same place. A guard that refused those would be refusing correct programs, and a guard that refuses correct programs gets removed. `recordFields` is the data-bound `keyValue` — a description list read from a published record. It carries no labels of its own: those were declared once beside the facts, because a record page shows the same field in more than one place and a label restated per placement drifts per placement. Additive; every tree that validated under 1.32 still validates, and the literal on each `...From` twin stays required so a record page has a title before its record arrives) ->

## 1.32

the ORGANIZATIONAL-UNIT SCOPE PICKER: `ouScopePicker` joins `Frontend\Blocks\BlockContract`, with the `ouScopeList` and `ouTypeKey` prop-rule kinds behind its `scopes` and `anchorType`/`memberType`. A form input whose value is a RULE over the OU tree rather than a pinned list of ids: `{unit, scope, type}`, where `scope` is one of `unit`/`subtree`/ `children` and is ALWAYS written. That is the whole shape decision — "this unit" and "this unit's subtree" are different answers, and a consumer must never have to infer which was meant from the presence of some other field. `type` is an OU type key from 1.30, filtering the set the scope resolves to; pinning the ids that set contains today is the parallel unit-id → kind map #822 exists to delete, and it goes stale the first time a unit is reparented, silently. The type declares NO `source`, and that is structural rather than a convenience. Every `source` in this contract is ownership-checked by the loader against the routes the declaring plugin registered, so a plugin cannot name `/api/ous` at all — a `referenceSelect` aimed there drops the whole feature, and the only way to satisfy the gate is to republish core's hierarchy through a plugin route, which is exactly the drift being prevented. So the host renderer reads the units and the vocabulary from CORE's own endpoints under the caller's own `ous:read` gate: a caller who may not read the org chart cannot build a rule over it, and a plugin has no prop with which to point the control anywhere else. `anchorType` and the value's `type` are deliberately separate: the first restricts which unit may ANCHOR the rule, the second restricts the set it resolves to, and "every department under a faculty" needs both. A `memberType` declared beside a `scopes` list of exactly ['unit'] is refused — a kind filter over the single unit the user just picked can only ever remove it. Additive) ->

## 1.31

WORKFLOW BLOCK TYPES: `timeline` and `inbox` join `Frontend\Blocks\BlockContract`, plus the `itemActionList` prop-rule kind behind `inbox.actions`. `timeline` is the easy half and was overdue: an ordered, append-only event list — actor, action, timestamp, optional note, optional from/to — data-bound to one ownership-checked `source` exactly as `dataStat` is. Every product on the platform with an audit trail was hand-rolling it, so the same history rendered differently on every screen. It declares no endpoint and no verb, so read-only is a property of the contract rather than a convention. `inbox` is the half with a seam, and where the seam falls is the whole design. Core has no notion of a task queue, so the ITEMS come from the plugin's own `source`. What core owns is which of the declared `actions` the caller may take on each item — and it resolves that from the ROUTE the action calls, with the same RoleChecker calls RbacMiddleware makes, through `POST /api/v1/me/permitted-actions`. An action therefore does NOT declare the permission its endpoint is gated on: that is not the plugin's to restate. A restated slug is a second answer to a question the route table already answers authoritatively, and it drifts the day someone re-gates the route and updates one of the two places. Resolving from the route makes "what the user is shown" and "what the middleware admits" the same computation rather than two computations that agree for now. `scopedPermission` is the one authorization fact a plugin CAN contribute, because it is the one the route table cannot express: the per-record predicate a handler applies inside the request, resolved at (`resourceType`, the item's id) through the resource-scoped grants of 1.17/1.22. It is an ADDITIONAL conjunct — the route gate is evaluated regardless — so it can only ever remove an action from the permitted set, never add one. That direction is chosen deliberately: a wrong declaration costs a user an affordance they had, and cannot cost a tenant an authorization it relied on. Declaring it requires `resourceType`, since a per-record check with no record silently becomes the tenant-wide check wearing the wrong name. The rejected alternative was to have the plugin's queue endpoint return the permitted actions per item. It is less code in core and it is exactly the failure the block vocabulary exists to prevent: every product re-deriving authorization beside the host's, each one drifting from the middleware in its own direction, and the drift only visible as a 403 after a click. Additive) ->

## 1.30

`Ou\PluginOuTypesInterface`, the organizational-unit TYPE contribution point. An OU carried no kind, so the only thing a plugin could filter its tree on was DEPTH — which names a different kind of unit on every installation (a single-campus institution has its faculties at depth 0, a multi-campus one at depth 1) and shifts the moment somebody inserts a parent above an existing unit. Slugs are declared BARE and the host stamps the plugin's prefix, so two plugins may both declare `clinic` and neither can mint a bare key — the unprefixed namespace belongs to core and to the tenant's own vocabulary. A declaration is a CATALOGUE ENTRY, not a write: it makes a key adoptable, and an administrator adopts it into their tenant explicitly, inheriting the declared label and rank as overridable defaults. Force-seeding instead would have been a cross-tenant write driven by an install-wide plugin. A malformed declaration costs that plugin the one type. Additive) ->

## 1.29

`PluginEventsInterface`, the audited-event contribution point, plus `Hooks\Events::forPlugin()` and the now-published namespacing rule `PluginNamespace`. The host's audit writer subscribed to a HARDCODED map of core event names, so a plugin's own domain events reached the platform audit trail never — an operator opening the one screen that answers "who did what" saw core's mutations and a silence where every plugin-side action had been. Both workarounds are worse than the gap: writing to `audit_log` directly puts a second writer on a table whose entire design is that it has one (no metadata sanitising, no tenant resolution, and nothing stopping a row that claims to be `user.deleted`), and a private activity table is a second audit surface in a second place nobody is looking at. A plugin now declares which of its events belong in the trail and the host records them through the SAME path core's go through. Names are declared BARE and the host stamps the plugin's prefix onto BOTH halves of the record — action `acme:task.completed`, target type `acme:task` — because an attributable action beside a target type of `user` still reads as something core did to a core record. The trigger is namespaced too, and that is the load-bearing part: the hook manager tells a listener nothing about who dispatched, so listening on the bare name would have written a row for EVERY plugin declaring `task.completed` whenever any one of them fired it, and an audit trail that records an event which did not happen is worse than one that records nothing. A declaration that throws or is malformed costs that plugin its subscriptions and costs core's own auditing nothing; the refusal is whole-declaration, because a plugin with half its events audited ships a trail that looks complete. The subscriptions are registered with the plugin's other hooks, so disabling a plugin removes them and re-enabling it restores them. Additive

## 1.28

`PluginJobsInterface`, the async-job contribution point. `JobInterface` has been public since 1.0 and the host's job registry has always taken a handler, but nothing DISCOVERED a plugin's — so the shipped `queue:work` worker knew only the core handlers and dead-lettered anything a plugin enqueued as "No handler registered for job". A plugin's only remaining option was to ship a `queue:work` of its own that re-registered the core handlers beside its own, which means the operator runs one worker per plugin and every one of them is a place core's own queued work can be duplicated or dropped. Declared names are BARE and the host stamps the plugin's prefix onto them, exactly as it does for resource types, health probes and settings keys: two plugins declaring `sync` get different canonical names, and no declaration can produce a `core.`-prefixed one. Submittability is declared separately and fails CLOSED, matching core — a handler the worker can run is not thereby reachable from the public submission API. A declaration that throws or is malformed costs that plugin its jobs and costs the worker nothing: it is the process that also delivers core's notifications and error alerts, so one bad plugin must not stop it. Additive) ->

## 1.27

`Testing\OfflinePluginHostConformanceTestCase`, the offline-host conformance kit. `Testing\TenantIsolationConformanceTestCase` proves a plugin's queries stay tenant-scoped; it says nothing about whether the plugin actually BOOTS under a real offline PHP host with no server framework behind it — no JWT/memberships/OU hierarchy, a single fixed device role, and a deliberately narrow SQLite dialect shim (the shape the Tauri desktop template's bundled FrankenPHP host runs plugins under). Every real gap that shim surfaced (a migration using `SERIAL` that SQLite silently mis-parses, an un-seeded `admin` role that left existing grant migrations silently inert, a route requiring a permission its plugin never declared) was found only by manually exercising a running host and watching it fail. This kit catches that class of defect in a plugin author's own CI, with no FrankenPHP process required: migrations apply cleanly against the same dialect shim; declared permissions are well-formed and every route's `requiredPermission` is one of them; a role granted one permission holds exactly that one and nothing else; and every declared hook runs cleanly on a synthetic payload — a generic `Throwable` fails the test loudly, since the real host's per-plugin error boundary would otherwise swallow it silently and ship the bug invisibly. Additive; a plugin ignoring it is unaffected) ->

## 1.26

UNDECLARED-REFERENCE LINTING: `Schema\UndeclaredReferenceLinter` and `Schema\ReferenceDeclarations`. With no foreign keys between plugin tables — the convention here, and the reason `blocks_delete` / `cascade_delete` are declared as data at all — nothing at the database level stops a delete orphaning a record's children. An adopter's schema had zero foreign keys and deleting a parent silently left its children pointing at an id that no longer resolved; the delete answered 200. The linter flags a `<something>_id` column that points at a table that really exists, carries NO foreign key, AND appears in NEITHER declaration list — "you have a relationship core cannot see". It deliberately does NOT flag an `*_id` column merely for lacking a foreign key: that rule fires on the intended design of nearly every plugin table, is muted within a day, and takes the credibility of the tenant linters with it. A schema with zero foreign keys passes completely, provided its relationships are declared. `tenant_id` is never flagged — that is a different invariant with its own two linters — and a column naming no known table is not treated as a reference at all. The escape hatch mirrors `@tenant-guard-ignore`: `-- @reference-lint-ignore: <reason>` on the column, with the REASON required, because a decision nobody wrote down is indistinguishable from a muted alarm) ->

## 1.25

PORTABLE WRITES: `Sql\Upsert`, and `Sql\SequenceAllocator` — the host-provided contract for "hand me the next number, and never hand the same one twice". `INSERT … ON CONFLICT … DO UPDATE … RETURNING` is the most repeated statement shape in an adopting plugin — one codebase carries 58 — and each one hand-builds four lists that must stay in step. `Upsert::tenantScoped()` builds them, and takes the tenant id as a REQUIRED separate argument that it writes into the value list AND prepends to the conflict target: an `ON CONFLICT (client_uuid)` where the intent was `ON CONFLICT (tenant_id, client_uuid)` is cross-tenant data loss written as an ordinary create, and it is now unrepresentable. `Upsert::unscoped()` serves declared-global tables, and its name is a declaration a reviewer can grep for. `buildSql()` is public, so nothing is hidden: the exact statement is inspectable, loggable and assertable. SequenceAllocator goes further than a helper and deletes the SQL entirely — a plugin that needs uniquely numbered records now declares nothing, migrates nothing and writes no SQL; it asks the host for a number, and the host allocates it in one statement with no read-then-write window for two clients to both observe. Gaps are possible and documented; duplicates are not) ->

## 1.24

the lifecycle WRITE contract: `DataType\DataTypeLifecycle` and the `DataType\LifecycleOutcome` it answers with. The host told adopters to route their lifecycle writes through it and then published only `DataType\DataTypeGuard` — read-only, deliberately, since its whole guarantee is that holding it confers no authority. So a plugin that needed to actually trash a record duck-typed a host-internal service: no contract, no compatibility promise, no obligation to keep its shape. Reads keep their guarantee — the guard is untouched and gains no mutators — and writes get a second contract instead of being smuggled into the first. The host binds it to the SAME object its generated endpoints authorize through, so an in-process call cannot skip a check the endpoint enforces: a type the caller may not read is UNKNOWN rather than forbidden, an action the type does not offer is refused, and the action's declared permission is resolved through the host's own checker. `$actorProfileId` is required for exactly that reason — it is the subject of the check, not a decoration. The outcome is the vocabulary the HTTP layer already publishes (`reason` as the stable key, `message` as the fallback sentence, `blockers`, and the status), so one branch serves both call paths. Bulk work is a LOOP over these calls; a bulk statement bypasses every guard, veto and hook at once) ->

## 1.23

PORTABLE SCHEMA PREDICATES: `Schema\SchemaInspector` and the `Schema\MigrationSchema` trait that puts it on `$this` inside a migration. The SDK asks every migration to be idempotent and the host runs them on PostgreSQL and SQLite, but `ALTER TABLE … ADD COLUMN IF NOT EXISTS` is a PostgreSQL extension SQLite rejects — so the moment a plugin adds a column to a table it already shipped, the author has to hand-write a driver-branching `tableExists()`/`columnExists()` over `information_schema` and `PRAGMA table_info`. That pair has been written four times in one session in a single adopter's codebase, identically each time, and a wrong answer gates DDL: it passes on the engine the author develops against and fails on the other one, at enable time, on somebody else's deployment. Written once here, proven on both engines, and correct in two places the hand-written copy is not — lookups are confined to the connection's own search path rather than matching a same-named table in any schema, and read from `pg_catalog` rather than the privilege-filtered `information_schema`. `addColumnIfMissing()`/`dropColumnIfExists()` go one step further and remove the branch entirely: the migration states the shape it wants instead of asking a question and acting on the answer. Additive; no SQL is hidden behind a builder, so the tenant-isolation guards still read every statement) ->

## 1.22

RESOURCE-SCOPED ROLE checks: the optional `$resourceType`/`$resourceId` pair 1.17 gave `Rbac\PermissionResolver::hasPermission()` is now on `Rbac\PermissionResolver::hasRole()` too. Host role resolution has honoured a resource scope since 1.17, but `hasRole()` asked the tenant-wide question regardless — so a role granted at ONE record through `resource_role_assignments` was resolvable and not askable, and reads as needing a schema change to `memberships` that it does not need. Additive: omitting them preserves 1.21 behaviour exactly, WC-712 §2) ->

## 1.21

plugin-declared SETTINGS: `Settings\PluginSettingsInterface`, by which a plugin contributes typed, validated, defaulted configuration keys to the HOST'S own settings store instead of rebuilding one as a private table with no declared keys and no validation. Host-namespaced under the plugin name the loader supplies, resolved through the same per-tenant ?? global ?? default chain as a core key, and published on the host's own settings screens only on an explicit `admin => true` opt-in — those screens are gated on core permissions, which are not the plugin's. Secret-shaped declarations are REFUSED rather than downgraded to a readable string, #713 item 1) ->

## 1.20

plugin-owned data types — Door 2 of the schema-driven admin surface: `Tenant\PluginTablesInterface` declares WHICH tables a plugin owns (the host stamps WHO owns them, from the plugin name the loader holds), `DataType\PluginDataTypesInterface` declares a record's lifecycle and its reference graph as DATA, and `DataType\DataTypeGuard` exposes the host's own guard evaluation so a plugin's custom delete route enforces through the same path the generated one does. Trashed and retired are kept distinct: reversible-and -pending-removal versus permanent-and-closed-to-new-references, WC-723) ->

## 1.19

status-page health probes: `Health\PluginHealthProbesInterface`, `Health\HealthProbeDefinition` and `Health\ProbeResult` — a plugin contributes a probe for a dependency it owns and the host samples and publishes it beside its own database/queue/scheduler/render probes, instead of the plugin inventing a second status surface nobody watches. Host-namespaced under the plugin name, so probes cannot collide or shadow a core one

## 1.18

plugin-declared resource types: `Rbac\PluginResourceTypesInterface`, the optional declaration that lets a plugin address a role grant at one of its own records instead of keeping a private grant table, WC-712 §2

## 1.17

resource-scoped resolution: `$resourceType`/`$resourceId` on `Rbac\PermissionResolver`, so a plugin can ask "may this caller act on THIS record?" — additive, omitting them preserves the previous tenant-wide answer exactly, WC-712 §2

## 1.16

read-only permission resolution: `Rbac\PermissionResolver`, the host-registered contract a plugin resolves from the service container to ask the SAME authorization question the host's RBAC middleware answers — membership gating, OU-ancestor inheritance, role hierarchy, live delegations, catalogue validation — instead of re-deriving it in hand-written SQL and drifting from what is actually enforced, WC-712

## 1.15

hook VETO contract: `Hooks\HookVetoException`, the one Throwable the host's per-plugin error boundary lets through. Paired with the host now running `*.deleting` → DELETE → `*.deleted` inside one transaction for tenants/OUs/roles, this lets a plugin refuse a deletion — or fail its own cleanup — and have the deletion rolled back rather than silently committed, WC-713

## 1.14

notification transport contract: the pluggable `Notification\NotificationTransport` channel contract plus the `Notification\NotificationMessage` and `Notification\SendResult` value objects — one contract for email/SMS/in-app/push delivery. Core ships the transport registry + a null/log transport; plugins contribute real ones, WC-notifications

## 1.13

`screen: 'embed'` frontend-feature value — the host iframes a plugin's own RBAC-protected GET route with zero host-application edits, WC-246 — plus real multipart file uploads for `screen: 'action'` fields, WC-247

## 1.12

optional theme-override contribution point: `PluginThemeInterface`, letting a plugin contribute design-token CSS variable overrides the host applies at render time. Same ownership model as data-bound block sources — the declared route must be one this plugin actually registered — and the host independently revalidates every returned key/value before it ever reaches a `<style>` tag, WC-242

## 1.11

`dataTable`/`dataList` inline client-side sort/filter/pagination: `dataTable.columns` upgraded to the new `dataColumnList` prop-rule kind (adds optional per-column `sortable`/`filterable` booleans), plus a shared optional `pageSize` on both leaves — purely additive, applies only to rows already fetched from the block's single verified `source`, WC-241

## 1.10

`chart` data-bound block type — bar/line/area/pie backed by the same `apiPath`-owned `source` trust boundary as `dataTable`/`dataStat`/`dataList`, plus the new `chartSeriesList` prop-rule kind for its semantic `{key, label, color: 1..5}` series set, WC-240

## 1.9

MCP prompt contribution point: `PluginMcpInterface`, WC-7abb732f

## 1.8

interactive block types: `form` container, 9 input leaves — `textInput`, `textArea`, `numberInput`, `select`, `checkbox`, `slider`, `dateInput`, `fileInput`, `colorInput` — plus `submitButton` and `actionButton`; new `inputName`/`selectOptions`/`submitSpec` prop-rule kinds; form-ancestor enforcement and per-form duplicate-name detection in the validator, WC-233

## 1.7

data-bound block types: `dataTable`, `dataStat`, `dataList` leaves with the new `apiPath` prop-rule kind — strict relative API path validation, WC-229

## 1.6

server-driven plugin-UI block contract: the platform-neutral `Frontend\Blocks\BlockContract` whitelist and the `Frontend\Blocks\BlockValidator`, plus the new `screen: 'blocks'` frontend-feature value, WC-225

## 1.5

multipart upload shapes: `Http\UploadedFile` and the additive `Http\Request::getUploadedFiles()` upload bag, plus the host-side `Http\MultipartParser`, WC-217

## 1.4

host CORE-version constraint declaration, `PluginRequirementsInterface::getCoreConstraint()`, gated against the host's core version independently of the SDK gate

## 1.3

tenant-isolation conformance kit: `Tenant\TenantPredicateScanner`, `Tenant\MigrationTenantColumnLinter`, and the shared `Testing\TenantIsolationConformanceTestCase` a plugin extends to prove its tenant tables and queries are scoped

## 1.2

frontend feature descriptor, `PluginFrontendInterface`, plus host-enforced route-level `requiredPermission`

## 1.1

requirements declaration, and `Sdk` itself

## 1.0

contract extraction
