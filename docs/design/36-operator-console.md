# 36 · The operator console: the System workspace runs the installation

**Status: proposed (WHIT-1351). Waiting for the owner's answers to section 12. Nothing here is built yet.** The register rows in section 11 are proposals. They go into `00-decision-register.md` only once the owner answers.

Owner, 22 November 2026:

> "system shouldn't be treated as a tenant with pages like appointments, etc... it should stay down with essentials needed to manage a whity installation with calendar having things like maintainance schedule, updates schedule etc... messages coming from tenant admins who might be requesting stuff from the system admins, change requests, bugs reports, usage patterns system reports, audits, manage pricings, campaigns of whity for us and for self-hosted their campaigns.. this is different from tenant campaigns"

This section turns the System workspace (tenant 0) into an **operator console**. It holds only what's needed to run a Whity installation: the workspaces, plugins, pricing, reports, audits and settings, plus three new things:

- a calendar for maintenance windows and updates;
- an inbox where workspace admins write to the operators;
- campaigns the installation runs for its own workspaces.

It amends W.2 (the Platform group). Every other workspace stays exactly as it is.

## 1. Today

How tenant 0 works now (survey of `develop` at `22b7d8aa4`, 22 Nov 2026):

- **The sidebar is the workspace sidebar plus a Platform group.**
  - Nav items come from one listener (`public/index.php:730-1427`), the calendar and convening listeners, and the two plugin bridges (`PluginNavigationBridge`, `TenantPluginNavigation`).
  - `NavigationApiHandler::isVisibleTo()` (`src/Api/NavigationApiHandler.php:160-206`) filters items by role, permission, `visibleIf` and `systemTenantOnly`.
  - Ten items and five Settings pages are `systemTenantOnly`. Nothing is marked "workspaces only", so tenant 0 sees Calendar, Contacts, Inventory, Sales, Documents, Forms, and the rest.
