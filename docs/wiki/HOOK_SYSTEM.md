# Hook System

Hooks let plugins (and the core) react to and modify events at key points, without modifying the framework. The implementation is a Mediator/Observer pattern in `HookManager` (`src/Core/Hooks/HookManager.php`). This page is grounded in the current source.

Related: [Architecture](Architecture.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [Plugin-Development](Plugin-Development.md).

## HookManager API

`HookManager` is an **instance** (not static). In `public/index.php` a single `HookManager` is created at worker boot and registered in the service container via `Whity\register_service(HookManager::class, $hookManager)`.

```php
// Register a listener (lower priority number = runs earlier; default 10)
public function listen(string $eventName, callable $callback, int $priority = 10): void

// Synchronous filter chain: runs all listeners in priority order, threading the
// returned array through each; returns the final data.
public function dispatch(string $eventName, array $data): array

// Asynchronous action: injects context and queues the payload for background work.
public function dispatchAsync(string $eventName, array $payload): void

// Remove a previously-registered listener (used by plugin hot-reload).
public function removeListener(string $eventName, callable $callback): bool

// Inspect registered listeners.
public function getListeners(?string $eventName = null): array
```

## Synchronous vs asynchronous

**Synchronous (`dispatch`)** — runs immediately in the request. Each listener receives `($data, $context)` and returns the (possibly modified) `$data`, which becomes the input to the next listener. If a listener returns a non-array, the data is left unchanged for that step. Use for validation, transformation, and synchronous side effects.

```php
$data = $hookManager->dispatch('role.creating', [
    'name' => 'editor',
    'description' => 'Content editors',
    'tenant_id' => 7,
]);
// Listeners may adjust $data before the role is written.
```

**Asynchronous (`dispatchAsync`)** — PERSISTS the event to the durable event spine (WC-154/#162): it writes an immutable `domain_events` row plus a `pending` `event_outbox` row via `DomainEventStore::append(...)`, then returns immediately. This replaces the retired log-only `Queue::push` stub, which dropped every event. The current tenant (`TenantContext`) and actor (`AuditContext`) are promoted to first-class columns, and the aggregate is derived from the dotted event name (`user.created.async` → `user`) plus the payload's own `id`; the raw payload is stored as JSON (no `_context` wrapper). Use for slow or non-critical side effects — a failure to persist is logged server-side and never propagated to the caller. A separate **relay worker** (not `dispatchAsync` itself) drains the outbox and performs downstream work such as sending notifications or calling external endpoints. A `HookManager` constructed without a store (plugin-loader / CLI contexts that only register listeners) treats `dispatchAsync` as a safe no-op.

```php
$hookManager->dispatchAsync('role.created.async', ['id' => 12, 'tenant_id' => 7]);
```

## Priority-based execution

Listeners run in ascending priority order (lower runs first); the default priority is `10`. Internally listeners are bucketed by priority and the buckets are `ksort`ed at dispatch time.

```php
$hookManager->listen('role.creating', $validate, 5);   // runs first
$hookManager->listen('role.creating', $transform, 10);  // default
$hookManager->listen('role.creating', $audit, 20);      // runs last
```

Suggested convention: `0–5` core validators, `10` default, `20+` side effects (logging, analytics).

## Context injection

The synchronous `dispatch()` passes a context array (built from the current request) as the second argument to each listener. `dispatchAsync()` instead records that same context as first-class `domain_events` columns (`tenant_id`, `actor_user_id`, `occurred_at`) rather than wrapping it into the payload. The shape of the sync context:

```php
$context = [
    'tenant_id' => TenantContext::getTenantId(), // current tenant (0 = system, null if unresolved)
    'timestamp' => time(),
];
```

For sync hooks the context is the **second argument** to each listener. Async hooks carry no `_context` key: the relay reads the tenant and actor from the `domain_events` columns above. Use `$context['tenant_id']` whenever a listener queries the database so its work stays within the current tenant (see [TENANT_ISOLATION](TENANT_ISOLATION.md)).

```php
$hookManager->listen('user.creating', function (array $data, array $context): array {
    // scope any lookups by the request's tenant
    // ... $context['tenant_id'] ...
    return $data;
}, 5);
```

## How plugins register hooks

Plugins declare hooks **declaratively** via `PluginInterface::getHooks()` (`sdk/src/PluginInterface.php`) — there is **no** `onEnable(HookManager)` method. `getHooks()` returns a map of event name → subscription, where a subscription is:

- a `callable` with signature `function (array $data, array $context): array`, or
- an array `['callback' => callable, 'priority' => int]`, or
- a list of either of the above.

```php
final class AuditPlugin implements \Whity\Sdk\PluginInterface
{
    public function getName(): string { return 'audit-logger'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getMigrations(): array { return []; }

    public function getHooks(): array
    {
        return [
            'role.created' => [
                'callback' => function (array $data, array $context): array {
                    // record an audit entry; always return the (un)modified data
                    return $data;
                },
                'priority' => 20,
            ],
        ];
    }
}
```

`PluginLoader::registerCapabilities()` reads `getHooks()` and subscribes each callback through `HookManager::listen()` — but **wrapped in a per-plugin error boundary** (`wrapHookCallback()`). A throwing hook callback is caught and logged, the original `$data` is returned unchanged (so a bad listener can't corrupt the chain), and the failure is recorded against the plugin's lifecycle (after `MAX_CONSECUTIVE_ERRORS = 3` the plugin is taken out of service).

The loader records the exact wrapped callbacks it registered so it can cleanly `removeListener()` them when the plugin is disabled, removed, or hot-reloaded. See [Plugin-Development](Plugin-Development.md) and the plugin lifecycle in [Architecture](Architecture.md#plugin-system).

**One exception crosses that boundary: `Whity\Sdk\Hooks\HookVetoException` (SDK 1.15, WC-713).** The isolation above is what makes a broken plugin survivable, but it also meant a plugin could never say *"do not do this"* — its objection was logged and the host carried on regardless. A veto is therefore re-thrown to the dispatching handler, and does **not** count toward the failure threshold (a veto is a healthy plugin doing its job, not a fault). See the deletion contract below.

## Observe-only listeners

A plugin subscribes with `['callback' => $fn, 'observe' => true]` when it only needs to know something happened (SDK 1.89). `PluginLoader::wrapObserveOnlyCallback()` returns nothing to `HookManager`, so the payload is unchanged whatever the listener does, and a `HookVetoException` stops at that boundary and is logged. The desktop host's `HookRegistrar` does the same. [Plugin-Development](Plugin-Development.md#what-a-listener-may-change) lists the events whose return value the host reads.

## Events fired by the core

Every event core publishes is a constant on [`Whity\Sdk\Hooks\Events`](../../sdk/src/Hooks/Events.php). The string is the wire contract; the constant gives a plugin completion and typo safety. The full catalogue is below, grouped as in `Events.php`, and `tests/Sdk/SdkSurfaceContractTest.php` fails when an event is added there without a row here.

**Kind** says what a listener can do:

- **observe**: the host ignores what the listener returns. Subscribe with `'observe' => true` (SDK 1.89).
- **filter**: the host reads the returned payload; return it, changed or not. [Plugin-Development](Plugin-Development.md#what-a-listener-may-change) lists these and `HookEventCatalogueTest` pins the list.
- **veto**: throw `HookVetoException` to refuse; the API answers 409 with your `reason()`.
- **queued**: the `.async` twin, persisted to the event spine and delivered by the relay after commit.

The tables are generated from [`Whity\Sdk\Hooks\EventCatalogue`](../../sdk/src/Hooks/EventCatalogue.php) (SDK 1.164), which a plugin can also read at runtime: each event's kind, the payload keys it documents, its `.async` twin and the SDK minor it arrived in. To change a row, change the catalogue and run `php scripts/hook-catalogue.php`; `tests/Sdk/EventCatalogueTest.php` fails when the page is stale. Where **Payload** is empty, no keys are written down yet, so read the dispatching code before relying on one. **Since** is exact from 1.164 on and reconstructed from the SDK changelog before that. `*.updating` events are observe-only today, whatever older text says.

<!-- event-catalogue:start (generated by php scripts/hook-catalogue.php from Whity\Sdk\Hooks\EventCatalogue; do not edit by hand) -->

### Users

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `user.creating` | `USER_CREATING` | veto | 1.70 |  |  |
| `user.created` | `USER_CREATED` | observe | 1.0 |  |  |
| `user.created.async` | `USER_CREATED_ASYNC` | queued | 1.0 |  |  |
| `user.updated` | `USER_UPDATED` | observe | 1.0 |  |  |
| `user.deleted` | `USER_DELETED` | observe | 1.29 |  |  |
| `user.membership.added` | `USER_MEMBERSHIP_ADDED` | observe | 1.89 | `profile_id`, `tenant_id`, `membership_id`, `role_id`, `role_name`, `ou_id` | Fired after the change, the return value ignored (SDK 1.89). Payload: `profile_id`, `tenant_id`, `membership_id`, `role_id`, `role_name`, `ou_id`. `added` may also carry `status`, `kind`, `via` or `is_primary`; `removed` also carries `status` and `granted_at`. |
| `user.membership.removed` | `USER_MEMBERSHIP_REMOVED` | observe | 1.0 |  |  |

### Registration

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `registration.completed` | `REGISTRATION_COMPLETED` | observe | 1.89 | `email`, `name`, `tenant_name`, `approval_required`, `verification_required` | Fired after the change, the return value ignored. These carry the person's address, so treat the payload as personal data. Payload: `email`, `name`, `tenant_name`, `approval_required`, `verification_required`. |
| `registration.approved` | `REGISTRATION_APPROVED` | observe | 1.89 | `email`, `name` | Payload: `email`, `name`. |
| `registration.rejected` | `REGISTRATION_REJECTED` | observe | 1.89 | `email`, `name` | Payload: `email`, `name`. |

### Federated sign-in

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `auth.federated.signed_in` | `AUTH_FEDERATED_SIGNED_IN` | observe | 1.89 | `profile_id`, `email`, `provider_key`, `issuer`, `subject`, `global_trust`, `provider_tenant_id`, `provisioned_tenant_id` | Somebody signed in through a federated provider. Fired after the sign-in, the return value ignored. Payload: `profile_id`, `email`, `provider_key`, `issuer`, `subject`, `global_trust`, `provider_tenant_id`, `provisioned_tenant_id`. Never a token. |

### Resource grants

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `rbac.resource_grant.created` | `RBAC_RESOURCE_GRANT_CREATED` | observe | 1.89 | `id`, `tenant_id`, `resource_type`, `resource_id`, `role_id`, `profile_id` | After the grant. Payload: `id`, `tenant_id`, `resource_type`, `resource_id`, `role_id`, `profile_id`. |
| `rbac.resource_grant.revoked` | `RBAC_RESOURCE_GRANT_REVOKED` | observe | 1.89 | `id`, `tenant_id` | After the revoke. Payload: `id`, `tenant_id`. |
| `rbac.resource_grant.revoked_all` | `RBAC_RESOURCE_GRANT_REVOKED_ALL` | observe | 1.89 | `tenant_id`, `resource_type`, `resource_id`, `revoked` | After every grant at one resource is revoked. Payload: `tenant_id`, `resource_type`, `resource_id`, `revoked`. |
| `rbac.resource_grant.verify_resource` | `RBAC_RESOURCE_GRANT_VERIFY_RESOURCE` | filter | 1.89 | `tenant_id`, `resource_type`, `resource_id`, `exists` | A FILTER: the host asks whether a plugin-owned resource exists before granting on it. Payload: `tenant_id`, `resource_type`, `resource_id`, `exists`. Return it with `exists` true for a resource you own. An observe-only listener cannot answer. |

### Time windows

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `time_window.close_report` | `TIME_WINDOW_CLOSE_REPORT` | filter | 1.39 | `tenant_id`, `window_id`, `window_type_id`, `starts_on`, `ends_on`, `unfinished` | A FILTER: the host collects what is unfinished before a period closes. Payload: `tenant_id`, `window_id`, `window_type_id`, `starts_on`, `ends_on`, `unfinished`. Append `['label' => string, 'count' => int, 'source' => string]` to `unfinished`. An observe-only … |

### Data type lifecycle

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `datatype.lifecycle.changing` | `DATATYPE_LIFECYCLE_CHANGING` | veto | 1.89 |  | Before a declared data type's record changes state. A listener may veto. |
| `datatype.lifecycle.changed` | `DATATYPE_LIFECYCLE_CHANGED` | observe | 1.89 |  | After the change, the return value ignored. |

### Tenants

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `tenant.creating` | `TENANT_CREATING` | filter | 1.0 |  |  |
| `tenant.created` | `TENANT_CREATED` | observe | 1.0 |  |  |
| `tenant.created.async` | `TENANT_CREATED_ASYNC` | queued | 1.0 |  |  |
| `tenant.updating` | `TENANT_UPDATING` | observe | 1.0 |  |  |
| `tenant.updated` | `TENANT_UPDATED` | observe | 1.0 |  |  |
| `tenant.deleting` | `TENANT_DELETING` | veto | 1.0 |  |  |
| `tenant.deleted` | `TENANT_DELETED` | veto | 1.0 |  |  |
| `tenant.deleted.async` | `TENANT_DELETED_ASYNC` | queued | 1.0 |  |  |
| `tenant.handle_changed` | `TENANT_HANDLE_CHANGED` | observe | 1.160 | `id`, `old_handle`, `new_handle`, `by_profile_id` | A workspace's @handle changed (design section 36, HD.4); the old one still leads to the workspace for 90 days. Payload: `id`, `old_handle` (null when it had none), `new_handle`, `by_profile_id`. |

### Roles

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `role.creating` | `ROLE_CREATING` | filter | 1.0 |  |  |
| `role.created` | `ROLE_CREATED` | observe | 1.0 |  |  |
| `role.created.async` | `ROLE_CREATED_ASYNC` | queued | 1.0 |  |  |
| `role.updating` | `ROLE_UPDATING` | observe | 1.0 |  |  |
| `role.updated` | `ROLE_UPDATED` | observe | 1.0 |  |  |
| `role.deleting` | `ROLE_DELETING` | veto | 1.0 |  |  |
| `role.deleted` | `ROLE_DELETED` | veto | 1.0 |  |  |
| `role.deleted.async` | `ROLE_DELETED_ASYNC` | queued | 1.0 |  |  |

### Organizational units

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `ou.creating` | `OU_CREATING` | filter | 1.0 |  |  |
| `ou.created` | `OU_CREATED` | observe | 1.0 |  |  |
| `ou.created.async` | `OU_CREATED_ASYNC` | queued | 1.0 |  |  |
| `ou.updating` | `OU_UPDATING` | observe | 1.0 |  |  |
| `ou.updated` | `OU_UPDATED` | observe | 1.0 |  |  |
| `ou.updated.async` | `OU_UPDATED_ASYNC` | queued | 1.0 |  |  |
| `ou.deleting` | `OU_DELETING` | veto | 1.0 |  |  |
| `ou.deleted` | `OU_DELETED` | veto | 1.0 |  |  |
| `ou.deleted.async` | `OU_DELETED_ASYNC` | queued | 1.0 |  |  |
| `ou.role_assigned` | `OU_ROLE_ASSIGNED` | observe | 1.0 |  |  |
| `ou.role_removed` | `OU_ROLE_REMOVED` | observe | 1.0 |  |  |

### Memberships

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `membership.customer_joined` | `MEMBERSHIP_CUSTOMER_JOINED` | observe | 1.73 | `tenant_id`, `profile_id`, `membership_id`, `role_id`, `role_name`, `via`, `approved_by` | Somebody joined a workspace as a CUSTOMER through the workspace's own sign-up: at once when sign-up is Open, or when an admin approves them when it is Approval required. Fired after the membership is committed, and the return value is ignored, so a listener … |
| `membership.customer_inviting` | `MEMBERSHIP_CUSTOMER_INVITING` | filter, veto | 1.105 |  | A customer is about to invite a colleague into the workspace (SDK 1.105, #1872). Customers may invite only when an admin turned it on in Settings > Sign-up, only into their own role, and only as customers. Runs before anything is written. Payload: … |

### Workspace owners

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `workspace.owner_added` | `WORKSPACE_OWNER_ADDED` | observe | 1.112 | `tenant_id`, `profile_id`, `added_by`, `offer_id` | Someone became an owner of a workspace: they accepted an offer to take it over or to co-own it. Payload: `tenant_id`, `profile_id`, `added_by` (the owner who offered), `offer_id`. |
| `workspace.owner_removed` | `WORKSPACE_OWNER_REMOVED` | observe | 1.112 | `tenant_id`, `profile_id`, `removed_by`, `via` | Someone stopped being an owner of a workspace. Payload: `tenant_id`, `profile_id`, `removed_by` (who caused it: the new owner on a hand-over, another owner, or themselves), `via` (`transfer`, `removed` or `left`). |
| `workspace.ownership_transferred` | `WORKSPACE_OWNERSHIP_TRANSFERRED` | observe | 1.112 | `tenant_id`, `from_profile_id`, `to_profile_id`, `offer_id`, `from_left` | A workspace was handed over: fired after {@see self::WORKSPACE_OWNER_ADDED} and {@see self::WORKSPACE_OWNER_REMOVED} for the same change. Payload: `tenant_id`, `from_profile_id`, `to_profile_id`, `offer_id`, `from_left` (true when the old owner chose to leave … |

### Documents

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `document.issuing` | `DOCUMENT_ISSUING` | filter, veto | 1.66 |  | Before a document is issued: a listener may veto with HookVetoException, and nothing is written. Payload: tenant_id, source_id, version, record, origin_key, actor — ids only, never field values. SDK 1.66: it also fires for a preflight, with `check_only: … |
| `document.issued` | `DOCUMENT_ISSUED` | observe | 1.94 |  | A document was issued (its record exists, with its QR code when the tenant has them on). Payload as above, plus id. |
| `document.issued.async` | `DOCUMENT_ISSUED_ASYNC` | queued | 1.94 |  |  |
| `document.rendered` | `DOCUMENT_RENDERED` | observe | 1.49 |  | A PDF was stored for a document, on issue or on a correction. Payload: id, tenant_id, artifact_id, source_id, version. |
| `document.rendered.async` | `DOCUMENT_RENDERED_ASYNC` | queued | 1.49 |  |  |
| `document.numbered` | `DOCUMENT_NUMBERED` | observe | 1.65 | `id`, `number`, `route_id`, `actor_profile_id` | SDK 1.65: a number handed out at final approval, once per document. A document numbered at issue carries its number on `document.issued` instead. Payload: `id`, `number`, `route_id`, `actor_profile_id`. |
| `document.numbered.async` | `DOCUMENT_NUMBERED_ASYNC` | queued | 1.49 |  |  |
| `document.revised` | `DOCUMENT_REVISED` | observe | 1.68 | `id`, `supersedes_id`, `reason`, `actor`, `number`, `revision` | SDK 1.68: a revision replaced a document. Payload: `id`, `supersedes_id`, `reason`, `actor`, `number`, `revision`. It also fires `document.issued` for the new document. |
| `document.revised.async` | `DOCUMENT_REVISED_ASYNC` | queued | 1.49 |  |  |

### Routes and forms

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `document.routed` | `DOCUMENT_ROUTED` | observe | 1.94 |  | A route was started on a document or a form submission. Payload also has `action`, `recipients`. |
| `document.routed.async` | `DOCUMENT_ROUTED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `document.route_acted` | `DOCUMENT_ROUTE_ACTED` | observe | 1.94 |  | Somebody acted on a route. Payload also has `action`, `verdict`, `decided`, `step_id`, `recipients`. |
| `document.route_acted.async` | `DOCUMENT_ROUTE_ACTED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `route.sent_back` | `ROUTE_SENT_BACK` | observe | 1.94 |  | A route was sent back to its author for changes. Payload also has `step_id`, `resume_from`. |
| `route.sent_back.async` | `ROUTE_SENT_BACK_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `route.resubmitted` | `ROUTE_RESUBMITTED` | observe | 1.94 |  | The author sent the changes back in, and the route went on. Payload also has `step_id`. |
| `route.resubmitted.async` | `ROUTE_RESUBMITTED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `route.completed` | `ROUTE_COMPLETED` | observe | 1.94 |  | Nothing is left open on a route. Payload also has `outcome`: `approved`, `rejected` or null. |
| `route.completed.async` | `ROUTE_COMPLETED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `route.signed` | `ROUTE_SIGNED` | observe | 1.94 |  | Somebody signed on a signing step. Payload also has `step_id`. |
| `route.signed.async` | `ROUTE_SIGNED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `route.stamped` | `ROUTE_STAMPED` | observe | 1.153 |  | A holder applied a company stamp on a stamp step (#1729). Payload also has `step_id` and `stamp_id`. |
| `route.stamped.async` | `ROUTE_STAMPED_ASYNC` | queued | 1.153 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `document.finalised` | `DOCUMENT_FINALISED` | observe | 1.94 | `id`, `document_id`, `route_id`, `subject_type`, `subject_id`, `artifact_id`, `tenant_id` | A route's finalise step made its final document. Payload: `id` (the document), `document_id`, `route_id`, `subject_type`, `subject_id`, `artifact_id`, `tenant_id`. |
| `document.finalised.async` | `DOCUMENT_FINALISED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `form.submitted` | `FORM_SUBMITTED` | observe | 1.94 | `id`, `form_id`, `tenant_id`, `actor_profile_id`, `route_id` | Somebody sent a form. Payload: `id` (the submission), `form_id`, `tenant_id`, `actor_profile_id` (null when it was anonymous), `route_id` (null when it has none). |
| `form.submitted.async` | `FORM_SUBMITTED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `submission.reviewed` | `SUBMISSION_REVIEWED` | observe | 1.94 | `id`, `form_id`, `tenant_id`, `actor_profile_id`, `route_id`, `step_id`, `decided` | The office recorded its answers on a submission, at a step of its route, after the act committed. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`, `route_id`, `step_id`, `decided` (the step's outcome, or null while it is still open). Never the answers. |
| `submission.reviewed.async` | `SUBMISSION_REVIEWED_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |
| `submission.withdrawn` | `SUBMISSION_WITHDRAWN` | observe | 1.94 | `id`, `form_id`, `tenant_id`, `actor_profile_id` | The person who sent a submission withdrew it. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`. |
| `submission.withdrawn.async` | `SUBMISSION_WITHDRAWN_ASYNC` | queued | 1.94 |  | Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no … |

### Calendar and bookings

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `calendar.event.created` | `CALENDAR_EVENT_CREATED` | observe | 1.98 |  | Fired after the write commits; the return value is ignored, so a listener cannot refuse or change anything. Each has an `.async` twin on the event spine. Ids only: `id`, `tenant_id`, `profile_id` (the member of staff whose calendar it is), and for bookings … |
| `calendar.event.created.async` | `CALENDAR_EVENT_CREATED_ASYNC` | queued | 1.98 |  |  |
| `calendar.event.updated` | `CALENDAR_EVENT_UPDATED` | observe | 1.98 |  |  |
| `calendar.event.updated.async` | `CALENDAR_EVENT_UPDATED_ASYNC` | queued | 1.98 |  |  |
| `calendar.event.deleted` | `CALENDAR_EVENT_DELETED` | observe | 1.98 |  |  |
| `calendar.event.deleted.async` | `CALENDAR_EVENT_DELETED_ASYNC` | queued | 1.98 |  |  |
| `booking.created` | `BOOKING_CREATED` | observe | 1.98 |  | A booking was made: `status` is `confirmed`, or `pending_payment` while a payment step runs. |
| `booking.created.async` | `BOOKING_CREATED_ASYNC` | queued | 1.98 |  |  |
| `booking.confirmed` | `BOOKING_CONFIRMED` | observe | 1.98 |  | A booking became confirmed: at once, or when its payment came in. |
| `booking.confirmed.async` | `BOOKING_CONFIRMED_ASYNC` | queued | 1.98 |  |  |
| `booking.rescheduled` | `BOOKING_RESCHEDULED` | observe | 1.98 |  |  |
| `booking.rescheduled.async` | `BOOKING_RESCHEDULED_ASYNC` | queued | 1.98 |  |  |
| `booking.cancelled` | `BOOKING_CANCELLED` | observe | 1.98 |  |  |
| `booking.cancelled.async` | `BOOKING_CANCELLED_ASYNC` | queued | 1.98 |  |  |
| `booking.expired` | `BOOKING_EXPIRED` | observe | 1.98 |  | A payment hold lapsed and the time is free again. |
| `booking.expired.async` | `BOOKING_EXPIRED_ASYNC` | queued | 1.98 |  |  |

### Agents

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `agent.created` | `AGENT_CREATED` | observe | 1.75 | `tenant_id`, `agent_id`, `actor_profile_id` | A workspace's agent changed. Fired after the change is saved, and the return value is ignored: a listener can't refuse or undo it. Payload: `tenant_id`, `agent_id`, `actor_profile_id` (the person who made the change). Ids only: read the agent with {@see … |
| `agent.updated` | `AGENT_UPDATED` | observe | 1.75 |  |  |
| `agent.archived` | `AGENT_ARCHIVED` | observe | 1.75 |  |  |
| `agent.restored` | `AGENT_RESTORED` | observe | 1.75 |  |  |
| `agent.paused` | `AGENT_PAUSED` | observe | 1.75 |  | Paused: refused on every call, and its waiting proposals can't run, until resumed. |
| `agent.resumed` | `AGENT_RESUMED` | observe | 1.75 |  |  |

### Agent proposals

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `agent.proposal.created` | `AGENT_PROPOSAL_CREATED` | observe | 1.78 | `tenant_id`, `proposal_id`, `agent_assignment_id`, `on_behalf_of_profile_id`, `tool` | Notifications only: each fires after the change is saved, the return value is ignored, and a listener can't refuse or change anything. Only the person who granted the agent decides a proposal, never a plugin. Ids and the tool's name only, never the call's … |
| `agent.proposal.decided` | `AGENT_PROPOSAL_DECIDED` | observe | 1.78 | `tenant_id`, `proposal_id`, `decision`, `decided_by_profile_id`, `on_behalf_of_profile_id`, `tool`, `failed` | A proposal stopped waiting. Payload: `tenant_id`, `proposal_id`, `decision` (`approved`, `declined` or `expired`), `decided_by_profile_id` (null when it expired), `on_behalf_of_profile_id`, `tool`, `failed` (it was approved and a check refused it when it ran, … |
| `agent.action.undone` | `AGENT_ACTION_UNDONE` | observe | 1.78 | `tenant_id`, `action_id`, `proposal_id`, `on_behalf_of_profile_id`, `tool` | A person undid what an agent did for them. Payload: `tenant_id`, `action_id`, `proposal_id` (null when it ran without asking), `on_behalf_of_profile_id`, `tool`. |

### AI connections

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `mcp.connection.created` | `MCP_CONNECTION_CREATED` | observe | 1.79 | `tenant_id`, `connection_id`, `profile_id`, `kind` | An app or agent connected over MCP. Fired after the change, the return value ignored. Payload: `tenant_id`, `connection_id` (as {@see \Whity\Sdk\Mcp\McpConnections} lists it), `profile_id` (whose connection), `kind` (`user` or `ai-agent`), … |
| `mcp.connection.grant_changed` | `MCP_CONNECTION_GRANT_CHANGED` | observe | 1.79 |  |  |
| `mcp.connection.revoked` | `MCP_CONNECTION_REVOKED` | observe | 1.79 |  | Also `by`: `owner`, `admin`, or `agent_removed` (the person stopped using the agent). |
| `plugin.settings.changed` | `PLUGIN_SETTINGS_CHANGED` | observe | 1.88 | `tenant_id`, `plugin`, `key`, `actor_profile_id` | A plugin setting changed (SDK 1.88). Fired after the change, the return value ignored. Payload: `tenant_id` (null when the deployment's own value changed, which every workspace without its own follows), `plugin` (slug), `key` (bare), `actor_profile_id` (null … |

### Platform

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `navigation.register` | `NAVIGATION_REGISTER` | filter | 1.0 |  |  |
| `permission.registered` | `PERMISSION_REGISTERED` | observe | 1.0 |  |  |
| `datatype.registered` | `DATATYPE_REGISTERED` | observe | 1.89 |  | Boot-time announcements (SDK 1.89). Each names the `source` that registered and what it registered; the return value is ignored. |
| `ou_type.registered` | `OU_TYPE_REGISTERED` | observe | 1.0 |  |  |
| `routing_rule.registered` | `ROUTING_RULE_REGISTERED` | observe | 1.0 |  |  |
| `window_type.registered` | `WINDOW_TYPE_REGISTERED` | observe | 1.0 |  |  |
| `rbac.resource_types.registered` | `RBAC_RESOURCE_TYPES_REGISTERED` | observe | 1.0 |  |  |
| `health.probes.registered` | `HEALTH_PROBES_REGISTERED` | observe | 1.0 |  |  |
| `settings.declared` | `SETTINGS_DECLARED` | observe | 1.0 |  |  |
| `tenant.tables.registered` | `TENANT_TABLES_REGISTERED` | observe | 1.0 |  |  |
| `worker.boot` | `WORKER_BOOT` | observe | 1.0 |  |  |
| `worker.request.start` | `WORKER_REQUEST_START` | observe | 1.0 |  |  |
| `worker.request.end` | `WORKER_REQUEST_END` | observe | 1.0 |  |  |

### Inventory

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `stock.moving` | `STOCK_MOVING` | veto | 1.127 | `tenant_id`, `batch_key`, `source_type`, `source_id`, `reason_code`, `acting_profile_id`, `occurred_at`, `lines` | Before stock moves post, inside their transaction (SDK 1.127). Payload: `tenant_id`, `batch_key`, `source_type`, `source_id`, `reason_code`, `acting_profile_id`, `occurred_at`, `lines` (`variant_id`, `from_location_id`, `to_location_id`, `quantity` in base-unit steps). Throw `HookVetoException` to refuse the whole post. |
| `stock.moved` | `STOCK_MOVED` | observe | 1.127 | `tenant_id`, `batch_key`, `source_type`, `source_id`, `acting_profile_id`, `move_ids`, `moves` | Inside the transaction, after the moves and levels are written. Payload: `tenant_id`, `batch_key`, `source_type`, `source_id`, `acting_profile_id`, `move_ids`, `moves`. One event per post. |
| `stock.moved.async` | `STOCK_MOVED_ASYNC` | queued | 1.127 |  |  |
| `inventory.item.created` | `INVENTORY_ITEM_CREATED` | observe | 1.127 | `tenant_id`, `item_id`, `variant_ids`, `acting_profile_id` | Payload: `tenant_id`, `item_id`, `variant_ids`, `acting_profile_id`. |
| `inventory.item.created.async` | `INVENTORY_ITEM_CREATED_ASYNC` | queued | 1.127 |  |  |
| `inventory.item.updated` | `INVENTORY_ITEM_UPDATED` | observe | 1.127 | `tenant_id`, `item_id`, `changed`, `acting_profile_id` | Payload: `tenant_id`, `item_id`, `changed` (field names), `acting_profile_id`. |
| `inventory.item.updated.async` | `INVENTORY_ITEM_UPDATED_ASYNC` | queued | 1.127 |  |  |
| `inventory.item.archived` | `INVENTORY_ITEM_ARCHIVED` | observe | 1.127 | `tenant_id`, `item_id`, `acting_profile_id` | Payload: `tenant_id`, `item_id`, `acting_profile_id`. |
| `inventory.item.archived.async` | `INVENTORY_ITEM_ARCHIVED_ASYNC` | queued | 1.127 |  |  |
| `inventory.operation.done` | `INVENTORY_OPERATION_DONE` | observe | 1.128 | `tenant_id`, `operation_id`, `kind`, `number`, `batch_key`, `acting_profile_id` | An adjustment, count or transfer is done: its stock has moved, or for a transfer arrived (SDK 1.128). Payload: `tenant_id`, `operation_id`, `kind`, `number`, `batch_key`, `acting_profile_id`. |
| `inventory.operation.done.async` | `INVENTORY_OPERATION_DONE_ASYNC` | queued | 1.128 |  |  |
| `inventory.count.differences` | `INVENTORY_COUNT_DIFFERENCES` | observe | 1.128 | `tenant_id`, `operation_id`, `number`, `location_id`, `waiting_approval`, `lines` | A finished count found differences, before they post or wait for approval. Payload: `tenant_id`, `operation_id`, `number`, `location_id`, `waiting_approval`, `lines` (`variant_id`, `expected`, `counted`, `difference`, `value_minor`, `currency`). |
| `inventory.count.differences.async` | `INVENTORY_COUNT_DIFFERENCES_ASYNC` | queued | 1.128 |  |  |
| `stock.level.below_reorder` | `STOCK_LEVEL_BELOW_REORDER` | observe | 1.128 | `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity`, `order_up_to`, `suggested_quantity`, `supplier_contact_id` | Available stock at a place went under its reorder level; once, until it comes back. Payload: `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity`, `order_up_to`, `suggested_quantity`, `supplier_contact_id`. |
| `stock.level.below_reorder.async` | `STOCK_LEVEL_BELOW_REORDER_ASYNC` | queued | 1.128 |  |  |
| `stock.level.restored` | `STOCK_LEVEL_RESTORED` | observe | 1.128 | `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity` | Back at or above the reorder level. Payload: `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity`. |
| `stock.level.restored.async` | `STOCK_LEVEL_RESTORED_ASYNC` | queued | 1.128 |  |  |
| `stock.lot.expiring` | `STOCK_LOT_EXPIRING` | observe | 1.137 | `tenant_id`, `lot_id`, `variant_id`, `item_id`, `code`, `expires_on`, `on_hand` | A batch with stock expires within the workspace's warning window (7 days unless changed); once per batch, from the daily job. Payload: `tenant_id`, `lot_id`, `variant_id`, `item_id`, `code`, `expires_on`, `on_hand`. |
| `stock.lot.expiring.async` | `STOCK_LOT_EXPIRING_ASYNC` | queued | 1.137 |  |  |
| `stock.lot.expired` | `STOCK_LOT_EXPIRED` | observe | 1.137 |  | A batch with stock passed its expiry date; once, the day after. Payload as `stock.lot.expiring`. |
| `stock.lot.expired.async` | `STOCK_LOT_EXPIRED_ASYNC` | queued | 1.137 |  |  |
| `inventory.order.submitting` | `INVENTORY_ORDER_SUBMITTING` | veto | 1.129 | `tenant_id`, `order_id`, `kind`, `number`, `contact_id`, `location_id`, `currency`, `total_minor`, `acting_profile_id`, `lines` | Before an order goes to its route or is approved (SDK 1.129). Payload: `tenant_id`, `order_id`, `kind`, `number`, `contact_id`, `location_id`, `currency`, `total_minor`, `acting_profile_id`, `lines` (`variant_id`, `quantity`, `price_minor`, `line_total_minor`). Throw `HookVetoException` to refuse it (409 with your reason). |
| `inventory.order.created` | `INVENTORY_ORDER_CREATED` | observe | 1.129 |  | An order was drafted. Every `inventory.order.*` notification carries `tenant_id`, `order_id`, `kind`, `number`, `contact_id`, `status`, `acting_profile_id`. |
| `inventory.order.created.async` | `INVENTORY_ORDER_CREATED_ASYNC` | queued | 1.129 |  |  |
| `inventory.order.approved` | `INVENTORY_ORDER_APPROVED` | observe | 1.129 |  | Approved by its route, or on sending when the workspace has none. |
| `inventory.order.approved.async` | `INVENTORY_ORDER_APPROVED_ASYNC` | queued | 1.129 |  |  |
| `inventory.order.rejected` | `INVENTORY_ORDER_REJECTED` | observe | 1.129 |  | Turned down on its route; back in draft. |
| `inventory.order.rejected.async` | `INVENTORY_ORDER_REJECTED_ASYNC` | queued | 1.129 |  |  |
| `inventory.order.partly_done` | `INVENTORY_ORDER_PARTLY_DONE` | observe | 1.129 |  | Part of it was received. Also `operation_id` (the receipt). |
| `inventory.order.partly_done.async` | `INVENTORY_ORDER_PARTLY_DONE_ASYNC` | queued | 1.129 |  |  |
| `inventory.order.done` | `INVENTORY_ORDER_DONE` | observe | 1.129 |  | Every line received or closed. Also `operation_id` when a receipt finished it. |
| `inventory.order.done.async` | `INVENTORY_ORDER_DONE_ASYNC` | queued | 1.129 |  |  |
| `inventory.order.cancelled` | `INVENTORY_ORDER_CANCELLED` | observe | 1.129 |  | Cancelled before anything arrived. |
| `inventory.order.cancelled.async` | `INVENTORY_ORDER_CANCELLED_ASYNC` | queued | 1.129 |  |  |
| `sales.quote.sending` | `SALES_QUOTE_SENDING` | veto | 1.132 | `tenant_id`, `quote_id`, `number`, `revision`, `contact_id`, `currency`, `total_minor`, `valid_until`, `acting_profile_id`, `lines` | Before a quote goes to the customer, its first send or a revision (SDK 1.132). Payload: `tenant_id`, `quote_id`, `number`, `revision`, `contact_id`, `currency`, `total_minor`, `valid_until`, `acting_profile_id`, `lines` (`variant_id` or null, `description`, `quantity`, `price_minor`, `line_total_minor`). Throw `HookVetoException` to refuse it (409 with your reason). |
| `sales.quote.created` | `SALES_QUOTE_CREATED` | observe | 1.132 |  | A quote or a revision was drafted. Every `sales.quote.*` notification carries `tenant_id`, `quote_id`, `number` (null until sent), `revision`, `contact_id`, `status`, `total_minor`, `currency`, `acting_profile_id` (null when the customer answered online). |
| `sales.quote.created.async` | `SALES_QUOTE_CREATED_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.sent` | `SALES_QUOTE_SENT` | observe | 1.132 |  | Sent to the customer, with its number. |
| `sales.quote.sent.async` | `SALES_QUOTE_SENT_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.accepted` | `SALES_QUOTE_ACCEPTED` | observe | 1.132 |  | The customer accepted, online or as a person recorded. Also `decided_by_name`, `online`. |
| `sales.quote.accepted.async` | `SALES_QUOTE_ACCEPTED_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.declined` | `SALES_QUOTE_DECLINED` | observe | 1.132 |  | The customer declined. Also `decided_by_name`, `online`, `reason`. |
| `sales.quote.declined.async` | `SALES_QUOTE_DECLINED_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.expired` | `SALES_QUOTE_EXPIRED` | observe | 1.132 |  | Past its valid-until date without an answer. |
| `sales.quote.expired.async` | `SALES_QUOTE_EXPIRED_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.converted` | `SALES_QUOTE_CONVERTED` | observe | 1.132 |  | Turned into a target. Also `target` (`sales_order`, `invoice`) and `target_id`. |
| `sales.quote.converted.async` | `SALES_QUOTE_CONVERTED_ASYNC` | queued | 1.132 |  |  |
| `sales.quote.cancelled` | `SALES_QUOTE_CANCELLED` | observe | 1.132 |  | Withdrawn before the customer answered. |
| `sales.quote.cancelled.async` | `SALES_QUOTE_CANCELLED_ASYNC` | queued | 1.132 |  |  |
| `calendar.commission.closed` | `CALENDAR_COMMISSION_CLOSED` | observe | 1.148 | `tenant_id`, `close_id`, `from`, `to`, `acting_profile_id`, `lines` | An owner closed a commission period (WHIT-1334). Carries `tenant_id`, `close_id`, `from`, `to`, `acting_profile_id` and `lines` (`profile_id`, `currency`, `bookings`, `sales_minor`, `commission_minor`). |
| `calendar.commission.closed.async` | `CALENDAR_COMMISSION_CLOSED_ASYNC` | queued | 1.148 |  |  |

### Accounting

| Event | Constant | Kind | Since | Payload | Notes |
| --- | --- | --- | --- | --- | --- |
| `accounting.entry.posting` | `ACCOUNTING_ENTRY_POSTING` | veto | 1.154 | `tenant_id`, `journal`, `date`, `memo`, `source_type`, `source_id`, `acting_profile_id`, `reverses_entry_id`, `lines` | Before a journal entry posts, inside its transaction (SDK 1.154). Payload: `tenant_id`, `journal`, `date`, `memo`, `source_type`, `source_id`, `acting_profile_id`, `reverses_entry_id`, `lines` (`account_id`, `debit_minor`, `credit_minor`, `contact_id`, `ou_id`). Throw `HookVetoException` to refuse the entry. |
| `accounting.entry.posted` | `ACCOUNTING_ENTRY_POSTED` | observe | 1.154 | `tenant_id`, `entry_id`, `number`, `journal`, `date`, `source_type`, `source_id`, `reverses_entry_id`, `total_minor`, `currency`, `acting_profile_id` | Inside the transaction, after the entry, its lines and the balances are written. Payload: `tenant_id`, `entry_id`, `number`, `journal`, `date`, `source_type`, `source_id`, `reverses_entry_id`, `total_minor`, `currency`, `acting_profile_id`. |
| `accounting.entry.posted.async` | `ACCOUNTING_ENTRY_POSTED_ASYNC` | queued | 1.154 |  |  |
| `accounting.entry.reversed` | `ACCOUNTING_ENTRY_REVERSED` | observe | 1.154 | `tenant_id`, `entry_id`, `reversal_entry_id`, `acting_profile_id` | Payload: `tenant_id`, `entry_id` (the original), `reversal_entry_id`, `acting_profile_id`. |
| `accounting.entry.reversed.async` | `ACCOUNTING_ENTRY_REVERSED_ASYNC` | queued | 1.154 |  |  |
| `accounting.period.closed` | `ACCOUNTING_PERIOD_CLOSED` | observe | 1.154 | `tenant_id`, `period_id`, `starts_on`, `ends_on`, `acting_profile_id` | A month was closed. Payload: `tenant_id`, `period_id`, `starts_on`, `ends_on`, `acting_profile_id`. |
| `accounting.period.closed.async` | `ACCOUNTING_PERIOD_CLOSED_ASYNC` | queued | 1.154 |  |  |
| `accounting.period.reopened` | `ACCOUNTING_PERIOD_REOPENED` | observe | 1.154 |  | A closed month was reopened. Payload as closed, plus `reason`. |
| `accounting.period.reopened.async` | `ACCOUNTING_PERIOD_REOPENED_ASYNC` | queued | 1.154 |  |  |
| `accounting.invoice.issuing` | `ACCOUNTING_INVOICE_ISSUING` | veto | 1.155 | `lines` | Before an invoice or credit note is issued (numbered, frozen and posted) (SDK 1.155). Payload as `accounting.invoice.issued`, plus `lines` (`variant_id`, `description`, `quantity`, `unit_price_minor`, `net_minor`, `tax_code`, `tax_minor`, `total_minor`). Throw `HookVetoException` to refuse it (a credit limit). |
| `accounting.invoice.issued` | `ACCOUNTING_INVOICE_ISSUED` | observe | 1.155 | `tenant_id`, `invoice_id`, `kind`, `number`, `contact_id`, `status`, `issue_date`, `due_date`, `currency`, `total_minor`, `tax_minor`, `due_minor`, `credits_invoice_id`, `acting_profile_id` | An invoice or credit note was issued. Payload: `tenant_id`, `invoice_id`, `kind` (`sale`, `sale_credit`), `number`, `contact_id`, `status`, `issue_date`, `due_date`, `currency`, `total_minor`, `tax_minor`, `due_minor`, `credits_invoice_id`, `acting_profile_id`. |
| `accounting.invoice.issued.async` | `ACCOUNTING_INVOICE_ISSUED_ASYNC` | queued | 1.155 |  |  |
| `accounting.invoice.credited` | `ACCOUNTING_INVOICE_CREDITED` | observe | 1.155 |  | A credit note settled an invoice. Payload as issued (the invoice), plus `credit_note_id` and `amount_minor`. |
| `accounting.invoice.credited.async` | `ACCOUNTING_INVOICE_CREDITED_ASYNC` | queued | 1.155 |  |  |
| `accounting.invoice.paid` | `ACCOUNTING_INVOICE_PAID` | observe | 1.156 |  | Payments settled all of an invoice (SDK 1.156). Payload as `accounting.invoice.issued`. |
| `accounting.invoice.paid.async` | `ACCOUNTING_INVOICE_PAID_ASYNC` | queued | 1.156 |  |  |
| `accounting.invoice.partly_paid` | `ACCOUNTING_INVOICE_PARTLY_PAID` | observe | 1.156 |  | A payment settled part of an invoice. Payload as `accounting.invoice.issued`. |
| `accounting.invoice.partly_paid.async` | `ACCOUNTING_INVOICE_PARTLY_PAID_ASYNC` | queued | 1.156 |  |  |
| `accounting.payment.recorded` | `ACCOUNTING_PAYMENT_RECORDED` | observe | 1.156 | `tenant_id`, `payment_id`, `direction`, `number`, `contact_id`, `paid_on`, `amount_minor`, `currency`, `method`, `status`, `cheque_status`, `unallocated_minor`, `acting_profile_id` | A payment was recorded and posted (money received or a transfer). Payload: `tenant_id`, `payment_id`, `direction`, `number`, `contact_id`, `paid_on`, `amount_minor`, `currency`, `method`, `status`, `cheque_status`, `unallocated_minor`, `acting_profile_id`. |
| `accounting.payment.recorded.async` | `ACCOUNTING_PAYMENT_RECORDED_ASYNC` | queued | 1.156 |  |  |
| `accounting.payment.allocated` | `ACCOUNTING_PAYMENT_ALLOCATED` | observe | 1.156 |  | A payment settled an invoice. Payload as recorded, plus `invoice_id` and `amount_minor` (the share). |
| `accounting.payment.allocated.async` | `ACCOUNTING_PAYMENT_ALLOCATED_ASYNC` | queued | 1.156 |  |  |
| `accounting.cheque.bounced` | `ACCOUNTING_CHEQUE_BOUNCED` | observe | 1.156 |  | A cheque bounced: its entries were reversed and the invoices it paid reopened. Payload as recorded, plus `fee_minor` and `reopened_invoice_ids`. |
| `accounting.cheque.bounced.async` | `ACCOUNTING_CHEQUE_BOUNCED_ASYNC` | queued | 1.156 |  |  |
| `accounting.bill.posting` | `ACCOUNTING_BILL_POSTING` | veto | 1.157 |  | Before a supplier bill or credit is posted (SDK 1.157). Payload as `accounting.invoice.issuing`, with `kind` `purchase` or `purchase_credit`. Throw `HookVetoException` to refuse it. |
| `accounting.bill.posted` | `ACCOUNTING_BILL_POSTED` | observe | 1.157 |  | A supplier bill or credit was posted. Payload as `accounting.invoice.issued`. |
| `accounting.bill.posted.async` | `ACCOUNTING_BILL_POSTED_ASYNC` | queued | 1.157 |  |  |
| `accounting.bill.paid` | `ACCOUNTING_BILL_PAID` | observe | 1.157 |  | Payments settled all of a bill. Payload as `accounting.invoice.issued`. |
| `accounting.bill.paid.async` | `ACCOUNTING_BILL_PAID_ASYNC` | queued | 1.157 |  |  |
| `accounting.expense.claimed` | `ACCOUNTING_EXPENSE_CLAIMED` | observe | 1.158 |  | A person filed an expense claim (SDK 1.158). Payload as `accounting.invoice.issued` (a `purchase`), plus `claimant_profile_id`. |
| `accounting.expense.claimed.async` | `ACCOUNTING_EXPENSE_CLAIMED_ASYNC` | queued | 1.158 |  |  |

<!-- event-catalogue:end -->

### Deletion is transactional (WC-713)

For `tenant.*`, `ou.*` and `role.*`, `TenantsApiHandler::delete()`, `OusApiHandler::delete()` and `RolesApiHandler::delete()` run

```
*.deleting  →  DELETE  →  *.deleted        [ all inside ONE transaction ]
                                            ↓ commit
                                        *.deleted.async
```

Three guarantees follow:

1. **`*.deleting` still sees the row.** It is the read point — look up whatever you need about the entity there, because by `*.deleted` it is gone (within the transaction).
2. **A throwing listener rolls the DELETE back.** Previously the DELETE committed on its own and `*.deleted` fired afterwards, so a plugin's cleanup was best-effort: a failure left the caller a 500 *and* a committed delete, with the plugin's own rows orphaned against a parent that no longer existed. Core tables survive that on their `ON DELETE CASCADE` constraints; plugin tables have no FK, so the hook was their only mechanism.
3. **`*.deleted.async` fires only after a successful commit**, so no durable/outbox event ever announces a deletion that was rolled back.

Throw `HookVetoException` from either hook to refuse the deletion deliberately; the API answers **409 Conflict** with your `reason()` under `details.reason`. Any other Throwable rolls the delete back too, but surfaces as a generic 500.

`user.deleted` is deliberately **not** part of this contract: it has no `user.deleting` counterpart, and its core listeners send mail — which must neither hold a transaction open nor be able to undo a membership removal.

> **Core subscriber — the audit trail (WC-34).** `AuditLogger` (`src/Core/Audit/AuditLogger.php`) subscribes (at priority 50) to the post-action `role.*`, `user.*`, `tenant.*` and `ou.*` lifecycle hooks and writes a row to `audit_log` for each — so security-relevant mutations are audited without per-handler code. To support this, `UsersApiHandler::update()`/`delete()` now also fire `user.updated` / `user.deleted` (carrying `id` + `tenant_id`). The listeners return `$data` unchanged. See [AUDIT_TRAIL](AUDIT_TRAIL.md).

## Events fired by a plugin

The event space is flat and shared, so a plugin's own events are **namespaced under the plugin**: dispatch `Events::forPlugin($this->getName(), 'task.completed')` — `acme:task.completed` — rather than a bare name. Two plugins that both dispatch `item.created` otherwise run each other's listeners, which is a bug neither author can see in their own repository.

That prefix is also how the audit trail attributes a plugin's activity. A plugin declaring the event via [`PluginEventsInterface`](../../sdk/src/PluginEventsInterface.php) (SDK 1.29) has it recorded as action `acme:task.completed` against target type `acme:task`; the host binds its listener to the namespaced name precisely so a row can only ever be credited to the plugin whose prefix it carries. See [Plugin-Development](Plugin-Development.md) Step 11.

## Best practices

1. **Always return data from sync hooks** — a missing `return` breaks the filter chain for downstream listeners.
2. **Scope by tenant** — use `$context['tenant_id']` in any query a listener runs.
3. **Keep payloads scalar** — pass ids/strings, not live model objects, so listeners can't mutate shared object state and escape the chain.
4. **No request state in statics** — workers persist; never accumulate per-request state in a static variable inside a listener.
5. **Fail loudly in validators** — throwing in a sync `*.creating`/`*.updating` listener is fine; the plugin error boundary will catch a plugin listener's throw, log it, and leave the data unchanged (and count it toward the plugin's failure threshold).
6. **Veto with `HookVetoException`, not a bare throw** — on the deletion paths and on `datatype.lifecycle.changing` it is the only Throwable that reaches the caller as a clean 409 with your reason; anything else is a generic 500 (deletion) or is swallowed while the transition proceeds (data-type lifecycle). Write `reason()` for a human administrator, never as raw internal error text — it is shown to the client. A veto never counts toward the plugin failure breaker.

## Summary

- `HookManager` is an instance-based Mediator/Observer; `dispatch()` is a synchronous filter chain, `dispatchAsync()` queues background work.
- Listeners run in ascending priority (default 10); every dispatch injects `{tenant_id, timestamp}` context.
- Plugins declare hooks via `PluginInterface::getHooks()`; the loader subscribes them through `HookManager::listen()` inside a per-plugin error boundary and unsubscribes them on disable/reload via `removeListener()`.
- A plugin's OWN events are namespaced under it (`Events::forPlugin(…)` → `acme:task.completed`); declaring them via `PluginEventsInterface` puts them in the audit trail under that same prefix, and the loader unsubscribes those listeners on disable alongside the plugin's other hooks.
- Core fires worker lifecycle, navigation, permission-registration, and role lifecycle events; confirm `user.*`/`tenant.*`/`ou.*` payload shapes in their handlers.
- Entity deletion (`tenant`/`ou`/`role`) runs `*.deleting` → DELETE → `*.deleted` in one transaction; a throwing listener rolls it back, `HookVetoException` turns that into a 409, and `*.deleted.async` fires only after commit.
</content>
