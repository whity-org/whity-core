# Verified plugins: security design

Status: **decided by the owner on 3 Nov 2026 (section 9).** Core's verification is built. No public key is pinned yet and no review process exists, so nothing can carry the mark in practice. WHIT-871; S7 in `IMPLEMENTATION-NOTES.md`; answers Q2 and Q4.

## 1. The recommendation in short

The owner wants plugins that look and feel built in and that are active as soon as they're installed. That outcome is reachable without running a publisher's code in the app's origin.

**Recommendation: a Verified plugin is a signed declaration, not signed code.** It's a `plugin.json` manifest, drawn by Whity's own block renderer with Whity's own components, installed into one workspace, active on install. Whity already has this runtime: workspace plugins (`TenantManifestHost`, `TenantManifestPolicy`, `BlockRenderer`). What's missing is signed delivery from the store, the mark, and revocation.

**Not recommended: true same-origin JavaScript from third parties.** Signing proves who wrote the code and that it wasn't changed. It doesn't limit what the code does. Native code runs as the signed-in person, and if that person is an administrator, the code is an administrator (section 6). Nothing in the browser can contain it. A bad update from one Verified publisher would reach every workspace that has it.

The trade-off is real: a declarative plugin can only use the blocks Whity provides. For the few plugins that need a bespoke widget, section 7 gives an isolated frame with a bridge that can follow later.

## 2. What the code does today

Each of these was checked while writing this, and three change the starting point of the brief.

| # | Fact | Where | Why it matters |
| --- | --- | --- | --- |
| 1 | **The plugin lifecycle is install-wide (Q4).** A code plugin is files in `plugins/`; its routes, hooks and jobs serve every workspace. Per-workspace control is entitlements only, and they gate limits, not code | `PluginLoader`, `PluginsApiHandler` | S.1 ("installing into that workspace") can't be true of a code plugin |
| 2 | **A code plugin is PHP running inside the shared worker**, with the shared database connection and no sandbox. On the preview image the worker runs as root and `plugins/` is world-writable | `PluginLoader`, WHIT-284 | Once a third-party plugin is on, the deployment's whole database and secrets are reachable, whatever happens in the browser |
| 3 | **"Turned off" doesn't mean the code hasn't run.** Install reads a plugin's metadata by executing it in a child process. The child has a clean environment, a memory limit and a deadline, but it has the same user, filesystem and network | `PluginInstaller::introspect`, `bin/plugin-introspect.php` | Installing from a store runs the publisher's code on the server, before anyone presses Turn on |
| 4 | **The frame isn't a sandbox.** `EmbedScreen` uses `sandbox="allow-scripts allow-same-origin allow-forms"` on a same-origin path. That pair gives the framed page the app's own origin: it can remove its own sandbox, reach the parent page, and call any API with the person's cookies | `web/components/plugin/embed-screen.tsx` | "Every third-party plugin stays in the sandboxed frame" describes a boundary that doesn't exist yet. Its own docblock says it's for first-party routes |
| 5 | **A workspace admin could reach the install-wide routes.** They checked only the slug, and the global `admin` role that holds the slugs is also a new workspace's first admin role. Fixed by WHIT-869: these routes now answer only to the system tenant | `TenantsApiHandler`, `PluginOperatorGate` | This was cross-workspace code execution. The fix narrows who can install; it doesn't change what a plugin can do once on |
| 6 | The app's only frontend CSP is `frame-ancestors 'none'`. There's no `script-src` | `web/next.config.ts` | Any script that gets in can load anything and send anything anywhere |
| 7 | Session tokens are httpOnly cookies, and state changes need `X-Requested-With`. The profile is held in memory | `CookieManager`, `CsrfGuard` | Script can't read the token, but it doesn't need to: its requests carry the cookie automatically, and it can set the header |
| 8 | No way to load third-party JavaScript into the app exists, and no signature check exists for plugins. `sodium` is available in the PHP image | `PluginInstaller` | Everything in section 5 is new |
| 9 | The store's catalogue is unsigned JSON, and any host on `plugins.store_allowed_hosts` is trusted equally | `InstallFromStoreApiHandler` | An allowlisted host must never be able to confer a mark |

So there are three separate boundaries, and the 2.9 wording only names the second:

- **Server:** PHP in the worker. No containment at all.
- **Browser:** the frame. No containment at all today.
- **Declaration:** manifest and blocks. Contained, because it's data the host interprets.