- **Every code plugin is on in tenant 0.** `CodePluginActivation::stateFor()` returns "on" for tenant 0 (`src/Core/Plugin/Workspace/CodePluginActivation.php:174-186`). So every plugin's screens (Salons, demo plugins, Whity Pay's workspace screen) appear in the System sidebar.
- **There is no operator role.** "Operator" means "acting in tenant 0" (`PluginOperatorGate::isOperator()`). Tenant-0 people hold the global `admin` role, the same one every workspace owner holds. Migration 199 explains why a grant alone can't say "operators only".
- **Plugin permissions never reach the `permissions` table.** `PluginLoader.php:4657-4659` keeps a code plugin's `getPermissions()` in the in-memory registry only. A plugin has rows only if it ships its own migration that inserts them. Whity Pay doesn't, so `whity_pay:manage` can't be granted to anyone, and nobody can open Whity Pay's setup. Filed as WHIT-1352 ([whity-org/whity-core#2409](https://github.com/whity-org/whity-core/issues/2409)). See section 8.3.
- **Some operator work has no page.** These exist only as CLI commands or ops scripts:
  - audit chain verify (`whity-cli audit:verify`);
  - backups (`scripts/backup-db.sh`, `ops/alerting/backup-freshness-textfile.sh`);
  - usage across workspaces (`GET /api/usage` shows only the caller's own workspace);
  - the billing catalogue import (`billing:catalogue:apply`);
  - the plugin kill switch (`POST /api/plugins/revocations`).
- **Nothing exists yet for the three new things:**
  - Conversations, chat and calendar rows all belong to one workspace, so nothing crosses from a workspace to the operators.
  - The maintenance announcement is two global settings (`maintenance.active`, `maintenance.until`) shown on `/status`.
  - There is no campaign or broadcast feature anywhere. Promotions are plan discounts.

## 2. Principles

1. **The console runs the installation.** Its pages manage workspaces, people's access to the installation, money, plugins, health and the installation's own outreach. It holds no customers, bookings, stock, sales or business documents.
2. **Hide, don't delete.** Tenant pages disappear from tenant 0's sidebar, palette and dashboard, and their routes answer 404 in tenant 0. Any data tenant 0 already holds stays in the database untouched (question 3).
3. **Operators only, enforced on the server.** Operator pages need an operator permission, and operator permissions can be held only through tenant-0 roles (section 8). Hiding a nav item is never the gate.
4. **One console for Whity Cloud and self-hosted.** The same screens everywhere. What differs comes from settings that already exist (section 10), not from an edition flag.
5. **Operators' outreach is separate from a workspace's.** The console's campaigns reach the installation's workspace admins. They never touch a workspace's contacts. A workspace's own marketing to its customers is not part of this section.

## 3. The sidebar in tenant 0

The new groups, in order. The group keys are new values in `NAV_GROUP_ORDER` that only tenant 0 uses.

| Group | Items |
| --- | --- |
| **Console** | Home (section 4) · Inbox (section 5) · Calendar (section 6) |
| **Installation** | Workspaces · Plugins and store · Pricing (section 7.4) · Campaigns (section 7.5) |
| **Reports** | Usage · Growth · Health (was System) · Errors |
| **Audit** | Audit log, with chain verify (section 7.3) |
| **Operators** | Operators (tenant-0 people) · Roles · Sign-up approvals |
| **Settings** | One entry with the installation's settings: general, branding, sign-in and SSO, sign-up, email, storage, feature flags, error tracking, languages, translations, public form keys, security, backups (section 7.6) |

The operator's own account settings stay where they are (bottom of the sidebar, as everywhere).

### 3.1 Every item today, kept, moved or hidden

**Kept** means unchanged in tenant 0. **Moved** means it stays in tenant 0 under a new group or name. **Hidden** means it's gone from tenant 0's sidebar, palette and dashboard, and its pages answer 404 there. Every other workspace is unchanged.

| Item (id) | Today | In the console |
| --- | --- | --- |
| dashboard | Workspace dashboard + `PlatformCards` | **Moved**: Console › Home, the operator home (section 4) |
| inbox (`/admin/inbox`) | A person's own work inbox | **Moved** into Console › Inbox as its "Assigned to me" tab. Section 5's operator inbox is the main list |
| approvals | Approvals | **Kept** under Console, only when there is something to approve in tenant 0 (plugin role approvals, sign-ups) |
| my-form-submissions, member-forms | Forms for members | **Hidden** |
| chat | Team chat | **Kept** for operators talking to each other (question 5) |
| my-messages | A customer's messages | **Hidden** |
| tasks, boards | Tasks | **Hidden** (question 5). Inbox items carry their own status |
| calendar | Workspace calendar | **Moved**: Console › Calendar, with the operator kinds only (section 6) |
| convening-meetings | Board meetings | **Hidden** |
| walk-ins, commission, printables | Front desk | **Hidden** |
| contacts | Contacts | **Hidden** |
| inventory | Inventory | **Hidden** |
| sales | Quotes, sales orders | **Hidden** |
| accounting (when on) | Accounting | **Hidden** |
| users | People | **Moved**: Operators › Operators. It lists tenant-0 people only. People in every workspace stay reachable from a workspace's record |
| user-groups, ous, delegations, relations | Organisation | **Hidden** |
| roles | Roles | **Moved**: Operators › Roles. Tenant 0 keeps the ability to edit global roles |
| approval-gating (Sign-up approvals) | People | **Moved**: Operators |
| my-agents, ai-principals | AI | **Hidden**. Agents work for a workspace's people, not the installation (question 5) |
| mcp-tools | MCP tokens | **Kept** in Settings › Security, so an operator can give `whity-cli` or an agent operator access |
| document-library, document-templates, document-route-templates, document-sources, saved-blocks | Documents | **Hidden** |
| forms-builder, forms-catalog | Forms | **Hidden** |
| tenants | Platform › Workspaces | **Moved**: Installation › Workspaces |
| plugins (+ store) | Platform | **Moved**: Installation › Plugins and store, plus the kill-switch list (section 7.6) |
| billing (Plans and pricing), promotions | Platform | **Moved**: Installation › Pricing (section 7.4) |
| tenant-billing (`/billing`) | The workspace's own bill | **Hidden**. Tenant 0 pays no one (`PaymentWall` already skips it) |
| audit-logs | Platform | **Moved**: Audit |
| errors | Platform | **Moved**: Reports › Errors |
| notifications (delivery log) | Platform | **Moved**: Reports › Health, as a tab |
| system (`/admin/stats`) | Platform › System | **Moved**: Reports › Health |
| languages, platform-email, platform-storage, platform-feature-flags | Platform | **Moved** into Settings |
| website-settings and the Settings pages | Settings | **Kept**: general, branding, signup, sso, email, storage, feature-flags, security, error-tracking, public-forms, email-domains. The workspace-only pages (owners, customer-signup, messages) are already hidden in tenant 0 |
| tags, tag-groups | Settings | **Hidden** (they label workspace records) |
| translations | Settings | **Kept** (installation-wide strings) |
| Fields, Document themes, Company stamps, Bookings, Licensed devices | Settings links by capability | **Hidden**, except **Licensed devices**, which is kept: the installation's device licences |
| Plugin features (`plugin-*`) | Plugins group | **Hidden** unless the plugin marks the feature as an operator screen (section 9). Whity Pay's setup moves to Pricing. Its workspace screen is hidden |

### 3.2 How it's enforced

- **Nav.** A new `scope` on every nav item: `workspace` (default for business pages), `operator` (replaces `systemTenantOnly`) or `both` (account, approvals, chat). `isVisibleTo()` drops `workspace` items in tenant 0 and `operator` items elsewhere. Core items get their scope in the listener. `tests/Core/CoreNavigationRegistrationTest.php` asserts the table above.
- **Routes.** A middleware refuses workspace-scoped API groups in tenant 0 with 404: calendar bookings, contacts, inventory, sales, accounting, documents, forms, tasks. It works the same way as `EnforceTenantIsolation`, from a list kept next to the nav scopes. Web routes check the same list in the auth gate.
- **Plugins.** Code plugins stay loaded in tenant 0, because their install-wide parts (webhooks, operator setup) must run. A plugin's frontend feature shows in tenant 0 only if it declares `scope: 'operator'` or `'both'` (section 9). Workspace plugins built in Whity Builder already refuse tenant 0.
- **Palette and dashboard.** They read the same filtered nav, so they need no rules of their own (`app-command-palette.tsx:103` today repeats the tenant-0 rule).

## 4. Console home

One page that answers "is the installation all right, and does anything need me?":

- **Needs you:** inbox items waiting for an operator; approvals; errors in the last day; a failed audit verify; a stale backup; an update ready.
- **Next on the calendar:** the next maintenance window or update, and who it affects.
- **Health at a glance:** version and build, queue lag, the last backup's age, storage used, error rate. These come from `/api/admin/stats`, `/api/health` and the backup freshness file.
- **Usage at a glance:** workspaces (active, on trial, paid), members, workspaces near a base allowance (section 7.1).

Built as a dashboard from cards, using the existing dashboard SDK (WHIT-1177). `PlatformCards` becomes its first cards.

## 5. The operator inbox, and "Contact support" in every workspace

### 5.1 What a workspace admin sees

- **Contact support** in the Help menu and the account menu of every workspace, and **Report a problem** on error screens and toasts.
  - The name comes from the installation's brand: on Whity Cloud it reads "Contact Whity"; on a self-hosted install, the install's name (section 09, white-label).
  - It opens a short form:
    - **Kind:** Question, Request, Change request, Bug report.
    - Subject and description.
    - Screenshots (images only, up to 5 files of 5 MB each).
    - For a bug: "What did you expect?"
  - These are attached automatically, and the form shows them before sending: the page address, app version and build, browser, workspace, and the time. No customer data is included.
- **Settings › Support** lists the workspace's requests with their status. Each one is a thread the admin can reply in.
- **Who can send:** people with `support:contact`. It is granted by capability to roles with `settings:manage`, so workspace admins (question 6).
- **Notifications:** the sender is notified when an operator replies or changes the status. This goes in-app and by email, through `NotificationDispatcher` and `BrandedEmailSender`, following each person's notification preferences.

### 5.2 What the operators see

**Console › Inbox** is one list with filters for kind, status, workspace, assignee and age.

- **Statuses:** `new`, `open`, `waiting_on_workspace`, `planned`, `done`, `declined`. Change requests and bugs can be **planned**, linked to a calendar update (section 6) so the sender sees when it ships.
- **Each item:** the thread, internal notes that the workspace never sees, assignee (a tenant-0 person), priority, the workspace's plan and usage (section 7.1), and a link to the workspace record.
- **Tabs:** All · Assigned to me · the person's own work inbox (today's `/admin/inbox`).
- **Send to Taskaat:** an operator can copy an item into Taskaat through core's existing Taskaat connection (`src/Core/Taskaat`). The item keeps a link to the task. Only when Taskaat is connected.

### 5.3 Data

These are deliberately cross-workspace rows. They are owned by tenant 0 and carry the sender's workspace.

| Table | Columns |
| --- | --- |
| `support_requests` | `id`, `tenant_id` (always 0), `from_tenant_id`, `from_profile_id`, `kind`, `subject`, `status`, `priority`, `assignee_profile_id`, `context` (JSON: page, version, build, browser), `planned_event_id`, `taskaat_ref`, timestamps |
| `support_messages` | `id`, `request_id`, `author_profile_id`, `body`, `internal` (operators only), `attachments` (file ids), `created_at` |

How access works:

- A workspace reaches its own rows only through `/api/support/*`, which filters on `from_tenant_id` = the caller's workspace and never returns `internal` messages.
- Operators use `/api/operator/support/*`.
- `EnforceTenantIsolation` gets one documented exception for these two tables, scoped by `from_tenant_id`. It is audited like the tenant-0 bypass.
- Attachments are stored in tenant 0's file storage. When a workspace is deleted, its requests are kept and marked as from a deleted workspace.

## 6. The operator calendar

**Console › Calendar** reuses the core calendar screens and `calendar_events` in tenant 0, with operator kinds only.

| Kind | Fields | What it does |
| --- | --- | --- |
| **Maintenance window** | start, end, affects (all workspaces, or a list), what's affected (sign-in, files, everything), message in English and Arabic | **Before:** shows a notice banner in the affected workspaces (default 48 hours ahead) and emails their admins once (default 24 hours ahead). **While it runs:** sets `maintenance.active` and `maintenance.until` (so `/status` shows it), and clears them when it ends |
| **Update** | target version, start, expected length, release notes link, linked inbox items | Same notices as maintenance. When the installation starts on the target version, the update is marked done and its linked inbox items become `done`, which notifies their senders |
| **Deployed** (made automatically) | version, build, time | When the app boots on a build different from the last recorded one, it records the deploy. This gives every installation a history of what ran when, including app.whity.dev's auto-deploys from `develop` |
| **Note** | free text | Operators' own reminders (a key ceremony, a renewal, a review) |

**Tie to the release pipeline:**

- `LatestReleaseCheck` already knows when a newer release exists. The console's Home and Calendar then offer **Schedule this update**, which makes an Update event with the release notes filled in.
- On Whity Cloud, the release workflow (WHIT-1340) adds nothing new. The Deployed record is the history. A planned release becomes an Update event that an operator makes from the same button.
- Nothing in the console runs the upgrade. Upgrading stays with the deploy tooling (whity-local-deploy, `scripts/`, the self-hosted guide). Question 7 asks whether it should.

Data: two new columns, `calendar_events.kind` with the four kinds and `calendar_events.operator_meta` (JSON: affects, target version, notice times, messages). They are allowed only in tenant 0. A table `operator_notices_sent` records which notices went out, so none goes twice.

## 7. The rest of the console

### 7.1 Reports › Usage

Usage across every workspace, read from `usage_ledger` and `tenant_storage_usage` (L1, WHIT-1325). There is no new counting.

- A table of workspaces × meters for a month, sortable. It marks workspaces past 80% of a base allowance, the same rule as `UsageReport`.
- Per-meter totals by month for the last 12 months.
- A workspace's own usage history, opened from its record.
- CSV export.
- API: `GET /api/operator/usage?period=&meter=&tenant=`.

### 7.2 Reports › Growth and Health

- **Growth:**
  - workspaces created, by week;
  - active workspaces (anyone signed in within 7 or 30 days);
  - members;
  - plan mix and trials started or ended;
  - money in by month, from `invoices`.
  - All numbers are counts and totals. No workspace's business data is read.
- **Health** (today's System page, renamed):
  - version, build, migrations;
  - queue lag and failed jobs;
  - health probes;
  - storage;
  - the notification delivery log (moved here);
  - the last backup.

### 7.3 Audit

- The existing audit log, which is install-wide in tenant 0. Its filters gain a workspace filter.
- **Verify the chain:** a button runs `AuditSeals::verify()` as a background job. It shows:
  - the result, and the first broken row if any;
  - the last sealed row;
  - the chain head, with a **Copy** button so the operator can keep it off the server (the readiness gap in `docs/security/READINESS.md`).
  - Each verify is itself an audit entry.
  - A scheduled daily verify reports a failure to Console › Home and by email to operators.
- API: `POST /api/operator/audit/verify`, `GET /api/operator/audit/verify/{run}`.

### 7.4 Installation › Pricing

Today's Plans and pricing, gathered on one page with tabs:

- **Plans and prices:** as built.
- **Meters and base allowances:** each plan's entitlements against the meters of section 33 §5, editable.
- **Promotions** and **Affiliates:** as built.
- **Cash payments:** as built.
- **Payments provider:** Whity Pay's setup screen, mounted here when the plugin is installed (section 9). If no billing plugin is installed, it says so and links to the store.
- **Catalogue:** the import that `billing:catalogue:apply` does today, uploading the same JSON, with a dry-run first. Question 8.

### 7.5 Installation › Campaigns

The installation's own outreach to the people who run its workspaces:

- On Whity Cloud, Whity's campaigns to its customers.
- On a self-hosted install, the operator's campaigns to their own workspaces.

**A campaign has:**

- **Audience:** a rule over workspaces and their people. For example: role (owners, admins, everyone), plan, trial state, created between, country or language, active or inactive for N days, usage past a meter's base. It reuses the `Audience` rule engine, run over the installation instead of one workspace. A preview shows the count and a sample.
- **Channels:**
  - **In-app announcement:** a card on the workspace dashboard and an item in the notification bell, dismissible.
  - **Email:** through `BrandedEmailSender`, English and Arabic, with an unsubscribe link.
  - Both channels can be on.
- **Optional promotion:** links a promotion (section 7.4), so the email's button opens checkout with the discount applied.
- **Schedule:** send now or at a time.
- **Results:** sent, delivered, opened (email), clicked, dismissed, converted (promotion redeemed).

**Consent:**

- Every person gets a **News from <installation>** switch in their notification preferences, on by default (question 9).
- Unsubscribe turns it off.
- Service messages ignore it: maintenance notices, replies to support requests, billing emails.
- Campaigns never count on any workspace's messages meter.

**Separate from a workspace's campaigns.** Console campaigns live in tenant 0's tables (`operator_campaigns`, `operator_campaign_sends`) and reach only people with an account on the installation. They never read a workspace's contacts or customers. If workspaces later get their own campaigns to their customers, those are a different feature, in the workspace, counted on its meter.

### 7.6 Kept as they are, in new places

- **Workspaces:** list, record, plan, add-ons, entitlements, branding host, create (as built).
- **Plugins and store:** install, enable, per-workspace activation, core grants, role approvals (as built). A **Kill switch** tab lists revoked plugins and imports a signed revocation list. Today that is API-only.
- **Settings:** the global settings form (`PlatformDefaultsSection`) split into the Settings pages of section 3. That includes **Sign-in**, with the password sign-in and SSO switches and a note on the break-glass `whity-cli auth password-sign-in on` / `WHITY_PASSWORD_SIGN_IN=force`.
- **Backups**, read-only in this version:
  - the last backup's time, size and age, from the backup freshness check;
  - where backups go;
  - the restore guide (`ops/RESTORE.md`);
  - an alert on Home when the last backup is older than the setting (default 26 hours).
  - Running a backup or a restore from the console is question 10.

## 8. Who gets in

### 8.1 Operator roles

New roles that belong to tenant 0 (`roles.tenant_id = 0`; migration 093 allows per-workspace roles):

| Role | Holds |
| --- | --- |
| **Operator** | Every operator permission. Tenant-0 people who hold the global `admin` today move to this role (question 2) |
| **Support** | Inbox, calendar (read), reports (read), workspaces (read) |
| **Billing** | Pricing, Whity Pay setup, cash payments, refunds, usage reports |
| **Marketing** | Campaigns, promotions (read), growth reports |
| **Auditor** | Audit log and verify, reports (read), everything else read-only |

New permissions:

- `operator:support`
- `operator:calendar`
- `operator:reports`
- `operator:audit_verify`
- `operator:campaigns`
- `operator:backups`

The existing operator permissions carry on, and the role table above assigns them: `tenants:*`, `plans:manage`, `subscriptions:manage`, `entitlements:manage`, `stats:read`, `settings:manage` in tenant 0, `plugins:*`, `languages:manage`.

### 8.2 The guard

- A permission can be marked **operator-only**: every new `operator:*` permission, the existing operator ones listed above, and plugin permissions a plugin marks as operator-only (section 9).
- `RolesApiHandler` refuses to grant an operator-only permission to a global role or to a role of any workspace other than 0, with a plain error. The same check runs in `PluginRoleSeeder` and in plugin migrations through a helper.
- `RoleChecker` treats an operator-only permission as held only through a tenant-0 membership. That way an old grant on the global `admin` can never reach a workspace owner.
- A migration moves existing grants of these permissions off global and workspace roles onto **Operator**. It records each move in the audit log.
- This closes what migration 199 could only half-do for `stats:read`.

### 8.3 Plugin permissions in the catalogue (WHIT-1352)

The fix for Whity Pay comes first, because the owner's Polar setup is waiting on it:

1. Core upserts every code plugin's `getPermissions()` into `permissions` when the plugin is installed, enabled or upgraded, and on boot as an idempotent repair (`ON CONFLICT DO NOTHING`).
2. A grant to a name that isn't in `permissions` is refused with 422 and logged, instead of being silently skipped (`RolesApiHandler.php:2154`, `PluginRoleSeeder.php:566-585`).
3. Whity Pay marks `whity_pay:manage` as operator-only (section 9). Once deployed, an operator grants it to the Operator and Billing roles.
   - On app.whity.dev, until roles exist, it's granted to the owner's tenant-0 admin membership.
   - That step goes through the orchestrator with the owner's word.

## 9. Plugins and the console (SDK)

A minor SDK release adds these (the orchestrator gives the number):

- A frontend feature's **`scope`**: `workspace` (default), `operator` or `both`.
- A feature's **`placement`**: optional, for an operator feature to appear inside a console page instead of the Plugins group. First value: `operator.pricing`, a tab on Pricing (used by Whity Pay's setup). Later: `operator.reports`, `operator.settings`.
- A permission's **`operatorOnly`** flag, which feeds the guard in section 8.2.
- `PluginOperatorGate` moves to the SDK as `OperatorContext::isOperator()`, so plugins stop checking `tenant_id === 0` themselves (Whity Pay's `SetupApiHandler.php:15-19`).

Plugins written before this release keep working. Their features count as `workspace`, so they leave tenant 0's sidebar. The only plugin that needs an update for this section is Whity Pay, which declares `scope: 'operator'`, `placement: 'operator.pricing'` and `operatorOnly` on `whity_pay:manage`.

## 10. Whity Cloud and self-hosted

There is no edition flag. One console, and the differences come from settings that already exist:

| | Whity Cloud (app.whity.dev) | Self-hosted |
| --- | --- | --- |
| Who the operators are | Whity's team | The installer's team |
| "Contact support" reads | Contact Whity | Contact <install name> (white-label) |
| Inbox | Whity's customers' requests | The install's own workspaces' requests. Problems with Whity itself go upstream through GitHub (question 11) |
| Campaigns | Whity's campaigns to its customers | The operator's campaigns to their own workspaces |
| Pricing | Whity's plans, USD, Whity Pay with Polar | Usually none: no base allowance means unlimited. The operator may charge with their own Whity Pay |
| Updates | `develop` auto-deploys. The calendar records Deployed and announces planned windows | "Update available" from `LatestReleaseCheck`. The operator schedules the window and runs the upgrade with their own tooling |
| Sign-in | Whity ID only (password sign-in off) | Whatever the installer sets |
| Backups | whity-local-deploy's backups | The installer's. The page shows the freshness check if it is set up, and otherwise says how to set it up |

## 11. Proposed register rows (OC.*)

A new register section "The operator console", added once the owner answers.

| # | Decision | Proposed |
| --- | --- | --- |
| OC.1 | Scope | The System workspace (tenant 0) is an operator console that runs the installation. It shows no tenant pages: calendar bookings, contacts, inventory, sales, accounting, documents, forms, tasks, organisation, agents. Their routes answer 404 there. Data already in tenant 0 is kept, not shown. Amends W.2 |
| OC.2 | Sidebar | Console (Home, Inbox, Calendar) · Installation (Workspaces, Plugins and store, Pricing, Campaigns) · Reports (Usage, Growth, Health, Errors) · Audit · Operators (Operators, Roles, Sign-up approvals) · Settings. Section 36 §3.1 lists every item |
| OC.3 | Scope on nav | Nav items and plugin features carry `scope` `workspace`, `operator` or `both`. `operator` replaces `systemTenantOnly`. A plugin feature is `workspace` unless declared |
| OC.4 | Operator roles | Tenant-0 roles Operator, Support, Billing, Marketing, Auditor. Operator-only permissions can be granted only to tenant-0 roles and count only through a tenant-0 membership |
| OC.5 | Plugin permissions | Core writes every code plugin's declared permissions into the catalogue on install, enable, upgrade and boot. Granting an unknown permission is refused (WHIT-1352) |
| OC.6 | Contact support | Every workspace has "Contact <installation>" and "Report a problem". Kinds are Question, Request, Change request and Bug report. Context is attached and shown before sending. Senders follow their requests in Settings › Support |
| OC.7 | Operator inbox | One inbox with statuses new, open, waiting on the workspace, planned, done and declined, plus assignee, internal notes, and an optional copy to Taskaat. A planned item links to a calendar update and closes when the update ships |
| OC.8 | Operator calendar | Kinds Maintenance window, Update, Deployed (automatic) and Note. Notices go to affected workspaces before (banner 48 h, email 24 h), and `maintenance.*` is set while a window runs. The console never runs the upgrade |
| OC.9 | Usage and growth | Install-wide reports from the usage ledger and counts only. No workspace's business data is read |
| OC.10 | Audit | Verify the hash chain from the console, daily on a schedule, with the chain head shown to copy off the server |
| OC.11 | Pricing | Plans, prices, meters and base allowances, promotions, affiliates, cash payments, the payments provider (Whity Pay's setup as a tab) and catalogue import, on one page |
| OC.12 | Campaigns | The installation's outreach to people with an account on it, by audience rule, in-app and email, optionally with a promotion. Its own consent switch "News from <installation>". Never counted on a workspace meter and never reaching a workspace's contacts. Separate from any workspace's own campaigns |
| OC.13 | Backups | Read-only status in the console (last backup, age, restore guide), with an alert when stale |
| OC.14 | Editions | No edition flag. Cloud and self-hosted run the same console, and the differences come from existing settings |

## 12. Questions for the owner

1. **The Whity Pay permission fix now.** May WHIT-1352 (section 8.3) be built and merged now, ahead of the rest, so the Polar setup can be entered? Recommended: **yes, now**.
2. **Who are the operators.** The tenant-0 people who hold the global `admin` today move to the new **Operator** role, and the global `admin` stops carrying operator permissions. Recommended: **yes**.
3. **Tenant 0's existing data.** On app.whity.dev, anything tenant 0 already holds (bookings, contacts, documents made while testing) is kept hidden, not deleted or moved. Recommended: **keep hidden**. If the owner wants any of it in a real workspace, say which.
4. **Five roles or one.** Operator, Support, Billing, Marketing and Auditor (section 8.1), or just Operator for now with the others later. Recommended: **all five**. They cost little and keep pricing and campaigns apart.
5. **Chat, tasks and agents in the console.** Keep team chat for operators, hide Tasks and Boards (the inbox has its own statuses), hide agents. Recommended: **as written**.
6. **Who may contact support.** Workspace admins only (`settings:manage`), or every staff member. Recommended: **admins**, with "Report a problem" on error screens open to every staff member as a bug report.
7. **Running updates from the console.** The console schedules and announces updates but never runs them. Recommended: **schedule and announce only**. Running upgrades needs the host's tooling and is a security boundary.
8. **Catalogue import from the console.** Upload the pricing catalogue JSON with a dry run, or keep it CLI-only. Recommended: **in the console**, with the CLI kept.
9. **Campaign consent.** "News from <installation>" on by default with unsubscribe in every email (an existing account relationship), or off until each person opts in. Recommended: **on by default** for people with an account, since there are no third-party lists.
10. **Backups.** Read-only status (recommended for this version), or also a **Back up now** button that runs `scripts/backup-db.sh` through the queue.
11. **Self-hosted bug reports to Whity.** On a self-hosted install, may an operator forward a bug report to Whity? It would be a prefilled GitHub issue that the operator reviews, with no data sent automatically. Recommended: **yes, as a prefilled GitHub issue**.
12. **The console's name.** "System" stays as the workspace's name in the switcher, or it becomes "Console" (Arabic: لوحة التشغيل). Recommended: **Console**.

## 13. Build order (after the answers)

One WHIT-ticketed PR each. The orchestrator gives migration numbers and SDK minors before each PR.

| # | Milestone | Needs |
| --- | --- | --- |
| M0 | WHIT-1352: plugin permissions into the catalogue, refuse unknown grants | Question 1 |
| M1 | Lean sidebar and routes: nav `scope`, the 404 middleware, plugin feature `scope`/`placement` (SDK), console groups, Home from cards | Question 5 |
| M2 | Operator roles and the operator-only guard, the migration that moves grants, Whity Pay updated | Questions 2 and 4 |
| M3 | Contact support and the operator inbox | Questions 6 and 11 |
| M4 | Operator calendar, notices, the Deployed record, Schedule this update | Question 7 |
| M5 | Reports (usage, growth, health), audit verify, backups status | Question 10 |
| M6 | Pricing page with meters, the payments tab and catalogue import | Question 8 |
| M7 | Campaigns | Question 9 |

Screens follow section 21's page patterns until the brand team's cards arrive. The console screens go to the brand team as an ask in `brand-asks/`.
