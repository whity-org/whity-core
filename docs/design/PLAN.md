# Build plan

## Document editor

The editor is designed (section 18, decisions E.1–E.39, the Document editor cards, `handoff/editor/`). This plan turns it into code in whity-core, one milestone at a time. Each milestone is a branch and a pull request; pull requests merge on green CI, and only a release into main needs the owner (owner, 2 Nov 2026).

**Visual check for every milestone:** render the built screen at the card's size (see `cards.html`) in light and dark, and in Arabic where there's an Arabic card, and compare with `screenshots/`. Differences in spacing, colour, type or state are bugs unless the register says otherwise.

## M0 · Survey and server proposal (no feature code)

1. Read `docs/design/HANDOFF.md`, `00-decision-register.md`, section 12, section 18, `handoff/editor/README.md` and `whity-document.ts`.
2. Survey the repository: the apps, `packages/ui`, `packages/tokens` (or `src/design/tokens`), the documents and templates code behind `/admin/documents`, Whity Render, the MCP server, AI principals and permissions.
3. Write `docs/design/IMPLEMENTATION-NOTES.md`:
   - **Foundation status:** which of section 12's items 1–12 are done, partly done or missing.
   - **Mapping check:** where each part of `handoff/editor/README.md` lands in this repository, and anything in the design that doesn't fit the code as it is (say so; don't change the design).
   - **Server proposal:** tables and migrations (documents, versions, suggestions, decisions, comment threads, saved blocks, block registry), API routes, MCP tools for agents (read a document, suggest blocks, suggest a rewrite, reply to a comment), the permissions they need (`document:read`, `document:edit`, …), the plugin sandbox `postMessage` protocol, and how a print view reaches Whity Render.
   - **Dependencies and risks**, with an estimate per milestone.
4. **Stop and ask for approval.** Nothing below starts until the owner approves the notes.

Done when: the notes exist, cover every point above, and the owner has approved them.

## M1 · Foundation

The parts of section 12 the editor needs and the repository lacks: tokens v3 (with `tokens.json` and `handoff/web/theme.css`), the fonts including `readex-pro-arabic-600.woff2`, the 116 icons, and the `packages/ui` components the editor reuses (Button, IconButton with Tooltip, Menu, Popover, ToggleGroup, Dialog, Avatar, ActorChip, Badge, AgentRunStatus).

Done when: those components match their cards in both themes; no Tailwind palette classes or hard-coded colours remain in them; no font weight 700 anywhere.

## M2 · Editor core

TipTap set up in `packages/ui` with the schema from `whity-document.ts` for text blocks (paragraph, headings 1–3, quote, code, flat lists with levels, checklist, divider). The shell: header, toolbar, outline, continuous sheet, status bar. The / menu and + inserter, Markdown shortcuts with one-step undo, the selection bubble, links (⌘K with a selection only, E.36), the gutter with drag and the block menu. Load and save `WhityDocument` JSON.

Done when: EditorShell, EditorBlockChrome, EditorInline (formatting and links) and EditorInsert match; a document round-trips through the JSON contract without loss (unit tests); keyboard paths in the cards' READMEs work.

## M3 · Rich blocks

Callout (Note, Important), toggle, columns, table (document table), image, file, page break, record and chart (live), signature and stamp, each with its block toolbar (E.8, E.13–E.16).

Done when: EditorBlocks matches; each block has its toolbar; record and chart blocks read within the viewer's permissions.

## M4 · Templates

Merge fields (the `field` node, chips, `{{` and Field ▾), preview with a record (E.12), the page view with pagination, letterhead, running header and footer (E.7, E.11), Draft and Publish with the publish check (E.9, E.37), PDF through Whity Render with values frozen at render time (E.16), the A33 Document designer screen.

Done when: EditorPageView, EditorInline (fields) and TplDocDesigner match; a template publishes only when the check is clear; a published template prints through Whity Render with field values.

## M4d · Document themes

Right after M4c (owner, 31 Oct 2026). The model in `DOCUMENT-THEMES.md` (E.44, E.45): the `document_themes` table, the resolver (Whity standard, plugin, workspace, document overrides), freezing the resolved theme at publish, the `/api/document-themes` routes with the new `documents:themes` permission, `documents.print_color` moved into the workspace theme, and the workspace main colour (2.8) stored server-side as the default accent. The theme picker and Document themes screen follow once their designs exist.

Done when: the editor's page view and a published template's PDF use the same resolved theme; changing a theme reaches a published template only at its next publish; no personal themes exist.

## M5 · Comments and suggestions