## 3. Threat model

"Native" is option A in section 7, true same-origin code. "Declaration" is the recommendation.

| Threat | What they can do with native code | What they can do with a declaration | Controls |
| --- | --- | --- | --- |
| **Malicious publisher** passes review with hidden behaviour | Act as whoever opens a screen: read and change anything they can reach, read what they type (including passwords in re-entry dialogs), draw fake approval dialogs. Review can't reliably find a time-delayed payload in minified code | Only what the manifest says: its own records, its declared permissions, its declared notifications. The worst case is misleading text on its own screens | Fixed block vocabulary; policy check on the server; review of declared access; revocation |
| **Compromised publisher account or build** | A signed bad version; the signature is valid | A signed bad manifest, still bound by policy, and unable to activate without a new attestation | Attestation per version from a separate key; every update re-checked; hardware-key 2FA for publishers |
| **Compromised store** (site or database) | If the store holds the signing key: every install, everywhere | Can change what's displayed; can't produce a valid attestation or widen a manifest past policy | Signing key isn't on the store's web tier; catalogue data is display only and never decides trust; root key offline |
| **Tampering in transit or on disk** | Stopped by signature and hash if checked at every load; PHP on a writable disk isn't | Signature checked before storing; stored as an immutable version with its hash, read per request | TLS and SSRF guard as today, signature, hash. An attacker who can already write the database or disk has won regardless, and we say so |
| **A Verified plugin's bad update** | Immediate reach to every install that auto-updates, for as long as the page stays open | The update needs a new attestation; more access waits for an admin; the old version keeps serving | Section 5.4 |
| **A tenant admin installs something harmful for their workspace** | Their own people's sessions are exposed; on a shared origin, anyone who is a member of several workspaces too | Harm stops at that workspace's records. Consent is logged with the person and the access list | Per-workspace install; consent log; agents can't install directly (S.3) |

**Out of scope:** someone who already has root or database write on the deployment, and a self-hoster who edits their own copy of an open-source core to change the pinned keys. A fork can trust what it likes; it just can't display Whity's mark as coming from Whity's key to anyone checking the attestation.

## 4. Earning and losing the mark

**The mark belongs to one version, not to a plugin or a publisher.** Stored server-side in one table, `plugin_version_marks` (plugin, version, package hash, source, attestation, granted by, granted at, revoked at, revoke reason), written by one service.

**Who can grant it, and nothing else can:**

| Source | How | Shown as |
| --- | --- | --- |
| Whity's store | A signed attestation that verifies against the key pinned in core | Verified |
| An operator | System tenant only, a new `plugins:verify` capability held by no role by default, a fresh two-step check. Applies to that deployment only | Verified by your operator, visibly different from Whity's mark |

A manifest, an upload, an install or a store's catalogue field can't set it. A `verified` field arriving from any of them is ignored and tested as ignored. Every grant, removal and refused attempt goes to the audit log.

