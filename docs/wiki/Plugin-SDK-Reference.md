# Plugin SDK reference: services, contribution points, errors

One map of the `whity/plugin-sdk` surface (SDK 1.124). It covers what a plugin **calls** (host services), what it **declares** (contribution points), and what it **implements** for the host (providers). Each interface's docblock is the full contract. This page tells you which one to open.

`tests/Sdk/SdkSurfaceContractTest.php` fails when an SDK interface is named on no wiki page, so a new contract arrives with its line here or on its own page.

See also: [Plugin-Development](Plugin-Development.md) (the walkthrough), [HOOK_SYSTEM](HOOK_SYSTEM.md) (every event), [Plugin-SDK-Coverage](Plugin-SDK-Coverage.md) (what is covered and what is missing), and [Plugin-SDK-Streamline-Proposal](Plugin-SDK-Streamline-Proposal.md) (where this surface is going).

## Host services: what a plugin calls

Resolve each one with `\Whity\app(X::class)` in a route handler, an MCP tool handler or a job. The host implements them; a plugin never does.

**Which workspace.** There are three rules today:
- **current**: the workspace the request or job runs in. No argument can name another one, and without one the call throws.
- **argument**: the call takes `int $tenantId`. Pass the workspace the host authorized the call in (the request's, or `McpToolContext::$tenantId`), never one a caller supplied.
- **deployment**: the call is not about one workspace.

### Start here: `PluginContext` (1.124)

One entry point for everything that acts as your plugin (decision D3):

```php
$ctx = \Whity\app(\Whity\Sdk\PluginContext::class)->for($this);   // a Context\PluginScope
$ctx->settings()->get('sync_interval');
$ctx->secrets()->set('api_key', $key);       // Secrets\WorkspaceSecrets: no workspace argument
$ctx->files()->put('exports/march.csv', $csv, 'text/csv');
$ctx->documents()->issue($request);
$ctx->notify()->toRole('order.shipped', $roleId, $notice);
$ctx->jobs()->enqueue('catalog.sync');
$ctx->audit()->record('order.refunded', 'order', $orderId);
$ctx->http(AddressPolicy::publicOnly())->send('GET', $urlSomeoneTyped, []);
$ctx->themes()->current()['light']['primary'] ?? null;   // 1.125: Theme\WorkspaceTheme, read-only
$ctx->workspaceId();                          // null outside a workspace
```

- **The workspace comes from the context (D2).** Every store acts in the workspace the request or job runs in, and none takes a workspace, so none can name another.
- **A missing service fails when you use it.** A service this host doesn't provide throws `Context\ServiceUnavailable` (an `SdkException`) when you ask for it, not when you load.
- **The context is bound at every entry point** (web, CLI, `queue:work`, `events:relay`): since WHIT-1247 each one boots the web kernel's own wiring, so a job or an async listener reaches every service a route does. `tests/Core/Plugin/PluginServiceBindingsTest` checks this.

### Your plugin's own stores: `->for($this)` (deprecated 1.124)

These are the factories `PluginContext` uses. They keep working through SDK 2.0.x and go in 2.1 ([plan](Plugin-SDK-2.0-Plan.md)). Pass the plugin itself; an object the host didn't load is refused. Everything a store holds is kept apart per plugin and per workspace.

| Factory → store | What for | Workspace | Since |
|---|---|---|---|
| `Settings\PluginSettings` → `Settings\PluginSettingsStore` | Read and set the settings you declared (`get`, `all`, `set`, `reset`). Keys are bare. | current | 1.88 |
| `Secrets\PluginSecrets` → `Secrets\SecretStore` | API keys and signing secrets: encrypted, never read back on screens or in the API. | argument | 1.60 |
| `Storage\PluginFiles` → `Storage\PluginFileStore` | Uploads and exports under plain relative paths (`put`, `get`, `exists`, `list`, `delete`). `Storage\PluginFileController` serves them. | current | 1.63 |
| `Documents\PluginDocuments` → `Documents\PluginDocumentStore` | Issue documents recorded as yours, then QR verification URLs and tracking for those documents only. | current | 1.63 |
| `Notification\PluginNotifier` → `Notification\Notifier` | Notify a person, a role or a unit with a notice type you declared. Preferences apply. | current | WHIT-1030 |
| `Jobs\PluginJobQueue` → `Jobs\JobQueue` | Enqueue your own jobs (idempotency key, delay) and read their `JobStatus`. | current | WHIT-1031 |
| `Audit\PluginAudit` → `Audit\AuditTrail` | Record and read your audit entries. The host attributes actor, IP and workspace. | current | WHIT-1032 |

### Core data and actions

| Service | What for | Workspace | Since |
|---|---|---|---|
| `Contacts\Contacts` | List, get, find by field, create and update contacts as the acting person; refusals throw `ContactRejected`. | argument | 1.119 |
| `Calendar\TimeOff` | Put time off on a person's calendar, idempotent by your reference; refusals throw `TimeOffRejected`. | argument | 1.120 |
| `Delegation\Away` | Is a person away at a moment, and who stands in. | argument | 1.120 |
| `Calendar\Bookings`, `Calendar\CalendarEvents` | Confirm or release a pending booking after payment; mirror external busy events. | argument | 1.98 |
| `Documents\Documents`, `Documents\Templates` | Issue (one, many, preflight) and read templates as the acting person. A plugin's own documents go through `PluginDocuments`. | current | 1.56, 1.82 |
| `Render\DocumentRenderer` | Render a `FlowDocument` to PDF. | current | 1.41 |
| `Agents\Agents` | The workspace's agents and their activity. | current | 1.75 |
| `Mcp\McpConnections` | The workspace's MCP connections. | current | 1.79 |
| `Setup\WorkspaceSetup` | Call core routes as the acting person during a guided setup. | current | 1.108 |
| `Messaging\CustomerMessages` | Send a customer a message through the workspace's channels. | argument | 1.99 |
| `Mail\WorkspaceEmails`, `Mail\BillingEmails` | Send a workspace-branded email; billing emails for a billing plugin. | argument | 1.100, 1.102 |
| `Rbac\PermissionResolver`, `Rbac\ResourceScope` | Ask the same permission question the host's middleware answers, and what a person reaches. | argument | 1.22, 1.74 |
| `DataType\DataTypeGuard`, `DataType\DataTypeLifecycle` | Ask whether a record of a declared data type may be referenced, and move it through its lifecycle. | argument | 1.19, 1.23 |
| `Sql\SequenceAllocator` | Gap-free counters for document numbers and the like. | argument | — |
| `Identity\LinkedPeople` | The workspaces a signed-in person is linked to, through a `Http\ServiceCall`. | per call | 1.84 |
| `Billing\PlanCatalogue`, `Billing\WorkspaceAddons`, `Billing\AccessRefresh`, `Licensing\StoreLicence` | Plans and prices, add-on grants, refresh a subject's access, a store licence's state. | deployment / argument | 1.84–1.107 |
| `Http\Outbound\HttpClient` | Call another server. Pass `StreamHttpClient` (with `AddressPolicy::publicOnly()` for URLs people type) and `Testing\RecordedHttpClient` in tests. | — | 1.114, 1.122 |

### Inventory (1.127)

Core's items, places and stock (WHIT-1232, design section 31). Through the context, in the workspace the call runs in:

```php
$inventory = $ctx->inventory();                                  // Context\InventoryScope
$match = $inventory->items()->findByBarcode($scanned);           // item, variant_id, unit
$customer = $inventory->locations()->virtual('customer');
$inventory->stock()->move(new MoveRequest(
    actingProfileId: $cashierId,                                 // must hold stock:adjust
    lines: [new MoveLine($match['variant_id'], '2', $shopId, $customer->id, unit: $match['unit'])],
    sourceType: 'sale',                                          // stored as "<your plugin>.sale"
    idempotencyKey: 'receipt-' . $receiptId,
));
```

| Service | What for | Workspace | Since |
|---|---|---|---|
| `Inventory\Items` | List, get, find by SKU or barcode, create, update and archive items with variants, units and barcodes, as the acting person (`inventory:write`, the plan's item limit). | current | 1.127 |
| `Inventory\Locations` | Real places and the virtual ones (supplier, customer, loss, found, production, consumed, opening, transit); create a place. | current | 1.127 |
| `Inventory\Stock` | Levels, available and the append-only ledger. `move()` and `reverse()` post as your plugin, idempotent by key, only through `$ctx->inventory()->stock()`; the container copy reads and refuses writes (`use_plugin_context`). | current | 1.127 |
| `Inventory\Units` | The workspace's units and exact conversions (`Inventory\Quantity`, never floats). | current | 1.127 |
| `Inventory\Operations` | Adjustments with a reason, counts and transfers, each an `Inventory\Operation` with its lines. Through `$ctx->inventory()->operations()`, bound to your plugin; the container copy reads. Above the workspace's value an adjustment or a count's differences wait for approval. | current | 1.128 |
| `Inventory\Orders` | Purchase orders: draft for a supplier contact, send (the workspace's route or approved at once), receive in parts at the order's cost, cancel. Through `$ctx->inventory()->orders()`, bound to your plugin; the container copy reads. | current | 1.129 |

`Context\InventoryScope` (`$ctx->inventory()`) holds the six (`operations()` since 1.128, `orders()` since 1.129). Refusals throw `Inventory\InventoryRejected` with a stable `reason`: `no_workspace`, `forbidden`, `not_found`, `invalid`, `conflict`, `vetoed`, `use_plugin_context`.

## Contribution points: what a plugin declares

Implement the interface next to `PluginInterface`. Each one is optional, and the host skips plugins that don't implement it. A declaration the host refuses is dropped with a logged reason, and the plugin keeps loading unless the page says otherwise. `Manifest\ManifestPlugin` answers some of them from `plugin.json`.

| Interface | Declares | More |
|---|---|---|
| `PluginInterface` | Name, version, routes, permissions, hooks, migrations | [Plugin-Development](Plugin-Development.md) |
| `PluginRequirementsInterface` | SDK and core constraints, plugin dependencies | sdk/README |
| `PluginDisplayNameInterface` | The name people read, `{en, ar}` | sdk/README |
| `PluginAccessInterface`, `PluginEntitlementsInterface` | How the plugin is sold (free, paid, per seat) and the entitlements it checks | [Workspace-Plugins](Workspace-Plugins.md) |
| `PluginRolesInterface`, `PluginCustomerPermissionsInterface` | Roles and their permissions; what customers may do | Plugin-Development |
| `PluginFrontendInterface` | Screens: crud, action, blocks, embed | [Plugin-UI-Blocks](Plugin-UI-Blocks.md) |
| `PluginDashboardInterface`, `PluginHomeCardInterface` | Dashboard setup points and widgets; the older single home card (deprecated 1.124, D5: use a `stat` widget) | [Plugin-Dashboard](Plugin-Dashboard.md) |
| `PluginOnboardingInterface` | A guided setup | sdk/README |
| `PluginWorkspaceTemplatesInterface` | Workspace templates | [Plugin-Workspace-Templates](Plugin-Workspace-Templates.md) |
| `PluginWorkspaceLifecycleInterface` | Called when the plugin is turned on or off in a workspace | Workspace-Plugins |
| `Settings\PluginSettingsInterface`, `Secrets\PluginSecretFieldsInterface` | Settings, and the secret fields an admin fills | Plugin-Development |
| `Tenant\PluginTablesInterface` | The tables you own, for tenant-isolation checks | sdk/README |
| `DataType\PluginDataTypesInterface` | Records with a lifecycle and delete guards | [Plugin-Data-Types](Plugin-Data-Types.md) |
| `PluginContactFieldsInterface` | Your own fields on core contacts | sdk/README |
| `PluginDocumentsInterface`, `PluginDocumentBlocksInterface` | Template slots, field providers (`Documents\DocumentFieldProvider`), document blocks | Plugin-Development |
| `PluginEventsInterface` | Which of your own events the audit trail records | [HOOK_SYSTEM](HOOK_SYSTEM.md) |
| `Notification\PluginNotificationsInterface` | Notice types you send | Plugin-Development |
| `PluginJobsInterface`, `Jobs\PluginScheduledJobsInterface` | Jobs (each a `JobInterface`) and their schedules | Plugin-Development |
| `Health\PluginHealthProbesInterface` | Health probes | [Plugin-Operations](Plugin-Operations.md) |
| `PluginMcpToolsInterface`, `PluginMcpToolsetsInterface`, `PluginMcpResourcesInterface`, `PluginMcpInterface` | MCP tools, toolsets, resources, and prompts (`PluginMcpInterface` declares prompts) | Plugin-Development |
| `PluginAgentsInterface`, `PluginAgentPresetsInterface` | Agents (a persona, toolsets, where a person's grant starts) under your own root; agent presets | Plugin-Development |
| `PluginInboxSourcesInterface` | Inbox sources (`Inbox\InboxSource`, `Inbox\KeysetInboxSource`) | Plugin-Development |
| `Contribute\PluginThemesInterface` | Theme files (the format of `schemas/theme/v1.json`), each checked on load; they appear in the workspace's theme picker, where a person chooses one (1.125). Read the result with `$ctx->themes()`, a `Theme\WorkspaceTheme`. | [30-themes](../design/30-themes.md) |
| `PluginThemeInterface` | **Deprecated 1.125**, use `PluginThemesInterface`. One of your own GET routes that answers design-token overrides (`{"data": {"primary": "#rrggbb"}}`). The host validates every key and value and falls back to no overrides on any error. | this page |
| `Rbac\PluginResourceTypesInterface` | Resource types roles can be granted on | Plugin-Development |
| `Ou\PluginOuTypesInterface` | Kinds of organisational unit (campus, faculty, clinic), so a consumer can ask for "every unit of kind X" | this page |
| `Routing\PluginRoutingRulesInterface` | Routing rule kinds for document routes, each a `Routing\RoutingRuleResolverInterface`; one that also implements `Audience\AudienceRuleResolverInterface` promises it needs only the workspace, actor and config, so it can define a named user group | this page |
| `TimeWindow\PluginWindowTypesInterface` | Kinds of named, non-overlapping, closable period (a crop year, a term) | this page |
| `MigrationInterface` (returned by `getMigrations()`) | Migrations, with the `Schema\MigrationSchema` helpers | Plugin-Development |

## Providers: what a plugin implements for the host

A plugin hands the host an object that does the work. The host decides when to call it.

| Interface | Contributed through | Does |
|---|---|---|
| `Billing\BillingPortal` (+ `TrialCheckout`, `QuantityCheckout`, `HostedCheckoutPage`, `ProvidedBy`) | `PluginBillingPortalInterface` | A billing provider. `HostedCheckoutPage` gives it a public `GET /api/billing/checkout-page` for providers whose checkout must run on the seller's own page. That page is unauthenticated, so it shows only what the provider's checkout shows and checks every reference with the provider. |
| `Billing\LicenceSales` | `PluginLicenceSalesInterface` | Sells store licences |
| `Mail\BillingEmails` purpose | `PluginBillingEmailsInterface` | Billing emails |
| `Calendar\BookingPaymentStep` | `PluginBookingPaymentInterface` | Payment before a booking is confirmed |
| busy times | `PluginCalendarBusyInterface` | External busy intervals on a person's calendar |
| `Messaging\MessageChannel` | `PluginMessageChannelsInterface` | A channel (WhatsApp, SMS) for customer messages |
| `Notification\NotificationTransport` | core's `TransportRegistry` (no plugin registration path yet) | A delivery transport for notifications |
| `Auth\ExternalAuthProvider` | core's OIDC engine (no plugin registration path yet, #1690) | A sign-in provider |
| `Sync\SyncableResource` | your own routes, with `Sync\SyncController` | Makes one of your tables offline-first and two-way syncable: idempotent create, optimistic-concurrency update and delete, tombstones and a changes feed. The table carries `SyncController::SYNC_COLUMNS` and a unique `(tenant_id, client_uuid)`. |

## Errors

Every exception the SDK defines implements `Whity\Sdk\SdkException` (1.123), so `catch (SdkException $e)` catches "the host or the SDK refused this":

`ContactRejected`, `TimeOffRejected`, `InventoryRejected`, `DocumentNotIssuedException`, `RenderRejectedException`, `RenderUnavailableException`, `BillingPortalException`, `HookVetoException`, `MultipartException`, `InvalidManifestException`, `ExpressionError`, `HttpFailure`.

Each still extends `\RuntimeException`, so existing catches don't change. The SDK also throws plain SPL exceptions that don't implement it: `\InvalidArgumentException` for a bad key or value, and `\RuntimeException` or `\LogicException` when there is no workspace in scope. The [streamline proposal](Plugin-SDK-Streamline-Proposal.md) suggests making these consistent in 2.0.
