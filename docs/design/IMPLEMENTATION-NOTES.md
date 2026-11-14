# Implementation notes

# Design 3.4 working plan (14 Nov 2026)

The working plan for design system 3.4, checked against `develop` at `1b681fd7` on 14 Nov 2026 (WHIT-1019). This is step 1 of `START-HERE.md`: organise, no feature code. 3.4 adds four milestones to `PLAN.md`: A14 (the web app on a phone), A15 (screens and surfaces with no card before 3.4), P1 (form printouts) and E2 (the ten 3.4 emails). The 3.3 working plan of 12 Nov is kept unchanged [below](#design-33-working-plan-12-nov-2026), and so are the 2.9 plan and the M0 notes under it.

How it was checked: the code was read on `origin/develop` and the merged commits were listed by WHIT id. No screen was compared with its screenshots; each milestone does that.

## What the 3.4 merge did

Unlike 3.3, the 3.4 package was drawn from this folder: the register keeps E.40-E.47, the S.1-S.12 answers and D1, and `PLAN.md` keeps M4d and "merge on green". So it was unzipped over `docs/design/` as `START-HERE.md` says, with no three-way merge. The zip has no deletions against the repo; the team's own files (`IMPLEMENTATION-NOTES.md`, `DESIGN-ASKS.md`, the `AGENT-*`, `DOCUMENT-*` and MCP notes) are untouched.

| Result | Files | Notes |
| --- | --- | --- |
| Added | 252 | 43 new cards (`Pw*`, `DocForm*`, `TplFormPrintStyle`, three `Email*`, `MsgCustomerMessages`, two `Site*`, 24 `Tpl*`) with a light and a dark screenshot each, ten new emails in English and Arabic, `handoff/print/FORM-PRINTOUTS.md`, `ANSWERS-3.4.md`, the 3.4 PDF |
| Changed | 148 | Register (+97 lines, nothing removed: PW, SC, Q34, E.23a, FP, SU), `13-app-map.md` (every row names a card), `PLAN.md` (A14, A15, P1, E2), `CLAUDE-snippet.md` (three rules), `HANDOFF.md`, `HANDOFF-PROMPT.md`, `cards.html`, `handoff/editor/whity-document.ts` (E.23a and `FormPrintoutStyle`), seven icons, the 15 3.3 emails (one line each), and real dark screenshots for the `Email*` and eight Arabic cards (Q10 of 3.3) |
| Removed | 1 | `Whity-Brand-Guidelines-v3.3.pdf`, replaced by 3.4's (byte-for-byte the copy in `D:\Projects\Whity`) |
| Moved | 1 | `START-HERE.md` from the repository root to `docs/design/` |

Outside `docs/design/`:

- `CLAUDE.md` is `CLAUDE-snippet.md` word for word, plus the team's own rule on size tokens (WHIT-846), which no design snippet has carried, under "Team additions" (**Q20**).
- Icons go from 156 to 163 (`folder`, `folder-open`, `grid`, `star`, `keyboard`, `checkbox`, `square`). `packages/ui/src/icon-map.ts` was regenerated with `scripts/generate-icon-map.mjs` and the pinned count in `web/__tests__/design-system-rules.test.ts` set to 163. All seven Tabler names exist in `@tabler/icons-react` 3.49. Nothing uses them yet.

## Is the folder complete

- **Cards:** 317 card folders, each with `README.md`, `index.html`, `preview.html` and exactly two screenshots (light and dark); no screenshot lacks a folder. Every one of the 220 card names in `13-app-map.md` and `PLAN.md` has its folder and both screenshots. **Nothing is missing.** The only names that don't resolve are code names, not cards: `PluginDocumentThemesInterface`, `WhityDocument`, and the `TplSettings*` wildcard (every Settings card). The `Pw*` cards hold their Arabic variants inside the card, as the other Arabic-capable cards do.
- **Counts:** 25 emails in English and Arabic (50 files), 163 icons, 31 Lottie files, 5 sites. `HANDOFF.md` still says 156 icons (**Q21**).

## E.23a: saved blocks' per-document parts by stable key

The saved-blocks code exists (WHIT-991, #1873; migrations 260 and 265), and it keys parts **by position**: `SavedBlockExpander::fillLine` and `packages/ui/src/editor/saved-parts.ts` both build `<blockId>#<n>` from where the part sits, the code's `contract.ts` mark is `{ type: 'perDocument' }` with no key, and `FieldRef.perDocument` is a boolean. So E.23a applies to built code. It is **not in this pull request**: it is feature code across about twenty files plus a one-time migration of live data on app.whity.dev, which this organise step rules out (**Q18**). It is the next pull request in the Editor lane, under WHIT-992.

What it changes:

- **Contract:** `packages/ui/src/editor/contract.ts` to the 3.4 `whity-document.ts`: `PerDocumentKey`, the mark's `key`, `FieldRef.perDocument` as a key, `SavedBlock.perDocumentParts` (label in English and Arabic, `kind`, `required`, placeholder) with `perDocumentKeys` read for one release, `SavedBlockVersionChange`.
- **Editor:** mint `pd_` + 8-character nanoid when a part is marked; keep it through edits, moves and translation; copying inside the block mints a new key, pasting elsewhere drops the mark. `saved-parts.ts`, `convert.ts`, `schema-rich.ts`, `saved-block-view.tsx`, `gutter.tsx`, `catalogue.ts`, `services.tsx`; the saved block page and `web/lib/documents/saved-blocks.ts`.
- **Server:** `SavedBlockExpander` fills by key and reports `orphanedParts` instead of dropping them; `SavedBlockRepository`, `SavedBlocksApiHandler` and the OpenAPI schema take `perDocumentParts`; `required` parts block issuing like E.27. Tests in `tests/Api/SavedBlocksApiRealEngineTest.php` and the editor's saved-parts tests.
- **Migration (number from the orchestrator):** for each saved block, mint keys for its parts in reading order and write them into the marks and fields of `saved_blocks.blocks` and every `saved_block_versions.blocks`; set `perDocumentParts[i].key`; then rewrite every `savedBlock` node's `overrides` in `document_sources.blocks` from `<blockId>#i` to `perDocumentParts[i].key`. Frozen published copies in `document_source_versions` are **Q19**. Idempotent, one transaction per tenant.

`web/__tests__/editor-contract.test.ts` holds `contract.ts` byte-equal to `handoff/editor/whity-document.ts`. 3.4 changed the handoff (E.23a, and `FormPrintoutStyle` for P1), so that one check is skipped in this pull request; the E.23a pull request copies the contract and turns it back on.

## Already built against the 3.4 cards

Most of A15 was built before its card, from the asks (WHIT-939, 944-946, 906, 915, 820, 809, 956). Those screens now owe a comparison with their card, not a build. A14 has the stopgap phone pass (WHIT-999 to WHIT-1006, WHIT-1009) but none of the `Pw*` designs. P1 and most of E2 are missing.

## Milestones

Status: **done** (built and merged), **partial** (some of it is built, or built before the card and not yet compared), **missing** (nothing yet). Estimates are in pull requests of one session each, as in 3.3.

### Overview

| Milestone | Status | Server work | Estimate |
| --- | --- | --- | --- |
| A14 The web app on a phone | Partial: stopgap phone layout, no `Pw*` design built | **Yes**: web push for PW.11 (keys, subscriptions table, sender) | 12-13 |
| A15 Screens and surfaces with no card | Mostly done before the cards; five gaps | **Some**: impact plan model, credential grant check, Open Graph images | 9-10 |
| P1 Form printouts | Missing (letterhead, running header, page x of y, QR verify exist) | **Yes**: most of it is the print engine | 8-10 |
| E2 The ten 3.4 emails | Partial: booking emails exist with gaps; five senders missing | **Yes**: senders, `.ics` methods | 5 |
| E.23a Saved-block stable keys | Missing (positions today) | **Yes**: migration | 1 |

### A14 The web app on a phone (PW.1-PW.11)

| Card | Now | Lands in | Missing |
| --- | --- | --- | --- |
| `PwNav` | Missing: the drawer only (`packages/ui/src/app-sidebar.tsx`, WHIT-999) | A tab bar part in `packages/ui/src/`, mounted by `web/app/(protected)/layout.tsx` under 640px; More from the sidebar's groups; the Create sheet (M.11) | Tab bar with counts on Inbox and Chat, More, Create, hiding while the keyboard is open (WHIT-1006 only resizes the page) |
| `PwShortSidebar` (WHIT-827) | Partial: the account menu opens upward with Account settings, language, Light or Dark and Sign out (WHIT-1003); the footer stays reachable (WHIT-1009) | `web/components/sidebar.tsx`, `packages/ui/src/app-sidebar.tsx` | On very short windows the whole sidebar scrolls, so the switcher and account row aren't truly pinned; no fade at the ends; folding to the current group below 480px |
| `PwLists` | Partial: `DataTable` `phoneLayout="stack"` (WHIT-1001) | `packages/ui/src/data-table.tsx` | Sort (the header is hidden on phones), Select with the bulk bar in place of the tab bar, filters as a sheet, search and the main button at the bottom |
| `PwSettings` | Partial: sub-nav as a sideways row (WHIT-999) | The settings layout | The menu as its own page, Last opened, the path on deep pages, the save bar above the tab bar |
| `PwLibrary` | Partial: "Folders · All documents" button (WHIT-1005) | `web/app/(protected)/admin/document-library/` | Folders sheet with the unit filter, collections as chips, list or grid, search split by title and text |
| `PwCalendar` | Partial: Day by default when narrow (WHIT-1005) | `web/components/calendar/calendar-screen.tsx` | Week as day cards, month as counts with the day under it, sub-pages from the title |
| `PwEditor` | Partial: header on two lines, toolbar scrolls sideways (WHIT-1005) | `packages/features/src/document-editor/`, `packages/ui/src/editor/` | Full screen without the tab bar, mode sheet, toolbar docked on the keyboard, More tools and the publish check as sheets |
| `PwBuilders` | Missing: the canvases at phone width | `admin/document-route-templates/[id]`, `admin/roles/[id]`; `packages/ui/src/screen-too-small.tsx` is built and unused, a ready seam | Read-only routes as numbered sentences, permissions as lists, Open on a computer |
| `PwMessages` | Done (WHIT-939: one pane at a time below 768px) | `/admin/messages` | The screenshot pass |
| `PwInstall` (PW.11) | Partial: manifest and service worker per host and language (WHIT-957, #1733) | `web/components/pwa/`, `web/lib/pwa/` | The install offer once, in the browser only (Android prompt, iPhone three steps), the splash from branding; the notifications ask once. **No web push exists**: VAPID keys, a push subscriptions table (migration), a sender behind `src/Core/Notification` (**Q24**) |

Order: `PwNav` first (every other phone screen sits on it), then `PwShortSidebar`, `PwLists`, `PwSettings`, `PwLibrary`, `PwCalendar`, `PwEditor`, `PwBuilders`, `PwInstall` (server first). Estimate: PwNav 2, PwLists 2, PwEditor 2, PwInstall 2 (1 server, 1 web), the rest 1 each: **12-13 PRs**. Each is done at 390 wide in light, dark and Arabic, with nothing scrolling sideways at 360.

### A15 Screens and surfaces with no card before 3.4 (SC.1-SC.18, SU.1-SU.10)

| Card | Now | Lands in / exists at | Server work |
| --- | --- | --- | --- |
| `TplMessagesInbox`, `TplChatBox`, `TplSettingsMessages` | Done before the card (WHIT-939) | `/admin/messages`, `/chat/[workspace]`, `/messages`, `/admin/settings/messages` | — |
| `TplApprovalsDecided` | Done before the card (Decided tab, `edit-proposal-dialog.tsx`, WHIT-809) | `/admin/approvals` | — |
| `TplImpactPlan` | **Missing** | `web/components/agents/proposals.ts` and the approvals list | **Yes**: an impact plan on Whity Builder's publish and rollback proposals (kinds, records touched, tests); none in `src/` today |
| `TplAgentCredential` | **Partial**: the token is shown once from My agents' Connect dialog (WHIT-810) | `admin/my-agents`, `agent-grant-screen.tsx` | **Yes**: refuse a starting grant above the issuer's own permissions |
| `TplSavedBlocks` | Done before the card (WHIT-991) | `admin/document-sources/saved-blocks` | — |
| `TplWelcomeWorkspace` | Done before the card | `web/app/welcome` | — |
| `TplSettingsSignup`, `TplJoinWorkspace` | Done before the card (WHIT-915) | `/admin/settings/customer-signup`, `/join/[workspace]`, `/join/confirm` | Team invites (SC.11, new in 3.4): **yes**, a customer company inviting its own people up to a number |
| `TplSettingsPublicForms` | Done before the card (WHIT-906) | `/admin/settings/public-forms` | — |
| `TplPlanLimit` | **Partial**: Billing warning and the plan-limit email | `billing/plan-limits.tsx` | The amber banner across the app for people who can change the plan; meters per limit |
| `TplDocThemes` | **Partial**: server only (WHIT-735, WHIT-786; migration 171) | A new Documents › Document themes screen and the picker in a document's settings (M4d's open picker, WHIT-785) | — |
| `TplIssueDocument` | Done before the card | `web/lib/documents/issue.ts`, the editor's `IssueBar` | — |
| `TplSignInTwoColumn` | Done before the card (WHIT-956, #1734) | `/login`, `sign-in-brand-panel.tsx` | — |
| `TplVerifyDocument`, `TplPaymentReturn` | Done before the card | `verify/[token]`, `billing/return` | — |
| `TplCalendarStaff` | Done before the card (WHIT-944, WHIT-946) | `/calendar/*` | Rooms and branches are the 3.3 Calendar extras lane (Q7) |
| `TplAgentPersona` | Done before the card (WHIT-820) | `/admin/agents/[id]` with `persona-editor.tsx`; the app map says `agents/[id]/persona` (**Q22**) | — |
| `TplBookingManage` | Done before the card (WHIT-945) | `/booking/[token]` | Brand colour and logo: WHIT-1014 |
| `SiteShareCards` | **Missing**: no Open Graph metadata anywhere | `generateMetadata` and `opengraph-image` on `f/[slug]`, `book/[workspace]`, `join/[workspace]`, `verify/[token]` and the sign-in | **Yes**: generated images in the workspace's colour and logo; shared documents never preview content |
| `SiteInstallerTerminal` | Exists, not compared: `install.sh` at the repo root | `install.sh` | — |
| `MsgCustomerMessages` | Seam only (`MessageChannel::templates()`) | The paid WhatsApp and SMS plugins in whity-org/whity-plugins (**Q25**) | Templates in the plugins |
| `TplWhityAgent`, `TplWhityIdMore` | Elsewhere | whity-org/whity-agent, whity-org/whity-id | Their repositories |

Order: the five gaps first (`TplDocThemes` 2, because P1's `TplFormPrintStyle` is a tab on it; `TplPlanLimit` 1; `TplAgentCredential` 1; `SiteShareCards` 2; `TplImpactPlan` 1, with Whity Builder), then the card comparison of the screens built before their cards in four passes (Messages and chat; approvals, persona and saved blocks; public pages: welcome, join, verify, payment return, booking; settings, sign-in and calendar), then `install.sh` against its card. Estimate: **9-10 PRs** here, plus Team invites (1-2) if it is in scope, plus the plugin and whity-agent and whity-id work in their repositories.

### P1 Form printouts (FP.1-FP.12)

Today a form response prints only through a template written for that form (`RouteFinaliser`, `FormSubmissionRouteSubject`, `WhityDocumentPrinter`, Whity Render in `render-service/`). What exists: the letterhead on page 1 and a running header after it, the footer with page x of y and the reference, QR blocks and `/verify/[token]`, a `question` block, signature drawing in the web app (`signature-drawing.tsx`, `SavedSignatureRepository`). Everything in the cards is missing: `DocFormPrintout`, `DocFormPrintoutMore`, `DocFormLooks`, `DocFormBlocks`, `TplFormPrintStyle`.

Of the twelve engine needs in `handoff/print/FORM-PRINTOUTS.md`, only #5 (running header and footer) is partly there; section styles A-F, tints and boxes, choices with every option, collapse and "Not answered", "continued" for questions, word counts, the signature row ("Confirmed in Whity" with the drawn image; the printer emits a `fields` line today), the decision, approvals and office blocks with "Not counted", centred and bilingual letterheads, two-column questions and the plan gate are all new.

| Step | Lands in | Server | PRs |
| --- | --- | --- | --- |
| Contract and theme: `form?: FormPrintoutStyle` and `letterhead.layout` in `contract.ts` and `DocumentThemeTokens`; the plan and trial gate (free = standard; a trial ending changes only new printouts) | `src/Core/Document/Theme/`, `src/Core/Entitlement` | Yes | 1 |
| Print blocks: section styles A-F, tints, choices, Yes / No / N/A, collapse and "Not answered", "continued" and word counts, two columns | `render-service/src/flow/`, `WhityDocumentPrinter` | Yes | 2 |
| Decision, approvals (supervisors "Not counted") and office blocks | Same, reading the route's decisions | Yes, on WHIT-1016-1018 (**Q26**) | 1-2 |
| Signature row: Confirmed in Whity with date and time, the drawn image, the dashed line | Same, `SavedSignatureRepository` | Yes | 1 |
| The automatic printout from a form's sections, "Customise in the editor", frozen printouts | A printer for form responses, `src/Core/Form` | Yes | 1-2 |
| Letterhead layouts, the client's marks, a QR on every page | Theme and printer | Yes | 1 |
| `TplFormPrintStyle`: the Form printouts tab of a document theme | The A15 Document themes screen | — | 1 |

Done when `DocFormPrintout`'s ethics application prints page for page from a real form, in English and Arabic. **8-10 PRs**, mostly serial.

### E2 The ten 3.4 emails (SU.8)

| Card / email | Now | Missing |
| --- | --- | --- |
| `EmailBookings`: confirmed, moved | Partial: `src/Core/Calendar/EmailBookingMessenger.php` on `EmailLayout`; the UID is stable (`booking-{id}-{tenant}@whity`) | The `.ics` goes as METHOD:PUBLISH with `SEQUENCE` from `time()`, and only when the mailer threads; it should be REQUEST with a rising sequence, always attached |
| `EmailBookings`: cancelled | Partial | No `.ics` at all; needs METHOD:CANCEL with the same UID |
| `EmailBookings`: reminder | Done | The 3.4 layout |
| `EmailForms`: form received | Missing | A sender on submission |
| `EmailForms`: form decision with the printout PDF | Missing | A sender; the PDF needs P1 |
| `EmailForms`: sign request | Missing (only the one-time code, `SigningCodeMailer`) | A sender |
| `EmailForms`: message reply | Partial: `EmailChannel` threads (Reply-To, In-Reply-To, References) | Plain text today; put it on `EmailLayout` |
| `EmailJoining`: approved, declined | Missing: `CustomerSignupService::approve` and `reject` only audit | Two senders |

Order: bookings `.ics` (1), joining (1), form received and sign request (1), message reply on the layout (1), form decision after P1 (1): **5 PRs**.

### Order and estimate

About **36-40 pull requests**: A14 12-13, A15 9-10, P1 8-10, E2 5, E.23a 1. They can run as four lanes at once, each starting with its server PR:

1. **E.23a first**, in the Editor lane: every day it waits, more documents store overrides by position on app.whity.dev.
2. **Phone (A14):** `PwNav`, then the rest in the order above. Overlaps the shell, so it goes one PR at a time.
3. **Surfaces (A15):** `TplDocThemes` first (P1 needs it), then the other gaps, then the comparison passes.
4. **Printouts (P1):** the contract and theme PR at once; blocks and the automatic printout follow; `TplFormPrintStyle` once `TplDocThemes` is in. WHIT-1016-1018 (the IRB gaps) come before the decision and approvals blocks.
5. **Emails (E2):** bookings and joining at once; the form decision email last, after P1.

Every merge deploys to app.whity.dev, so each must leave the app working on its own. Migration numbers (E.23a, web push, Team invites if any) come from the orchestrator.

## Design asks 3.4 answers

These asks are closed: each has a card. The screens already built for them now compare against the card (A15 and A14 above).

| Ask | What | Answered by |
| --- | --- | --- |
| WHIT-821 | Agents admin page, persona editor, My agents, preset gallery card, whity-agent | `TplAgentPersona` (SU.4); `TplAgents` (My agents and Everyone's agents), `TplAgentRecord`, `TplAgentPresets`, `TplAgentInstructions`; `TplWhityAgent` (SC.8) |
| WHIT-826 | Settings area with the grouped sub-nav | The left column of every `TplSettings*` card, now all of D1; on a phone `PwSettings` (PW.8) |
| WHIT-827 | User menu at the foot of the sidebar | `PwShortSidebar` (PW.10): Account settings, Language, Light or Dark, Help, Sign out, opening upward |
| WHIT-828 | Approvals: Decided, and Edit before approving | `TplApprovalsDecided` (SC.4, SC.5) |
| WHIT-829 | Proposal with an impact plan | `TplImpactPlan` (SC.6) |
| WHIT-830 | Issuing an agent credential | `TplAgentCredential` (SC.7) |
| WHIT-906 | Settings: Public forms | `TplSettingsPublicForms` (SC.13) |
| WHIT-915 | Settings: Sign-up, and Join a workspace | `TplSettingsSignup` (SC.11), `TplJoinWorkspace` (SC.12); emails `EmailJoining` |
| WHIT-939 | Messages inbox, chat box, Settings: Messages | `TplMessagesInbox`, `TplChatBox`, `TplSettingsMessages` (SC.1-SC.3); on a phone `PwMessages` |
| WHIT-945 | The customer's own booking | `TplBookingManage` (SU.10), with `TplBookingPage`; emails `EmailBookings` |
| WHIT-946 | Calendar (staff) | `TplCalendarStaff` (SU.3); on a phone `PwCalendar` (PW.7) |
| WHIT-1006 | The web app on a phone (navigation, lists, library, editor, calendar, settings, builders) | `PwNav`, `PwLists`, `PwLibrary`, `PwEditor`, `PwCalendar`, `PwSettings`, `PwBuilders` (PW.1-PW.9); also `PwInstall` (PW.11) |

Also answered by 3.4: the theme picker and Document themes screen (WHIT-785) by `TplDocThemes` (SC.15); usage and the limit warning (`brand-ask-limit-warning.md`) by `TplPlanLimit` (SC.14); the store revamp wait (WHIT-831) by Q34.3, "section 19 is the store"; and these 3.3 questions: Q1 (3.4 starts from this folder), Q9 (hosted in the EU, Q34.1), Q10 (real dark screenshots, SU.9), Q17 (every missing design is drawn).

## Questions for the owner

Numbered after 3.3's Q1-Q17. Each keeps its default until answered.

| # | Question | Default until answered |
| --- | --- | --- |
| Q18 | Step 1 says to apply E.23a in this pull request and also "no feature code". E.23a touches about twenty files and migrates live documents on app.whity.dev. | Its own pull request straight after this one, under WHIT-992, with a migration number from the orchestrator |
| Q19 | E.23a says old rows migrate once. Published template versions (`document_source_versions`) are frozen copies; rewriting their `overrides` keys changes a frozen record. | Rewrite drafts, saved blocks and their versions; for frozen published copies, map `<blockId>#i` to `perDocumentParts[i].key` when reading, for the release `perDocumentKeys` stays |
| Q20 | The 3.4 `CLAUDE-snippet.md` again lacks the team's rule on size tokens (WHIT-846). | Kept in `CLAUDE.md` under "Team additions"; ask the brand team to add it to the snippet |
| Q21 | `HANDOFF.md` says `handoff/icons/` has 156 icons; `icons.json` has 163 and `ANSWERS-3.4.md` lists the seven new ones. | The code follows `icons.json` (163) |
| Q22 | **SU.1-SU.10 reuse numbers**: the register already has SU.1 "Subscriptions and tiers" (3.2), and the new "Every surface" table starts again at SU.1. The snippet's "SU.1, SU.10" is ambiguous. | Cite the new ones as "SU.n (Every surface, 3.4)"; ask the brand team to renumber them in the next package |
| Q23 | The app map puts the persona editor at `agents/[id]/persona`; it was built inside `/admin/agents/[id]` (WHIT-820). | Keep the built route, the persona as part of the agent's page |
| Q24 | PW.11 asks for notifications once, but there is no web push at all (keys, subscriptions, sender). | In A14, server first; the install offer can ship before it |
| Q25 | `MsgCustomerMessages` (WhatsApp and SMS templates) is listed in A15, but those channels are paid plugins. | Built in the plugins (whity-org/whity-plugins), not core |
| Q26 | P1's approvals block ("Not counted" supervisors) and references overlap the IRB gaps WHIT-1016-1018. | Do WHIT-1016-1018 first; P1 prints their data |
| Q27 | `ANSWERS-3.4.md` says the four briefs (limit warning, issue a document, two-column sign-in, Whity ID) weren't in the repository; they are in `D:\Projects\Whity` (`brand-ask-*.md`), outside it, so the cards follow notes and built code. Send the briefs for a check? | Build to the cards; send the briefs with the next asks |
| Q28 | SC.11 adds **Team invites** (a customer company inviting its own people up to a number), which isn't in the asks or the code. In A15? | In A15, after the gaps, with its own server PR |
| Q29 | Where do A14, A15, P1 and E2 sit against the 3.3 lanes still open? | Run alongside them as four more lanes; E.23a first |

## For the design team to pick up

`HANDOFF.md`'s icon count (Q21); renumbering the 3.4 SU table (Q22); the size-token rule in the snippet (Q20); `ANSWERS-3.4.md` skips its part 3 and its PW range stops at PW.10 while the register has PW.11; `13-app-map.md`'s persona route (Q23).

---

# Design 3.3 working plan (12 Nov 2026)

The working plan for design system 3.3, checked against `develop` at `b7aae8e5` on 12 Nov 2026 (WHIT-952). This is step 1 of `START-HERE.md`: organise, no feature code.

**Target: the whole 3.3 redesign ships in 0.2.29** (owner, 12 Nov 2026: "The full new redesign is planned for the next release"; 0.2.28 is the latest release). Every milestone in `PLAN.md` is in scope for 0.2.29; none is marked later. How the work is split into a shared foundation and parallel lanes is in [Release 0.2.29](#release-0229). What looks unlikely to land with the rest is under [Questions for the owner](#questions-for-the-owner-1), not dropped. The 2.9 working plan of 2 Nov and the M0 notes of 29 Oct are kept unchanged [below](#design-29-working-plan-2-nov-2026); their done and partly-done status is carried into the tables here.

How it was checked: the code was read on `origin/develop` and the merged pull requests were listed. No screen was compared with its screenshots; each milestone does that.

## What the 3.3 merge did

3.3 was merged into `docs/design/` three ways against the original 2.9 package, as 2.9 was, not unzipped over it. The 3.3 package was drawn from the brand team's own 2.9, so it lacks everything the team added in the repo (the same list as 2.9's "For the design team to pick up", below).

| Result | Files | Notes |
| --- | --- | --- |
| Added | 777 | Sections 26-29, `business/`, `sites/`, `handoff/emails/`, `handoff/motion/`, 80 more icons, 106 new cards (`Tm*`, `Site*`, `Email*`, 28 new `Tpl*`) with screenshots, the 3.3 PDF |
| Taken from 3.3 | 406 | Unchanged in the repo since 2.9. 99 existing cards changed only their example people (WS.4: the Whity team instead of Rana, Omar, Lina) |
| Merged line by line | 3 | `00-decision-register.md`, `13-app-map.md`, `PLAN.md`, no conflicts. The register keeps E.40-E.47, the 6.3 and 6.8 changes and the S.1-S.12 answers; the plan keeps M4d and "merge on green"; the app map keeps D1 and the messages, calendar, public forms and customer sign-up rows |
| Merged by hand | 1 | `CLAUDE-snippet.md`: 3.3's text, plus the three rules 3.3 dropped again (16px editor sheet, "with the permission it used", the editor pointer) and "merge on green" in place of "stop for approval" (owner, 2 Nov, Q7 below) |
| 3.3's version | 1 | `HANDOFF.md`: the repo had changed only its first line, to say 2.9 was merged; this file says so now |
| Repo's version kept | 8 | 3.3 didn't change them: `16-documents.md`, `18-document-editor.md`, `19-plugin-store.md`, `handoff/editor/README.md`, `handoff/editor/whity-document.ts`, and the `EditorAgents`, `EditorBlocks`, `EditorCollab` READMEs |
| Repo-only, kept | 18 | The team's notes (`AGENT-*`, `DESIGN-ASKS.md`, `DOCUMENT-*`, `MCP-TOOL-SURFACE.md`, `VERIFIED-PLUGINS-SECURITY.md`, `WHITY-BUILDER.md`, this file), the `Cover` card, `assets/motion` and `assets/social` (3.3 spells them `Motion` and `Social`; the files are the same) |
| Removed | 1 | `Whity-Brand-Guidelines-v2.9.pdf`, replaced by 3.3's |
| Moved | 1 | `START-HERE.md` from the zip's root to `docs/design/` |

The business model PDFs in `business/` and the 3.3 guideline PDF are byte-for-byte the copies in `D:\Projects\Whity`.

Outside `docs/design/`: `CLAUDE.md` takes the merged snippet and keeps its own rule on size tokens. 3.3 grows the curated icons from 116 to 156, and `web/__tests__/design-system-rules.test.ts` holds `packages/ui/src/icon-map.ts` equal to `icons.json` with a fixed count, so the map was regenerated with `scripts/generate-icon-map.mjs` and the count changed to 156. Every new Tabler name exists in `@tabler/icons-react` 3.49, below the 3.50 the lock file installs. Nothing uses the new icons yet.

## Is the folder complete

- **Cards:** all 270 cards in `cards.html` have a folder with `README.md`, `index.html` and `preview.html`, and a light and a dark screenshot. Every card named in `13-app-map.md`, `PLAN.md` and sections 26-29 exists. Only `Cover` has a folder and no entry in `cards.html`, as in 2.9.
- **Dark screenshots that aren't dark:** 27 cards ship a dark screenshot identical to the light one: the six `Email*` cards, the 13 `TplDoc*` starters (paper, so expected) and eight Arabic cards (`TplAgentApprovalsAr`, `TplAgentRecordAr`, `TplBillingAr`, `TplDocRoutingAr`, `TplFormBuilderAr`, `TplUserRecordAr`, `TplDocInvoiceAr`, `TplDocInvoiceLong`). The Arabic ones were already like this in 2.9. The `Email*` cards matter: E1 is done when the emails match in dark mode, and there is no dark target. Ask for them.
- **Counts:** 15 emails in English and Arabic, 31 Lottie files, 156 icons, 5 sites with 20 pages a language, 48 `Tm*` and 22 `Site*` cards, as `HANDOFF.md` says.
- **Fonts:** 3.3's `fonts/` ships Noto Sans, Noto Sans Arabic and Geist Mono at weight 700 (and Geist Mono 600) although 3.6 rules out 700. They were in 2.9 too. Not copied into the app; nothing needs them.
- **Still not designed:** `HANDOFF.md`'s list (publisher page, public marketplace, everything "To design" in section 28), plus what 2.9 left open and 3.3 didn't draw: the theme picker and Document themes screen (WHIT-785), the user menu (WHIT-827), Decided and Edit in Approvals (WHIT-828), the impact plan (WHIT-829), issuing an agent credential (WHIT-830), E.40-E.47 blocks, the Messages inbox (WHIT-939 ask), the calendar staff screens (WHIT-946 ask), public forms and customer sign-up settings (WHIT-906, WHIT-915 asks), and usage and the limit warning on Billing (`brand-ask-limit-warning.md`). `DESIGN-ASKS.md` has them.

## The repository's own tokens, fonts, icons and logos

Nothing was replaced.

| Kind | In the repo | Against `docs/design/` |
| --- | --- | --- |
| Tokens (source) | `src/design/tokens/base.json` 3.0.0, generated by `src/design/tokens/generate-tokens.js` into `src/design/tokens/generated/` and `packages/tokens/generated/` | 3.3 didn't change `tokens.json`, `tokens.css`, `handoff/web/` or `handoff/flutter/` since 2.9 |
| Web CSS | `packages/tokens/generated/theme.css`, `base.css`, `fonts.css` (`@whity/tokens` 0.2.0) | All 56 colours that both define are equal within 2/255: the repo writes oklch, `handoff/web/tokens.css` writes hex. The repo is ahead on sizes (2.9's 15px, `--control-h` 40/32/44, `--row-h` 48, the Larger text scale, from A0). `handoff/web/tokens.css` has `--space-*`, `--logo-*` and `--mark-cut-switch`, which the repo doesn't generate; nothing in the app asks for them |
| Fonts | `packages/tokens/fonts/` (10 files) | All 10 are byte-identical to `fonts/`. The repo has no 700 files (by rule) and no Geist Mono 600 |
| Icons | `packages/ui/src/icon-map.ts` (Tabler by name), `packages/ui/src/icon.tsx` | Generated from `handoff/icons/icons.json`; now 156 (above). The SVG exports in `handoff/icons/ink` and `paper` aren't used: the app draws Tabler components |
| Logos | `packages/ui/src/mark.tsx`, `lockup.tsx`, `built-on-whity.tsx`, `tenant-logo.tsx`; `web/app/app-icon/` (a route) and `web/app/manifest.webmanifest`; `docs/design/assets/Logos/` | `mark.tsx` draws the same paths as `handoff/logos/whity-mark-colour.svg`. `handoff/logos/favicon.svg` and `favicon.ico` have no copy in `web/public/`, which still holds Next's starter SVGs (`next.svg`, `vercel.svg`, `globe.svg`, `file.svg`, `window.svg`). The favicon comes from the workspace's branding or the app icon route |
| Flutter | `flutter/whity_tokens/` (generated `tokens.dart`) | `handoff/flutter/lib/whity_brand.dart` (the plane, blueprint, logo sizes) isn't in the repo. There is no Flutter app in this repository |
| Motion | none | `handoff/motion/` (31 Lottie files, `whity-lottie.js`, Flutter code) has no counterpart; no `lottie` dependency |
| Templates | `templates/tauri-desktop/src-tauri/icons/` | The desktop template's own app icons; not compared |

## Where each website lives

| Site | Lives in | Stack | What the port means |
| --- | --- | --- | --- |
| whity.dev | `whity-org/whity-web`, repo root (Worker `whity-dev-site`) | Astro, Cloudflare Workers static assets | Not plain static: the `sites/whity.dev` pages become Astro pages. It already has `index`, `install`, `license`, `privacy`, `security`, `404`, `platform`, `plugin-sdk`, `ecosystem`, `contribute`, `cli`, `terms` and a blog. 3.3 adds the module pages, Partners, About, Contact and `ar/` |
| docs.whity.dev | `whity-org/whity-web/docs-site` (Worker `whity-docs-site`) | Astro Starlight | Compiled from this repo's `docs/wiki` and `docs/adr` by `docs-site/scripts/sync-docs.mjs`; `docs/design/` isn't published. `notify-docs-site.yml` sends a rebuild on every `docs/**` push, so this PR triggers one |
| brand.whity.dev | `whity-org/whity-web/brand-site` (Worker `whity-brand-site`), files in `whity-web/brand/` | Astro | Section 12 item 9: its logo files were last seen matching 2.2 |
| health.whity.dev | this repo, `ops/watchdog/` (Worker `whity-watchdog`, route `health.whity.dev`) | Plain Cloudflare Worker reading KV | Kept apart from the app on purpose (the outage of 9 Sep). `SiteStatus` is a restyle of `ops/watchdog/src/index.js`; it already has the real status data |
| id.whity.dev | `whity-org/whity-id` | Laravel with React and Inertia (`resources/js/Pages`), Blade mail | `SiteId` lands in its pages, not here |

No site needed a new repository.

## Already built in core

| Area | What exists | Where |
| --- | --- | --- |
| Messages | Customer conversations: an inbox, email and web chat channels, a channel interface for plugins (`PluginMessageChannelsInterface`), merged inbox sources. WhatsApp and SMS are paid plugins | ADR 0016; #1811, #1820 (SDK 1.97), #1821 (migrations 243-245), #1824; `/admin/messages`, `/messages`, `/chat/[workspace]`, `src/Core/Messaging` |
| Calendar | Staff calendars, services, availability (hours and time off), the public booking page and the customer's booking page, reminders through Messages, ICS feed and `.ics` attachment, outside busy times, a payment step | ADR 0017; #1819, #1822, #1823, #1825, #1826 (SDK 1.98), #1827, #1828; `/calendar`, `/calendar/services`, `/calendar/hours`, `/calendar/settings`, `/book/[workspace]`, `/booking/[token]`, `src/Core/Calendar` |
| Plan limits | BM.2: 5 staff and 500 customers a month on the free plan, agents not counted, add-on packs, warn mode; Built on Whity follows the plan | #1787 (catalogue, default plan), #1799 (server), #1800 (Billing screen, add-on pack editor); `/billing/plan-limits.tsx`, `src/Core/Entitlement`, `src/Core/Usage` |

**3.3 cards against that work:**

| Card | Built by | Fits | Doesn't fit yet |
| --- | --- | --- | --- |
| `TplAppointments` | Calendar (`/calendar` and its sub-pages) | Day and week, services with length and price, who can do each, per-person hours and time off, reminders | Columns of **rooms** and **branches** (no room resource or branch filter); **checked in** state; **requests** that need the staff's OK with Accept and Suggest another time; a Today panel. Reminders go by email in core; WhatsApp at 24 and 2 hours is the paid plugin's. Meetings in `src/Core/Convening` don't block calendar time |
| `TplBookingPage` | `/book/[workspace]`, `/booking/[token]` | Service, day, time, summary, book, move and cancel by link up to a cut-off | The address on the tenant's own domain (`examplebakery.jo/book`) needs the custom-domain work; Google or Apple sign-in creating a Whity ID (M.31) needs Whity ID; confirmation by WhatsApp is the paid plugin's; approval-needed services send a request (as above) |
| `TplChat` | Not this. Core Messages are conversations **with customers**; `TplChat` (CH.1-CH.2) is the team's own chat, people and agents together, with groups, mentions and proposals in the thread | The conversation and message tables, the web chat transport and the agent proposal card could be reused | Everything else: groups and direct chats between staff, agents as members with "for Emad", joining lines, read-from-join scope, chats linked to a board, meeting or record. **Question for the owner (Q3)** |
| `TplSubscriptions` | Not this. Plan limits are the workspace's **own** Whity plan (Billing, section 25). `TplSubscriptions` (SU.1) is plans a tenant **sells to its customers** | `src/Core/Subscription` and `src/Core/Plan` model the workspace's plan and could lend their shapes | Customer plans, subscribers, renewals, retries, pause on failure, CliQ and card. Taking money is Whity Pay's (a paid plugin, the installer's own Stripe, Polar or Paddle). **Q4** |
| `TplBilling` (changed only in names) | #1800 | Plans and add-on packs | Usage against the limits and the warning: no design yet (ask in `brand-ask-limit-warning.md`). The `plan-limit` email in `handoff/emails/` is the first design of the warning |

## Release 0.2.29

### Where each piece goes

| Piece | Repository | Lands in 0.2.29 as |
| --- | --- | --- |
| Web app screens (editor, A0-A13), server, MCP tools, emails (E1) | whity-core | This release |
| health.whity.dev (W2), the installer script (W7), the plans and contact endpoints (W1, W6) | whity-core (`ops/watchdog/`, the installer next to `docker-compose.yml`, `src/Api/`) | This release |
| whity.dev, brand.whity.dev, docs.whity.dev (W1, W4, W5, W6, W8) | whity-org/whity-web | Deployed on their own (push to `main`), timed with 0.2.29 |
| id.whity.dev (W3) | whity-org/whity-id | Whity ID's own release, timed with 0.2.29 |
| Flutter mobile app (`Tm*` cards, section 26) | **No repository yet.** whity-core has only `flutter/whity_tokens/` (the tokens package, where `whity_brand.dart` goes). The app isn't a `PLAN.md` milestone (section 28 puts it at L9) | **Not in 0.2.29 (owner, 12 Nov).** Its foundation share (`whity_brand.dart`, fonts) lands here |
| Subscriptions a workspace sells (`TplSubscriptions`) | whity-org/whity-plugins, `whity-pay/` | In 0.2.29 as part of Whity Pay (owner, 12 Nov), released with it |
| Receiving, waste, bank and the accounting reports (A11, A12) | whity-org/whity-plugins, a new `front-office/` folder (owner, 12 Nov) | In 0.2.29, released with it |
| Store pages (A5 follow-ups) | whity-org/whity-id, store module | Whity ID's release |

### The shared foundation (one lane, first)

Every other lane starts after this merges. It is the only lane that changes the shared design files.

| # | Part | Lands in | Server |
| --- | --- | --- | --- |
| F1 | **Tokens**: generate the `--space-*`, `--logo-*` and `--mark-cut-switch` values `handoff/web/tokens.css` has and the repo doesn't; `whity_brand.dart` into `flutter/whity_tokens`; Avatar pattern colours (`brandHue`) | `src/design/tokens/base.json`, `generate-tokens.js`, `packages/tokens/generated/`, `flutter/whity_tokens/` | — |
| F2 | **Fonts and logos**: fonts are already identical (nothing to do); `favicon.svg` and `favicon.ico` into `web/public/`, Next's starter SVGs out | `web/public/` | — |
| F3 | **Icons**: the 156 are mapped by this PR; nothing left but using them | — | — |
| F4 | **Kit components** the new screens share: Avatar with the seeded pattern (M.34); a board column and card (Tasks, Boards, Flows); a time grid (Appointments, the calendar); a message thread with agent members (Chat, Messages); a KPI strip and a column matcher (Import, Reports, Bank) | `packages/ui/src/` (new files), `packages/ui/src/index.ts`, `bundle.css` as the reference | — |
| F5 | **Motion player and the Animations setting** (LT.4): `packages/ui/src/lottie.tsx` on `handoff/motion/whity-lottie.js`, the 31 files served lazily, Full, Calm, Off and reduce motion; the moments inside shared components (`empty-state.tsx`, the approval set-down in `action-proposal.tsx`, the waiting and error states in `page-states`) | `packages/ui/src/`, `web/public/motion/`, Account › Preferences in `web/app/(protected)/settings` | `animations` preference key in `src/Api/DisplayPreferencesApiHandler.php` (no migration if it joins the existing preference row; check) |
| F6 | **Email layout** (EM.1): `EmailLayout` restyled to `handoff/emails/`, so every lane that sends mail uses the new look | `src/Core/Mail/` | — |

Estimate: 4 PRs (F1+F2, F4, F5, F6), run as one lane in that order; F6 can run beside F4.

### Core or paid plugin

**The rule (owner, 12 Nov 2026):** what any team can use goes in core, free; what a business needs to trade goes in a paid plugin. Each lane below follows it.

| In core (free) | In a paid plugin |
| --- | --- |
| The editor M6-M8, including the plugin block registry and saved blocks | |
| Bulk approval, two-step reset with a second admin, I'm away (A7) | The 80 mm receipt (`TplReceipt80`, A7), with the JoFotara QR: **Front Office** |
| Contacts (customers and suppliers), tags, the import engine for contacts (A8) | Importing items, opening stock and opening balances (A8): **Front Office** |
| Custom fields on core's records, usable in lists, filters, documents and flows (A9) | Custom fields on items and stock: Front Office uses core's mechanism |
| Tasks and boards through Taskaat (A10) | |
| | Receiving, three-way match, waste and adjustments, bank reconciliation (A11): **Front Office** |
| | Profit and loss, balance sheet, ageing, stock value, sales tax (A12 reports): **Front Office** |
| Agent notifications by email (A12) | Agent notifications by WhatsApp (and SMS): the WhatsApp plugin |
| The motion player, the Animations setting and the app's moments (A13) | Business moments (`money-invoice`, `payment-received`, `stock-*`, `spot-jofotara`, `spot-cliq`, `spot-jod`) ship with the plugin whose screen shows them |
| Team chat with agents (`TplChat`) | |
| Calendar rooms, branches, check-in and requests (`TplAppointments`); reminders by email | Reminders and confirmations by WhatsApp; deposits; calendar sync |
| | Plans a workspace sells, subscribers, renewals (`TplSubscriptions`): **Whity Pay** |
| Emails for accounts, security and the workspace: verify email, reset password, invitation, new sign-in, two-step off, workspace ready, needs approval, daily summary, plan limit (E1) | Emails for trading: invoice, quote, receipt, payment reminder: **Front Office**; payment failed, and Whity's own receipt for a plan: **Whity Pay** |
| The public plans and contact endpoints, health, the installer (W1, W2, W6, W7) | |

Core keeps an extension point wherever a paid plugin adds to a core screen (an email sender, a moment, a contact tab, a custom-field record type, a calendar reminder channel), so the plugin never patches core.

### Lanes after the foundation

Each lane owns its files. The columns say what each one may touch, so lanes that don't share a row can run side by side.

| Lane | Milestones | Owns | Server work | PRs |
| --- | --- | --- | --- | --- |
| **Editor** | M6, M7, M8 | `packages/ui/src/editor/`, `packages/features/src/document-editor/`, `src/Core/Document/`, `web/app/(protected)/admin/document-sources/` | **Yes.** M6: MCP tools (`documents.read`, `suggest_blocks`, `suggest_rewrite`, `reply_comment`), streamed runs. M7: block registry, `saved_blocks` tables (migrations), **SDK minor**. M8: theme options E.46, E.47 | 6 |
| **People and approvals** | A7 (core part) | `admin/approvals`, `admin/approval-gating`, `admin/registrations`, `admin/delegations`, `src/Core/Delegation`, `src/Core/Security` | **Yes.** Bulk approve, two-step reset with a second admin (table), passkey identity check, "I'm away" | 2 |
| **Contacts** | A8 then A9 | new `web/app/(protected)/contacts`, `packages/features/src/contacts`, `src/Core/Contacts`, `src/Core/CustomFields`, settings page for fields | **Yes.** `contacts` table, import engine (preview, 24-hour undo, Excel reader), field definitions and values, MCP tools for contacts | 6 |
| **Taskaat** | A10 | new `web/app/(protected)/tasks`, `boards`, `src/Core/Taskaat` | **Yes. The Taskaat link**: per-workspace connection (OAuth), a proxy to Taskaat's API, "Open in Taskaat", Taskaat tools in the workspace MCP. Needs Taskaat's team | 3 |
| **Operations** (paid) | A11, A12 reports, the 80 mm receipt, item and stock imports, trading emails | A Front Office code plugin in whity-org/whity-plugins (Q6): its own `src/`, migrations and screens; in core only the extension points it asks for | **Yes, the largest.** Purchase orders, receipts, supplier bills, three-way match, stock items and movements, adjustments with approval, bank accounts and lines, a ledger to report from; the Bookkeeping assistant's suggestions as MCP tools | 8+ |
| **Team chat** (owner, 12 Nov) | `TplChat`, `TplChatAddAgent` | new `web/app/(protected)/chat`, `src/Core/Chat` beside `src/Core/Messaging` (reads its tables, doesn't change its channels) | **Yes.** Team conversations on the Messages tables (kind: team), members that can be agents, read-from-join scope, proposals in the thread, links to a board, meeting or record, MCP tools to read and post | 3 |
| **Calendar extras** (owner, 12 Nov) | `TplAppointments` gaps | `web/app/(protected)/calendar`, `src/Core/Calendar` | **Yes.** Rooms as resources, branches, checked-in state, requests needing approval with Suggest another time | 2 |
| **Subscriptions** (paid; whity-plugins, owner, 12 Nov) | `TplSubscriptions` | whity-org/whity-plugins `whity-pay/` | **Yes, in the plugin.** Customer plans, subscribers, renewals, retries then pause, CliQ and card; may need an SDK hook from core | 3 |
| **Agent notifications** | A12 notifications | `admin/agents` (its notifications tab), `src/Core/Notification` | **Yes.** Per-agent setting, one an hour, through `MessagingService` | 1 |
| **Moments** | A13 (screens) | One PR per screen family, after its own lane, or done by that lane | — (F5 did it) | 1-2 |
| **Emails** | E1 (core emails) | `src/Core/Mail/` senders, `src/Core/Identity/*Mailer.php` | **Yes.** New senders: new sign-in, two-step off, workspace ready, needs approval, daily summary (scheduler), plan limit (from warn mode); an email sender extension point for plugins. Trading emails go with Front Office and Whity Pay | 3 |
| **Public endpoints and health** | W1, W2, W6, W7 (core side) | new `src/Api/PublicPlansApiHandler.php`, `PublicContactApiHandler.php`, `ops/watchdog/`, the installer script | **Yes.** `GET /api/public/plans` (plan bundles plus a marketing layer, a migration), `POST /api/public/contact` | 4 |
| **Websites** (whity-web) | W1, W4, W5, W6, W8 | whity-org/whity-web | Calls the two endpoints above | 7 |
| **Whity ID** (whity-id) | W3 | whity-org/whity-id | Passkeys, Apple, Google | 2 |

**Which lanes can run at once** (after the foundation): all of them, with these limits.

- **Editor** and **Operations** meet at the 80 mm receipt: Front Office draws it, and the Editor lane adds the 80 mm page size to `src/Core/Document/Print` as a core extension point.
- **Contacts** runs A8 before A9 (fields attach to contacts).
- **Agent notifications** and **Emails** both send through `src/Core/Messaging`; neither changes it. Emails owns `src/Core/Mail`; agent notifications only calls it.
- **Moments** follows each screen's own lane, or the lane adds its moments itself.
- **Websites** need the **Public endpoints** lane's two routes for W1 and W6; they can build the pages first with the fallback plans and a stub form.
- **Operations** is a Front Office plugin (Q6), so it touches no core files except the SDK extension points it needs, each a separate core PR.
- **Team chat** and **Agent notifications** both read `src/Core/Messaging`; neither changes it, and Team chat owns `src/Core/Chat`.
- **Calendar extras** and **People and approvals** don't share files; Calendar extras owns `src/Core/Calendar` and `/calendar`.

**Files every lane touches, as in 2.9** (add only your own lines): `src/OpenAPI/CoreApiSchemas.php`, `packages/api-client/src/schema.d.ts`, `database/i18n/*` (Arabic kept sorted), nav in `public/index.php` (orders unique within a group), `packages/ui/src/index.ts`, the permission area grid. **Migration numbers and SDK minors come from the orchestrator** before each server PR; Editor M7 and possibly Contacts and Taskaat need SDK minors.

### Size

About 63 pull requests: 4 foundation, 43 in whity-core lanes (with team chat and the calendar extras), 8+ for Operations in the Front Office plugin, 3 for subscriptions in Whity Pay, 7 in whity-web and 2 in whity-id. With eleven lanes at once after the foundation, the longest chains are Operations (8+, serial) and Contacts (6, serial). **0.2.29 is released when every lane is green, with no fixed date (owner, 12 Nov).** **No switch: the redesign ships as it lands** (owner, 12 Nov); every develop merge deploys to app.whity.dev within minutes (#1832), so each merge must leave the app working. **Taskaat is changed directly** by pull requests on its repository (owner, 12 Nov), so A10 adds the structured results, members and Board statuses it needs there.

## Milestones

Status: **done** (built and merged), **partial** (some of it is built), **missing** (nothing yet). Estimates are in pull requests of the size the 2.9 milestones had (one session each). Every row is in 0.2.29.

### Overview

| Milestone | Status | Lane | Server work | Estimate |
| --- | --- | --- | --- | --- |
| Editor M0 Survey | Done | — | — | — |
| Editor M1 Foundation | Done | — | — | — |
| Editor M2 Core | Done | — | — | — |
| Editor M3 Rich blocks | Done | — | — | — |
| Editor M4 Templates (with M4b labels, M4c issuing) | Done | — | — | — |
| Editor M4d Themes | Done (server); picker waits on WHIT-785 | Editor | — | 1 when the card exists |
| Editor M5 Comments and suggestions | Done (#1519) | — | — | — |
| Editor M6 Agents | Missing | Editor | **Yes**: MCP tools, run streaming | 2 |
| Editor M7 Plugin and saved blocks | Missing | Editor | **Yes**: block registry, saved blocks tables, SDK minor | 3 |
| Editor M8 Arabic, type, accessibility | Partial | Editor | **Yes**: theme options E.46, E.47 | 1-2 |
| A0 Ease-of-use foundation | Done (#1546) | — | — | — |
| A1 Home, People, Inbox | Done (#1561, #1572, #1575, #1588; S2, S3) | — | — | — |
| A2 Forms | Done (#1569 and follow-ups; S4) | — | — | — |
| A3 Documents and routing | Done (#1570, #1638; S5) | — | — | — |
| A4 AI and agents | Done (#1564; S6) | — | — | — |
| A5 Plugin store | Done (#1562; S7). Store moves to Whity ID | Whity ID | Store module in whity-id | — |
| A6 Workspace, billing, settings | Done (#1563 and follow-ups; S8) | — | — | — |
| A7 Quick wins | Missing | People and approvals (core); Operations (the receipt, paid) | **Yes**: two-step reset, away; the receipt in Front Office | 2 + 1 |
| A8 Contacts and import | Missing | Contacts | **Yes**: contacts table, import engine, MCP tools | 3 |
| A9 Custom fields | Missing | Contacts | **Yes**: field definitions and values | 3 |
| A10 Tasks on Taskaat | Missing | Taskaat | **Yes**: the Taskaat link | 3, plus Taskaat's side |
| A11 Receiving, waste, bank | Missing; core has no stock or ledger | Operations | **Yes**: stock, purchasing, bank, ledger | 6+ (Q6) |
| A12 Reports, agent notifications | Missing | Operations (reports), Agent notifications | **Yes**: reports read the ledger; notifications per agent | 2 + 1 |
| A13 Lottie and Animations | Missing | Foundation (F5), then Moments | **Yes**: one preference key | 2 + 1-2 |
| W1 whity.dev | Partial (Astro site exists, 2.x design) | Websites, Public endpoints | **Yes**: `GET /api/public/plans` | 2 + 1 server |
| W2 health.whity.dev | Partial (Worker exists, own look) | Public endpoints | — | 1 |
| W3 id.whity.dev | Partial (whity-id has sign-in) | Whity ID | **Yes** (in whity-id): passkeys, Apple, Google | 2, in whity-id |
| W4 brand and W5 docs | Partial | Websites | — | 1 each |
| W6 Company pages and footer | Partial (security, privacy, license, 404 exist) | Websites, Public endpoints | **Yes**: `POST /api/public/contact` | 2 + 1 server |
| W7 Installer | Partial (`/install` page exists; the script doesn't) | Public endpoints | — | 2 |
| W8 Launch check | Missing | Websites | — | 1 |
| E1 Fifteen emails | Partial (one branded layout; 8 emails built on it) | Foundation (F6), then Emails | **Yes**: several senders | 1 + 3 |

### Editor M0-M8

Paths: `packages/ui/src/editor/` (the editor), `packages/features/src/document-editor/` (the screen), `src/Core/Document/` (server), `web/app/(protected)/admin/document-sources/[id]` (A33).

- **M0-M5 done.** M0 #1409, M2 #1416, M4b #1434, M4d #1471, M5 #1519 (migrations 183-186). M1, M3, M4 and M4c are in the history between them. The 3.3 editor cards changed only names. Each later milestone still owes its screenshot pass in both themes and Arabic.
- **M6 Agents, missing.** Cards `EditorAgents`. Lands in `packages/ui/src/editor/bubble.tsx` (Rewrite in the selection bubble), a new `agent-panel.tsx`, `collab-rail.tsx` for @agent replies. Server: MCP tools `documents.read`, `documents.suggest_blocks`, `documents.suggest_rewrite`, `documents.reply_comment` writing only `document_suggestions` and comments with the agent's actor and the person it acts for (M0 notes 3.4); a streamed run with Stop (`AgentRunStatus` exists in `packages/ui`); routines (E.32) on the scheduler. Fits: proposals, actors and per-permission modes are built (items 10, 11). 2 PRs.
- **M7 Plugin and saved blocks, missing.** Cards `EditorCustomBlocks`. Lands in `packages/ui/src/editor/` (registry, plugin-off placeholder, saved block node) and the SDK (`PluginDocumentBlocksInterface`, `PluginDocumentThemesInterface`). Server: block registry from manifests, `saved_blocks` and `saved_block_versions` tables, the next-publish rule. Doesn't fit: E.17 in 3.3 still says Verified plugins render natively with the SDK components; the register here says a Verified plugin is a signed declaration drawn by Whity's renderer, and publishers' JavaScript never runs in the app (owner, 3 Nov). The merged register keeps the owner's wording. 3 PRs, one SDK minor.
- **M8 Arabic, type, accessibility, partial.** Arabic labels and mirroring are in; `readex-pro-arabic-600.woff2` ships. Missing: the theme options title face and large print (E.46, E.47), an axe check on the editor (axe runs on components in `web/__tests__/*.a11y.test.tsx`, not the editor), a keyboard audit. 1-2 PRs.

### App A0-A6 (2.9, carried forward)

Done by the 2.9 milestones and server items S1-S8; the tables [below](#milestones-2) say what each covered. 3.3 changed these cards only in their example people, so nothing is owed against 3.3 beyond the open asks and these: the store pages (A5) move to Whity ID with the store; A6's Billing needs usage and the limit warning once designed.

### A7 Quick wins on built pages

| Card | Lands in | Now | Server |
| --- | --- | --- | --- |
| `TplApprovalsList` (AQ.1) | `web/app/(protected)/admin/approvals/page.tsx`, `DataTable` selection and bulk bar (A0) | One at a time | Bulk approve for tier-1 items only, one audit entry each |
| `TplGatingTwoStep` (AG.1) | `admin/approval-gating`, `admin/registrations` | Request dates and tiers are in (S3) | A two-step reset needing a second admin, never the requester (state table, migration); identity check by passkey or Face ID: WebAuthn exists only for document signing (`src/Core/Document/Signing/SigningSecondFactor.php`) |
| `TplAway` (OR.1) | `admin/delegations`, account menu | Delegations with names | "I'm away" as a dated delegation set from the person's own account |
| `TplReceipt80` (N8) | `src/Core/Document/Print` and the starters | 13 A4 starters | An 80 mm page size at 72 mm print width; the JoFotara QR belongs to Front Office (2 Nov), so core prints the receipt without it. **Q5** |

2-3 PRs.

### A8 Contacts and import

Cards `TplContacts`, `TplImport`, contacts in `TmOpsMobile`, `TmFinanceMobile`. Lands in a new `web/app/(protected)/contacts`, `packages/features/src/contacts`, `src/Core/Contacts`. Server: one `contacts` table (customer, supplier or both, tags through `src/Core/Taxonomy`), linked to the customer's person and profile where there is one (customer sign-up, Messages and Calendar each keep their own customer today); an import engine with a preview of create, update and skip and a 24-hour undo (the label-sheet matcher in `src/Core/Document/Print/LabelCsv.php` maps columns but reads CSV only; Excel needs a reader). Doesn't fit: items, opening stock and opening balances have no home in core; they belong to Front Office. 3 PRs.

### A9 Custom fields

Cards `TplCustomFields`, "Add a field" in `TmFinanceMobile`. Lands in settings (`web/app/(protected)/admin/settings`) and every list that shows them. Server: field definitions per record type and values (migration), reusing the form builder's kinds from `src/Core/Form`; exposure in lists, filters, document merge fields (documents already have their own fields, migration 163) and flow conditions. Doesn't fit: of the named record types only contacts would be core's (after A8); items are Front Office's, tasks are Taskaat's, and flows (`TplFlows`, `TplFlowBuilder`) aren't built. 2-3 PRs after A8.

### A10 Tasks and boards on Taskaat

Cards `TplTasks`, `TplBoard`, `TmTasks`, `TmBoards`. Lands in a new `web/app/(protected)/tasks` and `boards`. Server: a Taskaat connection per workspace (OAuth to Taskaat, a project per workspace or team), a proxy that reads and writes through Taskaat's API so there is no second task engine (T.1-T.4), "Open in Taskaat" links, and Taskaat tools reached from the workspace MCP. Nothing exists in core. Needs Taskaat's team to agree the API. 3 PRs here.

### A11 Receiving, waste and bank (N4-N6)

Cards `TplReceiving`, `TplAdjustments`, `TplBankRec` and their phone screens. In scope for 0.2.29, and the largest piece of it: core has no purchase orders, stock, bills, ledger or bank accounts, and Front Office is the paid product meant to have them (2.9 note P01). Whichever home is chosen (**Q6**), the server work is the same: purchase orders and receipts against them, supplier bills, the three-way match that blocks paying a mismatched bill, stock items, batches and movements, adjustments with approval above a value, bank accounts and statement lines with matching, and a ledger the month closes on at a zero difference. The Bookkeeping assistant's dashed suggestions are MCP tools that write proposals only. Lands in new `web/app/(protected)/receiving`, `adjustments`, `bank` and `src/Core/Stock`, `src/Core/Ledger`, or the same shape inside a Front Office plugin. 6+ PRs, serial.

### A12 Reports and agent notifications (N7, N9)

`TplReports` (profit and loss, balance sheet, ageing, stock value, sales tax) are read-only views of A11's ledger, so they land with A11, in its lane and its home: 2 PRs after the ledger exists. `TplAgentNotify` lands in core: agent notifications by email go through `MessagingService` (Messages lane), with the one-an-hour limit and no actions in the message; WhatsApp is the paid plugin's channel. Lands in `admin/agents` (per-agent setting) and `src/Core/Notification`. 1 PR here.

### A13 Lottie and the Animations setting (LT.4)

Cards `TplMoments`, `TmMoments`, `TmMotion`. Lands in a new `packages/ui/src/lottie.tsx` built on `handoff/motion/whity-lottie.js` (adds `lottie-web` or the light player), the moments in `empty-state.tsx`, the approvals set-down, first run and waiting states, and Account › Preferences (`web/app/(protected)/settings`). Server: an `animations` key (full, calm, off) beside text size and technical details in `src/Api/DisplayPreferencesApiHandler.php`. Fits. The 31 files are about 1 MB together; load them lazily. The player, the setting and the moments inside shared components are foundation F5 (2 PRs); moments that belong to one screen go with that screen's lane (1-2 PRs).

### Websites W1-W8

- **W1 whity.dev** (`whity-web` root, Astro): restyle to `SiteHome` and `SiteHomeAr`, the module pages, `ar/`. Server here: `GET /api/public/plans` from the plan bundles (ADR 0010, `src/Core/Plan`) plus a marketing layer (tagline, features, highlight, order, visible), CORS for `https://whity.dev`, `Cache-Control: public, max-age=300` (contract in `sites/README.md`). Feature claims must be checked against what has shipped. 2 PRs in whity-web, 1 here.
- **W2 health.whity.dev** (`ops/watchdog/src/index.js`, here): restyle to `SiteStatus` in both languages; the data is already real. 1 PR.
- **W3 id.whity.dev** (whity-id): `SiteId` sign-in, number-match approval, account. Passkeys, Apple and Google are whity-id work. 2 PRs there.
- **W4 brand.whity.dev** (`whity-web/brand-site`): `SiteBrand`, the brand kit linked to `handoff/logos/`. **W5 docs.whity.dev** (`whity-web/docs-site`): `SiteDocs`; Arabic articles don't exist, so each says "coming". 1 PR each.
- **W6 company pages and footer** (`whity-web`): Partners, About and Contact are new; Security, Privacy, Licence and 404 exist in the old look. Server here: `POST /api/public/contact` with rate limiting, sent through Messages so it lands in the operator's inbox. The privacy text waits for the lawyer. 2 PRs there, 1 here.
- **W7 installer:** `whity.dev/install.sh` as section 29 specifies (Docker or Node.js checks, one plain line when missing, a signed-in Whity at localhost:3000). The script is new; it could live in this repo next to `docker-compose.yml` and be published by whity-web. 2 PRs.
- **W8 launch check:** links, claims, `llms.txt` (whity-web generates one for docs today), `security.txt`, the brand kit. 1 PR.

### E1 The fifteen emails

Core has one branded layout (`src/Core/Mail/EmailLayout.php`, `EmailContent.php`, `EmailBranding.php`) and eight senders on it: verify email, reset password, invitation, two-step recovery, customer sign-up, document signing code, booking messages. 3.3's fifteen: verify email, reset password, invitation, new sign-in, two-step off, workspace ready, needs approval, daily summary, invoice, quote, receipt, payment reminder, payment failed, plan limit, Whity's own receipt. Lands in `src/Core/Mail/` (restyle the layout to `handoff/emails/`: 17px text, one button, Sent with Whity removable by plan) and the senders. Missing senders: new sign-in, two-step off, workspace ready, needs approval (no actions inside), daily summary, plan limit (from warn mode), and Whity's receipt (Whity Pay or billing). Invoice, quote, receipt, payment reminder and payment failed are documents sent through Messages; with JoFotara they are Front Office's. White-label sending from the workspace's domain uses the email domains settings. 3 PRs.

## Order inside 0.2.29

1. **Foundation** (F1-F6), one lane. Server first where it has any (F5's preference key).
2. **Every lane at once** once the foundation merges, each starting with its server PR: Editor, People and approvals, Contacts, Taskaat, Team chat, Calendar extras, Agent notifications, Emails, Public endpoints; Operations (Front Office) and Subscriptions (Whity Pay) in their plugins. Websites and Whity ID start at the same time in their own repositories.
3. **Moments** for single screens as each screen's lane finishes.
4. **W8 launch check** last: every feature claim on the sites against what 0.2.29 shipped.

## Questions for the owner

Nothing below is dropped from 0.2.29. The owner answered Q3, Q4, Q6, Q7, Q14 and Q16 on 12 Nov 2026; the rest keep their defaults until answered. These are the points that decide whether it can all land together, plus the design conflicts found.

| # | Question | Default until answered |
| --- | --- | --- |
| Q1 | The 3.3 package came from the brand team's 2.9, not the repo's: it drops E.40-E.47, the S.1-S.12 answers of 2-3 Nov, M4d, D1 and three snippet rules again. Ask the brand team to start 3.4 from this folder? | Merged three ways; the repo's decisions stand |
| Q2 | 3.3's snippet and `PLAN.md` say stop for approval between milestones. Your rule is merge on green. | Merge on green; the snippet says so |
| Q3 | `TplChat` is a team chat for people and agents. Core's Messages are customer conversations. It isn't a `PLAN.md` milestone; is it in 0.2.29, and on the Messages tables? | **Owner, 12 Nov: in core, in 0.2.29.** Its own Team chat lane, on the Messages tables |
| Q4 | `TplSubscriptions` sells plans to a tenant's customers; not a `PLAN.md` milestone. Core, or part of Whity Pay (paid)? | **Owner, 12 Nov: in Whity Pay, in 0.2.29** (whity-org/whity-plugins, `whity-pay/`) |
| Q5 | `TplReceipt80` shows the JoFotara QR; JoFotara is Front Office's (2 Nov). A core 80 mm receipt without it? | Core receipt without the QR; Front Office adds it |
| Q6 | **A11 and A12's reports need stock, purchasing, bank and a ledger, which core doesn't have.** This is the biggest piece of 0.2.29 (8+ serial PRs) and the one least likely to land with the rest. Core, or a Front Office plugin released alongside? | **Owner, 12 Nov: whichever performs best.** They perform the same: a code plugin runs in the same PHP process on the same raw `\PDO` connection and database, with its own tables, indexes and migrations, and can join core's tables (contacts) directly. Speed comes from the ledger's design (indexed movements, period balances), not its home. So the tie-break is the business model: **a Front Office code plugin**, in 0.2.29, in the whity-org/whity-plugins monorepo (owner, 12 Nov) |
| Q7 | `TplAppointments` has rooms, branches, check-in and requests to approve; the calendar has none. Add them in 0.2.29? | **Owner, 12 Nov: yes.** Its own Calendar extras lane |
| Q8 | The near-term pages (`TplContacts`, `TplImport`, `TplCustomFields`) name items, opening stock and balances. Only contacts in core? | Contacts in core; items, stock and balances follow Q6 |
| Q9 | The sites say Whity is hosted in the EU (WS.18). Is app.whity.dev in the EU today? | Check before W1 ships the claim |
| Q10 | The `Email*` cards and eight Arabic cards have no real dark screenshots. Ask the brand team? | Ask (with the other asks) |
| Q11 | W7's installer script: here or in whity-web? | Here, published by whity-web |
| Q12 | **A10 needs Taskaat's API and its team.** If Taskaat can't take OAuth and a per-workspace project in time, A10 can't land. | Start the Taskaat side now; A10 ships reading and writing tasks, boards follow if the API isn't ready |
| Q13 | **Editor M7 needs an SDK minor and the Verified wording** (E.17 in 3.3 says Verified renders natively; your 3 Nov answer says a signed declaration drawn by Whity). | The register's wording (yours); M7 ships with the declarative renderer |
| Q14 | **The Flutter mobile app has no repository** and isn't a `PLAN.md` milestone (section 28, L9). Is it in 0.2.29? If so, where (a new whity-org repo)? | **Owner, 12 Nov: not in 0.2.29.** Its foundation (`whity_brand.dart`, fonts) lands here |
| Q15 | **W3 lives in whity-id** and ships on its own release. Does 0.2.29 wait for it? | No; core doesn't depend on it |
| Q16 | **Size**: about 63 PRs across eleven lanes. A release with all of it will be later than 0.2.28's cadence. A date, or ship when the lanes are green? | **Owner, 12 Nov: release when every lane is green.** Operations (Q6) is the one to watch |
| Q17 | Designs still missing (theme picker WHIT-785, Messages inbox, calendar staff screens, the Billing limit warning, the user menu, Approvals Decided and Edit, impact plan, agent credential). Build to the nearest card in 0.2.29, or wait for the brand team? | Ask the brand team now; build to the nearest card if the design isn't back before the lane reaches it |

## For the design team to pick up

Everything in 2.9's list below still applies. New with 3.3: the dark `Email*` screenshots; whether `fonts/` should keep the 700 files; the rooms, branches and requests on the calendar against what's built; and starting 3.4 from this folder.

---

# Design 2.9 working plan (2 Nov 2026)

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
- The owner's answers of 2 Nov: section 24 and its cards should describe workspace agents with per-person assignments (Q3); the settings paragraph in the app map should follow W.1 and W.2 (Q1); `PLAN.md`, `HANDOFF-PROMPT.md` and the snippet should say pull requests merge on green and only a release needs the owner (Q7). The Verified rule in S.4 should say that only an operator or Whity's store can grant it (Q2).

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
| S7 | A5 | `plugins:install` split from `plugins:upload` (grant by capability); 30-day keep on uninstall; trust, price and usage data; update approval; one nav item. Per-workspace install if Q4 says so. **Verified (Q2):** a server-side mark only an operator or Whity's store can grant, never set by a plugin's manifest, an upload or an install; the verification process; what native access means for a plugin's code, and the mechanism that loads it, decided before any third-party plugin runs natively. Unverified plugins stay in the frame, off after install |
| S8 | A6 | Audit: names, sentences, agent filter, CSV. Tenants: plan and status. Remind for two-step. Plan change. Platform nav gates by system tenant. Email domains on a permission. 500 reference and maintenance signal. Inviter and role on an invitation. Amount in words in both languages |

## Questions for the owner

The two registers merged with no conflict: no row was changed on both sides. The real conflicts are between 2.9 and decisions recorded elsewhere in the repo, or between 2.9's own files. The owner answered Q1, Q2, Q3 and Q7 on 2 Nov 2026, as relayed by the orchestrator session. The others keep their defaults until the owner says otherwise.

| # | Conflict | Answer or default |
| --- | --- | --- |
| Q1 | **Settings.** D1 (31 Oct, app map) keeps Email, Storage, Languages and Feature flags in the settings area. W.1 and W.2 (2 Nov) move them to Platform and add Devices. Section 21 also calls a group "Workspace" | **Owner: follow 2.9.** Those four go under Platform, and Devices is added. D1's paragraph in the app map is out of date on this |
| Q2 | **Verified plugins.** M0 Q8 keeps native rendering to first-party blocks, with every third-party plugin in the frame. E.17, S.4 and S.12 say Verified runs natively and is active on install | **Owner: follow 2.9. This reverses M0 Q8.** Verified plugins run natively and activate on install. Nothing can become Verified by itself: only an operator or Whity's store can grant it. The frame stays as it is for everything else. The process, and what native access means for a plugin's code, are in S7. **Owner, 3 Nov: a Verified plugin is a signed declaration: a `plugin.json` drawn by Whity's block renderer, installed per workspace and active on install. Publishers' same-origin JavaScript isn't built.** See `VERIFIED-PLUGINS-SECURITY.md` |
| Q3 | **What an agent is.** Section 24 and its cards say an agent works for one person, and anyone can create one with everything on Ask first. The decided plan (`AGENT-PERSONAS-AND-WHITY-AGENT.md`) has workspace agents under `agents:manage`, per-person assignments, and reads that can start Allowed | **Owner: keep the 1 Nov decision, workspace agents as built.** "Works for" reads from assignments and Everyone's agents lists the workspace's agents. The design team should correct section 24 |
| Q4 | **Where a plugin installs.** S.1 installs into one workspace. The plugin lifecycle looks install-wide (`PluginsApiHandler`) | **Checked: it is install-wide. Owner, 3 Nov: workspaces install declarative plugins only; code plugins are operator-only and install-wide.** S.1, S.3 and S.9 now say so |
| Q5 | **The form builder.** F.1 draws a canvas. Forms are descriptors so desktop and mobile share them | A web-only React builder; filling in keeps working on every platform |
| Q6 | **Routes.** The app map has agents under `/admin/ai-principals` and the designer at `/admin/documents` | The built `/admin/agents`, `/admin/my-agents` and `/admin/document-sources/[id]` |
| Q7 | **Approval between milestones.** `PLAN.md` and the snippet say to stop. The owner's standing rule is merge on green, with only a release needing approval | **Owner: the standing rule.** `PLAN.md` now says so. `CLAUDE.md` and `CLAUDE-snippet.md` change once the owner confirms it in the session making the edit |
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
| 8 | **Native rendering for first-party blocks only.** Reversed by the owner on 2 Nov 2026: see Q2 above. | Every third-party plugin uses the sandboxed frame, verified or not, until a signed-module mechanism exists. |
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
