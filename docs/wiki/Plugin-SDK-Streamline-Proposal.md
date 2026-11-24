# Plugin SDK: streamline proposal (v0.2.31 → SDK 2.0 at v0.2.40)

Status: **decided by the owner on 19 Nov 2026** (WHIT-1227, WHIT-1242). Every recommendation was accepted. D10 and D11 were replaced by the owner's theme and inventory decisions of the same day (§5, §7). Written 2026-11-19 against `develop` at SDK 1.121. The plan to build it is [Plugin-SDK-2.0-Plan](Plugin-SDK-2.0-Plan.md). On 24 Nov 2026 the owner moved the public beta to after v0.2.50 (WHIT-1385); SDK 2.0 still ships at v0.2.40.
The owner asked to "streamline the SDK, remove redundancies, make a better SDK that covers more and more customization" before the public beta. This document audits what the SDK is today, says what to fold together, what to fix, and what is missing, and ends with the decisions only the owner can take.

Nothing here changes `docs/design/00-decision-register.md`. The SDK lane is shipping only the non-breaking items in §6 while this waits.

---

## 0. The surface today, in numbers

| Part | Size |
|---|---|
| PHP package `whity/plugin-sdk` (`sdk/`) | 237 files, 101 interfaces, 132 classes/traits, 121 minors since 1.0 (one per feature) |
| Contribution points (`Plugin*Interface`, what a plugin declares) | 43 |
| Host services (what a plugin calls) | about 30 |
| Hook events (`Hooks\Events`) | 121 constants |
| Validators shipped in the SDK (`Manifest\*`, `BlockValidator`) | 15, about 6,800 lines |
| TypeScript packages (`packages/`) | `api-client` 0.4.0, `features` 0.4.1, `ui` 1.0.0, `tokens` 1.1.0, `cli` 0.2.0 (+ private `spa-harness`) |
| Plugin docs | `sdk/README.md`, `docs/wiki/Plugin-*.md` (11 pages), `HOOK_SYSTEM.md`, `Plugin-SDK-Coverage.md` (generated from `docs/sdk-coverage/coverage.json`) |

What the published plugins use (`whity-org/whity-plugins`, which also holds the store, at its 2026-11-18 head): 87 distinct SDK names. The heaviest are `Http\Request`/`Response`, `MigrationInterface`, `PluginInterface`, `Billing\*` (Whity Pay), `Setup\WorkspaceSetup`, `Testing\RecordedHttpClient`, `BlockValidator`. The full list is the guard for §4: nothing on it is removed in 1.x.

The SDK grew one minor per feature across many sessions, each correct on its own. The result is a big, careful, well-tested surface with **four ways to get a service, three ways to pin a workspace, six shapes of bilingual text, four pagination shapes and no common error type**. A plugin developer has to read the docblock of every service to learn its rules. That is what this proposal fixes.

---

## 1. Redundancies and overlaps

### 1.1 Seven "scoped store" pairs that are one pattern

`PluginSettings`→`PluginSettingsStore`, `PluginSecrets`→`SecretStore`, `PluginFiles`→`PluginFileStore`, `PluginNotifier`→`Notifier`, `PluginJobQueue`→`JobQueue`, `PluginAudit`→`AuditTrail`, `PluginDocuments`→`PluginDocumentStore`.

Each pair is a factory with a single `for(PluginInterface $plugin)` method and the store it returns. They are the right design (the host binds plugin identity), but:
- They are 14 interfaces where 7 accessors would do.
- The store names don't follow one rule (`SecretStore`, `Notifier`, `AuditTrail`, `PluginFileStore`).
- `SecretStore` alone still takes `int $tenantId` after `for($this)`.

**Proposal:** one entry point, `Whity\Sdk\PluginContext`:

