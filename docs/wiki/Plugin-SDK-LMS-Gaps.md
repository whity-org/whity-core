# Plugin SDK gaps for an advanced LMS

Status: gap list, 25 Nov 2026 (WHIT-1411). Nothing here is decided or built.
Measured against the plugin SDK **1.168** on `develop`.

Someone plans to build an advanced learning product on Whity: interactive
lessons with instant feedback, in the style of Brilliant, rather than a course
catalogue with videos and quizzes. This page lists what the SDK already gives
such a plugin, what it lacks, and what an addition could look like. The SDK
stays on 1.x (owner, 24 Nov 2026), so every proposal below is **additive**: a new
optional interface or class, nothing existing changes.

## What already works

An LMS plugin can lean on these today:

| Need | SDK today |
|---|---|
| Course, lesson, attempt and progress tables | `getMigrations()`, `Tenant\PluginTablesInterface`, workspace isolation guards and conformance tests |
| Teachers, assistants, reviewers | `getPermissions()`, `Contribute\PluginRolesInterface` |
| Learners as a workspace's customers, acting on their own records | `PluginCustomerPermissionsInterface` (SDK 1.104) |
| Streak reminders, weekly digests, grading in the background | `PluginJobsInterface`, `PluginScheduledJobsInterface`, `PluginNotificationsInterface`, `Messaging` |
| Certificates and transcripts | `Render\DocumentRenderer`, `PluginDocumentsInterface`, printouts |
| Staff screens for authoring and reports | `PluginFrontendInterface`: `crud`, `action`, `blocks`, `embed` (with the `frame/1` bridge) |
| Setup after install, workspace templates | `PluginOnboardingInterface`, `PluginWorkspaceTemplatesInterface` (moving to the templates SDK, design 40) |
| Selling limits to the workspace ("max students") | `PluginEntitlementsInterface`, `Usage` meters |
| AI tools for staff | `PluginMcpToolsInterface`, `PluginAgentsInterface` |
| Offline use on the desktop app | `Sync\SyncableResource`, `blocks` rendering on the offline host |
| Files | `Storage\PluginFiles` |
| Arabic and English, settings, secrets, audit | `I18n`, `Settings`, `Secrets`, `Audit` |

## The gaps

Ordered by how much each one blocks an advanced LMS.

### 1. No learner-facing surface

Every screen kind in `PluginFrontendInterface` renders inside the **staff admin
UI**. Learners join as customers, and `PluginCustomerPermissionsInterface` lets
them hold the plugin's permissions, but a plugin cannot give them an app of
their own: a learner home, a course map, a full-screen lesson player.
Plugin public routes are limited to three shapes (signed `webhooks`, anonymous
`forms`, keyed `read` links); none of them is a signed-in learner screen.

**Proposal:** a `Contribute\PluginCustomerScreensInterface` declaring screens
shown to the plugin's customers in a customer area (own navigation, the
workspace's brand, phone layout), using the same `blocks` and `embed` kinds and
the same route-level permission checks. This is the largest piece and the one
most other gaps build on.

### 2. Nowhere good to run an interactive lesson

The `blocks` vocabulary covers business screens and documents. It has no
question with instant feedback, no maths (LaTeX), no drag-and-drop, no graph or
simulation, no code exercise. The `embed` frame can run anything, but its
bridge suits tools rather than a lesson player: a five-minute token bound to the
plugin, only the plugin's own routes, 30-second timeout, one request at a time,
no way to keep state across steps except by calling a route.

**Proposal (choose one or both):**
- interactive blocks in a versioned block set: `question` (choice, number,
  expression with a checker), `math`, `reveal`, `step`, `hint`; checked on the
  plugin's route, so answers never ship to the page;
- a `lesson/1` bridge profile for `embed`: batched attempt events, local state
  saved through the bridge, resume, and a progress call the host understands.

### 3. No live updates and no high-volume events

There is no websocket or server-sent events channel in the SDK, and no event
intake built for learning analytics (every attempt, hint and second spent). Live
classes, leaderboards, a teacher watching a class work through a lesson, and
adaptive difficulty all need one or both.

**Proposal:** an `Events\LearningEvents`-style append-only intake (batched,
per-workspace, rolled up by a job) and a `Realtime` channel scoped to a
workspace and a plugin resource, so the host owns the connection and the
permission check.

### 4. The usage meters count learners as customers

Free counts 200 customers a month and paid plans sell customer packs (business
model, 21 Nov 2026). A consumer-scale LMS with thousands of learners would sit on
the customer meter from day one. `PluginEntitlementsInterface` lets the plugin
sell "max students" to the workspace, but the core customer meter still counts
them.

**Owner decision needed:** do learners count as customers, get their own meter,
or count only when active in a month?

### 5. No selling to learners

The billing SDK covers a workspace paying for Whity and plugin licence sales.
A learner buying a course or a monthly subscription, and content that opens only
when they have paid, would go through Whity Pay subscriptions. No plugin hook
answers "is this customer entitled to this course".

**Proposal:** a `Billing\CustomerEntitlements` read API (backed by Whity Pay
subscriptions and one-off purchases) and a hook when a customer's entitlement
starts or ends, so the plugin can open or close content.

### 6. No shared catalogue across workspaces

Brilliant is one catalogue for millions of people. Whity keeps each workspace's
data apart, and a plugin cannot declare a table shared across workspaces; only
core can (`SanctionedGlobalTables`). A course library written once and sold to
many schools or companies has no home.

**Owner decision needed:** content shipped inside the plugin package (read-only,
versioned with the plugin), content copied into each workspace on install, or a
sanctioned shared table for plugins with its own review rules.

### 7. No video

`Storage\PluginFiles` stores files. There is no transcoding, adaptive streaming
or CDN delivery, and no player.

**Proposal:** treat video as an outside integration (a paid plugin connecting
a video service), with a small SDK contract for "a playable media item" so
course plugins don't each wire their own.

### 8. Agents do not fit tutoring

Whity's agents propose and a person approves (AG rules). An AI tutor that talks
to a learner directly, in real time, does not fit that model.

**Owner decision needed:** a learner-facing assistant that only explains and
never changes records (hints, worked steps), allowed without approvals, or keep
agents staff-only.

### 9. Smaller gaps

- **Authoring:** no versioned "draft / published" content contract; each plugin
  would build its own. A `Content\Versioned` helper would cover lessons,
  questions and templates alike.
- **Spaced repetition and streak time:** scheduled jobs run on the workspace's
  time; per-learner time zones need the customer's own zone in the SDK.
- **Search:** no plugin hook into core search for course content.
- **Phone:** the web app on a phone follows PW.1–PW.11, but there is no native
  mobile app in scope (0.2.29), so offline lessons on a phone need the PWA path.

## Suggested order

1. Learner-facing screens (gap 1), since 2, 5 and 9 build on them.
2. The lesson player (gap 2).
3. Customer entitlements through Whity Pay (gap 5).
4. Learning events and live updates (gap 3).
5. Video as an integration (gap 7).

Gaps 4, 6 and 8 wait for the owner's decisions before any design work.

## Before building anything

Get the builder's actual feature list. "Advanced, like Brilliant" covers a wide
range, and some of the above (live classes, a shared catalogue) may not be
wanted at all.
