# Threat Model (STRIDE)

A trust-boundary-driven threat model for Whity Core, grounded in the current source — every claim below cites the file that enforces (or fails to enforce) it. Written as part of an adversarial internal security audit (WC-security-audit); see [Pen-Test-Scoping-Brief](Pen-Test-Scoping-Brief.md) for the external-facing companion document (what still needs professional testing, and what this pass already found/fixed).

**Refreshed 2026-11-22 (WHIT-1335)** against develop `2a7247613` for the security readiness audit: Whity ID SSO, plugin signing and the kill switch, plugin UI frames, MCP approvals, usage meters, chat and the public surfaces are new sections; the open gaps they found (TM-1 to TM-25) are tracked in [READINESS](../security/READINESS.md#b3-whity-core-threat-model-gaps).

Related: [Architecture](Architecture.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [Plugin-Development](Plugin-Development.md) · [AUDIT_TRAIL](AUDIT_TRAIL.md).

## How to read this document

STRIDE classifies threats by the property they violate: **S**poofing (identity), **T**ampering (integrity), **R**epudiation (non-deniability), **I**nformation disclosure (confidentiality), **D**enial of service (availability), **E**levation of privilege (authorization). Each section below names the trust boundary, the assets it protects, the realistic attackers, and the STRIDE threats that apply — with the actual enforcing code cited, not aspirational controls. Where a control is a documented, *accepted* residual risk (not a bug), it is called out as such rather than silently omitted.

## System overview

```mermaid
flowchart LR
    Browser["Browser (staff)"] -->|httpOnly cookies| Next["Next.js UI (web/)"]
    Visitor["Anonymous visitor<br/>(public forms, booking, web chat)"] -->|no session| Next
    NativeClient["Native/desktop client<br/>(device credential)"] -->|Bearer JWT| FP
    Next -->|"/api/* proxy<br/>web/app/api/[...path]/route.ts"| FP["FrankenPHP workers<br/>(public/index.php)"]
    Next -. "postMessage bridge (frame/1)" .- Frame["Plugin embed iframe<br/>(sandbox: allow-scripts allow-forms, opaque origin)"]
    AI["MCP clients / agents"] -->|"Bearer: MCP key or Whity ID OAuth token"| FP
    subgraph Core["Whity Core (trusted compute)"]
        FP --> Kernel["HttpKernel + middleware pipeline"]
        Kernel --> Plugins["Code plugins<br/>(in-process, full trust)"]
        Kernel --> WsPlugins["Workspace plugins<br/>(declarative manifests)"]
        Kernel --> DB[("PostgreSQL<br/>shared, all tenants")]
    end
    FP <-->|"OIDC (PKCE, nonce), JWKS"| WID["Whity ID<br/>(operator IdP, OAuth server for MCP)"]
    FP -->|"SSRF-guarded OIDC"| TIdP["Workspace-owned IdPs"]
    Pay["Whity Pay / Polar"] -->|"HMAC-signed webhooks"| FP
    FP -->|"internal HTTP,<br/>shared secret"| Render["whity_render<br/>(headless Chromium)"]
    FP -->|"allowlisted host +<br/>Ed25519 attestation"| Store["Plugin store"]
    Mailer["SMTP / mail transport"] --- FP
```

**Trusted compute boundary**: everything inside the FrankenPHP worker process — core handlers, repositories, and every loaded plugin — runs as one first-party trust domain with one shared PostgreSQL connection per worker. There is no OS-level sandboxing between core and plugin code (see [Plugin sandbox boundary](#4-the-plugin-boundary-e---elevation-of-privilege-first)). The boundaries that matter are therefore *logical*: the tenant boundary enforced by `TenantContext` + query predicates, the auth boundary enforced by JWT validation + RBAC, and the network boundaries at the edges (browser, render service, marketplace, IdPs).

## Assets

| Asset | Why it matters | Primary control |
| --- | --- | --- |
| Tenant business data (users, documents, notifications, taxonomy, …) | Confidentiality/integrity across 185 tenant-owned tables in one shared DB | `TenantContext` + per-query `tenant_id` predicate ([TENANT_ISOLATION](TENANT_ISOLATION.md)) |
| Identity/credentials (`profiles.password_hash`, TOTP secrets, backup codes) | Account takeover if disclosed or forged | bcrypt hashing, AES-256 authenticated encryption at rest, single-use backup codes |
| Session material (JWT access/refresh, `token_epoch`, `revoked_tokens`) | Impersonation if forged or replayed | HS256 HMAC signing, per-token + per-profile revocation ([Auth boundary](#3-the-authsession-boundary)) |
| Admin approval queues (password-reset, 2FA-recovery) | Account-takeover-adjacent — approving one clears a second factor or applies a password | `permission_delegations`-style RBAC gate + re-verified tenant membership JOIN at approve/reject time |
| Plugin marketplace packages | Supply-chain: a malicious package runs as fully-trusted core code once installed | SSRF-guarded fetch + host allowlist + `PluginInstaller` validation + Ed25519 attestation (built, **inactive until a key is pinned**, TM-1) |
| Rendered documents (PDF via `whity_render`) | Tenant content confidentiality; render service is a second compute surface | Shared-secret auth, no public exposure, size/time bounds |
| Audit log | Forensic record of security-relevant events | `AuditLogger` is the only writer and no code path updates or deletes rows; the table itself is not tamper-evident and is deleted with its workspace (CO-5, CO-6) |
| Plugin trust root (pinned Ed25519 keys, revocation list) | Decides which code runs as fully trusted core | `src/Core/Plugin/Trust/TrustedKeys.php`, `PluginTrust::checkAttestation` |
| Agent grants and pending proposals | Decide what an AI can do without a person | `AgentScopeGrant`, `AgentProposalRepository` (atomic decide) |
| Frame tokens (`plugin_frame`) | A person's authority, scoped to one plugin's routes | `PluginFrameTokens::admits()`, Bearer only, 300 s |
| Usage ledger and meter counters | Drive the bill and the plan limits | `UsageLedger` (atomic upserts), `MeterService` |
| Billing webhook secret and event ledger | Plan and access changes | `WebhookVerifier`, `EventLedger` |
| Chat history and attachments | Private staff and customer conversations | `ChatService` membership checks |

## Attackers considered

| Actor | Capability | Example goal |
| --- | --- | --- |
| Anonymous internet attacker | No credentials; can reach every public route | Enumerate accounts, brute-force login/2FA, forge tokens, SSRF via any operator-configurable URL field |
| Authenticated user, tenant A | Valid JWT for one ordinary tenant/role | Read/modify tenant B's data (IDOR), escalate role within own tenant |
| Malicious/compromised tenant admin | Full admin rights *within their own tenant* | Escalate to system-tenant authority, pivot to other tenants via a shared surface (marketplace, SSO config, render service) |
| Malicious plugin author | Can get a package installed (self-hosted store, or a mis-scoped `plugins:upload` grant) | Full host compromise — see [Plugin boundary](#4-the-plugin-boundary-e---elevation-of-privilege-first) |
| Network-position attacker (MITM, compromised proxy, shared network) | Can observe/replay traffic that isn't protected by TLS end-to-end, or intercept a displayed/typed code | TOTP replay, cookie theft if TLS is misconfigured at the edge |
| Operator/insider with deploy access | Can set environment variables, read secrets at rest | Weak secrets, disabled guards — this is why secret-strength is *enforced in code*, not left to operational discipline alone |
| Prompt-injection author (customer, form submitter, chat visitor) | Puts text into data an AI client later reads | Get an MCP client to send, delete or leak data |
| Plugin page script (embed frame) | Runs JS in a sandboxed, opaque-origin iframe | Reach core APIs or the person's session through the bridge |
| Workspace admin with their own IdP | Controls a workspace's OIDC issuer | Use members' global accounts outside the workspace |
| Anonymous public submitter | Uses public form, booking and web-chat routes | Spam, enumeration, filling quotas |
| Forged billing caller | Can reach `/api/billing/*webhook` | Upgrade a workspace without paying |

---

## 1. The tenant boundary (I / T — the platform's #1 risk)

**Assets**: every tenant-owned row in the shared PostgreSQL database (~35 tables — see [TENANT_ISOLATION](TENANT_ISOLATION.md#the-query-layer--explicit-predicates-proven-by-tests) for the full, current list in `src/Core/Tenant/TenantOwnedTables.php`).

**Boundary**: `TenantContext` (request-scoped, JWT-derived, locked-after-set) + `EnforceTenantIsolation` (HTTP-layer refusal before any handler/DB work) + a hand-written `tenant_id` predicate on every tenant-owned-table query, proven by `tests/Integration/CrossTenantRejectionRealEngineTest.php` and statically enforced by `scripts/ci-tenant-predicate-guard.php`.

- **Information disclosure**: a caller reads another tenant's rows. Blocked at three layers (HTTP declared-target gate, query predicate, CI static guard) — see the re-verification below.
- **Tampering**: a caller writes/deletes another tenant's rows. The same predicate applies to `UPDATE`/`DELETE`; a cross-tenant write matches zero rows and surfaces as 404, verified by the same real-engine suite (WC-161/190/191).
- **Elevation of privilege**: a caller declares a *different* tenant via the `X-Tenant-Id` header / `tenant_id` query / `/api/tenants/{id}` path and expects the server to honor it. This is the **declared-target boundary** (WC-193): these three signals are read in exactly one place, feed exactly one consumer (the cross-tenant gate), and can only ever *match the caller's own JWT tenant or be refused* — never widen access. Fail-closed parsing (`ctype_digit()`/`(\d+)`) means a crafted value (`-1`, `2.0`, `0x2`, `2; DROP`) resolves to *no declared target* rather than a coercible one.
- **Spoofing** (of tenant identity): would require forging the JWT's `tenant_id`/`active_tenant_id` claim — covered under the [auth boundary](#3-the-authsession-boundary) (HMAC signature + hard-pinned algorithm).

### Re-verification of the 2026-07-20 investigation

The prior investigation (2026-07-20, Tasker task `9516c095`) audited all 21 then-current tenant-owned tables against migrations, the CI predicate scanner, and the real-engine rejection suite, concluding **no live cross-tenant leak** (one test-coverage gap found and closed in PR #573).

Since then, seven feature phases landed and each added tables. This audit re-derived `TenantOwnedTables::all()` and `SanctionedGlobalTables::all()` from the current migrations and re-ran the full triad (registry + `scripts/ci-tenant-predicate-guard.php` + `CrossTenantRejectionRealEngineTest`-style real-engine proof, plus the newer per-feature real-engine suites) to confirm the conclusion still holds:

| Phase / feature | New tenant-owned tables | Isolation mechanism verified |
| --- | --- | --- |
| Document/label designer (WC-docdesigner) | `document_templates`, `document_blocks` | `findById($id, $tenantId)` binds both id AND tenant_id in the repository (`src/Core/Document/DocumentTemplateRepository.php`) — a cross-tenant id returns `null` → 404, never leaks existence. RBAC row-filtering (`DocumentAccessPolicy`) is layered on top, not instead of, this. |
| Feature flags / settings audit | (no new tenant table; `tenant_settings` pre-existing) | `SettingsService` reads tenant overrides scoped by `tenant_id`; global defaults live in the sanctioned-global `app_settings`. |
| Admin-enforced 2FA policy (WC-525) | `two_factor_policies` | Every query binds `tenant_id`; a policy can never leak across tenants. |
| Native taxonomy/tagging (WC-621) | `tag_groups`, `tags`, `entity_tags` | All three carry `tenant_id NOT NULL`; `entity_tags.tenant_id` is denormalized from the tag's tenant so the predicate and the reverse-lookup index sit on one row. |
| Durable job queue + event spine (WC-queue, #154) | `jobs`, `domain_events`, `event_outbox` | Each row carries the enqueuing tenant's id, restored into `TenantContext` before its handler runs; the queue/relay *mechanics* (reserve/complete/reclaim) run as audited system infra across tenants, annotated `@tenant-guard-ignore` with a stated reason in `JobRepository`/`DomainEventStore` — not a silent gap. |
| Scheduler (#a934420e) | `scheduled_jobs` | Tenant-scoped CRUD binds `tenant_id`; the cron tick claims due rows across tenants (same annotated system-infra pattern as the queue) and stamps each enqueue with the row's origin tenant. |
| Notifications (#d89dcc2c / #c56a6455 / #2aa3411a / #d70c6083) | `notifications`, `notification_deliveries`, `user_notification_preferences`, `notification_templates`, `tenant_notification_settings` | `NotificationRepository` binds `tenant_id` **and** `recipient_profile_id` together on per-recipient reads (`src/Core/Notification/NotificationRepository.php`), so even a same-tenant user cannot read another user's notification by guessing its id. `notification_templates` uses the tenant-0-is-global convention (mirrors base roles). |
| Password-reset / 2FA-recovery (WC-password-reset-2fa-recovery) | *(none — intentionally global, see below)* | See next subsection. |

**Password-reset / 2FA-recovery — the intentionally-global tables, re-verified.** `password_resets` and `two_factor_recovery_requests` carry **no `tenant_id` column** by design (they key on the global `profiles` identity anchor, ADR 0005 — a credential belongs to a person, not a tenant) and are correctly enumerated in `src/Core/Tenant/SanctionedGlobalTables.php`. The admin approval **queue** for each is tenant-scoped **at query time via a JOIN to `memberships`**, not via a column on the table itself. This audit read the actual queries in `src/Core/Identity/PasswordResetService.php` and `src/Core/Identity/TwoFactorRecoveryService.php` and confirmed, for every one of `listPendingForTenant()`, `approveForTenant()`, and `rejectForTenant()`:

```sql
-- approveForTenant(): the lookup itself re-verifies membership — a request id
-- from a profile with NO active membership in the caller's tenant matches zero rows.
SELECT pr.id, pr.profile_id, pr.staged_password_hash, pe.email
FROM password_resets pr
JOIN memberships m ON m.profile_id = pr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
...
WHERE pr.id = :id AND pr.status = 'awaiting_approval'
```

Crucially, this membership check is re-run on **every** state-changing call (not just on the initial list), including `rejectForTenant()`'s `UPDATE ... WHERE id = :id AND status = '...' AND EXISTS (SELECT 1 FROM memberships ...)` — so a tenant-A admin cannot approve/reject a request id belonging to a tenant-B-only profile even by guessing/enumerating ids directly against the approve/reject endpoints, bypassing the list. This was verified by code reading (the query text above) and by the existing real-engine test suites for these services; see [IDOR testing](#2-authzidor--rbac-gated-resources-by-id) below for the dynamic attempt.

**Conclusion: the tenant-isolation invariant still holds.** No drift found. The registry/scanner/test triad correctly tracks every table added since 2026-07-20, and the two intentionally-global tables from the newest feature (password-reset/2FA-recovery) use the same JOIN-to-`memberships` re-verification pattern already established for the registrations approval queue, checked on every mutating call, not just at list time.

## 2. Authz/IDOR — RBAC-gated resources by id

**Boundary**: `RbacMiddleware` (route-declared `requiredRole`/`requiredPermission`, re-checked against the DB via `RoleChecker` — the JWT's own role/permission claims are never trusted) + handler-level ownership checks that bind the resource id together with the caller's tenant/profile id in the same query.

Handlers spot-checked (code review; several also exercised as real-engine tests in this repo, see below):

| Handler | Ownership binding | Cross-tenant/cross-user id manipulation result |
| --- | --- | --- |
| `DocumentTemplatesApiHandler` / `DocumentBlocksApiHandler` | `findById($id, $tenantId)` | 404 (row not found for that tenant) — verified in `tests/Api/DocumentTemplatesApiHandlerRealEngineTest.php` / `DocumentBlocksApiHandlerRealEngineTest.php` |
| `PasswordResetApprovalsApiHandler` / `TwoFactorRecoveryApprovalsApiHandler` | JOIN to `memberships` on every approve/reject, re-checked per call (not cached from the list) | `null` return → generic 404 (`'No pending ... request found for that id'`) |
| `NotificationRepository` (via its API handlers) | `tenant_id` **and** `recipient_profile_id` bound together | A same-tenant user presenting another user's notification id gets no row |
| `RolesApiHandler` | `(tenant_id = ? OR tenant_id IS NULL)` on read; owner-only on write | A regular tenant gets 404 modifying a global base role or another tenant's custom role — only the system tenant (id 0) can |
| `OusApiHandler`, `UsersApiHandler` | tenant-bound lookups (`WHERE ... AND tenant_id = ?`) | Same 404-on-mismatch pattern |

No handler in this sample resolves a resource by bare id and defers tenant/ownership scoping to a *later* check (the classic IDOR shape — "fetch first, authorize second, and the fetch already leaked existence via timing/error-shape"). The consistent pattern across the codebase is to bind the id **and** the scoping predicate in the **same** query, so an out-of-scope id simply never matches a row — a 404, not a 403, which also avoids confirming the id's existence to an unauthorized caller.

**Dynamic verification**: the full `tests/Integration/CrossTenantRejectionRealEngineTest.php` and per-feature real-engine suites (document templates/blocks, notifications, roles, OUs, users, password-reset/2FA-recovery) were re-run against a real PostgreSQL engine as part of this audit (see the [pen-test brief](Pen-Test-Scoping-Brief.md) for the exact commands) — all pass, proving read AND write rejection, not just read.

On top of the test suite, this audit also ran a **live HTTP attack** against a throwaway instance (own PostgreSQL + FrankenPHP containers, network-isolated from the shared staging stack): seeded a second tenant ("Tenant B") and a `document_templates` row owned by it, then, authenticated as Tenant A's admin, attempted to read it by id —

```
GET /api/v1/document-templates/5           (Tenant A admin's own valid cookie)
→ 404 {"error":"Template not found"}

GET /api/v1/document-templates/5           (same cookie, PLUS X-Tenant-Id: 2)
→ 403 {"error":"Access to the requested tenant is forbidden"}
```

— i.e. the query-layer predicate silently returns nothing (never confirms the row's existence to the wrong tenant), and separately, the classic "trick the header-based tenant selector" attempt is refused by `EnforceTenantIsolation` before the handler even runs. Both match the code-level analysis exactly.

## 3. The auth/session boundary

**Assets**: the ability to authenticate as a given profile in a given tenant.

### Spoofing — token forgery

`JwtParser` (`src/Auth/JwtParser.php`) wraps `firebase/php-jwt` and decodes with `new Key($this->secret, 'HS256')` — the algorithm is **hard-pinned to the key's own algorithm**, not read from the attacker-controlled `alg` header. This closes both classic JWT attacks:

- **`alg: none`**: rejected — `firebase/php-jwt` only accepts `none` when the caller explicitly passes it in an allowed-algorithms list, which this code never does.
- **Algorithm confusion (HS256 signed with the server's *public* RS256 key, or vice-versa)**: not applicable — the server only ever issues and expects HS256, and `Key($secret, 'HS256')` fixes both the key material and the algorithm together, so a token claiming `alg: RS256` (or any algorithm other than HS256) fails verification rather than being verified against the wrong key interpretation.

The federated (OIDC) path is a **separate, correctly-scoped** verification: `JwtParser::verifyExternalIdToken()` uses `JWK::parseKeySet($jwks, 'RS256')` — asymmetric-only, keyed by the token's `kid` header against the *provider's* published JWKS — and additionally checks `iss`, `aud`, and `nonce`. The two paths never share a key or an algorithm allowance, so a token minted for one cannot be replayed against the other.

**Dynamic check performed**: against the same throwaway live instance, crafted three forged tokens — `alg: none` with an empty signature segment, `alg: none` with a junk trailing segment, and a validly-shaped `alg: HS256` token signed with an attacker-chosen 32-character secret (simulating an attacker who knows the scheme but not `JWT_SECRET`) — each carrying an admin-role payload for the seeded admin profile, and sent every one as a `Bearer` token against `GET /api/v1/users` (an admin-only route). All three came back `401`, identical to sending no token at all. No alg-confusion or none-alg bypass found.

### Tampering / Repudiation — revocation and session integrity

- **Per-token revocation**: `revoked_tokens` (sanctioned global; a `jti` is unique platform-wide) — checked on every access/refresh/device/MCP token validation (`TokenValidator::isTokenRevoked()`).
- **Per-profile epoch**: `profiles.token_epoch`, bumped on password change (self-service and admin-approved paths both bump it — see `PasswordResetService`) and 2FA disable/clear. A bump invalidates **every** outstanding token for that profile across every device/tenant membership in one write, closing the classic "attacker already has a token, victim changes password, attacker's session should die" gap.
- **Membership gate**: `ActiveTenantMembershipGuard` requires an *active* membership in the token's declared tenant (or system-tenant authority) on every validation — a token for a tenant the profile has since been removed from stops working immediately, not just at next login.

### TOTP replay — CONFIRMED and fixed by this audit

`TotpService::validateCode()` (the only production call site was `AuthHandler::handle2fa()`) verified a submitted TOTP code against the current time window but recorded **no state about which step had already been accepted**. Because a TOTP code is valid for its entire ~30-second period regardless of how many independent requests check it, presenting the **same** valid code twice completed **two separate logins** — a textbook TOTP replay (the exact weakness RFC 6238 tells implementers to guard against). This was reproduced two ways before writing the fix:

- A real-engine regression test (`tests/Integration/TotpReplayRealEngineTest.php`): the first `handle2fa()` call with a valid code returned 200; an immediate second call with the **identical** code also returned 200.
- Live, against the throwaway HTTP instance: enrolled 2FA on the seeded admin account, logged in to get a temp token, generated a real TOTP code for the exact same period, and posted it to `/api/v1/login/2fa` twice with the **same** temp token and **same** code. Before the fix, both requests returned `200` (two independent sessions minted from one captured code). After applying the fix, the first request still returns `200`; the immediate replay now returns `401 {"error":"Invalid 2FA code"}`, while a **later, unconsumed** code for the account still authenticates normally — confirmed live, not only in the test suite.

**Fix** (this audit, TDD): migration `080_add_two_factor_last_used_step_to_profiles.php` adds `profiles.two_factor_last_used_step`. `AuthHandler::consumeTotpStep()` atomically advances it (`UPDATE ... WHERE two_factor_last_used_step IS NULL OR < :step`) — the same single-use-burn pattern `BackupCodesService::validateCode()` already used for backup codes, applied here to TOTP time-steps, and race-safe under concurrent workers for the same reason (the `WHERE` guard means only one concurrent request can flip the row). A later, unconsumed step still authenticates normally — the fix stops **replay**, not legitimate subsequent logins. See the [pen-test brief](Pen-Test-Scoping-Brief.md) for severity/fix details.

### Rate-limiting / brute-force

- **Login**: `LoginThrottleService` — independent per-user and per-IP fixed-window counters (defaults 10/900s and 20/900s), checked before password verification.
- **2FA step**: the same throttle is keyed on the temp-token's `profile_id` and checked before code validation in `handle2fa()`.
- **Backup codes**: single-use, atomically burned (`UPDATE ... WHERE used = false`) — a code cannot be spent twice even under concurrent requests, and (per the fix above) TOTP codes now share that same single-use guarantee.
- **Password-reset request**: `PasswordResetHandler::forgot()` — per-email (5/hour) and per-IP (20/hour) fixed windows, counted *before* any lookup so the throttle behaves identically whether or not the address exists (no enumeration).

## 4. The plugin boundary (E — elevation of privilege, first)

**This is the platform's most permissive trust boundary, and it is *not* a sandbox.** A "loaded plugin" is ordinary PHP, `require_once`'d and instantiated by reflection (`PluginLoader::discover()`/`materializeClass()`, `src/Core/PluginLoader.php`) in the **same OS process, same FrankenPHP worker, same PHP memory space** as core code. There is no seccomp/gVisor/separate-process isolation, no restricted `open_basedir`, no capped filesystem/network egress at the language level. Concretely, any plugin route handler or hook callback can:

- **Access the live database connection** — `\Whity\app(Database::class)` (`src/helpers.php`) resolves the *exact same* worker-scoped `Database` instance every core handler uses, via a process-global service container with no per-caller restriction. A plugin can run arbitrary SQL, bypassing every repository-layer `tenant_id` predicate entirely.
- **Manipulate `TenantContext` directly** — `setTenantId()`, `reset()`, and `setSystemMode()` are `public static` methods (`src/Core/Tenant/TenantContext.php`) with no caller-identity check; they are protected only by convention (the framework calling them at defined lifecycle points), not by access control. A plugin could call `TenantContext::reset()` then `setTenantId(0)` (or `setSystemMode(true, 'anything')`) to acquire cross-tenant authority for the remainder of the request.
- **Make unrestricted outbound network calls** — nothing prevents a plugin from opening its own sockets/`curl`/`file_get_contents` to anywhere, including internal-only hosts (the SSRF guards on [HttpFetcher](#5-external-network-boundaries) protect *core-initiated* fetches with *admin-configured* URLs; they do not — and structurally cannot — constrain arbitrary plugin code).
- **Read process environment** — `$_ENV`, including `JWT_SECRET`/`ENCRYPTION_KEY`/`RENDER_SHARED_SECRET`/DB credentials, is ordinary PHP superglobal state visible to any code in the process.

What **does** exist is *error* isolation, not *security* isolation: `PluginLoader::wrapHandler()`/`wrapHookCallback()` catches `\Throwable` around every plugin entry point so a crashing plugin degrades to a `500`/`503` and trips its own lifecycle (`failed` after 3 consecutive errors) without taking down the host or other plugins (`src/Core/PluginState.php`, `src/Core/PluginLifecycle.php`). Permission registration is validated (`PermissionRegistry::register()` rejects a malformed permission name) but nothing stops a plugin from simply calling privileged core APIs directly rather than going through the permission it declared.

**This is an accepted architectural reality, not a bug** — no code or documentation prior to this audit claimed plugins were sandboxed, so there is nothing to "fix" here without a genuine sandboxing feature (separate process/runtime, capability-scoped DB handles, egress allowlisting), which is a substantial undertaking out of scope for this pass. The actual, and currently the **only**, control on this boundary is **what gets installed**:

- **Who can install.** Every code-plugin install, upload, enable, update and kill route also calls `PluginOperatorGate::refusal()`, which only lets the system workspace through (`src/Core/Plugin/PluginOperatorGate.php`). A workspace admin can only switch on code plugins the operator already installed, through `code_plugin_access`. Workspace plugins are declarative manifests (`TenantManifestPolicy`; `AutomationRunner` actions limited to set_fields, create_record, move and notify) and cannot reach SQL or HTTP.
- **Manual upload** (`plugins:upload`, operator only): `PluginInstaller` validates zip-slip and zip-bomb limits and a filesystem-safe name allowlist, then runs introspection before committing. No signature is checked, and introspection runs the package's PHP as the same OS user (TM-4).
- **Store install** (`InstallFromStoreApiHandler`): master switch `plugins.store_enabled`, exact-host allowlist, `HttpFetcher` (public IPs only, connects to the IP it checked). The package is hashed and its Ed25519 attestation (plugin, version, sha256, expiry, revocation list with a sequence number) is checked before it is unpacked (`PluginTrust`). **The pinned key list `TrustedKeys::PINNED` is empty today**, so every package fails with `no_keys`, and store installs work only while the operator's global override `plugins.store_allow_unsigned` is on (TM-1). The "Verified" badge (`StoreMarks::PINNED_KEYS`) is a separate trust root that labels plugins; it does not gate installs.
- **Kill switch.** An operator imports a signed revocation list through `POST /api/plugins/revocations`. A killed workspace plugin is turned off; a killed code plugin is off for every workspace at every request surface (`CodePluginActivation::stateFor`, reason `withdrawn`). Its code is still `require`d and booted when a worker starts (TM-3).

Until TM-1 is closed, treat the plugin boundary as **High**: unsigned code with full trust. Once a key is pinned and the override is off, operator-only signed installs are the accepted control, and the missing runtime sandbox stays a documented risk (TM-2).

**Recommendation for the external pen-test**: attempt an actual malicious-plugin load against a throwaway instance (a route handler that calls `TenantContext::reset()`/`setTenantId()` and reads another tenant's rows, or one that exfiltrates `$_ENV`) to convert this static analysis into a live proof, and treat plugin-install permission grants (`plugins:upload`, marketplace admin) as equivalent to full host compromise when scoping who may hold them.

## 4a. Plugin UI frames (the SDK frame bridge)

**Boundary.** An `embed` screen loads the plugin's own GET route in `<iframe sandbox="allow-scripts allow-forms" referrerpolicy="no-referrer">` (`web/components/plugin/embed-screen.tsx`). The backend sends the same sandbox in the page's CSP (`SecurityHeaders::respectingHandlerCsp`). Without `allow-same-origin` the page has an opaque origin: it cannot read the app's DOM, cookies or storage, and its own fetches carry no session.

**Bridge** (`web/lib/plugin-frame-bridge.ts`, page side `sdk/src/Frontend/FrameBridge.php`), protocol `whity: 'frame/1'`. The host accepts a message only when `event.source === iframe.contentWindow` (an origin check is impossible, the origin is `null`) and only five types:

- `ready`: the host replies with locale, direction, theme and API base.
- `request`: GET, POST, PUT, PATCH, DELETE to paths under `/api/` only, with no `//`, `..`, `%2e`, backslash or control characters.
- `resize`: clamped to 120 to 8000 px.
- `navigate`: in-app paths only, no `//` and no `/api/`.
- `toast`: at most 300 characters, fixed tones.

**Tokens.** The host makes the request with a 300-second `plugin_frame` JWT and `credentials: 'omit'`; the token never enters the frame. It is issued only from a session access token, for an embed screen that is on for the workspace and whose permission the person holds; it is accepted only as a Bearer header on routes under the plugin's own namespace (`PluginFrameTokens::admits`, `EnforceTenantIsolation`); it falls with the parent session, and RBAC re-checks the person's live permission.

**Residual risks.** No `frame-src` in the web CSP, so a frame that navigates to another site keeps the bridge (TM-12). `navigate` accepts any in-app path (TM-13). Toasts put plugin-written text in the app's chrome. The first frame load is an ordinary cookie-authenticated GET, so the plugin's server code sees the full session, consistent with full-trust code plugins.

## 5. External network boundaries

| Boundary | Direction | Guard | Residual risk (accepted / documented) |
| --- | --- | --- | --- |
| Browser ↔ Next.js ↔ core API | Inbound | httpOnly cookies, CORS allowlist (`src/Http/Cors.php` — origin reflected only when it matches `CORS_ALLOWED_ORIGINS`, never a wildcard with credentials), `X-Requested-With` CSRF guard (see below) | The Next.js proxy (`web/app/api/[...path]/route.ts`) forwards upstream redirects by default; OAuth/SSO redirect routes must opt into `redirect: 'manual'` (documented gotcha, not itself a vulnerability in the audited routes) |
| Core → tenant/operator SSO IdPs | Outbound (server-initiated) | `HttpFetcher` SSRF guard (https-only, public-IP-only resolution, no redirects, TLS verified) + PKCE + `iss`/`aud`/`nonce` validation on the returned ID token (`OidcEngine`) | DNS-rebind TOCTOU between the guard's resolution and the fetch's own resolution — documented in `HttpFetcher`'s docblock as an accepted limitation given the target is admin-configured, not attacker-supplied |
| Core → plugin marketplace | Outbound (server-initiated) | Master switch + exact-host allowlist (primary) + `HttpFetcher` guard (defense-in-depth); fetched bytes never trusted (`PluginInstaller`'s full validation pipeline) | DNS rebinding closed (`HttpFetcher` connects to the IP it checked, #2320); attestation built but inactive until a key is pinned (TM-1) |
| Core → `whity_render` (headless Chromium) | Outbound (server-initiated, internal-only) | Shared-secret header (`X-Render-Secret`, constant-time comparison, ≥32 chars enforced on *both* sides), no public exposure, size/time bounds, own rate limiter | `RenderServiceClient` deliberately applies **no** SSRF/public-IP guard — by design, since the target is the operator-configured, internal-network `RENDER_SERVICE_URL`, not user input; this is the correct trust level for a same-compose-network internal service, not an oversight. Chromium runs with `--no-sandbox`, JavaScript on, no request interception and no CSP, so escaping in the HTML builder is the only barrier to SSRF from document content (TM-21) |
| MCP clients and agents | Inbound | Three token kinds on `/mcp` (`TokenValidator`): a minted MCP key (registered jti, revocation, `token_epoch`, membership); a session token; a Whity ID OAuth token (signature, iss, aud, exp; system workspace refused; active membership; `OAuthAccessTokenVerifier`). MCP tokens are refused on REST. Rate limits 300/min per workspace and 60/min per person (`McpRateLimiter`). See [section 9](#9-mcp-agents-and-approvals). | OAuth tokens are not revocable from core before they expire (TM-8); self-registered clients act as the person with no approval step (TM-5) |
| Core <-> Whity ID (OIDC client) | Outbound + redirect | State (`hash_equals`), PKCE S256, nonce, exact iss, aud, exp, nbf, iat, asymmetric algorithms only (`JwtParser`, `SsoAuthHandler`); signed flow cookie; `HttpFetcher` SSRF guard | No `azp` check, no back-channel logout (TM-10, TM-11) |
| Whity Pay / Polar -> core webhooks | Inbound | HMAC-SHA256 over `timestamp.rawBody`, constant time, 300-second window, refused when no secret is set (`WebhookVerifier`); single-claim event ledger (`EventLedger`); access is re-read from the billing service, never taken from the payload (`ExternalBillingApiHandler`) | Event id header outside the signature (TM-22) |
| Anonymous -> public forms, booking, web chat | Inbound | 256-bit form slug or host-checked workspace slug; the same 404 for every refusal; per-IP, per-workspace and per-email limits; BotCheck on booking and chat; per-staff and per-room locks against double booking | Client IP comes from a trusted header (TM-19); forms have no BotCheck (TM-20) |

### CSRF

State-changing (`POST`/`PUT`/`PATCH`/`DELETE`) requests that authenticate **ambiently** via a cookie (`access_token`/`refresh_token`/`temp_auth_token`) — or that hit an always-sensitive auth endpoint (`login`, `login/2fa`, `refresh`, `logout`, `select-tenant`, `switch-tenant`) even without a cookie — must carry `X-Requested-With: XMLHttpRequest` (`CsrfGuard`, `src/Http/Middleware/CsrfGuard.php`). This is **enforced server-side**, not merely a client-side convention: a cross-site HTML form cannot set a custom header, and a cross-origin `fetch`/XHR that tries to triggers a CORS preflight the strict origin allowlist refuses for any non-allowlisted origin. A Bearer-only request (no cookie) is exempt because it carries no ambient, browser-attached credential a cross-site attacker could ride. The public, unauthenticated endpoints this audit reviewed (`PasswordResetHandler`, `EmailVerificationHandler`, `TwoFactorRecoveryHandler`) are correctly **not** in the always-protected list: they carry no session cookie to ride, so classic CSRF (riding a victim's ambient credential) does not apply to them — the risk there is enumeration/abuse, which is handled by the rate-limiting and generic-response conventions described above, not CSRF tokens.

**Live confirmation**: a cookie-authenticated `POST /api/v1/auth/logout` sent **without** `X-Requested-With` against the throwaway instance returned `403 {"error":"Cross-site request rejected"}` — the same request with the header succeeds. Separately, an `OPTIONS` preflight carrying `Origin: https://evil.example.com` against an admin route came back with no `Access-Control-Allow-Origin` (and no credentials header) at all — the origin is silently dropped rather than reflected, matching `Cors::headers()`'s allowlist-only behavior.

## 6. Denial of service

- **Global rate limiting**: platform/IP/tenant/principal-tier fixed windows (`RateLimitMiddleware`, env-tunable, `RATE_LIMIT_ENABLED=0` kill switch for emergencies).
- **Body size cap**: `MAX_REQUEST_BODY_BYTES` (default 1 MiB) rejected before parsing (`RequestBodyValidator`).
- **Worker recycling**: `MAX_REQUESTS` bounds how many requests a FrankenPHP worker serves before recycling, limiting the blast radius of a slow memory leak.
- **Render service**: its own `express-rate-limit` window (each render drives a full headless-Chromium page load — expensive), plus a 50 MiB PDF read cap on the client side (`RenderServiceClient::$maxBytes`).
- **Plugin error isolation**: 3 consecutive errors trips a plugin to `failed` and short-circuits further invocations, bounding the cost of a plugin stuck in a crash loop.
- **MCP**: 300 calls/min per workspace and 60/min per person; a batch counts once per item and is capped at 20 items.
- **Render quotas**: spent before rendering and given back on failure (`MeterService::consume/refund`).
- **Public endpoints**: per-IP, per-workspace per-day and per-email caps, plus BotCheck on booking and web chat.

## 7. Secrets management

Three operator-supplied secrets share a documented "≥32 chars outside development" convention: `JWT_SECRET`, `ENCRYPTION_KEY`, `RENDER_SHARED_SECRET`.

| Secret | Enforced? | Notes |
| --- | --- | --- |
| `JWT_SECRET` | Yes — `JwtSecretGuard::assertValid()`, boot-time fail-fast outside development | Pre-existing |
| `RENDER_SHARED_SECRET` | Yes — checked on **both** sides (`RenderServiceClient::isConfigured()` and `render-service/src/server.js`'s `isAuthorized()`, constant-time comparison) | Pre-existing |
| `ENCRYPTION_KEY` | **Was NOT enforced — found and fixed by this audit.** Only an *empty* key was rejected; a short-but-present key (e.g. `"abc"`) passed, even though `.env.example` already documented "must be >= 32 chars ... outside APP_ENV=development" | New `EncryptionKeyGuard` (mirrors `JwtSecretGuard`), wired into both consumers: `TotpService::resolveEncryptionKey()` (2FA secrets) and `EncryptedSecretStore::fromEnv()` (IdP client secrets, OAuth/webhook tokens, mail-provider credentials) — these two had silently diverged (only the former was ever guarded at all) |

`.gitignore` correctly excludes `.env`/`.env.local`/`.env.staging` (real secrets) while keeping `.env.staging.example` committed; a repo-wide grep for hardcoded credential-shaped literals in `src/`/`config`/compose files found none — every compose file (including the staging ones) requires secrets via `${VAR:?must be set}`, never a baked-in value.

**Live confirmation**: booted a throwaway FrankenPHP instance with `APP_ENV=production` and `ENCRYPTION_KEY=too-short` — the worker refused to start at all (`frankenphp app module: ... too many consecutive failures: worker ... has not reached frankenphp_handle_request()`), with the container log showing the exact new guard firing: `Uncaught RuntimeException: ENCRYPTION_KEY must be at least 32 characters in non-development environments in .../EncryptionKeyGuard.php:55`. Before this fix, the identical configuration would have booted successfully and silently encrypted every stored TOTP secret under a trivially brute-forceable passphrase.

**Lower-confidence observation (not fixed, flagged for the external pen-test / operator hygiene)**: `.env.example`/`docker-compose.yml` ship well-known placeholder values (`dev_secret_key_change_in_production`, `dev_encryption_key_change_in_production`) that happen to be ≥32 characters — long enough to pass the strength guards above. An operator who copies the example file into a real deployment **verbatim** would pass every automated check while using a publicly-known secret. This is a generic "don't ship real config from an example file" operational risk common to most frameworks with example env files (Rails, Django, etc. have the identical shape of risk), not a defect in the guards themselves; recommended follow-up (not implemented here, to keep this pass's fix scoped to the concrete enforcement gap) is a deploy-time check that rejects the specific known example-secret literals.

## 8. Whity ID SSO and workspace confinement

**Identity linking** (`FederatedIdentityLinker`): no decision is made on an email without a strictly true `email_verified`; the operator's IdP links only to confirmed addresses; a workspace's own IdP reaches only that workspace's confirmed members, and non-members only through a DNS-verified domain with auto-provision on.

**Confinement.** A workspace-IdP sign-in mints a signed `confined_tenant_id` claim that survives refresh; a malformed value confines to -1 (fail closed). `EnforceTenantIsolation` refuses any other active workspace and the global routes listed in `src/Auth/TenantConfinement.php`. Workspace switching, email and password changes and "sign out others" are refused.

**Password sign-in** is a global-only setting; the break-glass `force` value comes only from the environment (`PasswordSignIn`).

**Threats.** E: a workspace IdP's session creating workspaces in the member's name (TM-9). S: signing out at Whity ID does not end core sessions (TM-10). Accepted by design: a workspace admin's own IdP can sign in as any member of that workspace, inside that workspace only.

## 9. MCP, agents and approvals

**Authority.** An agent's authority is the intersection of the person's live role permissions on the resolved route and its grant (`AgentScopeGrant`); both are re-read on every call.

**Ask first.** The call is stored as a proposal with its exact arguments and their hash (`ToolsCallHandler`). Approval is one atomic update (`AgentProposalRepository`), limited to the person asked, and re-runs every check (`AgentProposalsApiHandler`). The approval routes are not MCP tools, so an agent cannot approve itself.

**OAuth.** The OAuth server for MCP is Whity ID: exact redirect_uri (loopback exception only), PKCE S256, and a consent screen for self-registered clients.

**Threats.** E: prompt injection driving writes through clients not marked as agents (TM-5). T/E: tool-to-route confusion through unencoded path values (TM-6). E: a revoked key's waiting proposals can still be approved (TM-7). S: OAuth token lifetime after revocation (TM-8). D: no cap on waiting proposals per agent (TM-25).

## 10. Usage meters and quotas

**Two mechanisms.** `MeterService` holds spendable quotas: a calendar-aligned counter per workspace and window in `shared_store`, an atomic `INSERT ... ON CONFLICT` increment, and claim-and-give-back across windows; renders and printouts use it. `UsageLedger` is the billing history: an atomic upsert per workspace, meter, month and dimension; it never refuses and swallows its own errors.

**Server-side only.** No count comes from the client. Counted meters are incremented at the point of work (messages, MCP calls); snapshot meters (seats, customers, items, invoices, storage) are read from the database by a daily job and when the usage screen opens.

**Settings.** `meters.enforcement` and `seats.enforcement` are global-only, so a workspace cannot turn its own limit off. Both default to `warn`.

**Residual risks** (they matter in `block` mode): the seat limit is checked only on invitations (TM-14); quota windows follow the workspace-editable time zone (TM-15); REST calls are not counted and snapshot meters are sampled, not peak-tracked (TM-16); the ledger fails open by design.

## 11. Team chat

Every `/api/chat/*` route goes through `ChatApiHandler::guard()`: staff of the workspace, and agents only in chats they belong to. Queries bind `tenant_id`; a non-member gets "not found"; message-id access re-checks membership and the read-from point; only the author edits or deletes. Attachments are checked by their leading bytes against an allow-list with no SVG or HTML, 25 MB maximum, served `nosniff`. Messages render through `web/lib/safe-markdown.tsx` (no raw HTML). Clients poll through the same guard.

**Threats.** I: adding someone to a 1:1 chat shows them the whole history (TM-18). S: the customer payment block accepts any https URL (TM-23).

## 12. Public forms and booking

The workspace comes only from a 256-bit slug or token, or a host-checked workspace slug; the Host header is never trusted alone. Every refusal is the same 404. Limits per IP, per workspace per day and per email; BotCheck on booking and web chat. See the section 5 row.

**Threats.** S/D: the client IP comes from a header the backend trusts if it is reachable directly (TM-19). D: public form submit and upload have no BotCheck (TM-20).

## Summary — confirmed findings from this audit pass

| # | Finding | STRIDE | Severity | Status |
| --- | --- | --- | --- | --- |
| 1 | TOTP codes were replayable within their validity window (no anti-replay floor) | Spoofing / Elevation of Privilege | Medium | **Fixed** — migration 080 + `AuthHandler::consumeTotpStep()`, TDD real-engine regression test |
| 2 | `ENCRYPTION_KEY`'s documented ≥32-char convention was never enforced in code | Information Disclosure (weak encryption at rest) | Medium | **Fixed** — `EncryptionKeyGuard`, wired into both consumers |
| 3 | Plugin execution has no runtime sandbox — full trust once loaded | Elevation of Privilege | **High while package signing is inactive (TM-1)**; accepted architectural risk once operator-only signed installs are live | Tracked as TM-1 to TM-3 in [READINESS](../security/READINESS.md) |
| 4 | Known committed example secrets are long enough to pass strength guards if copied verbatim into production | Information Disclosure | Low (operational hygiene) | Not fixed — flagged as lower-confidence observation |

The 2026-11-22 refresh added TM-1 to TM-25; see [READINESS, section B.3](../security/READINESS.md#b3-whity-core-threat-model-gaps).

Everything else checked in the original pass (tenant isolation across all tables including new ones, JWT alg-pinning, CORS/CSRF enforcement, IDOR spot-checks, backup-code single-use, generic-error convention, parameterized queries) held up under adversarial review with no exploitable gap found. See the [Pen-Test-Scoping-Brief](Pen-Test-Scoping-Brief.md) for what was and wasn't dynamically exercised, and what still warrants independent professional testing.