```php
$ctx = \Whity\app(PluginContext::class)->for($this);
$ctx->settings()->get('sync_interval');
$ctx->secrets()->get('api_key');          // workspace from the context
$ctx->files()->put('exports/march.csv', $csv, 'text/csv');
$ctx->notify()->toRole('order.shipped', $roleId, $notice);
$ctx->jobs()->enqueue('catalog.sync');
$ctx->audit()->record('order.refunded', 'order', $id);
$ctx->documents()->issue($request);
$ctx->http();                             // HttpClient with the deployment's policy
$ctx->workspaceId();                      // the one the call runs in, or null
```

In 1.x it is additive: `PluginContext` returns the existing store interfaces, and the seven factories stay (marked `@deprecated` with "use `PluginContext::for()`"). In 2.0 the factories go. This is decision **D3**.

### 1.2 Two document-issuing services

`Documents\Documents::issue()/preflight()` (as the acting person) and `PluginDocumentStore::issue()/preflight()` (the same, plus "recorded as this plugin's" and QR/tracking) take the same `IssueRequest`. A plugin can't tell which to use without reading both docblocks.
**Proposal:** `PluginContext::documents()` is the one way for plugins; `Documents` stays for host-side and agent tooling; the docs say so. No break.

### 1.3 Home card vs dashboard widgets

`PluginHomeCardInterface` (1.62) is one card with a number, lines and a chart. `PluginDashboardInterface` (1.115) widgets of kind `stat`/`list`/`chart` cover the same ground, and `dashboard_layout` already orders home cards with widgets. Its docblock still says a person "can't rearrange it", which H.5 replaced.
**Proposal:** deprecate the home card in favour of a `stat` widget, and have the host render an existing home card as a widget. No published plugin implements it. Decision **D5**, because home cards are in the register (H.4).

### 1.4 Four MCP contribution interfaces

`PluginMcpInterface` (prompts only, despite its name), `PluginMcpToolsInterface`, `PluginMcpToolsetsInterface`, `PluginMcpResourcesInterface`. **2.0:** one `PluginMcpInterface::getMcp(): {tools, toolsets, resources, prompts}`. 1.x: an alias `PluginMcpPromptsInterface` for the misnamed one.

### 1.5 Four job contracts