Modes (E.10), suggestion marks and block suggestions, the margin rail and the drawer under 1024px (E.6), comment threads with @mentions and resolve, accept and reject with logging (E.29), comment highlights on screen only (E.39).

Done when: EditorCollab matches; people without edit permission can only suggest; every decision is in the audit log with the actor.

## M6 · Agents

Ask an agent, Rewrite, @agent in comments, streaming with AgentRunStatus and Stop, results always as suggestions with the actor, the person it acts for and the permission used (E.4, E.30, E.31), routines (E.32). Requires section 12 items 10 and 11.

Done when: EditorAgents matches; no code path lets an agent change a document without a suggestion; agents read only the document and what it references, within both permission sets.

## M7 · Plugin and saved blocks

The block registry and plugin manifest, native rendering for verified and first-party plugins and the sandboxed frame for others, print views, the plugin-off placeholder (E.17–E.20). Plugin document themes (`PluginDocumentThemesInterface`, `DOCUMENT-THEMES.md` 7) ship in the same SDK release. Saved blocks: save from a multi-block selection, synced or copy, sharing, per-document parts, detach, the library, and the next-publish rule (E.21–E.24, E.38).

Done when: EditorCustomBlocks matches; a sandboxed block can't reach the page outside its frame; a disabled plugin keeps its data and blocks publishing.

## M8 · Arabic, type and accessibility

Arabic labels throughout, the mirrored shell, per-block direction, Readex Pro Arabic titles (E.35), the screen and print type scales (E.33), the theme type options title face and large print (E.46, E.47), full width (E.34), keyboard access to every action, screen reader names, reduced motion.

Done when: EditorArabic and EditorType match; an axe check passes on the editor; every toolbar and menu action works from the keyboard.

Live collaboration (cursors, presence, version history) comes after M8 (E.3).


# App screens: build plan (2.3–3.2)

Every screen in the inventory now has cards (`13-app-map.md`). Build them after the foundation (editor M1) is in, one milestone per branch and pull request, comparing with `screenshots/` in both themes and in Arabic where there's an Arabic card.

## A0 · Ease-of-use foundation
Tokens 2.9 (15px text, 13px floor, 40px controls, 48px rows), the collapsible sidebar groups, Show technical details and text size as per-person preferences (W.3), the page states and confirmation tiers (section 21) as shared components. Done when: `PatternStates` and `PatternConfirm` match; no text below 13px; no native dropdowns.

## A1 · Home, People and Inbox (sections 20, 21)
Done when: `TplHome`, `TplInbox`, `TplUsers`, `TplUserRecord`, `TplRoles`, `TplRoleRecord`, the drawers and Approval gating match; no ids where names belong; every risky action uses its tier.

## A2 · Forms (section 22)
Done when: the builder, filling in with server drafts, the public form in the workspace brand, responses and My submissions match; all form text is translatable.

## A3 · Documents and routing (section 23)
Done when: the library, the document record with Document, Routing, History and Verification tabs, templates, notes and wiki, route templates and the top-down flow editor match; the old circulation address redirects.

## A4 · AI and agents (section 24)
Needs section 12 items 10 and 11. Done when: Agents, presets, an agent's page, Connected AI apps with the connect wizard, and What AI can do match.

## A5 · Plugin store (section 19)
Done when: store, plugin page, install dialog and Installed tab match; `plugins:install` and `plugins:upload` are separate.

## A6 · Workspace, billing and settings (section 25)
Done when: the Settings sub-nav and its pages, your account with text size, billing, the audit log in sentences, and the Platform pages for operators match; money in normal units; Promotions default to JOD.

## A7 · Quick wins on built pages
The Approvals list with bulk approval for tier-1 items only (AQ.1, `TplApprovalsList`); approval gating with request dates, identity checks, Face ID or passkey, and a second admin for two-step resets (AG.1, `TplGatingTwoStep`); Delegations in names, with I'm away (OR.1, `TplAway`); the 80 mm thermal receipt (N8, `TplReceipt80`). Done when: those cards match, the receipt prints at 72 mm with the JoFotara QR in English and Arabic; a two-step reset can't be completed by one admin or by the requester.

## A8 · Contacts and import
Customers and suppliers as one record type with tags and a record page; spreadsheet import (CSV, Excel) with column matching for contacts, items, opening stock and opening balances, reusing the label-sheet matcher. Done when: `TplContacts`, `TplImport` and the contact screens in `TmOpsMobile` and `TmFinanceMobile` match (N1, N3); an import shows what will be created, updated or skipped before anything changes, and can be undone for 24 hours.

