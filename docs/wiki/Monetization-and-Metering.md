# Monetization & Metering

How whity decides **what a tenant may use**, **how much**, and **whether their
subscription still entitles them to it**. This guide ties together four layers that
were built to stack cleanly on top of one another:

```
ENTITLEMENTS  →  PLANS  →  SUBSCRIPTIONS  →  PAYMENT WALL       (feature/quota gate + billing state)
                    ↘                          RATE LIMITS       (per-request throughput, plan-driven)
```

The design goals throughout: **one runtime access gate** (no second resolution
path), **billing-correct auditability** (what a customer has is a stable fact),
**provider-agnostic** (no PSP is assumed), and **each client is different** (nothing
load-bearing is hardcoded — it's a setting/entitlement with a smart default).

See also: [ADR 0010](../adr/0010-subscription-plans-and-billing-extensibility.md)
(plans/billing model) and
[ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md) (enforcement).

---

## 1. Entitlements — the runtime gate

**What:** per-tenant feature flags and quotas. A boolean grants a capability; an
integer sets a limit (`-1` = unlimited).

- `EntitlementRegistry` — the code catalogue of entitlement keys, their type
  (bool/int), and their **free-tier baseline default** (an unregistered key is
  never granted).
- `tenant_entitlements` — tenant-owned rows holding per-tenant overrides.
- `EntitlementService` — the **single access-control choke point**:
  - `isGranted(key)` — boolean capability check.
  - `limit(key)` — integer quota (`-1` = unlimited).
  - `effective(key)` — resolved value: **tenant override ?? registry default**.
- **The system tenant (id 0) is implicitly unlimited** — it is the operator.

Every feature that needs gating asks `EntitlementService`, and only that. Plans and
subscriptions layer on top **without** introducing a second thing consumers must
check.

## 2. Plans — a catalog that materialises into entitlements

**What:** named bundles ("Free", "Pro", "Enterprise") an operator applies in one
step. See [ADR 0010](../adr/0010-subscription-plans-and-billing-extensibility.md).

- `plans` — global catalog (`plan_key`, name, `is_active`, `sort_order`).
  **Deliberately carries no price columns** — it is the anchor future billing tables
  reference.
- `plan_entitlements` — a plan's bundle: `(plan_id, entitlement_key, value)`,
  validated against the `EntitlementRegistry`. New entitlements are automatically
  plan-able.
- `tenant_plan` — which plan a tenant is on (tenant-owned).
- `PlanService::applyToTenant(planId, tenantId)` — **materialises** the plan:
  in one transaction it resets `tenant_entitlements` to exactly the plan's bundle
  (keys the plan sets → that value; keys it doesn't → registry default) and records
  `tenant_plan`.

**Read live (migration 151).** A tenant on a plan reads the plan's values at
runtime, so re-tuning a plan reaches every tenant on it at once; `PlanService`
refuses a change that takes something away from live tenants unless it is
confirmed. Add-on packs (`is_addon` plans, `setTenantAddon`) add their numbers on
top. A per-tenant override set through the entitlements API is final over both.

## 3. Subscriptions — lifecycle state on the tenant↔plan anchor

**What:** the lifecycle of a tenant's paid access. See
[ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md).

Migration `057` adds subscription state to the existing `tenant_plan` row (not a new
table): `status`, `current_period_end`, `grace_until`, `enforcement_mode`,
`external_ref`.

- `status` — `active` / `trialing` / `past_due` / `canceled` (etc.).
- `grace_until` — a `past_due` tenant keeps access until this deadline (driven by the
  `billing.grace_days` setting).
- `external_ref` — **the webhook seam.** An opaque provider id a future PSP webhook
  writes; **nothing in core reads it today** — billing collection is provider-agnostic
  and out of band.
- `SubscriptionService` — stores/reads this state and answers the wall's question
  via `decide(tenantId, isWrite)`.

**Payment is external state, not a payment we process.** whity is told the status
(an admin sets it, or a future webhook writes it) and enforces on it. There is no
Stripe integration; billing *collection* (`plan_prices`, `promo_codes`/early-bird,
the PSP webhook) is a deferred additive layer that hangs off `plans`/`tenant_plan`
(ADR 0010's extension points) and never touches the entitlement engine.

## 4. Payment wall — enforcement at the boundary

`PaymentWall` middleware (in the `HttpKernel` pipeline) calls
`SubscriptionService::decide()` and short-circuits a lapsed tenant with **402 Payment
Required** + an `X-Subscription-Status` header (and an optional `Link` to the billing
URL). See [ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md).

**Enforcement mode is a per-tenant operator setting**, resolved as:

> per-tenant `tenant_plan.enforcement_mode` **??** global `billing.enforcement_default`
> setting **??** `warn`

| Mode | Behaviour when a tenant is lapsed |
|------|-----------------------------------|
| `off` | Never wall (single-customer / internal deployments). |
| `warn` | Allow everything; stamp `X-Subscription-Status` so the UI can nudge. **Default.** |
| `block_writes` | Reads allowed; mutations get 402. |
| `block_all` | Fully walled (402). |

An `active`/`trialing` tenant, or a `past_due` tenant still within `grace_until`, is
always allowed regardless of mode.

**Hard exemptions — lockout is structurally impossible:**

1. **Public / unauthenticated** requests (no resolved tenant) → pass.
2. **The system tenant (id 0)** → always pass. *The operator is never walled* — an
   admin can always upgrade a tenant even if billing lapsed externally.
3. **Billing / subscription-management routes** (e.g. `/api/v1/subscription`) →
   always reachable, so an admin can inspect and fix billing state while walled.

The whole layer is a no-op unless `BILLING_WALL_ENABLED` is set — a deployment opts
in. `BILLING_URL` supplies the 402 `Link` target.

## 5. Rate limits — throughput, plan-driven

`RateLimitMiddleware` + `RateLimitRule` meter requests across several scopes, backed
by a Postgres `shared_store` so limits hold across FrankenPHP workers:

- **`ip`** / **`tenant`** / **`principal`** — fixed-window counters per scope.
- **`tenantEntitled`** — the per-tenant request-per-minute cap reads the tenant's
  **`ratelimit.rpm` entitlement** (via `RateLimitRule::limitFor()`), so throughput
  **scales with the plan** — a "Pro" tenant simply has a higher `ratelimit.rpm`
  entitlement. Nothing about the number lives in code.
- **`platform`** — a global safety cap protecting the deployment as a whole.

This is the same principle as the wall: the *number* is an entitlement/setting, the
*mechanism* is generic. A single-customer sovereign deployment and a busy multi-tenant
SaaS get the right behaviour from the same code with different data.

## 6. Usage meters and the usage ledger (section 33 §5, BM.17)

Every limit only warns (BM.4, SC.14): past a base, the work goes ahead and the
people who can change the plan are told. `meters.enforcement` defaults to
`warn`; an operator may still set `block` for document renders.

`usage_ledger` (migration 332) is the durable history a bill is made from: one row
per workspace, meter, month (in the workspace's own time zone) and dimension.

| Meter | Base on | Kind | Fed by |
|---|---|---|---|
| `seats` | `members.max` | snapshot | `SeatService::used()` |
| `customers` | `customers.per_month` | snapshot | `CustomerUsage` |
| `inventory.items` | `inventory.items.max` | snapshot | `PlanLimitsReport::liveItems()` (IV.24) |
| `invoices` | `invoices.per_month` | snapshot | accounting's `InvoiceUsageCounter`; 0 until accounting binds one |
| `storage.bytes` | `storage.quota_bytes` | snapshot | `tenant_storage_usage` (migration 333), kept by `StoredFiles` |
| `messages.outbound` | `messages.outbound.per_month` | counted, per channel | `MessagingService` |
| `agents` | `agents.runs.per_month` (on `runs`) | counted: `runs`, `tool_calls` | `McpUsage`. A run is one agent's work in the 30 minutes from its first call. Tokens only when Whity supplies a model |
| `api.calls` | `api.calls.per_month` | counted: `mcp` | `McpUsage`: MCP calls from a person's own AI |

Snapshot meters are read once a day (`core.usage.snapshot`, queued by
`schedule:run`) and whenever the usage screen opens; each month keeps the latest
reading and the highest. Whity's own apps (web, phone, desktop) never count as API
calls.

- Owners read it on **Billing > Usage** (`GET /api/usage?period=YYYY-MM`,
  `billing:view`); the limits card and banner (`GET /api/billing/limits`) read the
  same ledger. Plugins read it through `Whity\Sdk\Usage\Usage` (SDK 1.133).
- `whity-cli usage:storage-rebuild [--workspace=<id>] [--check]` sets stored bytes
  from the files again; `whity-cli usage:snapshot` takes the snapshots now.
- Every core limit defaults to unlimited, so a self-hosted install has no limits
  (BM.3 Q14). The Free base is set on the free plan; migration 334 adds invoices
  and API calls.

---

## Operator quick reference

| I want to… | Do this |
|---|---|
| Give a tenant a tier | `PlanService::applyToTenant(plan, tenant)` (materialises entitlements). |
| Bump one tenant's quota without a plan | Set the entitlement directly on that tenant (cleared on next plan re-apply). |
| Turn on billing enforcement | Set `BILLING_WALL_ENABLED`; set `billing.enforcement_default` (global) and/or per-tenant `enforcement_mode`. |
| Give a lapsed tenant a grace period | `billing.grace_days` (global) + set the tenant `past_due` with `grace_until`. |
| Raise a tenant's request rate | Raise its `ratelimit.rpm` entitlement (or put it on a higher plan). |
| Never wall the operator | Nothing — the system tenant (id 0) and billing routes are exempt by construction. |

## Invariants (do not break)

- `EntitlementService` is the **only** runtime access gate. Plans/subscriptions feed
  it; they are not a second path.
- The **system tenant (id 0) is never walled and is implicitly unlimited.**
- Enforcement runs on **stored state**, not a payment whity processes — keep it
  provider-agnostic; the PSP webhook writes `status`/`external_ref` only.
- **No hardcoded tunables** — quotas, caps, grace, enforcement strength are
  entitlements/settings with smart defaults (per-tenant ?? global ?? registry).
- Every tenant-owned query (`tenant_entitlements`, `tenant_plan`) carries an explicit
  `tenant_id` predicate; the tables are registered in `TenantOwnedTables`.
