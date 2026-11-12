# whity/plugin-sdk

The standalone plugin contract package for the Whity platform. A plugin
implements the SDK types — and depends on **nothing else**: this package
requires only PHP, never `whity-core`. That is what makes a plugin
distributable across Whity-based applications without dragging a host
framework along.

## Contract surface (v1.44.0)

| Type | Since | Purpose |
| --- | --- | --- |
| `Whity\Sdk\PluginInterface` | 1.0 | The plugin contract: name/version, routes, permissions, hooks, migrations. |
| `Whity\Sdk\MigrationInterface` | 1.0 | Plugin schema migrations: `up(\PDO)` / `down(\PDO)`, idempotent statements, tested rollback; executed by the host's `migrate run`. |
| `Whity\Sdk\Http\Request` | 1.0 | The request shape route handlers receive (headers, body, per-request attribute bag incl. `ATTR_JWT_CLAIMS`). |
| `Whity\Sdk\Http\Response` | 1.0 | The response shape handlers return; `Response::json()` / `Response::error()` factories. |
| `Whity\Sdk\Hooks\Events` | 1.0 (`user.creating` read-only: 1.70) | Catalogue of hook event names (`user.creating`, `tenant.deleted`, `worker.request.start`, …). Since 1.70 `user.creating` carries only `email` and `role_id`, ignores what listeners return, and is refused with `HookVetoException`. No hook payload carries a password. |
| `getHooks()` `'observe' => true` | 1.89 | An observe-only listener: its return value is ignored, it cannot change the payload, and a veto it throws is logged and ignored. |
| `Whity\Sdk\Hooks\Events`: membership, registration, sign-in, grants, close report, data type lifecycle, boot announcements | 1.89 | Published constants for events core already fired: `USER_MEMBERSHIP_ADDED`/`REMOVED`, `REGISTRATION_COMPLETED`/`APPROVED`/`REJECTED`, `AUTH_FEDERATED_SIGNED_IN`, `RBAC_RESOURCE_GRANT_*`, `TIME_WINDOW_CLOSE_REPORT`, `DATATYPE_LIFECYCLE_*`, and the `*_REGISTERED` / `SETTINGS_DECLARED` boot events. |
| `Whity\Sdk\Hooks\HookVetoException` | 1.15 | The only Throwable that crosses the host's per-plugin error boundary. Throw it from `*.deleting` / `*.deleted` to REFUSE a deletion (or report failed cleanup): the host rolls the delete back — those hooks run inside the DELETE's transaction — and answers `409 Conflict` with your `reason()`. Every other exception stays isolated. |
| `Whity\Sdk\Rbac\PermissionResolver` | 1.16 (resource scope: 1.17, roles 1.22) | READ-ONLY access to the host's authoritative permission resolution, resolved from the service container (`\Whity\app(PermissionResolver::class)`). Ask it for an authorization decision INSIDE a handler instead of re-deriving one in SQL: it is backed by the same delegation-aware resolver the host's RBAC middleware enforces with (active-membership gating, OU-ancestor inheritance, role hierarchy, live delegations, catalogue validation), so plugin and platform can never disagree about the same caller. All three methods take an optional `$resourceType`/`$resourceId` pair that narrows the question to ONE record — `hasPermission()`/`effectivePermissions()` since 1.17, `hasRole()` since 1.22 — so per-record authority needs no private grant table. Pass both or neither; a record grant only ever WIDENS the tenant-wide answer and never substitutes for tenant membership. Grants no authority — three question methods, no cache control, no database handle. |
| `Whity\Sdk\Rbac\ResourceScope`, `Whity\Sdk\Rbac\ResourceReach` | 1.74 | The NARROWING check for a plugin's own resource types, resolved from the service container (`\Whity\app(ResourceScope::class)`). `reach($profileId, $tenantId, $permission, $resourceType, ?$unrestrictedPermission)` returns the records the caller may act on: those where a role is granted to them by name (everyone-grants do not count, any role does) AND `hasPermission()` allows the permission. `PermissionResolver` with a resource only ever widens, so it cannot filter a list; AND this with it instead. `ResourceReach::filter()` returns a subset of its input, `contains()` checks one record, `ids()` builds an `IN (...)`. Unrestricted only when the caller holds both the permission and the plugin-named "every record" permission tenant-wide. Read only. |
| `Whity\Sdk\PluginBillingPortalInterface`, `Whity\Sdk\Billing\BillingPortal` | 1.77 | A plugin can be the deployment's billing portal, selling its plans through one payment provider. `getBillingPortal()` returns a `BillingPortal`; the host uses it when no hosted billing service is set in the environment, one portal per deployment. The portal resolves or creates the provider price for a plan price (`priceRefFor(PlanPriceTerms)`), starts checkout, answers `accessFor()` from the provider's current state, lists receipts and subscriptions, changes plan or quantity, links to the provider's customer portal, refunds (operator only), and verifies provider notifications posted to `POST /api/billing/provider-webhook` (`verifyNotification()` returns only the delivery id and the subject). The host owns the subscription record, the plan and the entitlements. Money is in minor units. |
| `Whity\Sdk\I18n\LocalizedText` | 1.93 | Translatable content fields: one value in Arabic and English, `{"ar": "...", "en": "..."}`. `schema()` is the OpenAPI property, carrying the `x-whity-localized-text` marker (`MARKER`), which makes `screen: 'crud'` show the reader's language in lists and both languages in forms. `normalize()` checks a value from a request or a row, `resolve()` picks the text for a language with the same fallback as the screens, and `isSchema()` tests a schema node. See `docs/wiki/Plugin-Localized-Text.md` and `plugins/Glossary`. |
| `Whity\Sdk\Sdk` | 1.1 | SDK identity: `Sdk::VERSION`, what hosts evaluate plugin SDK-constraints against. |
| `Whity\Sdk\PluginRequirementsInterface` | 1.1 (core constraint: 1.4) | OPTIONAL declaration of a required SDK constraint, a host CORE-version constraint (`getCoreConstraint()`, since 1.4), and inter-plugin dependencies (composer constraint syntax). Unsatisfied plugins are quarantined (`PluginState::Failed` + reason); satisfied ones load in topological dependency order. |
| `Whity\Sdk\PluginFrontendInterface` | 1.2 | OPTIONAL declaration of the admin-UI screens a plugin contributes (frontend feature descriptors). UI metadata only — descriptors grant nothing; the host validates, permission-filters, and serves them via `GET /api/frontend/features`. |
| `Whity\Sdk\Tenant\TenantTableRegistry` | 1.3 | The portable, dependency-free model of a host's / plugin's tenant-owned and sanctioned-global tables, consumed by the scanner and linter. |
| `Whity\Sdk\Tenant\TenantPredicateScanner` | 1.3 | The tokenizer-based static scanner that flags any `SELECT`/`UPDATE`/`DELETE` on a tenant-owned table missing a `tenant_id` predicate (honours `@tenant-guard-ignore:` + the global allowlist). |
| `Whity\Sdk\Tenant\MigrationTenantColumnLinter` | 1.3 | Lints a plugin's `CREATE TABLE` migrations: every tenant table must declare a `tenant_id` column (or be a declared global / transitively-scoped exception). |
| `Whity\Sdk\Testing\TenantIsolationConformanceTestCase` | 1.3 | The shared PHPUnit base case a plugin extends to PROVE its tenant isolation: wires the linter + scanner + a RealEngine schema check. Requires `phpunit/phpunit` (dev-only `suggest`). |
| `Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase` | 1.27 | The shared PHPUnit base case a plugin extends to PROVE it boots and behaves correctly under an OFFLINE PHP plugin host (no server framework — the shape the Tauri desktop template's bundled FrankenPHP runs plugins under): migrations apply cleanly on the same narrow SQLite dialect shim, declared permissions are well-formed and match every route's `requiredPermission`, a role granted one permission holds exactly that one, and every declared hook runs cleanly on a synthetic payload. Requires `phpunit/phpunit` (dev-only `suggest`), same as the tenant-isolation kit. |
| `Whity\Sdk\Settings\PluginSettingsInterface` | 1.21 | OPTIONAL declaration of the CONFIGURATION KEYS a plugin owns — key => type (`string`/`bool`/`int`/`enum`), default, constraints, options, localized label, description. The host stores them in ITS OWN `app_settings` / `tenant_settings` tables and resolves them through ITS OWN chain (per-tenant override ?? global default ?? declared default), so a plugin stops rebuilding the settings layer as a private table with no declared keys and no validation. Keys are namespaced under the plugin name the loader supplies (`acme:sync_mode`), so two plugins cannot collide and none can shadow a core key; a declaration whose own `default` fails its own rules is refused at load. Publication on the host's settings screens is an explicit `admin => true` opt-in (those screens are gated on CORE settings permissions, not the plugin's). NOT for credentials: a secret-shaped declaration is refused, since settings are readable TEXT served to anyone holding `settings:read`. |
| `Whity\Sdk\Render\DocumentRenderer` | 1.41 | The RENDERING SEAM, RESOLVED rather than implemented: `\Whity\app(DocumentRenderer::class)` turns a plugin's structured content into a document. `render()` answers bytes and stores nothing; `issue()` answers a first-class platform document — an id, an immutable artifact, per-tenant storage routing — so routing, verification and the organizer apply without the plugin arranging any of it. `isAvailable()` is the cheap check to make before assembling something expensive. NO METHOD TAKES A TENANT ID: the host reads tenant and actor from its own request-scoped context, so a document built from one tenant's content and filed in another's storage is not expressible. Available in requests, queue jobs and CLI commands (1.76, #1151). |
| `Whity\Sdk\Render\FlowDocument` | 1.41 | The content a document is assembled from: headings, paragraphs, tables, figures, page breaks, plus generated contents / list-of-tables / list-of-figures front matter, in RTL and LTR. A mutable BUILDER (the one deliberate departure from this SDK's immutable value objects — a hundred-page submission is tens of thousands of blocks and copy-on-append would be quadratic). It refuses only what it must know to build a correct tree — a heading level outside 1-6, a remote figure source, a map row where a list belongs — and leaves everything else to the renderer, which names the offending field. NUMBERING IS NOT SETTABLE: tables, figures and heading numbers are assigned by the renderer in document order, because a caller that numbered its own would have to renumber on every insert and any disagreement with the generated lists would be invisible until somebody read the printed document. |
| `Whity\Sdk\Render\PageSpec` | 1.41 | Page geometry in MILLIMETRES: the four presets by name (`a4()`, `a5()`, `letter()`, `legal()`), an explicit `ofSize()`, and `withMargins()`. Presets send the NAME rather than the dimensions, so the millimetres stay decided in one place. |
| `Whity\Sdk\Render\RenderedDocument` | 1.41 | The bytes plus what only the renderer could know: `pageCount`, `frontMatterPages`, and the derived `bodyPageCount()`. A flowing document is defined by not knowing its own length in advance, so this is told rather than computed. |
| `Whity\Sdk\Render\IssuedDocument` | 1.41 | A rendered document the platform now OWNS: `documentId`, `contentUrl`, page counts, byte size. Deliberately carries NO bytes — they are already stored, and a second copy of a thing whose defining property is that there is one of it is a liability. |
| `Whity\Sdk\Render\RenderRejectedException` / `RenderUnavailableException` | 1.41 | The two failure modes, kept APART on purpose: the first will never succeed unchanged (a ceiling, a refused tree) and carries a `clientMessage` written to be shown; the second is an outage or a disabled instance, which is the DEFAULT state of a fresh install. A plugin that cannot tell them apart either retries a malformed document forever or gives up on a container that was restarting. |
| `Whity\Sdk\Manifest\ManifestPlugin` / `PluginManifest` / `ManifestValidator` | 1.47 | A plugin's declarations in `plugin.json` (format `whity.plugin/1`, JSON Schema in `resources/plugin-manifest.schema.json`): identity, requirements, permissions, roles, `blocks` screens, owned tables, data types and limits, every label in English and Arabic. Extend `ManifestPlugin` with an empty class next to the file and it answers each capability in the shape the PHP interfaces define; a subclass adds routes, hooks and migrations. The manifest is validated as a whole and an invalid one refuses the whole plugin. |
| `Whity\Sdk\Expression\Expression` / `ExpressionContext` / `ExpressionError` / `ExpressionDescriber` | 1.48 | The closed expression language manifest rules, computed fields and conditions are written in: a JSON tree, one operator per object, no loops, no references outside the record, capped at 256 nodes, depth 24 and 10,000 evaluation steps. `validate()` before use, `evaluate()` against a context of fields, state, roles and the date, and `ExpressionDescriber::describe()` for the English or Arabic words a person approving it reads. The TypeScript twin (`@whity/features/expressions`) passes the same fixtures, `resources/expression-fixtures.json`. See `docs/wiki/Manifest-Expressions.md`. |
| `Whity\Sdk\Manifest\RecordTypeValidator` | 1.50 | The `records` section of `plugin.json`: record types a workspace plugin keeps in the host's shared record store. Twelve field types (`text`, `long_text`, `number`, `money` in minor units with an ISO currency, `date`, `datetime`, `boolean`, `choice`, `multi_choice`, `person`, `ou`, `record`), an optional lifecycle of states and named moves each gated by a permission, and `read`/`create`/`edit`/`delete` gated by permissions the manifest declares. `PluginManifest::records()` reads them. Installed plugins keep records in their own tables. |
| Record rules (in `RecordTypeValidator`) | 1.51 | A record type's `rules` (`require` must hold over the record as it will be, `when` it applies, with an English and Arabic `message` and an optional `field` it is reported against), and a field's `default` (create only), `computed` (recalculated on every write, never written by the caller) and `lockedWhen` (the field can't change while it holds). All are manifest expressions over the record type's own fields; the host applies them on every write and fails closed when one can't be evaluated. |
| `Whity\Sdk\Http\PublicForm` | 1.67 | A route declared `'public' => 'form'` on POST `/api/<slug>/forms/<name>/{key}` takes anonymous browser submissions. The host resolves the tenant from the key and runs its limits, Origin check and bot check before the handler, which reads the result with `PublicForm::from()`. See `docs/wiki/Plugin-Public-Forms.md`. |
| `Whity\Sdk\Http\PublicRead` | 1.69 | A route declared `'public' => 'read'` on GET `/api/<slug>/public/<name>/{key}` answers anybody holding the link, once the operator has switched public reads on (`PLUGIN_PUBLIC_READS=on`). The host resolves the tenant from the key and runs its limits and Origin check before the handler, which reads the result with `PublicRead::from()`. See `docs/wiki/Plugin-Public-Forms.md`. |
| `Whity\Sdk\Http\ServiceCall`, `Whity\Sdk\Identity\LinkedPeople` | 1.84 | A route declared `'service' => '<scope>'` is called server to server by another service of the trusted issuer (Whity ID), never by a person. The host admits it only with a bearer JWT from that issuer (asymmetric key from its JWKS), audience APP_URL + /api/v1, a client on `SERVICE_TOKEN_CLIENTS`, at most ten minutes long, carrying the scope; anything else is 401. `ServiceCall::from()` gives the verified issuer, client and scopes. No tenant, no person: A call may act for a person (`sub_on_behalf`) only when it comes from the issuer's own client (`SERVICE_TOKEN_ACTING_CLIENT`, default `whity-id`); any other client sending it is refused. `LinkedPeople::workspacesFor($call, $permission)` resolves that person through the global sign-in link to the active, non-system workspaces where they hold the permission. Never MCP tools. |
| `Whity\Sdk\Billing\PlanCatalogue` | 1.84 | Read-only plan prices from the container: `activePrice($planKey, $period, $currency)`, `price($priceId)` (also retired ones, to read back what was bought), `isAddon($planKey)`, `addonPrices()` (what can be bought beside a tier). A billing plugin prices a plan exactly as the host does without reading its tables. |
| `Whity\Sdk\Billing\LicenceSales`, `Whity\Sdk\PluginLicenceSalesInterface` | 1.86 | Selling paid store plugin licences. A billing plugin implements `PluginLicenceSalesInterface`; the store resolves `\Whity\app(LicenceSales::class)` and calls `checkout(LicenceCheckout)` (product `plugin.<slug>`, the installation as customer, its licence id as reference) and `status($reference)`, which is the truth. The host keeps one seller, stamped with its plugin name (`ProvidedBy::providerName()`), and refuses a second. A licence never touches a workspace plan. `LicenceSaleStatus::$testMode` marks a test-mode licence, which `isActive()` never counts. |
| `Whity\Sdk\Billing\AccessRefresh` | 1.86 | `refresh($subjectRef)` asks the host to re-read one workspace's access through the active billing portal and record it, the path a verified notification takes. Only the subject goes in, never a state, so a call cannot grant more than the portal reports. Throttled per subject, audited as `billing.access_refreshed`. A billing plugin uses it when it switches from test to live. |
| `Whity\Sdk\Hooks\Events::ROUTE_*`, `DOCUMENT_ROUTED`, `DOCUMENT_ROUTE_ACTED`, `DOCUMENT_FINALISED`, `FORM_SUBMITTED`, `SUBMISSION_*` | 1.94 | Routes and form submissions, after the change is saved and with ids only: `document.routed`, `document.route_acted`, `route.sent_back`, `route.resubmitted`, `route.completed`, `route.signed`, `document.finalised`, `form.submitted`, `submission.reviewed`, `submission.withdrawn`. |
| `Whity\Sdk\Hooks\Events::MEMBERSHIP_CUSTOMER_JOINED` | 1.73 | `membership.customer_joined`: somebody joined a workspace as a customer through its own sign-up, at once when sign-up is Open or on an admin's approval. Ids only (`tenant_id`, `profile_id`, `membership_id`, `role_id`, `role_name`, `via`, `approved_by`), fired after commit; the return value is ignored. |
| `Whity\Sdk\PluginRolesInterface` | 1.72 | Role grants stay the plugin's own: only permissions the plugin declares, never core ones, and only to roles it created or the operator approved for it. Uninstall removes only the grants the plugin made. |
| `Whity\Sdk\Manifest\AutomationValidator` | 1.53 | The `automations` section of `plugin.json`: what a workspace plugin does by itself when its records change. A trigger (`<type>.created`, `.changed`, `.changed.<field>`, `.moved`, `.moved.<state>`), an optional `when` expression, and steps from a closed catalogue: `set_fields`, `create_record` (optionally linked back), `move`, `notify` (a role the manifest declares, or a person field, with an English and Arabic message) and, from 1.57, `issue_document` (one of the manifest's own `documents.slots` for the triggering record type, with optional merge `values` as expressions). `send_webhook`, `ask_approval`, `wait` and `call_action` are refused by name until the host runs them. `PluginManifest::automations()` reads them. |
| `Whity\Sdk\Manifest\ManifestTestValidator` | 1.54 | The `tests` section of `plugin.json`: examples a record type's rules must keep. Each test `create`s a record, or starts from a `given` record (in a `state`) and `update`s fields or makes a `move`, acting with the roles `as` lists, and `expect`s it `refused` (optionally on which fields, `issues`) or the `fields` and `state` it leaves. Values are literals. `PluginManifest::tests()` reads them; the host runs them in a rolled-back transaction before publishing a version. |
| `Whity\Sdk\Manifest\DocumentSlotValidator` | 1.55 | The `documents.slots` section of `plugin.json`: template slots, the named places a workspace plugin needs a document template. Each slot has a `label`, a `record` (one of the manifest's own record types), and optional `kinds` (`template`, `label`; default `template`). The host addresses it as `<plugin>.<slot>`; a workspace binds a published template to it, and `POST /api/documents` issues by `slot`. `PluginManifest::documentSlots()` reads them. |
| `Whity\Sdk\Manifest\DocumentStarterValidator` | 1.81 | The `documents.starters` section of `plugin.json`: starter templates a workspace plugin ships (`label`, `page`, the editor's `blocks`, optional `ownFields`, `kind`, `lang`, `slot`, `record`). The host seeds each into a workspace when the plugin goes live there, published, and binds it to its slot when the slot has no template. Seeded once; a copy the workspace changed is never overwritten, an untouched one follows the plugin's updates. `PluginManifest::documentStarters()` reads them. |
| `Whity\Sdk\PluginAgentPresetsInterface` | 1.61 | Optional. Ready-made agent templates this plugin ships: `getAgentPresets()`, or `agentPresets` in `plugin.json`. A persona in English and Arabic, toolsets (the plugin's own or core's) and where a person's grant starts. A preset may start a read at Allowed, never a write. |
| `Whity\Sdk\PluginHomeCardInterface` | 1.62 | Optional. This plugin's one card on the home page: `getHomeCard()`, or `homeCard` in `plugin.json`. A GET route the plugin registered under the same permission gives one number, a few lines and at most one chart, in the person's scope. `Manifest\HomeCardValidator` checks it. |
| `Whity\Sdk\PluginDocumentsInterface` | 1.56 | Optional. A code plugin's part in documents: `getTemplateSlots()` (each a `Documents\TemplateSlot`: a name, an English and Arabic label, a record type and the kinds that can fill it, addressed as `<plugin name>.<name>`), `getDocumentFieldProviders()` (its record types as merge fields, each `<plugin name>.<type>`) and `issuesInBackground()`. A bad declaration is dropped with a logged reason. |
| `Whity\Sdk\Documents\Documents` | 1.56 | Host service, `\Whity\app(Documents::class)`. `issue(new IssueRequest(TemplateRef::slot('Invoicing.invoice'), record: 'Invoicing.invoice:4812', values: [...], originKey: 'order:4812:invoice'))` issues as the person whose request it is and returns an `IssueResult` (`documentId`, `title`, `replayed`, `contentUrl`). It refuses with `DocumentNotIssuedException` and a stable `reason`; outside a request that is `no_actor`. |
| Background issuing (in `Documents`) | 1.58 | A job of a plugin that declares `issuesInBackground()` issues with no person, where the workspace allowed it (`PUT /api/document-background-issuing/{plugin}`), only from its own slots and with only the permissions it declares; recorded with no person and the system as the actor. Otherwise `DocumentNotIssuedException::BACKGROUND_NOT_ALLOWED`. |
| `Whity\Sdk\Mcp\McpToolContext` | 1.59 | The second argument to an authored MCP tool's handler now also says who is calling: `actorKind` (`person`, `agent` or `system`; `isAgent()`), `principalKind`, and for an AI agent a stable `agentId` and a display `agentName`. `subject` is the person's Whity ID `sub` when the call came in with a Whity ID token. Record changes as "agent X for person Y". |
| `Whity\Sdk\Documents\Templates` / `TemplateInfo` / `TemplateField` | 1.82 | Host service, `\Whity\app(Templates::class)`. `list(?kind, ?recordType)`, `find($id)` and `forSlot('<plugin>.<slot>')` read the workspace's published templates: the published version's title, kind, language, record type, bound slots and merge fields (path, label, source `template`/`record`/`host`, required, sample). It answers for the caller `Documents` acts for: the person, or a background plugin's own bound templates where allowed. |
| `Whity\Sdk\Documents\BulkIssueRequest` / `BulkIssueItem` / `BulkIssueStatus` / `BulkIssueItemResult` | 1.83 | `Documents::issueMany(BulkIssueRequest)` issues up to 500 documents from one template as a background job and returns a `BulkIssueStatus`; `Documents::batch($id)` reads it again: `queued`/`running`/`done`, counts, and a result per item (`issued`, `replayed`, `refused` with a reason, `failed`). The batch key makes a resend return the same batch; each item has an origin key, so a resumed batch never issues twice. Each item is checked and preflighted as the sender when it runs. |
| `Whity\Sdk\Documents\PreflightResult` | 1.66 | What `Documents::preflight()` and `PluginDocumentStore::preflight()` return: `ok`, `checks` and `missingFields`. They run every check an issue would, including `document.issuing` listeners with `check_only: true`, and write nothing. A real issue checks again. |
| `Whity\Sdk\Documents\DocumentNumberingRule` | 1.65 | How a slot's documents are numbered: a pattern such as `INV-{YYYY}-{SEQ:4}` and `on_issue` or `on_final_approval`. Give it to `TemplateSlot`, or as `numbering` on a slot in `plugin.json`. The bound template may carry its own rule, which wins. `IssueResult::$number` returns the number; the field `issued_document.number` prints it; `Hooks\Events::DOCUMENT_NUMBERED` says one was handed out at final approval. |
| `Whity\Sdk\Agents\Agents` | 1.75 | Host service, `\Whity\app(Agents::class)`. The workspace's AI agents (`list()`, `find($id)`) and what they did and proposed (`activity($agentId, $status, $since, $limit, $offset)`, newest first, never the call arguments), read as the person whose request it is. With `agents:manage` that's every agent and the whole workspace. Without it, only the agents they use and their own activity. With no person (a job), nothing. Read only. Agent lifecycle events: `Events::AGENT_CREATED`, `AGENT_UPDATED`, `AGENT_ARCHIVED`, `AGENT_RESTORED`, `AGENT_PAUSED`, `AGENT_RESUMED` (ids only, after the change). |
| Proposal events and previews | 1.78 | `Events::AGENT_PROPOSAL_CREATED`, `AGENT_PROPOSAL_DECIDED` (`approved`, `declined` or `expired`) and `AGENT_ACTION_UNDONE`: ids and the tool's name, never the arguments, after the change; listeners can't refuse or change anything. An authored tool's optional `preview` (`fn(array $arguments, McpToolContext $context): ?array`) shows the person deciding an agent's proposal what it would change, as `label`/`before`/`after` rows. The host runs it in a transaction it rolls back (READ ONLY on PostgreSQL). Only the person who granted the agent decides a proposal, never a plugin. |
| `Whity\Sdk\Settings\PluginSettings` | 1.88 | Host service, `\Whity\app(PluginSettings::class)->for($this)` gives the plugin's own store for the current workspace: `get($key)`, `all()`, `set($key, $value)`, `reset($key)`. Only the keys it declared, in their declared types. `Events::PLUGIN_SETTINGS_CHANGED` fires by key, never with the value. |
| `Whity\Sdk\Secrets\PluginSecretFieldsInterface` | 1.88 | Optional. `getSecretFields()` declares secrets a person enters, each with the `permission` that may change it. A `secretField` block draws the field; the host stores the value for that person's workspace and only ever tells the browser whether it is set. |
| `Whity\Sdk\Http\Request` context | 1.87 | `getWorkspaceId()` (null with no workspace), `getLanguage()` (the person's, or `en`), `getActorKind()` (`person`, `agent`, `system`, or null when nobody is signed in) and `isAgent()`. The host stamps them on every route, MCP tool and resource call from its own authorization. A plugin's unit test can set `Request::ATTR_WORKSPACE_ID`, `ATTR_LANGUAGE` and `ATTR_ACTOR_KIND`. |
| `Whity\Sdk\Mcp\McpConnections` | 1.79 | Host service, `\Whity\app(McpConnections::class)->list()`. The workspace's active AI connections (apps and workspace agents connected over MCP): name, kind (`user` or `ai-agent`), person, agent, scope and created/expires/last-used. With `mcp:tokens:manage` that's the whole workspace, otherwise the person's own, and with no person nothing. Never a token, jti or hash. Events: `Events::MCP_CONNECTION_CREATED`, `MCP_CONNECTION_GRANT_CHANGED`, `MCP_CONNECTION_REVOKED` (`by`: `owner`, `admin` or `agent_removed`), with the connection's row id only, after the change. |
| `Whity\Sdk\PluginBookingPaymentInterface`, `Calendar\BookingPaymentStep`, `Calendar\Bookings` | 1.98 | Optional, one per deployment. `requirement()` says whether a booking must pay first (a deposit, say); core then holds the slot as `pending_payment`, calls `start()` for the URL to send the customer to, and the plugin settles it with `\Whitypp(Bookings::class)->confirmPaid()` or `release()`. Unsettled holds expire. Core never takes card details. |
| `Whity\Sdk\PluginCalendarBusyInterface`, `Calendar\CalendarEvents` | 1.98 | `busyTimes()` adds busy time from outside core (a Google or Microsoft calendar); it is read when free times are listed and again inside the person's lock, and a failure is read as not busy. `\Whitypp(CalendarEvents::class)->upsertExternal()` / `deleteExternal()` keep outside events on a person's calendar by the plugin's own reference. Calendar and booking events (`Events::BOOKING_CREATED` and others) carry ids only. |
| `Whity\Sdk\Messaging\CustomerMessages` | 1.99 | Host service. `\Whity\app(CustomerMessages::class)->send($this, $tenantId, $profileId, $body, ['whatsapp', 'email'], $subject)` tells a workspace's customer something through Messages: the first channel in your order that is on and can reach them takes it (a bare name matches an installed plugin channel ending in `:<name>`), in their conversation, marked as sent by your plugin. Returns the channel key, or null when none took it. |
| `Whity\Sdk\Mail\WorkspaceEmails`, `Mail\WorkspaceEmail` | 1.100 | `\Whitypp(WorkspaceEmails::class)->send($tenantId, $to, $subject, new WorkspaceEmail(...))` sends as the workspace: its brand, its language (or the one you name) and the EM.1 layout. You give the words (heading, paragraphs, label-value rows, one button, a note, why it was sent); core draws it. Plain text only. Returns false rather than throwing. |
| `Whity\Sdk\Frontend\Blocks\BlockText` | 1.101 | Any text a person reads in a block screen may be `['en' => 'Save', 'ar' => 'حفظ']` instead of a string: the descriptor's `label`, and `label`, `title`, `placeholder`, `emptyText`, `description`, `confirm`, `trigger`, `itemLabel` anywhere in the tree, `value` on `text`, `text` on `heading` and `body` on `alert`. Core serves the reader's language and falls back to the other. `BlockText::resolve($tree, 'ar')` shows what a reader gets; `BlockValidator` accepts the objects. |
| `Whity\Sdk\PluginDocumentBlocksInterface`, `Documents\DocumentBlock`, manifest `documents.blocks` | 1.103 | Add blocks to the document editor. Each is a declaration: a label, its `fields` (the block's Edit form), a `view` and an optional `print` view in the screen vocabulary's display blocks (heading, text, keyValue, list, table, …), naming fields as `{{data.key}}`. Whity's own renderer draws it, so a Verified plugin's block looks built in while no plugin code reaches the browser. Without `print` a block is for notes and wiki pages only. `Manifest\DocumentBlockValidator` checks the shape. |
| `Whity\Sdk\Audit\PluginAudit`, `AuditTrail`, `AuditEntry` | 1.92 | Record an audit entry with a target (`\Whity\app(PluginAudit::class)->for($this)->record(...)`) and read the plugin's own entries in the current workspace (`entries`). The host attributes every entry: names go under the plugin's namespace, and the actor, IP and workspace come from the request or job, never from an argument. |
| `Whity\Sdk\Jobs\PluginJobQueue`, `PluginScheduledJobsInterface`, `JobStatus` | 1.91 | Enqueue the plugin's own declared jobs in the current workspace with `\Whity\app(PluginJobQueue::class)->for($this)->enqueue(...)`, read their status (`status`, `recent`), and declare cron schedules that run once per workspace. Only the plugin's own jobs in the current workspace can be enqueued or seen. |
| `Whity\Sdk\Notification\PluginNotifier`, `PluginNotificationsInterface`, `Notice` | 1.90 | Declare notification types with English and Arabic labels, then send them with `\Whity\app(PluginNotifier::class)->for($this)`: `toPerson`, `toRole`, `toUnit`. Only the current workspace's active members are reached, each person's opt-outs apply, and types are namespaced under the plugin, so a plugin cannot pose as a security or billing notice. In-app only for now. |
| `Whity\Sdk\PluginMcpResourcesInterface`, agent context | 1.80 | Optional. MCP resources for AI agents: `getMcpResources()`, each a `path` (published as `whity-plugin://<slug>/<path>`), `name`, `description`, `mimeType`, a required `requiredPermission` and `read(McpToolContext): string` (at most 1 MB). A plugin's resources and its prompts (`PluginMcpInterface`, whose `messages` and `arguments` are now served too) reach agents only from a Verified plugin once the operator grants it **agent context** (`whity-cli plugin agent-context grant <name>`, install-wide, audited), and every one must declare `requiredPermission`. A revoke applies to the next call. Narrows 1.9: until granted, a plugin's prompts are no longer listed. |
| `Whity\Sdk\Secrets\PluginSecrets` | 1.60 | Host service, `\Whity\app(PluginSecrets::class)->for($this)` gives the plugin its own `Secrets\SecretStore`: `set($tenantId, $key, $value)`, `get(...)`, `delete(...)`. Values are encrypted with the host's key, kept apart by plugin and workspace, never read back by settings or the API, and audited by key only. Works in routes, MCP tools and jobs. |

## Versioning policy

Semantic and **additive**: new capabilities land in minor versions, each an
optional surface existing plugins can ignore —

- **1.0** — contract extraction (plugin/migration interfaces, HTTP shapes, events).
- **1.1** — requirements declaration + version gate.
- **1.2** — frontend feature descriptor (`PluginFrontendInterface`), plus the
  route-array `requiredPermission` key is now **enforced by the host**: the
  RBAC middleware denies callers without the permission (403 naming it), and
  a malformed value fails closed (the route is not registered).
- **1.3** — tenant-isolation conformance kit (`Whity\Sdk\Tenant\*` +
  `Whity\Sdk\Testing\TenantIsolationConformanceTestCase`): a reusable
  migration linter + tenant-predicate scanner + shared base test case a plugin
  runs in its OWN CI to prove its tenant tables and queries are scoped.
- **1.4** — host CORE-version constraint declaration
  (`PluginRequirementsInterface::getCoreConstraint()`): a plugin built for a
  specific or newer host core can be quarantined cleanly at load, gated against
  the host's core version independently of the SDK gate.
- **1.27** — offline-host conformance kit (`Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase`):
  the same idea as the 1.3 tenant-isolation kit, but proving a plugin actually
  BOOTS under an offline PHP host with no server framework behind it — see
  [Proving offline-host compatibility](#proving-offline-host-compatibility-127)
  below.

**Every minor updates the coverage matrix in the same pull request.**
[`docs/sdk-coverage/coverage.json`](../docs/sdk-coverage/coverage.json) rates how far
plugins reach each core area; add what the minor gave plugins to the area it
moved (a `has` entry with `since`), bump `sdk_version`, and run
`php scripts/sdk-coverage.php` to regenerate
[`docs/wiki/Plugin-SDK-Coverage.md`](../docs/wiki/Plugin-SDK-Coverage.md). CI
fails a minor the matrix does not account for.

The list above is illustrative, not a changelog. The authoritative,
version-by-version ledger is the docblock on
[`Sdk.php`](src/Sdk.php), which every release appends to.

Breaking contract changes require a new major. A plugin declares the SDK range
it supports via `getSdkConstraint()` (e.g. `'^1.1'`) and, optionally, the host
CORE range via `getCoreConstraint()` (e.g. `'^0.1'`); the host refuses to load
a plugin when its own `Sdk::VERSION` or `CoreVersion::VERSION` falls outside the
respective range — with the reason visible in the admin plugin list.

### What a MINOR version may and may not change

"Additive" is the promise; these are its terms. A plugin pinned `^1.x` and
loading today must still load on any later `1.y`.

**A minor MAY:**

- add a new optional interface a plugin can choose to implement
  (`PluginHealthProbesInterface`, `PluginMcpToolsInterface`, `PluginMcpToolsetsInterface` (1.52), `PluginAgentPresetsInterface` (1.61), …);
- add an OPTIONAL parameter to an existing method, with a default;
- add a new value object, exception type, or testing kit;
- add a new optional key to a declared array shape (a route declaration, a
  block descriptor), where omitting it preserves the previous behaviour exactly;
- widen what an existing method accepts.

**A minor MAY NOT:**

- add a method to an existing interface — that breaks every implementer, which
  is why a new capability arrives as a NEW interface even when an obviously
  related one already exists;
- remove or rename anything public, or narrow a parameter or return type;
- change the meaning of an existing key. Adding `scope` to a descriptor is
  additive; changing what an ABSENT `scope` means is not, because it silently
  alters the behaviour of code nobody edited.

The last one is the rule that is easiest to break by accident, because the
diff looks like a default value rather than a contract change.

### Upgrading a deployment that carries third-party plugins

The gate is enforced at LOAD, per plugin, and it fails closed: a plugin whose
`getSdkConstraint()` excludes the host's `Sdk::VERSION` is **quarantined** —
moved to `failed` state with no routes, permissions, hooks, migrations or
frontend features registered. It does not partially load, and it does not take
the host down with it.

**After upgrading**, read the state of every installed plugin:

```bash
curl -s http://<host>/api/plugins | jq '.data[] | {name, version, state, last_error}'
```

A quarantined plugin is in `state: "failed"` with `last_error.type` of
`"quarantine"` and a message naming the mismatch:

```
requires plugin SDK '^2.0', but the host provides 1.44.0
```

The same message is written to the host's error log at load, so it is visible
without an authenticated call.

**Before upgrading there is no equivalent probe, and that is a real gap.** A
plugin's declared `getSdkConstraint()` is evaluated at load but is NOT
published in the plugin list, so the only way to know whether an installed
build will survive an upgrade is to read the plugin's own source or its
distribution metadata. Until that is exposed, the practical check is to stage
the upgrade somewhere disposable first and read the list above.

A plugin that declares NO constraint is never quarantined by this gate. That
is deliberate — the declaration is opt-in — but it means such a plugin can load
against an SDK it was never tested on and fail later, in its own code, on a
request. Declaring a constraint is how a plugin author converts that into a
clean refusal at boot.

**Recovering** is a plugin-side change, not a host-side one: publish a build
whose constraint admits the new SDK, install it, and the plugin loads on the
next boot. Nothing in the host needs rolling back, because a quarantined
plugin changed nothing when it failed to load.

### Declaring frontend features (1.2)

A plugin MAY implement `PluginFrontendInterface` to describe the screens the
host's admin UI should render for it. A descriptor is **UI metadata only** —
it grants nothing; data access stays enforced by the route-level RBAC of the
underlying API routes, and the host filters the descriptor listing per caller
server-side.

```php
use Whity\Sdk\PluginFrontendInterface;

final class MyPlugin implements PluginInterface, PluginFrontendInterface
{
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'hello-greetings',          // REQUIRED kebab-case slug, unique across plugins
            'label' => 'Greetings',             // REQUIRED human title
            'screen' => 'crud',                 // REQUIRED: 'crud' | 'custom'
            'requiredPermission' => 'hello:view', // REQUIRED, must be one of THIS plugin's getPermissions()
            'resource' => [                     // REQUIRED when screen = 'crud'
                'basePath' => '/api/hello/greetings', // must be a GET route THIS plugin registers
                'titleField' => 'message',      // optional display-name field
            ],
            'icon' => 'message-circle',         // optional tabler icon
            'group' => 'plugins',               // optional nav group (default 'plugins')
            'order' => 10,                      // optional sort order (default 100)
        ]];
    }

    // ... PluginInterface methods ...
}
```

Validation is fail-closed and per descriptor: a descriptor with a malformed
id/screen/permission, a permission the plugin does not itself declare, or a
`crud` resource over a path the plugin does not serve is dropped with a logged
warning — the plugin still loads. Duplicate ids across plugins keep the first
(discovery order). The host exposes the surviving descriptors at
`GET /api/frontend/features`, returning each caller only the entries whose
`requiredPermission` they hold.

### Declaring requirements

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

final class MyPlugin implements PluginInterface, PluginRequirementsInterface
{
    public function getSdkConstraint(): string
    {
        return '^1.1';
    }

    /** Optional host CORE-version constraint (SDK 1.4); '' = no constraint. */
    public function getCoreConstraint(): string
    {
        return '^0.1';
    }

    /** @return array<string, string> plugin name => version constraint */
    public function getPluginDependencies(): array
    {
        return ['HelloWorld' => '^1.0'];
    }

    // ... PluginInterface methods ...
}
```

### Proving tenant isolation (1.3)

Whity is multi-tenant: a **tenant-owned** table carries a `tenant_id` column,
and every `SELECT`/`UPDATE`/`DELETE` on it must bind a `tenant_id` predicate.
The conformance kit lets a plugin prove — in its own CI, with nothing but this
SDK + PHPUnit — that its migrations and handlers uphold that invariant. Extend
the shared base case:

```php
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;
use MyPlugin\Migrations\CreateNotesTable;

final class MyPluginTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    protected function tenantTableRegistry(): TenantTableRegistry
    {
        // Declare YOUR tenant tables, and merge in the host's so an unscoped
        // query against a core tenant table is flagged too. A standalone plugin
        // builds the host portion from a small published table list:
        return TenantTableRegistry::for([
            'notes' => 'MyPlugin notes; carries tenant_id (CreateNotesTable).',
        ])->merge(TenantTableRegistry::for(
            ['users' => 'host', 'roles' => 'host', /* … */],
            ['revoked_tokens' => 'host global', 'core_schema_migrations' => 'host global']
        ));
    }

    protected function migrationsDirectory(): string { return __DIR__ . '/../Migrations'; }

    /** @return list<string> */
    protected function handlerSourceDirectories(): array { return [__DIR__ . '/../Api']; }

    /** @return list<\Whity\Sdk\MigrationInterface> */
    protected function schemaMigrations(): array { return [new CreateNotesTable()]; }

    /** @return list<string> */
    protected function ownTenantTables(): array { return ['notes']; }
}
```

The case enforces three checks (each a separate test):

1. **Migration linter** — every `CREATE TABLE` declares a `tenant_id` column,
   or is declared global / transitively-scoped (with a reason) in the registry.
2. **Handler-scoping scanner** — every tenant-table query in your source binds
   a `tenant_id` predicate, honouring `// @tenant-guard-ignore: <reason>` for
   sanctioned exceptions (e.g. a system-tenant "sees all" branch).
3. **RealEngine** — your migrations are applied to a real SQL engine and each
   declared tenant table is asserted to physically carry `tenant_id`. Which
   engine is an **environment** decision, not a code one — see below.

You can also run the linter / scanner directly (no PHPUnit) in a CI script —
see `scripts/ci-plugin-tenant-conformance.php` in whity-core for the pattern.
PHPUnit is a **dev-only** requirement (`suggest`); the runtime SDK still
depends on nothing but PHP.

### Running your tests against real PostgreSQL

Your tests run SQLite. Your users run PostgreSQL. A whole class of defect lives
in that gap and cannot be found by adding assertions:

- `GROUP_CONCAT(x SEPARATOR ',')` parses on SQLite; PostgreSQL has no
  `GROUP_CONCAT` at all (`string_agg`).
- `WHERE varchar_col = 42` matches on SQLite; PostgreSQL refuses with
  `operator does not exist: character varying = integer`. Nothing about the
  statement is malformed — it is the engine's type semantics that differ, so no
  wrapper or query builder can catch it. Only running it on PostgreSQL can.

`Whity\Sdk\Testing\RealEnginePdo` is the harness. Set `PHPUNIT_PG_DSN` and the
same suite runs against real PostgreSQL; leave it unset for the fast local loop:

```bash
docker run -d --name plugin_pg -p 5432:5432 \
  -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=plugin_test postgres:15-alpine

vendor/bin/phpunit                                     # SQLite
PHPUNIT_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=plugin_test" \
PHPUNIT_PG_USER=postgres PHPUNIT_PG_PASSWORD=postgres \
  vendor/bin/phpunit                                   # real PostgreSQL
```

`TenantIsolationConformanceTestCase::makePdo()` already delegates to it, so a
plugin extending the base case needs **no code change** — the variable is the
whole switch. Use it directly in your own data-layer tests too:

```php
use Whity\Sdk\Testing\RealEnginePdo;

$pdo = RealEnginePdo::make();
foreach ($this->migrations() as $migration) {
    $migration->up($pdo);
}
```

Each call gets its own auto-dropped schema in the target database, so parallel
runs never collide. Keep BOTH jobs in CI: the SQLite one for speed, the
PostgreSQL one for truth. Run the dual-engine job against a deliberately broken
query once (drop a `CAST`) to confirm it can actually fail — see
[Testing Against PostgreSQL](../docs/wiki/Testing-Against-PostgreSQL.md) for the
full trap catalogue and a copy-paste CI workflow.

### Proving offline-host compatibility (1.27)

`TenantIsolationConformanceTestCase` proves a DIFFERENT thing than the kit
below: that your queries stay tenant-scoped. It says nothing about whether
your plugin actually **boots** under a real offline PHP host that has no
server framework behind it at all — the shape the Tauri desktop template's
bundled FrankenPHP process runs plugins under (`templates/tauri-desktop/php-host/`
in whity-core): no JWT/memberships/OU hierarchy, a single fixed device role,
and a deliberately narrow SQLite dialect shim (only `SERIAL PRIMARY KEY` is
rewritten; `JSONB`/`gen_random_uuid()`/`RETURNING`-in-DDL are not).

Every real gap that host surfaced in practice — a migration using `SERIAL`
that SQLite silently mis-parses (leaving every inserted `id` `NULL`), an
un-seeded `admin` role that left existing grant migrations silently inert, a
route requiring a permission its plugin never declared in `getPermissions()`
— was found only by manually running the host and watching it fail. Extend
the shared base case to catch that class of defect in your own CI instead,
with no FrankenPHP process required:

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;
use MyPlugin\MyPlugin;

final class MyPluginOfflineHostConformanceTest extends OfflinePluginHostConformanceTestCase
{
    protected function pluginUnderTest(): PluginInterface
    {
        return new MyPlugin();
    }
}
```

That's the whole test file — the base case supplies four checks (each a
separate test, run against an in-memory SQLite engine carrying only the
offline host's bare RBAC skeleton tables):

1. **Migrations apply cleanly** on the same narrow dialect shim the real host
   uses — catches a Postgres-only construct the shim doesn't rewrite, or a
   migration that assumes a table only a different plugin or core creates.
2. **Declared permissions are well-formed** (`resource:action`) — a malformed
   slug gets the WHOLE plugin quarantined by the offline host's loader, never
   silently ignored.
3. **Every route's `requiredPermission` is one of your declared permissions**,
   and a role granted exactly one permission can exercise only that one — an
   undeclared or typo'd permission slug is never grantable to anyone, offline
   or in production.
4. **Every declared hook runs cleanly on a synthetic empty payload.** A
   generic `Throwable` FAILS this test loudly, even though the real host's
   per-plugin error boundary would silently swallow it in production — the
   whole point is catching the bug in your CI instead of shipping it
   invisibly. `HookVetoException` is the one sanctioned exception and is not
   a failure.

## Minimal plugin

```php
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Hooks\Events;

final class MyPlugin implements PluginInterface
{
    public function getName(): string { return 'MyPlugin'; }
    public function getVersion(): string { return '1.0.0'; }

    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/my/hello',
            'handler' => fn (Request $r): Response => Response::json(['ok' => true]),
            'requiredRole' => null,
        ]];
    }

    public function getPermissions(): array { return ['my:view']; }
    public function getHooks(): array { return [Events::USER_CREATING => fn (array $d, array $c): array => $d]; }
    public function getMigrations(): array { return []; }
}
```

## Packaging & distribution mechanism

This package lives in the `whity-core` monorepo under `sdk/` and is consumed
through a **composer path repository** — the exact mechanism a distributable
plugin (and the host apps that install it) reuses:

1. **Inside whity-core (this repo):** the root `composer.json` declares

   ```json
   "repositories": [{ "type": "path", "url": "sdk" }],
   "require": { "whity/plugin-sdk": "^1.0" }
   ```

   Composer links `sdk/` into `vendor/whity/plugin-sdk`, so `Whity\Sdk\…`
   autoloads exactly like any third-party package. The SDK owns the
   `Whity\Sdk\` autoload root — no other package may map that prefix.

2. **A distributable plugin package** requires the contract, not the host:

   ```json
   "require": { "whity/plugin-sdk": "^1.0" }
   ```

3. **A host application installing a plugin** adds the plugin (and
   transitively the SDK) through the same path-repository mechanism while the
   packages are co-developed:

   ```json
   "repositories": [
     { "type": "path", "url": "../whity-core/sdk" },
     { "type": "path", "url": "../whity-plugin-something" }
   ]
   ```

   Publishing to a private Composer repository (or extracting `sdk/` with
   `git subtree split`) later changes only the `repositories` entry — the
   `require` constraints and all plugin code stay identical. This is the same
   mechanism the extract-once/consume-twice pilot uses for distribution.

## License

MIT — deliberately permissive so plugin authors are not bound by the host
framework's license.