## A9 · Custom fields
Fields added to existing records (items, contacts, tasks), using the form builder's kinds, shown in lists, filters, document merge fields and flow conditions. Done when: `TplCustomFields` and "Add a field" in `TmFinanceMobile` match (N2); a new field is usable at once in lists, filters, a document template and a flow condition, without code.

## A10 · Tasks and boards on Taskaat
Tasks and Boards read and write Taskaat (T.1–T.4), worked out with Taskaat's team: done-when checks, evidence, the review gate, decisions. Done when: `TplTasks`, `TplBoard`, `TmTasks` and `TmBoards` match against a live Taskaat project, and "Open in Taskaat" opens the same task.

## A11 · Receiving, waste and bank (N4–N6)
Receiving against a purchase order with the phone's scanner and the three-way match (`TplReceiving`); waste and stock adjustments with approval above a value (`TplAdjustments`); bank reconciliation with the Bookkeeping assistant's dashed suggestions (`TplBankRec`). Done when: those cards and their phone screens match; a mismatched bill can't be paid until someone resolves it; the month closes only at a zero difference.

## A12 · Reports and agent notifications (N7, N9)
Profit and loss, balance sheet, ageing, stock value and sales tax (`TplReports`), as read-only views of the accounts; agent notifications by email and WhatsApp (`TplAgentNotify`). Done when: the cards match; a notification only opens Whity (no actions in the message) and an agent sends at most one an hour, except sign-in approvals.

## A13 · Lottie in the app and the Animations setting (LT.4)
The player (`handoff/motion/whity-lottie.js` pattern, or `lottie` in Flutter) with colours from the tokens; the moments in `TplMoments` and `TmMoments`; single approvals play `approve-set-down`, bulk stays in code; the Animations setting (Full, Calm, Off) in Account › Preferences and on the phone. Done when: each moment plays once as specified, never blocks a button, follows the workspace's colour and dark mode, and shows its last frame for Calm, Off and reduce motion.

Everything after A13 is in the long-term roadmap (section 28): the 3.1 modules (L8) and Whity mobile (L9) are designed and wait there.


# Websites (3.3)

The public sites (section 29, WS.1–WS.3). Build-ready static pages are in `sites/`, in English and Arabic. Each site lives where it lives today (whity.dev as a static site, id.whity.dev as the identity provider's templates, the status page apart from Whity); port the pages into that stack and keep the class names and structure. One milestone per site, in this order.

## W1 · whity.dev
Done when: the page matches `SiteHome` and `SiteHomeAr` at 1440 wide and has no sideways scroll at 390; every feature claim on it is checked against what has shipped (anything not shipped is removed or marked "coming"); plans come from `GET /api/public/plans` (WS.5, contract in section 29) and the page falls back to the saved, then the built-in plans when it doesn't answer; prices stay `[price]` until the owner sets them in Whity.

## W2 · health.whity.dev
Done when: it matches `SiteStatus`, reads the real status JSON, says in one sentence what isn't working, and stays up when Whity is down.

## W3 · id.whity.dev
Done when: sign in, approve on the phone and the account page match `SiteId` in both languages; passkeys, Apple and Google sign-in work.

## W4 · brand.whity.dev and W5 · docs.whity.dev
Done when: they match `SiteBrand` and `SiteDocs`; the brand kit download links to the current files in `handoff/logos/`; docs search works and every article has an Arabic version or says it's coming.

## W6 · The company pages and the footer
Security, Partners, About, Contact, Privacy, Licence and 404, and the mega footer on every page (WS.13–WS.23). Done when: each matches its `Site*` card in both languages at 1440 and 390; the footer's colour choice is remembered; the contact form posts to `POST /api/public/contact` (contract in section 29) and keeps the message if sending fails; the privacy page shows the lawyer's text once it exists.

## W7 · The one-line installer
`https://whity.dev/install.sh` as specified in section 29 (WS.20). Done when: on a clean Mac, Linux and Windows-with-WSL machine the line gets to a signed-in Whity at localhost:3000; with Docker or Node.js missing it stops with one plain line and changes nothing; the terminal on `SiteInstall` shows exactly what it prints.

## W8 · Launch check
Done when: every feature claim on every page is checked against what has shipped; no link on the sites is broken; `llms.txt`, `security.txt` and the brand kit are served.

# Emails (3.3)

## E1 · The fifteen emails (EM.1)
Build from `handoff/emails/` (English and Arabic, placeholders listed in its README). Done when: each email renders as its card (`EmailAccount`, `EmailMoney`, `EmailAgents`, `EmailWorkspace`, `EmailArabic`, `EmailDark`) in Gmail, Outlook and Apple Mail, light and dark; white-label removes "Sent with Whity" and sends from the workspace's own domain; security emails can't be turned off; approval emails only open the app.