**Who checks what (Whity's store):**

| Stage | Who | What |
| --- | --- | --- |
| Publisher | Whity staff, once and yearly | Legal identity, a domain they control, hardware-key 2FA on the account, a named security contact, agreement to the rules |
| Each version, automatic | Store pipeline | Manifest valid and within policy, name rules, outside domains listed, diff of access against the last version |
| First version, and any version with new access | A Whity reviewer, not the publisher | What it asks for matches what it says it does; screens match the listing; each outside domain is justified |
| Signing | The pipeline, after a second person approves for first versions | Attestation: plugin, version, package hash, permissions, domains, publisher, issued, expires |

The people who review and the key that signs are separate, so one compromised account can't review and sign alone.

**What removes it:** a confirmed security report, behaviour that doesn't match the declaration, a compromised publisher or key, a change of ownership until re-checked, a lapsed publisher check, an operator's decision (for their own deployment), or an expired attestation.

**How fast it reaches installs.** Whity publishes a signed revocation list with a rising sequence number and an expiry. Core fetches it from the scheduler.

| Deployment | Reach | When the list is stale |
| --- | --- | --- |
| Online | Within 30 minutes of publishing (fetch every 15 minutes) | After 7 days without a fresh list: a banner to the operator, and no new Verified activations. Running plugins keep running |
| Offline | When an operator imports the signed list with the CLI. Each core release also carries the list current at build | Same rules counted from the list's date. The page says "Not confirmed since [date]" |

An older sequence number is refused, so an attacker can't replay a list from before a revocation. **An air-gapped install can't be reached at all, and no design changes that.** Its operator is told, and accepts that by not importing.

**What happens to a running plugin:**

| Kind | Effect | Timing |
| --- | --- | --- |
| Remove the mark | Keeps working, the mark disappears, no updates, operator told | Next request |
| Kill (security) | Turned off everywhere: screens gone, record routes refuse, automations stop, blocks show the plugin-off placeholder (E.20). Data kept under the 30-day rule (S.7) | Next request, because workspace plugins are read per request |

Native code couldn't honour the kill timing: an open tab keeps running what it already loaded until it's reloaded.

### 4.1 Store marks for code plugins (decision 8, 8 Nov 2026)

The owner decided that the Whity store also marks **code plugins**, one exact package at a time. This is a separate source from the attestations above, and it says less: an operator on app.whity.dev reviewed these bytes. It does not say the code is contained. A code plugin still runs on the server with the access section 2 describes, and the label says so.

| | How it works |
| --- | --- |
| **Granting** | The store's operator, in its system workspace only, marks a version after reviewing it. They give the package's sha256, so the mark names the bytes they reviewed. A later version is never covered by an earlier one's mark |
| **Statement** | The store signs a statement good for 24 hours: `whity-mark+jwt`, EdDSA, with plugin, version, sha256, `verified` / `revoked` / `none`, and when it was reviewed. It is served on the store's public plugin page, per version |
| **Key** | Its own key (`PLUGIN_STORE_MARK_KEY`), separate from the licence key. **It lives on app.whity.dev for now**, not offline as section 5.2 describes; reviewer-side offline signing is a recorded follow-up |
| **Pinning** | Core pins the mark keys per store host (`StoreMarks::PINNED_KEYS`). A store that is allowed for installing but has no pinned key can never make a plugin Verified (fact 9) |
| **Checking** | Core fetches statements for store-installed code plugins every 15 minutes and when an operator opens the plugins page, and caches them. A plugin is Verified only while a cached statement from a pinned key names its slug, version and installed sha256 (`plugin_installs`) and says `verified`. Requests read the cache only |
| **Revoking** | The store's operator revokes with a reason, and the statement says `revoked` from then on. Installations stop showing Verified at their next fetch, within about 15 minutes when online |
| **Offline** | A `verified` statement keeps counting until 7 days after the last good fetch, and never more than 6 days past its expiry. Then the plugin is Not verified until the store answers. The detail line says "Not confirmed since [date]" |
| **What it changes** | The plugins page shows "Verified", with "Reviewed on app.whity.dev on [date]; its code runs on your server". With `plugins.mcp_prompts_require_mark` on (off by default), a code plugin's MCP prompts are served only while it is Verified. The check runs at every list and get, so a revocation reaches every worker. Tools keep their own permission checks and are not gated by the mark |

Store marks never write `plugin_version_marks`, which stays for declarative plugins and Whity's root-key attestations.

## 5. Loading, signing and pinning

### 5.1 The package and its signature

- One package per version: the manifest, plus any images, all listed with hashes.
- **Two signatures:** the publisher's key says "I wrote this", and Whity's attestation says "this exact hash passed review". Both must verify. The publisher's key is registered with the store, and a change of key forces review.
- **Checked on the server, before anything is stored.** Ed25519 through `sodium`. A failed check stores nothing and writes an audit row.
- **Pinned by hash.** The workspace's version row keeps the package hash and the attestation. Activating checks them again.
- **No remote code at run time.** Blocks reference only `/api/x/<name>/` (already enforced by `TenantManifestPolicy` on every string in the tree), and images are served from core out of the verified package, never hot-linked.
- SRI and hash-pinned script tags apply only if option C or A is ever built. Option C's assets would be served from core at a path containing their hash, with `integrity` on the tag.

### 5.2 Keys

| Key | Where it lives | What it does | Rotation |
| --- | --- | --- | --- |
| Root | Offline, 2 of 3 hardware tokens held by named people, used in a recorded ceremony | Signs the signing and revocation keys, and root rotation | Rarely; core pins current and next, so a release can move to the next |
| Signing | Non-exportable in KMS or an HSM, reachable only from the pipeline | Signs attestations | Yearly, certified by root, old one valid until its expiry |
| Revocation | Same, separate key | Signs the revocation list | Yearly |
| Publisher | The publisher's own, registered with the store | Signs their packages | On request, forces review |

If the signing key is compromised, root signs a statement rejecting that key and everything it signed after a date. This is a small version of the TUF model. **Core pins root's public keys in code**, so a settings change or a store can't add a trust root. Installing a deployment-local key takes server access (CLI), never the UI.

**Don't ship a placeholder.** If the key ceremony hasn't happened when 0.2.25 ships, core ships with no Whity key pinned, and only the operator path can grant a mark.

**Exception for store marks (decision 8).** The key that signs store marks for code plugins (section 4.1) sits on app.whity.dev for 0.2.27, online, with only its public half pinned in core. A compromise of that host can mark code Verified until the key is rotated in a core release. That is accepted for now. Moving the signing to the reviewer's own machine, with only the 24-hour status signed online, is the recorded follow-up.

### What's built now (decision 6)

Sections 5.1 and 5.2 describe the full design. For now, with the owner as the only key holder and reviewer:

- **One key signs both attestations and the revocation list.** There's no separate signing or revocation key yet.
- **One signature, not two.** The publisher's signature comes with a publisher key registry, which doesn't exist yet.
- **`TrustedKeys` is empty.** The owner adds the public key in a reviewed change. Until then every check answers `no_keys`.
- **The operator's grant isn't built,** so Whity's signature is the only source of a mark.

### 5.3 CSP

The recommended design needs **no CSP change**, because no new script comes in. Separately, the app should gain a nonce-based `script-src` and a tight `connect-src`, since today any injected script is unconstrained (fact 6). That's worth doing for every XSS risk, not only plugins, and needs care with Next's inline runtime.

### 5.4 Updates

- Every version needs its own attestation. **A valid publisher signature alone never activates anything.**
- Attested, with the same or less access: installs by itself and says so in the Version column (S.6). Audited.
- Attested, with new permissions or outside domains: waits for an admin. The old version keeps serving, and "Review access" shows only the changes (S.6).
- Not attested: stays on the old version, and the row says an update is waiting for review.
- Roll back: to any earlier version whose attestation still stands and isn't on the revocation list. Downgrade to a revoked version is refused.

**Code plugins never update by themselves.** S.6's "install automatically" would mean unsigned code from a store running on the server without anyone's say. The Part 1 build approves every code plugin update by hand, and this needs the owner's sign-off because it narrows S.6 (decision 5).

## 6. Containment: what code can and can't reach

| | Native same-origin JS | Frame, opaque origin, with bridge | Declaration |
| --- | --- | --- | --- |
| Run arbitrary script in the app's origin | Yes | No | No |
| Call any API as the person | **Yes.** Cookies attach automatically | Only through bridge calls the host allows | Only its own record routes, via the renderer |
| Read the session token | No (httpOnly), but it has no need to | No | No |
| Read other screens, or what the person types there | **Yes** | No | No |
| Draw a convincing fake dialog over the app | **Yes** | Only inside its frame | No: only fixed blocks, in its own screen |
| Send data outside | Yes, without a strict CSP; even with one, through navigation and allowed hosts | Blocked by frame CSP; declared domains go through the server | Declared and server-side only |
| Stopped when revoked | At next page load | At next load, and the bridge refuses at once | At next request |
| Tenant isolation and RBAC on the server | Holds per person. It can't tell the plugin from the person, so it doesn't limit the plugin | Holds, and the bridge adds a limit per plugin | Holds |

**An API allowlist doesn't work for same-origin code.** Wrapping `fetch` is only a suggestion: script can get a clean `fetch` from a fresh iframe, or use XHR, a form, or an image. The server can't tell a plugin's request from the app's, because they're the same origin with the same cookie and headers. An enforceable allowlist needs a boundary, which means leaving the origin. That's why section 7 recommends what it does.

**Server rules that hold in every option:** tenant scope and permissions are checked on the server, whatever the client does; no client value grants anything; agents still propose (7.5).

**Kill switch, at three levels:**

1. Per plugin: Turn off, audited.
2. Deployment-wide: `plugins.verified_enabled`, an operator setting, off by default until this ships.
3. Whity-wide: the revocation list's kill entries.

## 7. Options

| | A. Same-origin JS | B. Declaration and blocks | C. Isolated frame and bridge |
| --- | --- | --- | --- |
| **Looks built in** | Yes, fully | **Yes: it is Whity's components**, including Arabic, dark mode and Larger text, on web and later on mobile and desktop | Mostly: shares tokens and a UI kit. Dialogs can't leave the frame; focus and resizing need care |
| **Active on install** | Yes, but unsafe to allow | **Yes** | Yes, for a Verified plugin |
| **Per workspace (S.1)** | Browser yes; PHP half no | **Yes, today** | Frontend yes; PHP half no |
| **What it can't do** | Nothing | **Custom widgets.** Anything beyond the block vocabulary needs a new block from Whity. Reads of core data need new, declared, read-only grants | Direct access to the app's page |
| **Blast radius of a bad version** | Every open session of everyone who installed it, up to administrator | That plugin's own records in that workspace | That plugin's frame and its allowed bridge calls |
| **Revocation** | At next reload | Next request | Bridge at once, frame at next load |
| **Couples to Whity's releases** | Tightly: plugin bundles depend on React and `@whity/ui` versions | No | Loosely, by bridge version |
| **New work** | Module loader, import maps, shared React, nonce CSP across the app, signing, and a way to review minified code | Signed delivery, marks, revocation. **Runtime already exists** | Asset serving, bridge, UI kit, per-plugin grants |
| **Safe for 0.2.25** | **No** | Possibly; see section 10 | No |

**Recommendation:**

1. **B is what Verified means.** E.17's "render natively with the Whity SDK components" is true of it, literally.
2. **C replaces today's frame for third-party screens**, as its own later work: `Content-Security-Policy: sandbox allow-scripts`, `connect-src 'none'`, `frame-ancestors` set to the app, no `allow-same-origin`. An opaque origin needs no extra domain, so it works for self-hosters.
3. **A isn't built.** If the owner still wants it, the minimum before any third-party code loads is: nonce CSP across the app, a fresh two-step check on every sensitive action so script can't complete them alone, reviewed source with reproducible builds, and written acceptance that a bad Verified update can act as any administrator who opens it.

**And the PHP half.** Option B needs no publisher PHP at all, which is the point. A plugin that truly needs server code stays what it is today: an operator's install-wide decision, never one a workspace can make, and never marked as if it were contained. Since decision 8 the store can mark a code plugin's exact package as reviewed (section 4.1). That mark says who reviewed which bytes, not that the code is contained, and its label says the code runs on your server. An out-of-process plugin runtime would be the real fix and is a separate project.

**What this gives up.** Publishers can't ship anything Whity's blocks can't draw, so the store will start with fewer and simpler plugins. Whity carries the work of growing the block vocabulary and of adding safe, declared reads of core data. In exchange, one publisher's mistake can't become every customer's incident.

## 8. Tests: proving each control can fail

Each runs on real Postgres where data is involved, and each must be seen to fail with its control removed.

| Control | Test that must fail if the control goes |
| --- | --- |
| Mark can't be set from outside | Manifest, upload, install and catalogue with `verified: true`, `trust: "verified"` and an attestation field: mark stays absent |
| Only the service writes the mark | CI guard: scanning for writes to `plugin_version_marks` outside the service finds none, and exits non-zero if the scan matches nothing at all |
| Operator grant | Workspace admin, system tenant without `plugins:verify`, and no fresh two-step: all refused and audited. Holder guard run against the migrated database |
| Audit | Grant and removal each leave a row with person, plugin, version, reason |
| Signature | One flipped byte in manifest, attestation or signature: refused, nothing stored |
| Wrong key | Validly signed by an unpinned key, and by a publisher key alone: refused |
| Hash pin | Change the stored manifest after install: activation refuses |
| Update needs re-attestation | Valid publisher signature, no attestation: old version keeps serving |
| New access waits | Attested update adding a permission or a domain: old version serves until approved, and the approval shows only the changes |
| Downgrade | Rolling back to a revoked version: refused |
| Revocation | Listed version: refused by record routes at the next request; screens gone; automations stop |
| Replay | Older sequence number: refused, and the current state stands |
| Staleness | Clock past expiry: banner on, new activation refused, running plugins continue |
| Policy holds for Verified | A signed manifest asking for `tables`, a foreign permission or a path outside `/api/x/<name>/`: refused despite a valid attestation |
| Tenant isolation | Workspace B can't see, install, read or disable A's copy; ids answer 404 |
| Kill switch | Each of the three levels stops serving, from another worker too |
| No remote code | A block with an outside URL or script string: refused. Browser check on a rendered plugin screen: no request leaves the origin |
| Frame (option C, when built) | Framed page tries `parent.document`, a cookie-bearing API call, and `fetch` out: all fail in a real browser |

## 9. The owner's decisions

| # | Decision | Answer (3 Nov 2026) |
| --- | --- | --- |
| 1 | What "runs natively" means | A signed declaration, drawn by Whity's block renderer, installed per workspace, active on install. Publishers' same-origin JavaScript isn't built |
| 2 | Who installs what | Workspaces install declarative plugins only. Code plugins are operator-only and install-wide (S.1, S.3, S.9) |
| 3 | What an unverified plugin is called | "Not verified", with a plain warning that its code runs on the server once it's on (S.4) |
| 4 | The frame | Remove the app's cookies for every non-first-party screen as soon as the system can tell them apart, and plan the move to a bridge for embed screens that rely on cookies. **Not done yet:** nothing can tell first party from third until a mark exists |
| 5 | Code plugin updates | By hand only (S.6) |
| 6 | Root key holder, key ceremony, who reviews | The owner holds the root key for now, generated and kept on the owner's machine, never in this repository or any session. The owner is the only reviewer until a process exists, so nothing can be marked Verified in practice. Core has only public-key verification and server-side checks |
| 7 | Offline, stale revocation list | Keeps running Verified plugins, shows a banner, no new activations |
| 8 | Code plugins and the store (8 Nov 2026) | The store marks reviewed code-plugin versions (section 4.1). Labelled "Verified", with "Reviewed on app.whity.dev on [date]; its code runs on your server". Its key is separate from the licence key and stays online on app.whity.dev for 0.2.27; offline reviewer signing is a follow-up. An operator setting, off by default, serves code plugins' MCP prompts only while they are Verified; tools aren't gated by the mark |

### The move to a bridge (decision 4)

Today's embed screens rely on the app's cookies. To take those away:

1. **Tell them apart.** A screen keeps cookies only if its plugin carries a By Whity mark. That needs the marks table, so it comes with the signing work. Until then nothing changes and the frame stays as described in section 2.
2. **Serve third-party frames with no origin.** `Content-Security-Policy: sandbox allow-scripts; default-src 'none'; connect-src 'none'; frame-ancestors` set to the app, and no `allow-same-origin` on the iframe. No extra domain is needed, so it works when self-hosted.
3. **Add the bridge.** `postMessage` from the frame to the host page, with an allowlist of operations per plugin. The host page checks the sender's window, makes the call as the person, and the server's own checks still apply.
4. **Move first-party embed screens to the bridge**, then take `allow-same-origin` off every frame.

Step 2 alone breaks any third-party embed that calls an API from its page, so steps 2 and 3 ship together.

## 10. Time, and what can't be finished for 0.2.25

Said plainly:

- **In whity-core, if decision 1 is the declaration and it comes soon:** signature checks, the marks table, the operator grant, store delivery into the workspace publisher, the revocation client and all of section 8's tests are realistic but tight for 0.2.25. It's also new security code, and it shouldn't be rushed past its tests. It ships only whole, behind `plugins.verified_enabled`.
- **Outside whity-core, not doable by a coding session:** the key ceremony, the store's signing pipeline, publisher checks and a human review process. Without them **no plugin can carry Whity's mark in 0.2.25**, whatever core does. The operator's grant would be the only source.
- **Same-origin JavaScript (option A) can't be finished safely for 0.2.25**, and shouldn't be started before decision 1.
- If those pieces aren't ready, the honest 0.2.25 is: Part 1 merged, S7's design approved, and Verified not switched on. The pages already draw the mark only when the server returns it.

## 11. Register changes this would need

If approved, the owner changes these first (the register wins over code): E.17 (what native means), S.1 and S.3 (who installs what), S.4 (the unverified label, the mark per version, two sources), S.6 (code plugins by hand, unattested versions wait), S.12 (active on install for declarative Verified plugins only). Q2 and M0 Q8 in `IMPLEMENTATION-NOTES.md` point here.
