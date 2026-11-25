# Plugin SDK 2.0 plan (0.2.31 → 2.0.0 at 0.2.40)

Status: **plan**, built from the owner's decisions of 19 Nov 2026 on the [streamline proposal](Plugin-SDK-Streamline-Proposal.md) (D1–D11). Ticket WHIT-1242. The SDK lane keeps this page current: when a release ships, its row says so.

**Public beta moved (owner, 24 Nov 2026, WHIT-1385):** the plan below is unchanged and SDK 2.0.0 still ships at 0.2.40. The public beta itself now comes after 0.2.50, not at 0.2.40. Where this page or the proposal says "at the beta", read it as "by 2.0.0".

## The rules this plan follows

- **One SDK minor per core release (D7).** 0.2.31 is 1.124, 0.2.32 is 1.125, and so on up to 0.2.39, which is 1.132. **0.2.40 is 2.0.0.** Every lane that adds to the SDK in a release adds to that release's minor, through the SDK lane. Nobody else bumps `Sdk::VERSION`. The `Sdk.php` ledger gets one entry per release, listing every contract added.
- **Additive until 2.0 (D1).** Every new shape lands in 1.x next to the old one, and the old one is marked `@deprecated` with its replacement named. No 1.x release removes anything the published plugins use.
- **Shims through 2.x's first release (D1).** 2.0.0 makes the new surface the contract. The 1.x names, factories and event names still resolve in 2.0.x as deprecated shims, and each one logs a notice once per plugin. They are removed in 2.1.0.
  - The host's SDK constraint gate accepts a plugin declaring `^1.124` (or later 1.x) on a 2.0.x host. A plugin declaring an older `^1.x` is refused, with a message that names the migration guide.
