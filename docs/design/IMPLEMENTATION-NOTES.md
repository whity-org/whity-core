# Implementation notes

The working plan for design system 2.9 (A0 to A6), checked against `develop` at `a8fd3a96` on 2 Nov 2026. This replaces the M0 summary and M0's foundation table. The rest of the M0 notes, including the owner's answers of 29 Oct, are kept unchanged [below](#m0-notes-29-oct-2026).

How it was checked: the code was read on `origin/develop`, screen by screen against each card's README. Screens were not compared with screenshots; each milestone does that. The only browser check was `app.whity.dev/login` on v0.2.23: body 14px, button 36px, input 28px with 12px text.

## What the 2.9 merge did

2.9 was merged into `docs/design/` three ways against the original 2.2 import (`6c0c9a9a`), not unzipped over it.

| Result | Files | Notes |
| --- | --- | --- |
| Added | 352 | Sections 19-25, the new cards and screenshots, the 2.9 PDF |
| Taken from 2.9 | 292 | The repo hadn't changed them since 2.2 |
| Merged line by line | 3 | `00-decision-register.md`, `13-app-map.md`, `PLAN.md`, no conflicts |
| Repo's version kept | 7 | 2.9 hadn't changed them: `16-documents.md`, `18-document-editor.md`, `handoff/editor/README.md`, `handoff/editor/whity-document.ts`, and the `EditorAgents`, `EditorBlocks` and `EditorCollab` READMEs |
| Removed | 1 | `Whity-Brand-Guidelines-v2.2.pdf`, replaced by 2.9's |

Every file only the repo has stays, including the `Cover` card.

**For the design team to pick up** (their package lacks all of this, so the next package should start from this folder):

- Register: E.40-E.47, the E.45 relaxation in 6.3, and 6.8's Invoice and credit note in the free set, with the Tax invoice and fiscal credit note being what Front Office adds.
- Contract: `handoff/editor/whity-document.ts` with barcode, QR, maths, label sheets, own fields and themes. A test holds it equal to `packages/ui/src/editor/contract.ts`.
- Text: the free-set paragraph in section 16; blocks and label sheets in section 18; M4d and plugin themes in `PLAN.md`; D1 in `13-app-map.md`; the corrected cross-references (M0 Q12).
- `CLAUDE-snippet.md`: 2.9's version dropped three rules still in force. They are back: the 16px editor sheet, "with the permission it used", and the pointer to section 18 and `handoff/editor/`.
- E.40-E.47 still have no cards. Themes need a picker and a screen (WHIT-785).

## Section 12, items 1-15

| # | Item | Status | Where it stands |
| --- | --- | --- | --- |
| 1 | Tokens v3 | Done, 2.9 sizes missing | `src/design/tokens/base.json` is 3.0.0 with the colours. Text, control, row and corner sizes are 2.2's. A0 |
| 2 | Type | Partly | Fonts and no-700 are done and guarded. Default is 14px. 576 `text-xs` lines (12px) and 117 fixed sizes under 12px. A0 |
| 3 | Button, input, select | Partly | Button is at the 2.2 sizes. Input and select are 28px with 12px text. A0 |
| 4 | Badge, alert, nav, tabs | Partly | Badge is done. Palette classes remain in `alert`, `card`, `color-card`, `color-picker`, `plugin-card`, `route-flow/editor` and `admin/stats`. A0, except `stats` in A6 |
| 5 | Card, menus, dialogs | Partly | Floating layers are done. `card.tsx` keeps `shadow-2xs`. A0 |
| 6 | `data-table.tsx` | Partly | Sort, paging and states exist. No row click, selection, bulk bar, sticky header or totals. Rows aren't 48px. A0 |
| 7 | App shell | Mostly | 240px, 56px rail, drawer and switcher exist. Group labels are 11px capitals. A0. `BuiltOnWhity` is missing (A2) |
| 8 | Icons | Done | `robot` maps to `IconRobotFace`, stroke 2.25 at 16px and below |
| 9 | brand.whity.dev | Not checked | It lives in `whity-org/whity-web`, not here. 2.9's logo files match 2.2's |
| 10 | Agent proposals | Done | Migration 176, `/api/me/agent-proposals`; undo in 182 (WHIT-812) |
| 11 | Per-permission modes | Done | Migrations 180-181, `AgentDefinition`, server-side grants |
| 12 | Whity Render | Partly | All 13 starters seeded in both languages (189, 190). Amount in words is typed. JoFotara belongs to Front Office, not core |
| 13 | Move to a Whity organisation | Done | `whity-org`, `@whity/*`. `composer.json` keeps the name `amroksaleh/whity-core` and the item's text is out of date |
| 14 | Build every screen from its card | Partly | See the milestones below |
| 15 | Text size, Show technical details | Missing | Theme is browser-only, language is on the profile, `/api/ui/preferences` is per workspace. A0 |

## Milestones

Client work only; server items are listed apart. A0 comes first and the rest can run side by side. Editor M6-M8 are separate: M6 and M7 aren't started, and M8 hasn't been checked against its list.

### A0 Foundation

Tokens 2.9, text size and technical details per person, and what every screen shares: `DataTable` selection and bulk bar, page states, confirm tiers, record tabs with the save bar, `Stepper`, `MoneyInput`, `SearchField`. Server: S1. Done by the foundation PR.

### A1 Home, People and Inbox

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A01 Dashboard | Placeholder | `TplHome`, `TplHomeMember` | `/api/admin/stats`, `/api/me/inbox`, `/api/me/agent-proposals` | Whole page. `/admin` still goes to stats |
| A02 Inbox | Partly | `TplInbox` | `/api/me/inbox` | From column, row click, translated states |
| A03 Approvals | Nearly | `TplAgentApprovals`, `TplAgentApprovalsAr` | `/api/me/agent-proposals`, `/api/me/agent-actions` | Permission codes, skeletons, retry |
| A10 Users | Partly | `TplUsers`, `TplUserRecord`, `TplUserRecordAr` | `/api/users` | Tabs, save bar, names, avatars, filters, bulk |
| A11 Groups | Partly | `TplUserGroups` | `/api/user-groups` | Drawer, "Profile #" |
| A12 Roles | Partly | `TplRoles`, `TplRoleRecord` | `/api/roles` | Tabs, save bar, the area grid |
| A13 Units | Partly | `TplOrgUnits` | `/api/ous` | Drawer tabs, permission checks on drawer actions |
| A14 Delegations | Partly | `TplDelegations` | `/api/delegations` | "Role #", confirm tier |
| A15 Relations | Partly | `TplRelations` | `/api/persons` | Server search, confirm on remove |
| A16, A48 Registrations, gating | Partly | `TplApprovalGating` | Registrations, reset and recovery routes | Dates, tiers 2 and 3 |

Server: S2, S3.

### A2 Forms

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A34 Builder and responses | Block tree | `TplFormsManage`, `TplFormBuilder`, `TplFormBuilderAr`, `TplFormResponses` | Forms, fields, submissions | New React builder (Q5) |
| A34 Catalogue, submissions | Descriptor tables | `TplFormsCatalog`, `TplMySubmissions` | `/api/me/form-submissions` | Drafts, where each one is |
| A34 Filling, public | Partly | `TplFormFill`, `TplFormPublic`, `TplFormPublicStates` | Public form routes | No translations at all (`forms/[id]`, `f/[slug]`), native selects, brand, Stepper |

Server: S4.

### A3 Documents and routing

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A30 Library, record | Library mostly, record partly | `TplLibrary`, `TplDocRecord` | Document library, routing, QR | Four tabs, header actions, "Account #" |
| A30 Notes and wiki | Partly | `TplNotesWiki` | `/api/document-sources` | The list, New wiki page as main |
| A31 Templates | Mostly | `TplDocTemplates` | Document sources | Wording, start from a starter |
| A32 Routes | Partly | `TplRouteTemplates`, `TplFlowEditor`, `TplDocRouting`, `TplDocRoutingAr` | Route templates, routing | Sentence, rename, leave guard, top-down only, redirect `document-routing` to the Routing tab |
| A33 Designer | Editor through M5 | `TplDocDesigner` | Document sources | Compare only. The route is `/admin/document-sources/[id]` |

Server: S5.

### A4 AI and agents

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A20 Agents, connected apps | Partly | `TplAgents`, `TplAgentPresets`, `TplConnectedApps`, `TplConnectWizard` | `/api/agents`, `/api/me/agents`, `/api/agent-presets`, AI principals | Tabs, gallery, raw columns, naming |
| A21 Agent page | Forms only | `TplAgentRecord`, `TplAgentRecordAr`, `TplAgentInstructions`, `TplAgentScope` | Agent CRUD, grants | Record page with four tabs |
| A22 What AI can do | Technical view | `TplWhatAICanDo` | `/api/v1/admin/mcp/tools` | The whole plain-word view |
| A23 Activity | Only inside Approvals | `AgentActivity`, `Timeline`, `UndoBar` | `/api/me/agent-actions` | The page |

Server: S6.

### A5 Plugin store

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A41 Store, plugin, install, installed | Partly | `TplPluginStore`, `TplPluginDetail`, `TplPluginInstall`, `TplPluginsInstalled`, `PluginCard` | Upload, enable, uninstall, install from store | Plugin page, install dialog, one sidebar item, operator menu |

Server: S7.

### A6 Workspace, billing and settings

| Screen | Now | Cards | Existing API | Main gaps |
| --- | --- | --- | --- | --- |
| A40 Tenants | Partly | `TplTenants` | `/api/tenants` with `q` | Use server search, typed delete |
| A42, A43 Billing, promotions | Partly | `TplBilling`, `TplBillingAr`, `TplPlans`, `TplPromotions` | Plans, prices, invoices, promotions | Normal-unit money, JOD default, tier 1 retire |
| A44 Audit, errors, System | Partly | `TplAuditLog`, `TplErrors`, `TplSystem` | `/api/audit-logs`, errors, `/api/admin/stats` | Sentences, technical details, palette classes |
| A45, A46, A47 | Partly | `TplSettingsTranslations`, `TplPlatformSettings`, `TplSettingsTags`, `TplNotificationDelivery` | Existing | Native selects, one Tags page |
| A50-A53 Settings | Partly | `TplSettingsGeneral`, `TplAdminBranding`, `TplSettingsEmailDomains`, `TplSettingsSecurity`, `TplSettingsSSO`, `TplPlatformSettings` | Existing | Save bar, native selects, tiers, "User #" |
| U01-U03 Sign-in, invitation | Mostly | `TplAuthSignIn`, `TplAuthTwoStep`, `TplAuthReset`, `TplAuthInvite` | Existing | `OtpInput`, password checklist, SSO first |
| U04 Account | Stacked cards | `TplAccountPrefs`, `TplAccountSecurity`, `TplLicensing` | Profile, sessions, S1 | Tabs, the Preferences tab |
| U05, U06 Status, errors | U05 mostly, U06 missing | `TplSystemStates` | `/status` | `not-found`, `error`, `global-error` |
| R01-R13 Starters | Seeded | `TplDoc*` | Starters | Compare only; both amounts in words need S8 |

Server: S8. P01 Front Office is a separate product, and nothing here builds it.

## Server work that's really missing

| # | For | Work |
| --- | --- | --- |
| S1 | A0 | Per-person preferences: text size, Show technical details. Migration |
| S2 | A1 | One merged waiting list (`/api/me/inbox` requires `source` by decision); home cards in the plugin manifest (SDK minor); a member-visible Dashboard item; `/admin` to `/dashboard`; System item for the system tenant only |
| S3 | A1 | Users: last active, two-step state, display name, role and unit filters, bulk role change, per-user require two-step. Roles: people count, built-in flag, permission-to-area map. Groups: row count, Used in. Units: member count. Delegations: expiry (migration), names, un-revoke. Inbox: sender. Registrations and gating: paging, reason, an any-of nav gate |
| S4 | A2 | Server form drafts (migration), responses summary, withdraw, Save the answer to, translatable form text (check the description column) |
| S5 | A3 | Names for people in one batch, so "Account #" can go when the viewer lacks `users:read`; route usage count; withdraw a document |
| S6 | A4 | Agent paused state (migration); counts and last active per agent; admin activity with a filter by agent; plain name, area and undo window per tool; what an agent may read; preset summaries |
| S7 | A5 | `plugins:install` split from `plugins:upload` (grant by capability); 30-day keep on uninstall; trust, price and usage data; update approval; active on install; one nav item. Per-workspace install if Q4 says so |
| S8 | A6 | Audit: names, sentences, agent filter, CSV. Tenants: plan and status. Remind for two-step. Plan change. Platform nav gates by system tenant. Email domains on a permission. 500 reference and maintenance signal. Inviter and role on an invitation. Amount in words in both languages |

## Questions for the owner

The two registers merged with no conflict: no row was changed on both sides. The real conflicts are between 2.9 and decisions recorded elsewhere in the repo, or between 2.9's own files. Each has a default that holds until the owner says otherwise. None blocks A0.

| # | Conflict | Default |
| --- | --- | --- |
| Q1 | **Settings.** D1 (31 Oct, app map) keeps Email, Storage, Languages and Feature flags in the settings area. W.1 and W.2 (2 Nov) move them to Platform and add Devices. Section 21 also calls a group "Workspace" | W.1 and W.2: they're newer, and those pages are already gated to the system tenant |
| Q2 | **Verified plugins.** M0 Q8 keeps native rendering to first-party blocks, with every third-party plugin in the frame. E.17, S.4 and S.12 say Verified runs natively and is active on install | Q8. Verified means the publisher is checked; the plugin still runs in the frame |
| Q3 | **What an agent is.** Section 24 and its cards say an agent works for one person, and anyone can create one with everything on Ask first. The decided plan (`AGENT-PERSONAS-AND-WHITY-AGENT.md`) has workspace agents under `agents:manage`, per-person assignments, and reads that can start Allowed | The built model. "Works for" reads from assignments, Everyone's agents lists the workspace's agents, the register's AI.1-AI.4 are unaffected |
| Q4 | **Where a plugin installs.** S.1 installs into one workspace. The plugin lifecycle looks install-wide (`PluginsApiHandler`) | Keep the lifecycle, build the pages. Check this first in S7 |
| Q5 | **The form builder.** F.1 draws a canvas. Forms are descriptors so desktop and mobile share them | A web-only React builder; filling in keeps working on every platform |
| Q6 | **Routes.** The app map has agents under `/admin/ai-principals` and the designer at `/admin/documents` | The built `/admin/agents`, `/admin/my-agents` and `/admin/document-sources/[id]` |
| Q7 | **Approval between milestones.** `PLAN.md` and the snippet say to stop. The owner's standing rule is merge on green, with only a release needing approval | The standing rule. The text is left as the design team wrote it until the owner asks to change it |
| Q8 | **Larger text.** Marked open to change at 17px, every size one step up | Build it as drawn |

Still not designed, on top of `HANDOFF.md`'s list: the user menu (WHIT-827), Decided and Edit in Approvals (WHIT-828), the impact plan (WHIT-829), issuing an agent credential (WHIT-830) and the theme picker (WHIT-785). The cards cover WHIT-826, most of WHIT-821 and the store revamp (WHIT-831). The Arabic cards' dark screenshots are the same files as the light ones in the package, so they aren't a target.

---

# M0 notes (29 Oct 2026)

Kept as written, as the record of the editor's server proposal and the owner's answers. Much has been built since, so where it describes the code as it was, the sections above win: for example, TipTap is installed, the old designer is gone, and proposals, comments and suggestions exist.

## 2. Mapping check: where each part lands

### Packages

The design says "TipTap inside `packages/ui`". In this repository the split is: `packages/ui` holds presentational components with no data fetching, `packages/features` holds feature screens that take an adapter, and `web/` wires adapters to the API. The editor follows that split:

| Part | Lands in |
| --- | --- |
| Contract (`whity-document.ts`) | `packages/ui/src/editor/contract.ts`, copied unchanged. A drift test compares it with `docs/design/handoff/editor/whity-document.ts`. PHP gets matching value objects in `src/Core/Document/Editor/` and a JSON Schema generated from the contract for server-side validation. |
| TipTap schema, nodes, marks, input rules, suggestion filter, pagination decoration | `packages/ui/src/editor/` (`extensions/`, `nodes/`, `marks/`) |
| `DocEditor` and its shell (header, toolbar, outline, canvas, rail, status bar) | `packages/ui/src/editor/` as components that take `DocEditorProps` |
| Document designer screen (A33), notes and wiki screens | `packages/features/src/document-editor/`, with a `DocumentEditorAdapter` like today's `document-designer/adapter.ts` |
| Routes | `web/app/(editor)/admin/documents/...`, which today renders the old designer behind `ScreenTooSmall` (blocked below 1024px) |
| Print renderer (read-only blocks to HTML) | `packages/ui/src/editor/print/`, imported by both the page view and `render-service/harness`, as the current designer does (ADR 0012) |
| Strings | `t('editor.…', 'English')`, with Arabic in `database/i18n/ar/editor.json`. `DocEditorProps.labels` is filled from `t()` by the feature layer. |

### `handoff/editor/README.md` row by row

| Design part | Fits? | Note |
| --- | --- | --- |
| Blocks (E.13) as TipTap nodes, flat lists | Yes | New. Doesn't reuse the current flow blocks (`packages/ui/src/documents/flow.ts`), whose set differs (figure, spacer, facts, question). See Q1. |
| Marks, `bold` at 600 | Yes | The 700 token and the `font-bold` uses go in M1. |
| Merge fields (`field` node) | **Partly** | Today's fields are `{{placeholder}}` strings filled from flat string maps (`VariableData.php`). There is **no field catalogue per record type** and no core "invoice" record type: records come from plugins, data types and forms. See Q6. |
| Block chrome, / menu, +, Markdown shortcuts, bubble | Yes | Client only. |
| Suggesting mode (transaction filter) | Yes, with risk | Client only, but the hardest piece of client logic (see Risks). TipTap's paid Pro extensions aren't used; this is built in-house. |
| Comments (mark, thread store, rail) | Yes | Needs server tables and an update channel. |
| Agents (`onAskAgent` streams `AgentResult`) | **No, not yet** | MCP has no streaming tool results, and **nothing in Whity runs an agent**: agents are external MCP clients. Someone has to decide where "Ask an agent" goes when a person clicks it (Q3). Also needs items 10 and 11. |
| Page view (pagination decoration) | Yes | `render-service/src/flow/assets/paginate.js` is deterministic browser code, written so an editor can run it (`docs/wiki/Document-Render-Service.md`). Reusing it keeps the editor's breaks close to the PDF's. |
| Plugin blocks (native or sandboxed iframe) | **Partly** | Plugins are PHP classes with no JSON manifest and no way to ship JavaScript into the web app. Native rendering is possible only for blocks compiled into `packages/` (first party). Native rendering for third-party "verified" plugins needs a module-loading mechanism that doesn't exist (Q8). The iframe `embed` screen exists, but nothing enforces a sandbox. No "verified" flag exists. Plugin enablement is instance-wide (a `.disabled` file), with per-tenant access through entitlements. |
| Saved blocks | **Partly** | `document_blocks` (059) already holds reusable blocks, with four scopes (personal, tenant, global, system), usage lookup and a 409 on deleting a block in use. This is close to saved blocks, but has no versions, no synced or copy mode, and different sharing (Q2). |
| Collaboration (Yjs, later) | Later | No realtime of any kind today (no Mercure, websockets or SSE streams). |

### What doesn't fit the code as it is

1. **The existing designer's canvas mode has no counterpart in the design.** It places elements absolutely (text, image, barcode, QR, shapes, maths), and the starters and the CSV batch print depend on it. E.13 has no barcode, QR, shapes or maths blocks (Q1).
2. **Names.** The `documents` table already means *issued* documents (PDF artifacts, routing, QR). Permission slugs are plural (`documents:read`, `documents:write`, `documents:publish`, `documents:render`), while the contract's example is `document:edit` (Q5).
3. **Concurrency.** Writes are last-write-wins, with no version columns and no `If-Match`. The editor autosaves, and agents add suggestions to documents people have open, so optimistic concurrency is required from M2.
4. **Audit and actor.** Template and block writes write no audit row and no event. MCP audit rows don't record `principal_kind`, though ADR 0006 §6 says they should, and nothing records "on behalf of". The contract's `Actor` (`agent` with `onBehalfOf` and `scope`) can't be filled from today's data.
5. **Files.** No generic upload API or files table, so Image and File blocks need one.
6. **Live values.** No shared query layer for Record and Chart blocks. The nearest things are `ReportSourceRegistry` (tenant-scoped, permission-gated row sources) and `RecordSectionResolver`. Charts can take `source.query` as a report-source id. Records need a resolver per record type (Q6).
7. **Small screens.** The design keeps the editor usable under 1024px (a rail drawer, a scrolling toolbar). The current screen is blocked there by `ScreenTooSmall`, which goes for the editor.
8. **Repository conventions.** Branches are `type/WC-XX-…` and commits `WC-XX: …` (CONTRIBUTING). This branch is `design/editor-m0` as asked, and commits are prefixed "Editor M0:" for want of a ticket. CONTRIBUTING also forbids AI attribution in commits and PRs, so there is none. `docs/*` is git-ignored apart from `wiki/` and `adr/`, so this branch adds `!docs/design/` to `.gitignore`.
9. **Migrations are consolidated.** No production database exists, and the house rule is to edit the migration that creates a table rather than layer patches. Changes to `document_templates` and `document_blocks` therefore edit 059 (plus 075 and 117), and new tables get new numbers from 160.

## 3. Server proposal

The design leaves all of this open. These are proposals for the owner to approve or change; nothing here is built. They follow the house rules: every table carries `tenant_id` and is registered in `src/Core/Tenant/CoreTables.php`, every query has a literal tenant predicate, every new table gets a case in `CrossTenantRejectionRealEngineTest`, and every new permission ships with its grant migration.

### 3.1 Tables

Names assume the answers recommended in Q1 and Q5: one table for every written document, beside the existing `documents` table for issued ones.

| Table | Milestone | Columns (beyond `id`, `tenant_id`, timestamps) |
| --- | --- | --- |
| `document_sources` (one row per template, letter, report, note or wiki page; replaces `document_templates`) | M2 | `kind` (template · letter · report · note · wiki), `title`, `lang`, `default_view`, `full_width`, `page` JSONB, `record_type`, `owner_profile_id`, `owner_ou_id`, `scope`, `audience` (as today), `draft` JSONB (`blocks` only, no comments or suggestions), **`draft_version` int** (optimistic lock, bumped on every save), `published_version` int null, `updated_by` actor columns |
| `document_source_versions` | M4 | `source_id`, `version`, `blocks` JSONB, `saved_block_pins` JSONB (saved block id → version at publish, E.38), `published_by_profile_id`, `published_at`. Insert-only. |
| `document_suggestions` | M5 | `source_id`, `kind` (text · format · block), `summary`, `request`, `permission`, `routine_id` null, **actor columns**, `base_version`, `patch` JSONB (see 3.3), `status` (open · accepted · rejected · stale), `decided_by_profile_id`, `decided_at` |
| `document_comment_threads` | M5 | `source_id`, `anchor_block_id`, `quote`, `resolved_by_profile_id`, `resolved_at` |
| `document_comments` | M5 | `thread_id`, actor columns, `body` JSONB (`Inline[]`), `mentions` JSONB, `edited_at` |
| `saved_blocks` (evolves `document_blocks`) | M7 | `name`, `description`, `mode` (synced · copy), `sharing`, `owner_profile_id`, `blocks` JSONB, `per_document_keys` JSONB, `version` |
| `saved_block_versions` | M7 | `saved_block_id`, `version`, `blocks` JSONB. Lets a published template keep printing the version it was published with. |
| `saved_block_uses` | M7 | `saved_block_id`, `source_id`, `version`. Backs `usedIn`, the "used in 12 documents" label and the 409 on delete, replacing `BlockReferenceScanner`'s scan. |
| `document_files` | M3 | `source_id` null, `storage_key`, `name`, `mime`, `size`, `checksum_sha256`, `uploaded_by_profile_id`. Stored through `TenantRoutingStorageDriver`, served through the owning document's visibility check. |
| `agent_principals` | M6 (item 11) | `name` (by role, 5.6b), `created_by_profile_id`, `status`. A token's principal can then be an agent acting for a person. |
| `agent_permission_modes` | M6 (item 11) | `agent_principal_id`, `granted_by_profile_id`, `permission`, `mode` (off · ask · allow). Effective rights = person's ∩ the agent's allowed and ask permissions. Changes audited. |
| `agent_proposals` | M6 (item 10) | `agent_principal_id`, `on_behalf_of_profile_id`, `tool`, `arguments` JSONB, `diff` JSONB, `scope` JSONB, `undo_window_seconds`, `status` (waiting · approved · declined · expired · undone), decision columns |
| `agent_routines` | M6 (E.32) | `name`, `set_up_by_profile_id`, `agent_principal_id`, `schedule`, `request`, `target` JSONB. Run through the existing `jobs` queue. |

**Actor columns** are used wherever the contract has an `Actor`: `actor_kind` (person · agent · system), `actor_profile_id`, `agent_principal_id` null and `on_behalf_of_profile_id` null. The same four columns are added to `audit_log` and `domain_events`, so that "Billing assistant for Rana" can be reconstructed everywhere, and MCP audit rows start recording `principal_kind` as ADR 0006 §6 already requires.

**The block registry is not a table.** Definitions come from code: Whity's own from `packages/ui`, and plugins' from a new `PluginDocumentBlocksInterface` read at plugin load. Whether a plugin's block is usable for a tenant is answered by the plugin's state plus entitlements, both of which already exist. A persisted registry adds nothing but drift.

### 3.2 API routes

All routes live under `/v1`, are declared in `CoreApiSchemas` with `public/openapi.json` regenerated, and return `{"data": …}` or `{"error": …}` as today.

| Route | Permission | Notes |
| --- | --- | --- |
| `GET /api/document-sources`, `POST /api/document-sources` | `documents:read`, `documents:write` | List (paginated, unlike today) and create |
| `GET /api/document-sources/{id}` | `documents:read` | The `WhityDocument` projection: draft blocks plus open comments and suggestions, with `ETag: "<draft_version>"` |
| `PUT /api/document-sources/{id}/draft` | `documents:write` | Requires `If-Match`. On a mismatch, the server tries a block-level three-way merge (blocks are keyed by stable ids) and returns 409 with the current version only if the same block changed on both sides. Refused for agent principals: agents never write a draft (E.4). |
| `GET /api/document-sources/{id}/publish-check` | `documents:publish` | Returns `PublishBlocker[]` (E.37) |
| `POST /api/document-sources/{id}/publish` | `documents:publish` | Runs the check again (409 with blockers), writes a version and pins saved blocks |
| `GET /api/document-sources/{id}/versions[/{v}]` | `documents:read` | History of published versions |
| `GET /api/document-sources/{id}/preview?record=type:id` | `documents:read` + the record's own read | Field values for "Preview with a record" (E.12), with each missing value flagged (E.27) |
| `POST /api/document-sources/{id}/render` | `documents:render` | PDF of the published version, or of the draft for a preview. Persists through `DocumentIssuer` as today (see 3.6). |
| `GET/POST /api/document-sources/{id}/suggestions` | read / `documents:suggest` | Create from a patch. People in Suggesting mode use this too. |
| `POST /api/document-sources/{id}/suggestions/{sid}/decision` | `documents:write` | Accept or reject. Applies the patch to the draft on accept, and audits the decision with the actor (E.29). |
| `POST /api/document-sources/{id}/suggestions/decisions` | `documents:write` | "All" or "all from one agent" in the rail menu |
| `GET/POST /api/document-sources/{id}/comments`, `POST …/comments/{tid}/messages`, `POST …/comments/{tid}/resolve`, `…/reopen` | read / `documents:comment` | Threads, replies (with notifications to @mentioned people) and resolve |
| `GET /api/document-sources/{id}/changes?since=<cursor>` | `documents:read` | What changed since the cursor: suggestions, comments, draft version. Polled every 10 s while open in v1, later replaced by an SSE stream (Mercure in FrankenPHP) without changing the payload. |
| `POST /api/document-sources/{id}/files` | `documents:write` | Image and file upload, 1 MiB body limit raised for this route only |
| `GET /api/document-fields?recordType=` | `documents:read` | The field picker's groups, from a `DocumentFieldProvider` per record type (core: Company, Today; plugins add theirs) |
| `GET /api/document-block-definitions` | `documents:read` | Whity's and the tenant's enabled plugins' `BlockDefinition`s, plus `MissingPluginState` for plugins in use but off |
| `GET/POST/PATCH/DELETE /api/saved-blocks[/{id}]`, `GET /api/saved-blocks/{id}/usage` | `documents:read` / `documents:write`; workspace library: `documents:library:manage` | Replaces `/api/document-blocks` |
| `POST /api/document-sources/{id}/agent-requests`, `GET /api/agent-requests/{rid}` | `documents:read` + agent use | Ask an agent (depends on Q3). Returns a run id. The run's status (`AgentRunStatus`) and its suggestions arrive through `changes`. |
| `GET/PUT /api/agents/{id}/permission-modes`, `GET /api/agent-proposals`, `POST /api/agent-proposals/{id}/approve`, `…/decline`, `…/undo` | owner permissions | Items 10 and 11. The approvals queue (7.5b) reads `agent-proposals`. |

### 3.3 Suggestion patches

A suggestion is stored as its own row with a patch anchored to block ids, never as a hidden edit in the draft. The editor renders open suggestions as marks and dashed blocks, which is what the contract's `insertion` / `deletion` marks and `suggestion` attribute describe. Accepting one applies the patch to the draft on the server in one transaction. This is what makes "no code path lets an agent change a document without a suggestion" testable on the server, not just in the client. Patch shapes:

- `insert-blocks`: `{ afterBlockId, blocks: Block[] }`
- `replace-text`: `{ blockId, deleteText, insert: Inline[], occurrence }`. Matched by text, not offset, so it survives edits elsewhere in the block, and marked `stale` when the text has gone.
- `format`: `{ blockId, range, mark, add }`
- `delete-blocks`: `{ blockIds }`
- `move-block`: `{ blockId, afterBlockId }`

### 3.4 MCP tools for agents

These are hand-authored tools (`AuthoredToolRegistry`) rather than derived ones, so each can carry its rule. Every writing tool creates a suggestion, a comment or a proposal, never a draft write. The route-derived tools that write drafts (`PUT …/draft`, `publish`, `decision`) refuse agent principals.

| Tool | Does | Permission |
| --- | --- | --- |
| `document_read` | Returns the `WhityDocument` (draft when the request came from inside the editor, published otherwise; see Q4) and the records its fields and blocks point to, filtered by the agent's and the person's permissions (E.31) | `documents:read` |
| `document_list_fields` | The fields available for the document's record type | `documents:read` |
| `document_suggest_blocks` | Insert blocks after a block, as a suggestion. Plugin blocks only within the plugin's scopes and the agent's. | `documents:suggest` |
| `document_suggest_rewrite` | Replace text in a block, as a suggestion (`replace-text` patch) | `documents:suggest` |
| `document_comment_reply` | Reply in a thread (E.30), optionally with suggestion ids it made | `documents:comment` |
| `document_comment` | Start a thread, for example an agent's review comment with "Suggest the fix" | `documents:comment` |

Each suggestion records the agent, the person, the permission used and the routine if any, and goes to the audit log. If the agent's mode for `documents:suggest` is "Ask first", the suggestion itself is the thing a person decides on: a suggestion already waits for a person, so no proposal is wrapped around it (Q9).

### 3.5 Permissions

The existing plural slugs are reused, and three are added. Each new slug ships with a grant migration to whoever already holds the related capability, as `ci-permission-holder-guard.php` requires.

| Slug | Meaning | Status |
| --- | --- | --- |
| `documents:read` | Open, read, preview | exists |
| `documents:write` | Edit drafts, accept and reject suggestions (E.29), upload files | exists |
| `documents:publish` | Publish a template (E.9), share at workspace level | exists |
| `documents:render` | Make a PDF | exists |
| `documents:suggest` | Suggest changes (E.10; agents' main permission) | **new**; granted to holders of `documents:read` (see Q7) |
| `documents:comment` | Comment and reply | **new**; granted to holders of `documents:read` |
| `documents:library:manage` | Manage the workspace saved-block library (E.22) | **new**; granted to holders of `documents:publish` |

The editor's mode follows from these: write → Editing, suggest → Suggesting, read only → Viewing. `Suggestion.permission` then reads `documents:suggest` on agents' cards.

### 3.6 Plugin blocks and the sandbox protocol

- **PHP side.** A new SDK interface, `PluginDocumentBlocksInterface::getDocumentBlocks(): array`, returns `BlockDefinition`s. The plugin's `data` JSON Schema is validated on the server on every save, not only in the editor, and so are its `scopes`, which must be permissions the plugin owns or declares. `print` names a print view the plugin implements in PHP (see 3.7).
- **Native rendering.** First-party blocks are compiled into `packages/`. Third-party plugins use the frame until Q8 is answered.
- **The frame.** An `<iframe sandbox="allow-scripts">` with no `allow-same-origin`, so the frame has an opaque origin and can't reach cookies or storage. It's loaded from the plugin's own GET route, which is served with a strict CSP (`default-src 'self'; connect-src 'none'; frame-ancestors <app origin>`), so all data goes through the host. The host sizes it (`minHeight`, then whatever height the frame reports, capped).
- **Messages.** The host sends `init` with a `MessagePort` (`MessageChannel`), and every later message goes over that port, never `window.postMessage(*)`. Every message is `{ v: 1, id, type, ... }`.

| Direction | `type` | Payload |
| --- | --- | --- |
| host → frame | `init` | `blockId`, `blockType`, `schemaVersion`, `data`, `mode` (editing · suggesting · viewing · print-preview), `readOnly`, `theme`, `dir`, `lang`, `labels`, the token CSS text (the "same UI kit") |
| host → frame | `data-changed`, `env-changed` | Data accepted elsewhere (undo, a suggestion accepted); theme, direction or mode changed |
| host → frame | `toolbar-action` | `{ actionId }` from the block toolbar the definition declared |
| host → frame | `response` | `{ replyTo, ok, result \| error }` |
| frame → host | `ready` | none |
| frame → host | `resize` | `{ height }` |
| frame → host | `set-data` | `{ data }`. The host validates it against the schema. In Suggesting mode, or for an agent, it becomes a suggestion. |
| frame → host | `read` | `{ path, params }`. The host calls the plugin's API with the viewer's session, allowed only for routes gated by the block's `scopes`, so the effective right is the viewer's permissions ∩ the block's scopes. |
| frame → host | `error` | `{ message }`. Shown with the plugin's name, without taking the editor down. |

The host ignores anything that doesn't come over the port, drops unknown types, and rate-limits `set-data` and `read`. A frame gets no navigation, popups, top-level access or downloads.

### 3.7 The print path through Whity Render

1. **Publish** freezes the blocks and the saved-block versions (`document_source_versions`).
2. **Render** (`POST …/render`) resolves on the server, within the requester's permissions:
   - field values (E.27: an optional field is left blank, and a missing required or fiscal field returns 422 listing the fields);
   - Record and Chart blocks, as values at that moment (E.16);
   - synced saved blocks, at their pinned version with `overrides`;
   - plugin blocks, through the plugin's PHP print view, which returns blocks from a closed print set (the existing flow set: heading, paragraph, table, list, facts, qr, divider, figure), never HTML or code. That keeps third-party code out of the renderer. See Q10, since the contract calls this a "Whity Render template id".
3. **Payload.** The result is a print-ready `WhityDocument`: no comments, no suggestions, no field nodes, only values. It goes to a new `POST /render/document` on render-service, using the same `X-Render-Secret`, synchronous with a 30 s timeout, and returning PDF bytes plus the page-count headers.
4. **Render-service.** The harness imports `packages/ui/src/editor/print/`, the same components the page view uses (ADR 0012), and paginates with the existing `paginate.js`. The stage 6 `Doc` rules (letterhead, running header, "Page x of y", continuation pages, amount in words) are built there as part of item 12.
5. **Storage.** `DocumentIssuer` stores the artifact, and `documents.variable_data` keeps the frozen values, so a re-render can show what was printed.

## 4. Dependencies, risks and estimates

### Dependencies

- **PR #1407 (scope rename) should merge before M1.** It touches 358 files, the same ones M1 restyles.
- **M1 blocks everything visual.** The editor reuses its components.
- **Server work rides with the milestone that needs it**, since PLAN.md has no server milestone:
  - M2: `document_sources` with optimistic concurrency.
  - M3: files.
  - M4: versions, publish check, render.
  - M5: suggestions, comments, the changes feed and the two new permissions.
  - M6: items 10 and 11, agent principals, routines.
  - M7: registry, saved blocks, sandbox.
- **M6 needs Q3 answered.** Nothing can be built for "Ask an agent" until someone decides where the agent runs.
- **Item 12 (the `Doc` rules and templates) is a large piece of work of its own.** M4 needs at least the page rules. The 13 templates and JoFotara can follow, and JoFotara needs the accountant check the register lists.

### Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Suggesting mode as a ProseMirror transaction filter: paste, split and join, lists, tables and IME input all produce odd steps | High: it's E.10 and E.4's whole promise | Server-side patches (3.3) as the record of truth; a property-based test suite on the filter; scope v1 suggestions to text, format and whole blocks |
| Page view pagination drifts from the PDF | Medium | Reuse `paginate.js` in both; a test that compares page counts for the 13 templates |
| Replacing the current designer loses features people use (canvas, barcode, maths, CSV batch) | High | Q1 before M2; migrate starters and saved templates with a script and tests |
| M1 restyles the whole app, not just the editor (button heights, 14px, cards) | Medium: every screen changes at once | Screenshot harness first; review M1 screen by screen |
| No realtime: agent suggestions and comments arrive while someone types | Medium | Patches anchored to blocks, a three-way merge on save, polling at 10 s; SSE later |
| Undefined agent runtime | High for M6 | Q3 |
| A third-party plugin frame escaping its sandbox | High | No `allow-same-origin`, `MessagePort` only, strict CSP, a Playwright test that the frame can't read the parent, cookies or storage (M7's done-when) |
| Bidirectional text in ProseMirror (per-block `dir="auto"`, `bdi` around Latin) | Medium | M8, but design the node views with `dir` from M2 |
| TipTap major version (README says 2; 3 is current) | Low | Q11 |
| Bundle size (TipTap plus the editor) on every admin page | Low | Load the editor route on its own |

### Estimates

In engineer-weeks for one experienced full-stack engineer, including tests and the visual check, but not review time:

| Milestone | Client | Server | Total |
| --- | --- | --- | --- |
| M1 Foundation (tokens v3, fonts, icons, 10 components, screenshot harness) | 4–5 | 0 | **4–5** |
| M2 Editor core | 4–5 | 1 | **5–6** |
| M3 Rich blocks (incl. live Record and Chart, files, and Barcode, QR and Maths per Q1) | 4–5 | 1–3 | **5–8** |
| M4 Templates (fields, page view, publish, Render path, A33, label sheets and CSV batch, moving existing templates per Q1) | 4–5 | 4–5 (incl. the `Doc` page rules) | **8–10** |
| M5 Comments and suggestions | 3–4 | 2 | **5–6** |
| M6 Agents (incl. items 10 and 11) | 2 | 4–5 | **6–7**, once Q3 is settled |
| M7 Plugin and saved blocks | 2–3 | 2–3 | **4–6** |
| M8 Arabic, type, accessibility | 2–3 | 0 | **2–3** |
| **All** | | | **39–51** |

The 13 templates, amount in words and JoFotara (the rest of item 12) aren't in these numbers: add 4–6 weeks.

## 5. What M1 would change

- `src/design/tokens/base.json` → tokens v3 from `docs/design/tokens.json`. Then regenerate `web/app/globals.css`, `packages/tokens/generated/*` and `flutter/whity_tokens`. Import `docs/design/handoff/web/theme.css` after them, adapted into the generator so the drift check still holds.
- **Fonts:** self-host Noto Sans and Noto Sans Arabic 400/500/600, Geist Mono 400/500 and Readex Pro 600 (Latin and Arabic) in `packages/tokens/fonts`. Drop the 700 files and Geist Mono 600, which the handoff's `fonts/` still ships but the register no longer declares. Remove the `--font-weight-bold: 700` token, and change `font-bold` to `font-semibold` throughout (95 uses). Set the 14px default on `body`.
- **Icons:** the 116 icons with their Tabler names from `handoff/icons/icons.json`, an `Icon` component at stroke 2.25 / 16px, and the robot face for agents.
- **Restyle and new components in `packages/ui`:** Button (36 / 28 / 40, ¼ corners, outline secondary, the plane on the main button), IconButton with Tooltip, Menu (`dropdown-menu`), Popover, ToggleGroup (new), Dialog, Avatar (new), ActorChip (new), Badge, AgentRunStatus (new). No palette classes or hex values left in them.
- **A screenshot harness:** Playwright over Storybook, rendering each component's story at its card's size from `cards.html` in light, dark and Arabic, compared against `docs/design/screenshots/`. That makes the per-milestone visual check repeatable.
- **Not in M1:** the rest of the palette-class clean-up outside those components, data table, app shell and cards. They're section 12 items, but the editor doesn't need them. They'd be a separate foundation branch if wanted.

## Owner's answers (29 Oct 2026)

| Q | Answer | What it changes |
| --- | --- | --- |
| 1 | **Replace both modes.** The new editor replaces the flow mode and the canvas mode. Barcode, QR code and Maths become blocks, and labels and certificates become a label sheet layout with CSV batch printing. | Register additions E.40–E.43 below. M3 gains three blocks. M4 gains the label sheet kind, CSV batch and a migration of existing templates and starters into the new model. `ScreenTooSmall` and the old designer are deleted once nothing uses them (no-legacy rule). |
| 2 | **Evolve `document_blocks`** into `saved_blocks`. | Edit migration 059 (plus 075 and 117). `global` and `system` stay for blocks Whity ships; the Save dialog offers Only me and Everyone in the workspace. |
| 3 | **No Whity-paid model API for now.** In local mode the request goes to the person's own AI client (Claude Code or similar) connected over MCP. What the hosted website does is undecided. | No built-in agent runtime and no model-provider billing. "Ask an agent" writes an agent request to a queue. The person's MCP client picks it up with `document_agent_requests_next`, works with the tools in 3.4, and reports progress with `document_agent_request_update`, which feeds `AgentRunStatus` through the changes feed. The picker lists only agents connected for that person. When none is connected, the sheet says how to connect one rather than failing. The hosted website's behaviour stays open and doesn't block M6. ("Local mode" isn't yet a named concept in the code; it means a Whity the person's own AI client can reach over MCP.) |
| 4 | **Draft inside, published outside.** | As proposed: `document_read` returns the draft for requests started in the editor and the published version everywhere else. |
| 5 | **Plural `documents:*` slugs and a `document_sources` table.** | As proposed in 3.1 and 3.5. |
| 6 | **Field providers.** | `DocumentFieldProvider` per record type, registered by core (Company, Today) and plugins; Record blocks resolve through it. The contract carries the internal id and shows the display number. |
| 7 | **Separate slugs, granted to readers.** | As proposed in 3.5. |
| 8 | **Native rendering for first-party blocks only.** | Every third-party plugin uses the sandboxed frame, verified or not, until a signed-module mechanism exists. |
| 9 | **The suggestion itself is the approval.** | No proposal is wrapped around an agent's suggestion. |
| 10 | **PHP print view.** | As proposed in 3.7. `print.renderer` in the contract needs a register-approved rename or reinterpretation before M7. |
| 11 | **TipTap 3.** | `handoff/editor/README.md` says TipTap 2; the build uses 3. |
| 12 | **Fix the cross-references** in this PR. | Done in a separate commit (see below). The register's values were already right, so it isn't changed. |
| 13 | **The text is enough** for the 1024 and 768px layouts. | Checked against the card READMEs and section 18, not screenshots. |
| 14 | **CONTRIBUTING naming** (`feature/WC-XX-…`, `WC-XX:`) once there's a ticket; until then `design/editor-mN` and "Editor Mn:". | Later milestones use the ticket the owner gives. |

### Cross-reference fixes made (Q12)

- `18-document-editor.md`, `handoff/editor/README.md` and the `whity-document.ts` header: "E.1–E.34" → "E.1–E.39".
- `components/EditorCollab/README.md`: the dashed-means-not-happened rule points at 4.3, not 2.4. `whity-document.ts` (`Actor` comment): attribution points at the stage 7 actor chip, not 2.4.
- `components/EditorAgents/README.md`: the set-down is 5.4, not 2.6.

Section 18's shorter block menu is left as is: the card is the detailed spec, and the build follows the card (Copy link to block included).

### Register additions (Q1), approved 29 Oct 2026

Approved by the owner and added to `00-decision-register.md` as drafted below. Section 18, `handoff/editor/README.md` (TipTap mapping) and the Blocks card (`components/EditorBlocks/README.md`) now list them.

Still open, as the register's rule for later changes asks:

- they have no cards or screenshots yet;
- the guideline PDF hasn't been republished;
- the version (2.2) hasn't been bumped.

| # | Decision | Proposed value |
| --- | --- | --- |
| E.40 | Barcode block | A Whity block: Code 128, EAN-13 or Code 39, with the value typed or a merge field and the text under it optional. Ink only, sized in mm in the page view. Group "Whity" in the / menu. |
| E.41 | QR code block | A Whity block: a value or a merge field as a QR, with an optional caption. Distinct from the document's verification QR in the footer (6.7), which stays automatic. |
| E.42 | Maths block | A block (and an inline `math` node) for formulas in TeX, rendered with KaTeX on screen and in print. Group "Basic". |
| E.43 | Label sheets | A document kind `label`: a sheet (A4, Letter) divided into a fixed grid of labels, each one the same blocks with fields; Print fills one label per record or per row of an uploaded CSV. It opens in the page view and never in the continuous view. Replaces the canvas mode's labels and certificates; a certificate is a one-label sheet. |

Added to `handoff/editor/whity-document.ts`, which type-checks under `strict`:

- `BarcodeBlock`, `QrBlock` and `MathBlock` in `Block`, and an inline `MathRef` in `Inline`;
- `'label'` in `DocumentKind`;
- `WhityDocument.labels: LabelGrid` (columns, rows, label size in mm, gap);
- `LabelPrintSource` (records, or a CSV with a column-to-field map) and `DocEditorProps.onPrintLabels`.

## Questions for the owner

All answered on 29 Oct 2026 (above). Kept here as asked, for the record.

1. **The existing Document designer.** Does the new editor replace it, or sit beside it?
   - It has a canvas mode (absolutely placed text, images, barcodes, QR, shapes, maths), a flow mode, starters and CSV batch printing, none of which are in E.13.
   - Recommended: the new editor replaces the flow mode and becomes A33. Canvas layouts (labels, certificates) stay on the old canvas until a decision on whether they come back as blocks (Barcode, QR, Maths) or as a separate "label" document kind.
2. **Saved blocks and `document_blocks`.** Should saved blocks evolve the existing table? It already has four scopes (personal, tenant, global, system), while E.22 has two (Only me, Everyone in the workspace). Recommended: evolve it. Keep `global` and `system` for blocks Whity ships, and don't offer them in the Save dialog.
3. **Where does an agent run when a person clicks "Ask an agent"?**
   - Today agents are external MCP clients. Whity hosts no model and has no way to push a request to one.
   - Options:
     - (a) Whity calls a model provider itself, as a built-in agent runtime.
     - (b) Requests are queued for a connected agent, which picks them up through an MCP tool (`document_agent_requests_next`) or MCP sampling.
     - (c) Both: built-in for Whity's own assistants, the queue for the tenant's agents.
   - Streaming (`AsyncIterable<AgentResult>`) and `AgentRunStatus` work with any of the three, through the changes feed. M6 can't start without an answer.
4. **Which version do agents read?** E.9 says "Whity Render and agents use the published version". E.31 says an agent asked inside a document reads the document. Proposed reading: inside the editor an agent reads the draft it's suggesting against, and everywhere else (sending an invoice, a routine) it uses the published version. Is that right?
5. **Names.**
   - Permissions: OK to keep the existing plural `documents:*` slugs and add `documents:suggest`, `documents:comment` and `documents:library:manage`, rather than the contract's example `document:edit`?
   - Table: OK to name the new one `document_sources`, given that `documents` already means issued documents?
6. **Fields and records.** Where do record types and their fields come from? There is no core invoice, customer or statement record type; those come from plugins and data types. Proposed: a `DocumentFieldProvider` per record type, registered by core (Company, Today) and by plugins, with Record blocks resolved through the same provider. Also, `RecordRef.id` in the examples is a display number (`INV-1042`). Should the contract carry the internal id and show the number?
7. **Who may suggest and comment?** E.10 gives people without edit permission Suggesting. Is that everyone who can read the document, or only those granted the new `documents:suggest`? Recommended: a separate slug, granted by default to readers, so a tenant can make a read-only audience.
8. **Third-party "verified" plugins rendering natively.** That means loading a plugin's JavaScript into the app, and no mechanism for it exists. Is v1 fine with native rendering for first-party blocks only, and the frame for every third-party plugin, verified or not, until a signed-module mechanism exists? Who sets `verified`, and where is it stored?
9. **Agent modes and suggestions.** If an agent's `documents:suggest` is set to "Ask first", is the suggestion itself the approval, as proposed, or should it first be a proposal to make a suggestion? The second seems like a double approval.
10. **Plugin print views.** The contract says `print.renderer` is "a Whity Render template id". Plugins can't ship code into render-service. Proposed instead: a PHP print view that returns blocks from the closed print set. Acceptable, with the contract field reinterpreted (or renamed through the register)?
11. **TipTap 2 or 3.** `handoff/editor/README.md` suggests TipTap 2. TipTap 3 is the current major and is what would be installed today (MIT, React 19). OK to use 3?
12. **Small cross-reference errors in the design.** Listed so the register can be fixed first if wanted. None changes what gets built.
    - `18-document-editor.md`, `handoff/editor/README.md` and the `whity-document.ts` header say E.1–E.34, but the register goes to E.39.
    - `EditorCollab/README.md` ("the rule from 2.4") and the `Actor` comment in `whity-document.ts` ("2.4, E.4") point the dashed-means-not-happened rule at 2.4 (page ground). The rule is 4.3.
    - `EditorAgents/README.md` calls the set-down "from 2.6". It's 5.4.
    - Section 18's block menu lists Turn into, Comment, Duplicate, Move and Delete, but `EditorBlockChrome/README.md` also has Copy link to block. Built per the card unless told otherwise.
    - `docs/design/fonts/` ships 700 weights and Geist Mono 600, which stage 9 says are no longer declared. M1 won't copy them.
13. **Responsive states have no cards.** Every editor card is 1280px wide, but the design changes layout at 1280, 1024 and 768 (outline closes, rail becomes a drawer, toolbar scrolls). Those can only be checked against the text, not a screenshot. Should cards be added, or is the text enough?
14. **Branch and commit names.** CONTRIBUTING wants `type/WC-XX-…` branches and `WC-XX:` commits. Is there a ticket for the editor, and should later milestones follow that (`feature/WC-XX-editor-m1`), or keep `design/editor-mN`?