`JobInterface` (root), `PluginJobsInterface` (root, `getJobs` + `getSubmittableJobs`), `Jobs\PluginScheduledJobsInterface` and `Jobs\JobQueue`. **2.0:** `getJobs()` declares each job with optional `schedule` and `submittable` keys, all under `Jobs\`.

### 1.6 Two plugin declaration paths that cover different ground

`plugin.json` + `Manifest\ManifestPlugin` answers only 9 of the 43 contribution points (identity, requirements, roles, frontend, tables, data types, entitlements, agent presets, home card). Settings, notifications, dashboard, onboarding, contact fields, templates, jobs and the rest exist only as PHP methods returning untyped arrays, each checked by its own validator.
**Proposal:** manifest-first. Every *declarative* contribution point gets a `plugin.json` key with a JSON Schema generated from its validator. PHP stays for what is code (route handlers, hooks, providers such as `BillingPortal`, `MessageChannel`). The Builder, the CLI (`whity plugin validate`) and the store all check the same schema offline. Additive in 1.x. Decision **D4**.

### 1.7 Everything else that is duplicated

| Overlap | Where | Fold into |
|---|---|---|
| Block types defined three times | PHP `BlockContract` (57), `web/lib/plugin-features.ts` (57), desktop template `types.ts` (51, drifted) | Types generated from `BlockContract` into a published npm package (§3.3) |
| Page walker copied | `web/lib/api/fetch-all-pages.ts` and its desktop copy | the same npm package |
| Session refresh written twice | `packages/api-client/src/index.ts:77-97` and `web/lib/api/client.ts:155-195` | `api-client` |
| `Transport` means two things | `features/src/record/transport.ts` vs `web/lib/api/client.ts` | one `Transport` in `api-client` |
| Bilingual text has six shapes | `LocalizedText` (ui), `Localized` (editor), `LocalizedMap` (forms), `LocalizedTextValue` (web), `PublicLocalizedText` (OpenAPI), `nameAr`/`name_ar` fields; PHP has `I18n\LocalizedText` and `BlockText` | one `{en, ar, …}` shape, one picker, in PHP and TS |
| Two `useCurrentLanguage` hooks, two bilingual inputs | `features/forms` vs `features/i18n`; `ui/bilingual-input` vs `features/forms/bilingual-field` | keep the i18n hook and the ui input |
| Tokens pasted into the web app | `web/app/globals.css` (165 values) vs `@whity/tokens/css` | import the package |
| Changelog text in five places | `Sdk.php` docblock (1,400 lines), a 640-line assertion message in `SdkPackageContractTest`, the README table, root `CHANGELOG.md`, `coverage.json` `since` | `sdk/CHANGELOG.md`, read by the scripts (§4.1) |

---

## 2. Inconsistencies

### 2.1 Workspace pinning: three rules

| Rule | Services |
|---|---|
| Implicit: the workspace the request or job runs in | Settings, Files, Documents (plugin), Audit, Jobs, Notifier, Agents, McpConnections, Templates, WorkspaceSetup, DocumentRenderer |
| Explicit `int $tenantId` argument | **Secrets** (even after `for($this)`), Contacts, TimeOff, Away, Bookings, CalendarEvents, WorkspaceEmails, BillingEmails, WorkspaceAddons, SequenceAllocator, DataType*, PermissionResolver |
| A `ServiceCall` or the plugin passed per call | LinkedPeople, CustomerMessages |

The newest services (1.119–1.120) went explicit, while the stores stayed implicit. An argument that names the workspace is one a caller can get wrong, and the host then has to check it on every call. **Proposal (D2):** implicit is the rule for anything a plugin calls. Explicit stays only for deployment-wide host jobs, and in 2.0 it moves to a `Whity\Sdk\Host\…` namespace that plugins can't resolve (it ties into #1693, least privilege). In 1.x the explicit services get an implicit twin through `PluginContext` (for example `$ctx->contacts()->get($id)`), and a mismatched `$tenantId` is refused by the host.

### 2.2 Errors

- No common base. `ContactRejected`, `TimeOffRejected`, `DocumentNotIssuedException`, `RenderRejectedException`, `RenderUnavailableException`, `BillingPortalException`, `HookVetoException`, `MultipartException`, `InvalidManifestException` and `HttpFailure` all extend `\RuntimeException` directly, with two naming styles (`*Rejected`, `*Exception`).
- "No workspace in scope" is a `\RuntimeException` in Settings, Notifier, Jobs and Audit, but a `\LogicException` in Files and Documents.
- Some refusals carry a stable `reason` code (TimeOff, Contacts) and others only a message.

**1.x (shipping):** a marker interface `Whity\Sdk\SdkException`, implemented by every SDK exception, so a plugin can catch "anything the SDK refused". **2.0:** `NoWorkspaceInScope` is one class, every refusal carries `reason`, and the names follow `*Rejected` for "the host said no" and `*Unavailable` for "try later".

### 2.3 Naming and placement

- **Placement.** 31 `Plugin*Interface` sit in the root namespace and 12 in domain folders (`DataType\`, `Ou\`, `TimeWindow\`, `Routing\`, `Rbac\`, `Secrets\`, `Settings\`, `Tenant\`, `Health\`, `Notification\`, `Jobs\`). **2.0:** all in `Whity\Sdk\Contribute\`; 1.x gets aliases there now (D6).
- **Method names.** Most are `get*(): array`. The outliers are `billingEmailsPurpose()`, `busyTimes()`, `issuesInBackground()`, `suppressesDerivedMcpTools()` and `getThemeOverrideRoute()`, and they mix declarations with providers (`getBillingPortal(): BillingPortal`).
- **Names.** "tenant" vs "workspace" in code and events: `tenant.*` vs `workspace.owner_*`, `TenantContext`, `$tenantId`. The product word is workspace. **2.0:** the SDK says workspace everywhere; `tenant` stays inside core.
- **Hook events** (121): mixed tense (`navigation.register`, `worker.boot`, `time_window.close_report`), dots vs underscores for the same shape (`user.membership.added` vs `ou.role_assigned`), two roots for one thing (`user.membership.*` vs `membership.customer_*`; `datatype.*` vs `data_type.`), and uneven `.async` twins. Whether an event is a filter, an action or a veto exists only in prose, and the `Events.php` header gets it wrong for `*.updating`. **Proposal:** an `EventCatalogue` with metadata per event (kind: `observe` | `filter` | `veto`, payload keys, `async` twin, since). It is generated into the wiki and checked by a test. 2.0 renames to `<entity>.<verb-ing|verb-ed>` with old names aliased for one major.
- **Declared but never fired.** `submission.reviewed` (and `.async`) is advertised in the README and never dispatched. **Fired but not declared:** `notification.dispatch`, an internal command hook, not a plugin event, which stays out of the catalogue. Several host classes copy event strings instead of using `Events`.

### 2.4 Pagination

There are four shapes. `limit/offset` (Contacts, Agents) vs `beforeId` (AuditTrail) vs `InboxCursor` keyset vs the REST API's `page/per_page` with `perPage/totalPages` in the response, and features' `RoleListPage{roles,total,totalPages}`.
**Proposal:** one `Whity\Sdk\Page` (`items`, `next` opaque cursor, optional `total`) for every list method added from now on; existing ones keep their signatures in 1.x.

### 2.5 Returns

Arrays vs value objects: `Agents::list()`, `TimeOff::forPerson()` and `Away::at()` return arrays, while `Contacts` returns `Contact` and `JobQueue` returns `JobStatus`. **Rule from now on:** value objects, `final` and `readonly`, with `toArray()`.

### 2.6 Versions

- The SDK is 1.121, OpenAPI `info.version` is 0.2.30, and the npm packages are 0.2 to 1.1. Nothing says which SDK a package targets.
- The SDK minor is pinned in 7 places.
- Examples still show `^1.0`/`^1.1` constraints.
- The README heading says "Contract surface (v1.44.0)".

**Proposal (D7):** SDK minors per **core release**, not per feature. A release that adds three contracts is one minor. The npm plugin package versions in lockstep with the PHP SDK.

### 2.7 Host bindings outside the web request

The queue worker binds only the plugin stores, the document services and `CustomerMessages`. The events relay binds only `Database` and `HookManager`. So Contacts, TimeOff, Settings, Agents and others are likely missing inside jobs and async listeners, where docblocks say they work. This is unverified at runtime; a contract test (§6) will settle it, and any gap it finds is a bug fix, not a design change.

---

## 3. Gaps against real customization

### 3.1 The open SDK issues, ranked for the beta

No proposal on this list breaks the 1.x contract. Sizes are S/M/L, and "design" means it needs a written design first.

| # | Ask | Size | Why it's at this rank |
|---|---|---|---|
| 1 | **#2151** HttpClient headers + address policy | S–M | Safe, small, closes an SSRF gap every URL-fetching plugin would re-implement. **Done in #2161 (SDK 1.122).** |
| 2 | **#1681** tabs, sections and actions on core record pages | L, design | Where "customization" shows for every vertical (Salons, Front Office, ERP). The biggest single win. |
| 3 | **#1914 → #1680** member/unit directory readers, then the full people readers and pickers | M → L | Without them plugins call the REST API or core tables. #1914's narrow readers are the first slice. |
| 4 | **#1683** read entitlements, consume meters, `requiredEntitlement` on routes | M | Paid third-party plugins need it at the beta; doesn't wait for #1687. |
| 5 | **#1728** route subjects + `Routes` service | L | Approvals on plugin records are the most-asked workflow; the rest of #1717 is in. |
| 6 | **#1686** logger, error reporting, rate limits, lifecycle | M | Beta developers need it to support their customers. Low risk. |
| 7 | **#1910** read form submissions | M | Read-only; unlocks #1682. |
| 8 | **#1913** resource grants write/list | M | Turns `ResourceScope::reach()` into a usable authority model. |
| 9 | **#1912** FlowDocument blocks (image, QR, list, alignment…) | M | Every issuing plugin wants them; QR already lives in `Print\QrCode`. |
| 10 | **#2137** config schema for plugin routing rule kinds | S–M | Finishes 1.121's read-only step. |
| 11 | **#1684** document reader, withdraw, revise | M–L | Withdraw/revise exist over HTTP; the reader needs the visibility design. |
| 12 | **#1682** search and report sources | L, design | High value, waits on #1910. |
| 13 | **#1688** isolated origin for store-plugin UI | L, design | The gate for third-party UI in the store. |
| 14 | **#1693** least-privilege capabilities | L, design | Security baseline before the store opens widely; pairs with D2. |
| 15 | **#1687** charging through Whity Pay | L, design | Moves money; #1683 covers the beta. |
| 16 | #1692, #1911 routing/meeting events, meetings and quorum | M / L | University and committee use; #1911 needs a quorum decision. |
| 17 | #1691 custom form field types | L, design | Forms-heavy plugins. |
| 18 | #1689 editor blocks and document themes | L, design | See §5.1. |
| 19 | #1690 plugin identity providers | L, design | Identity is Whity ID's. |
| 20 | #1694 relations, delegations, CLI commands | L | The issue rates itself low. |

**Recommended beta set (0.2.32 → 0.2.39):** 1–7 plus 10, with 13 and 14 designed (not built) so the store's rules are known before the beta.

### 3.2 Gaps no issue covers yet

- **Plugin-to-plugin services.** Today plugins talk only through hooks, plus a few provider slots core knows by name (`BillingPortal`, `LicenceSales`, `MessageChannel`, `BookingPaymentStep`, `ProvidedBy`). An ERP family, or Salons decrementing Front Office stock, needs a plugin to **publish a typed service** that another resolves when it is on in the workspace: `provides: ['inventory.stock' => StockLevels::class]` and `$ctx->service(StockLevels::class)`. It would generalize the provider slots. (Issue to file; it's §5.2's foundation.)
- **A real `PluginContext::http()`.** Outbound HTTP with the deployment's policy and a per-plugin allowlist of hosts declared in the manifest. #2151 made the pieces.
- **TypeScript for plugin frontends** (§3.3).
- **Local dev loop.** `whity plugin new / validate / test` against the manifest schema and the offline conformance kits. The CLI has the commands for the API, not for plugins.

### 3.3 TypeScript: one published plugin package

There is no package a plugin's frontend can depend on. The proposal is `@whity/plugin-sdk` (npm), versioned with the PHP SDK:
- **Block types and a typed builder**, generated from `BlockContract` so the three hand-kept copies go. The web app and the desktop template import them.
- **A client for the plugin's own routes** (`pluginApi('acme').get('/orders')`) on `api-client`'s transport, plus `fetchAllPages`.
- **Feature-descriptor types** for `PluginFrontendInterface` (crud/action/blocks/embed) and the embed message bridge (with #1688).
- **One `LocalizedText`** and its picker.
- **Test helpers:** a mock transport and a block-tree renderer harness.

`@whity/features` and `@whity/ui` stay the app's own libraries. Their 25 ui subpaths and two root entry points that nothing imports become private (D8).

---

## 4. Deprecation plan

### 4.1 Stays in 1.x, marked `@deprecated` with a shim (no plugin breaks)

| Today | Replacement | Shim |
|---|---|---|
| `PluginSettings`, `PluginSecrets`, `PluginFiles`, `PluginNotifier`, `PluginJobQueue`, `PluginAudit`, `PluginDocuments` (`->for($this)`) | `PluginContext::for($this)->settings()` … | Factories keep working; `PluginContext` returns the same stores |
| `SecretStore::get/set/delete(int $tenantId, …)` | `$ctx->secrets()->get($key)` | Host refuses a `$tenantId` that isn't the context's |
| `PluginHomeCardInterface` (if D5) | dashboard `stat` widget | Host renders a home card as a widget |
| `PluginMcpInterface` (prompts) | `Contribute\PluginMcpPromptsInterface` alias | `class_alias` both ways |
| Root and domain-folder `Plugin*Interface` names | `Whity\Sdk\Contribute\*` aliases (D6) | `class_alias`; `instanceof` works with either |
| Event names that break the pattern | catalogue names | Host dispatches both names until 2.0 |
| `Render\DocumentRenderer::…` (already `@deprecated` 1.49) | `Documents::issue()` | unchanged |
| The `Sdk.php` changelog docblock | `sdk/CHANGELOG.md` | `scripts/sdk-coverage.php` and `ci-sdk-version-collision.php` read the new file |

### 4.2 What 2.0 would break

1. The seven factory interfaces go; `PluginContext` is the one way.
2. Explicit `int $tenantId` disappears from plugin-callable services; host-only services move to `Whity\Sdk\Host\` and plugins can't resolve them (#1693).
3. `Plugin*Interface` live only in `Contribute\`; the MCP interfaces become one, and so do the job interfaces.
4. Exceptions: one `NoWorkspaceInScope`, `*Rejected`/`*Unavailable` names, a `reason` on every refusal.
5. Event renames take effect; the old names stop.
6. List methods return `Page`.
7. Every declarative contribution point is in `plugin.json`. PHP `get*()` arrays still work through `ManifestPlugin` overrides, but the schema is the contract.
8. The PHP floor follows core (8.4.27+).
9. `seats.enforcement` (the open owner question) lands here if it is a break.

**Cost today:** all plugins that exist are ours (whity-plugins: Whity Pay, Appointments, Salons, Front Office, WorkspaceTemplates, store, theme-creator, demos), and there are no real customers yet. A 2.0 before the public beta is cheap now and expensive after it.

### 4.3 Migration guide, outline

`docs/wiki/Plugin-SDK-Migrating-to-2.md`:
1. Bump the constraint to `^2.0`; run `whity plugin upgrade --check` (a rector-style codemod for the mechanical renames).
2. Services: replace `\Whity\app(PluginX::class)->for($this)` with `PluginContext`, and drop `$tenantId` arguments.
3. Contribution interfaces: change `use` lines to `Contribute\`; move declarative arrays into `plugin.json` (the CLI prints the JSON for you).
4. Events: the rename table, with "both names fire in 1.x since 1.12x" so a plugin can move early.
5. Exceptions: catch `SdkException`, read `reason`.
6. Lists: `Page`.
7. Frontend: `@whity/plugin-sdk` instead of copied types.
8. Tests: the conformance kits, plus the new `PluginContext` fake.
9. A checklist per first-party plugin, with the PR that moved it.

---

## 5. Themes and inventory (decided elsewhere)

This section first sketched both. The owner decided both on 19 Nov 2026 in their own specs, so the sketches are replaced by pointers. The SDK follows those specs and does not define a second schema or a second service set.

### 5.1 Themes: TH.1–TH.14, `docs/design/30-themes.md` (#2158)

- A theme is data: one JSON file with the schema `schemas/theme/v1.json`, tokens only. It is one declarative format, and the SDK copies it rather than defining its own.
- Plugins ship theme files through a new SDK interface, `PluginThemesInterface::themes(): list<string>` (paths inside the plugin). Core validates each file on install.
- `PluginThemeInterface` (1.12, a route that returns colour overrides) is **deprecated**. While it lives, the host reads its overrides as a theme that has only `colour.overrides`. It goes in 2.0.
- Under D4 (manifest-first), `plugin.json` gets a `themes` key that lists the same files.
- The SDK minor comes from this lane under D7. The theme lane (theme-0231) builds the interface and validator, and the version bump goes through the SDK lane.

### 5.2 Inventory: IV.1–IV.24, `docs/design/31-inventory.md` (#2162)

Inventory is **in core**, not a plugin family. The SDK gets the services `Whity\Sdk\Inventory\{Items, Locations, Stock, Lots, Orders, Operations, Units}`, value objects (`Quantity`, `Item`, `Variant`, `Move`, …), the `stock.*` and `inventory.*` events, and extension points:
- `PluginCostingMethodInterface`
- `PluginAccountingExportInterface`
- `PluginStockOperationTypesInterface`
- `PluginItemFieldsInterface`
- `PluginBarcodeParserInterface`
- `PluginPriceListInterface`
- `PluginReorderPolicyInterface`

ERP plugins (costing, accounting, POS) build on those.

Two things from the original sketch still apply, through the inventory spec:
- **Plugin-to-plugin services (§3.2).** Appointments reserving consumables or a POS basket now goes through core's `Stock` service instead. The general mechanism is still useful for plugin families, so it stays in the 2.0 plan as a design item.
- **Workspace pinning (D2).** The inventory spec's §6.1 signatures take `tenantId` the way `Contacts` does. Under D2, plugins reach them through `PluginContext` (`$ctx->inventory()->stock()->available($variantId, $locationId)`) and the workspace comes from the context. The explicit-`tenantId` form is the host-side one, and it moves to `Whity\Sdk\Host\` in 2.0. The inventory lane is told this so phase 1 can ship the context form directly.

---

## 6. What the SDK lane ships

| Item | SDK | Status |
|---|---|---|
| #2151: response headers, `AddressPolicy` | 1.122 | #2161 |
| `SdkException` marker, full hook catalogue, the `Plugin-SDK-Reference` page, `SdkSurfaceContractTest` | 1.123 | #2165 |
| `PluginContext` (D3), the binding test for each entry point, `submission.reviewed` | 1.124 (0.2.31's minor under D7) | next |
| The 2.0 plan with the D9 issues | docs | [Plugin-SDK-2.0-Plan](Plugin-SDK-2.0-Plan.md) |

Not touched by this lane: performance, security and CI files (the other v0.2.31 lanes). Security reports go through SECURITY.md, never here; where a design choice here (D2, #1693, #1688) also hardens the SDK, the security lane is told separately.

---

## 7. Decisions (owner, 19 Nov 2026)

| # | Decision | Owner's answer |
|---|---|---|
| **D1** | SDK 2.0 as the public beta's SDK | **"2.0 at the beta (Recommended)".** 2.0 ships at v0.2.40, and the 1.x shims are kept through 2.x's first release. `seats.enforcement` folds into it. |
| **D2** | Implicit workspace pinning for everything a plugin calls; explicit `$tenantId` only host-side | **Accepted** ("Accept all (Recommended)", D2, D3, D4, D6, D8) |
| **D3** | One entry point, `PluginContext::for($this)`; the seven factories deprecated | **Accepted** |
| **D4** | Manifest-first `plugin.json` with a published JSON Schema | **Accepted** |
| **D5** | Home card folded into dashboard widgets | **"Fold into widgets (Recommended)"**, register H.4 updated |
| **D6** | `Whity\Sdk\Contribute\` namespace; "workspace" replaces "tenant" in SDK names | **Accepted** |
| **D7** | One SDK minor per core release; `sdk/CHANGELOG.md`; npm package in lockstep | **Accepted** ("Accept both (Recommended)", D7 and D9). The SDK lane owns the number; other lanes add to the current release's minor through it. 0.2.31 is **1.124** (1.122 and 1.123 were already used). |
| **D8** | `@whity/plugin-sdk` npm package; unused ui/features subpaths private | **Accepted** |
| **D9** | Beta scope: #2151 (done), #1681, #1914→#1680, #1683, #1728, #1686, #1910, #2137 built; #1688 and #1693 designed | **Accepted** |
| **D10** | Themes | **Superseded** by TH.1–TH.14 (§5.1): one schema `schemas/theme/v1.json`, tokens only; `PluginThemesInterface`; `PluginThemeInterface` deprecated |
| **D11** | Inventory / ERP | **Superseded** by IV.1–IV.24 (§5.2): inventory in core with SDK services and extension points; ERP plugins (costing, accounting, POS) build on them |