- **Workspace from the context (D2).** Every service a plugin calls acts on the workspace the request or job runs in. Explicit-`$tenantId` services become host-side and move to `Whity\Sdk\Host\` in 2.0. From 1.124 the host refuses a `$tenantId` that differs from the context's.
- **Manifest-first (D4).** Each declarative contribution point gets a `plugin.json` key and a JSON Schema, generated from the validator that already checks it.
- **Theme and inventory contracts come from their specs.** Themes follow `docs/design/30-themes.md` (TH.1–TH.14); inventory follows `docs/design/31-inventory.md` (IV.1–IV.24). Their lanes build them, and the SDK lane numbers them and keeps them consistent with D2, D3 and D6.

## Releases

| Release | SDK | SDK lane | D9 issues | Other lanes' SDK work in the same minor |
|---|---|---|---|---|
| **0.2.31** | 1.124 | `PluginContext::for($this)` (D3), giving settings, secrets, files, notify, jobs, audit, documents, http and the workspace id; a binding test at each entry point (web, CLI, `queue:work`, `events:relay`) and the missing bindings it finds; `submission.reviewed` fired or retired. Already shipped in 0.2.31: 1.122 (#2161, #2151) and 1.123 (#2165). | #2151 done | Themes: `PluginThemesInterface`, `PluginThemeInterface` deprecated (TH lane). Inventory phase 1, if it reaches the SDK. |
| **0.2.32** | 1.125 | `Whity\Sdk\Contribute\*` aliases for all 43 contribution points, plus workspace-named aliases (D6); `sdk/CHANGELOG.md` replaces the `Sdk.php` ledger, with the coverage and collision scripts moved to read it (D7, with the CI lane); `PluginContext` gets typed accessors for the explicit-`$tenantId` services (contacts, timeOff, away, bookings) | **#1914** directory readers (Members, Units); **#1910** read form submissions; **#2137** routing rule kind config schema | Inventory services in `PluginContext` form (§D2 note below) |
| **0.2.33** | 1.126 | `plugin.json` schema part 1: settings, notifications, jobs, dashboard, onboarding, contact fields, themes (D4); `whity plugin validate` in the CLI | **#1683** entitlements, meters, `requiredEntitlement`; **#1686** logger, error reporting, rate limits, lifecycle callbacks | |
| **0.2.34** | 1.127 | Event catalogue metadata (kind, payload keys, async twin, since), generated into HOOK_SYSTEM.md. **Shipped as 1.164 (WHIT-1390).** Renaming events, and firing them under both names, waits until 2.0 is decided (owner, 24 Nov 2026); the catalogue has an empty `renamedFrom` field ready for it | **#1681** record-page tabs, sections and actions (designed in 0.2.32); **#1680** people readers and picker blocks, on #1914 | |
| **0.2.35** | 1.128 | `@whity/plugin-sdk` npm 1.128 (D8): block types generated from `BlockContract`, a typed builder, the plugin route client, `fetchAllPages`, one `LocalizedText`, test helpers. The web app and desktop template import it and their copies go. Unused `@whity/ui`/`@whity/features` subpaths become private. | **#1728** route subjects and a `Routes` service | |
| **0.2.36** | 1.129 | `Page` for every new list method; `NoWorkspaceInScope`; a `reason` on every `*Rejected`; `plugin.json` schema part 2 (MCP, agents, roles, data types, the rest) | **#1688** and **#1693** designs approved (isolated origin; least-privilege capabilities) | |
| **0.2.37** | 1.130 | **2.0 freeze.** Every 2.0 name exists in 1.x. Every first-party plugin (Whity Pay, Appointments, Salons, Front Office, WorkspaceTemplates, store, theme-creator, demos) moves to the new names, one PR each, with no deprecation notice left in its test run | (catch-up) | |
| **0.2.38** | 1.131 | The migration guide (`Plugin-SDK-Migrating-to-2`) and the `whity plugin upgrade` codemod, tried on a copy of each first-party plugin before its PR | | |
| **0.2.39** | 1.132 | 2.0.0-RC on a branch: the host runs with shims in "notice" mode, and the constraint gate accepts `^1.124` and `^2.0` | | |
| **0.2.40** | **2.0.0** | 2.0.0 released: the new names are the contract, and the shims log once per plugin. `seats.enforcement` lands. The PHP floor follows core (8.4.27+). | | |
| 0.2.41+ | 2.1.0 | Shims removed. Build #1688 and #1693 | the rest of the issue list (#1684, #1682, #1912, #1913, #1687, #1692, #1911, #1691, #1689, #1690, #1694) | |

A release that can't take its row's issue moves the issue down, never the 2.0 date. The freeze in 0.2.37 is what has to hold.

## What 2.0.0 changes

The rows marked "shim" still resolve in 2.0.x with a once-per-plugin notice, and go in 2.1.

| Change | 2.0 | Shim in 2.0.x |
|---|---|---|
| `PluginContext` is the way to get every plugin store; the seven factories are deprecated | contract | yes |
| `$tenantId` arguments on plugin-callable services | must equal the context's | yes (refused when different, as since 1.124) |
| Host-only services (`Whity\Sdk\Host\…`) not resolvable from plugin code | enforced with #1693 | no |
| Contribution interfaces live in `Contribute\`, with workspace-named methods | contract | yes (`class_alias`) |
| One MCP contribution interface, one jobs declaration | contract | yes |
| Events renamed to the catalogue pattern | both fire | yes |
| `PluginHomeCardInterface` (D5), `PluginThemeInterface` (TH) | deprecated | yes; rendered as a widget and as a theme |
| Exceptions: `NoWorkspaceInScope`, `reason` on every refusal | contract | n/a (additive) |
| `seats.enforcement` | lands | n/a |
| `plugin.json` schema is the contract for declarations | contract | PHP `get*()` still read |

## D2 and the inventory services

The inventory spec (§6.1) gives `Items`, `Stock` and the rest `tenantId` arguments, the way `Contacts` has them today. Under D2, plugins reach them through the context:

```php
$ctx = \Whity\app(PluginContext::class)->for($this);
$ctx->inventory()->stock()->available($variantId, $locationId);
```

The workspace comes from the context. The explicit form is the host-side one, and it moves to `Whity\Sdk\Host\Inventory\…` in 2.0. If inventory's first SDK phase ships the context form directly, it never needs a shim.

## The D9 issues, in order

| Issue | Release | Size | Notes |
|---|---|---|---|
| #2151 | 0.2.31 | S–M | Done, SDK 1.122 |
| #1914 | 0.2.32 | M | First slice of #1680: `Members`, `Units` readers through `PluginContext` |
| #1910 | 0.2.32 | M | Read-only; unlocks #1682 later |
| #2137 | 0.2.32 | S–M | Finishes 1.121's read-only step |
| #1683 | 0.2.33 | M | Paid third-party plugins need it at the beta |
| #1686 | 0.2.33 | M | Logger with the core scrubber; error reporting; per-route rate limits |
| #1681 | 0.2.34 (design 0.2.32) | L | Needs a design approved first; the biggest customization win |
| #1680 | 0.2.34 | L | On #1914; picker blocks |
| #1728 | 0.2.35 | L | Approvals on plugin records |
| #1688, #1693 | design 0.2.36, build 2.1 | L | Store-plugin UI isolation; least privilege |
