# Whity design system 3.5: handoff

This folder is the Whity brand and design system, exported for building. Unzip it at the root of whity-core so it lands in `docs/design/`. It replaces 3.4 (and 3.3, 3.2, 2.9, 2.2). Keep the files the team added (`IMPLEMENTATION-NOTES.md`, `DESIGN-ASKS.md` and the like); the zip doesn't contain them.

**Source of truth:** `00-decision-register.md`. Every value in this folder comes from it. Where anything disagrees with the register, the register wins. Change the register first, then the files, and only with the owner's agreement.

## 3.5 update, 15 November 2026: US dollars

The owner chose US dollars everywhere (register CU.1–CU.5).
- Money is written $312.00: the symbol, then the number, 2 decimals, in English and Arabic. Currencies without a symbol keep their code (JOD 312.000).
- `Money`, `MoneyInput`, `Doc`, `DocAmount` and the document tables now take the currency from the workspace (default USD). New exports: `Whity.currencies`, `Whity.moneyText(amount, currency)`, and `Whity.amountInWords(amount, lang, currency)` with cents (dinars keep fils).
- Every card, email, website page and doc was switched; sample amounts were rounded to cents so receipts and invoices still add up. Screenshots and the guide PDF are rebuilt.
- To build: drop any hard-coded 3 decimals; read decimals from the currency. Keep JoFotara and the 16% sales tax for Jordanian workspaces.

## What's new since 3.4

- **The calendar, like Google Calendar** (CV.1–CV.11, section 27): the sidebar starts as the rail on calendar screens; a left panel with a mini month and people and rooms as coloured checkboxes; Colour by person, service or room, each person's choice; Day, 4 days, Week, Month and Year; details beside a booking; click or drag to create, drag to move, pull to resize, the customer asked first and Undo; the phone's Day, 3 days and Month. Cards `TplCalendarWeek`, `TplCalendarViews`, `TplCalendarEditing`, `TplCalendarPhone`; `TplCalendarStaff` is now the booking sheet and setup.
- **One chat** (UC.1–UC.13, section 27): team Chat and customer Messages are one. Three panes; bubbles or compact rows; replies, threads, reactions, mentions, pins, search, files, photos, voice with its text, seen and typing; blocks inside messages; bots set by the workspace; groups with people, agents and bots; blocking unwanted senders. Cards `TplChatHub`, `TplChatCompact`, `TplChatParts`, `TplChatPhone`. They replace 3.4's `TplMessagesInbox`, `PwMessages` and `PwCalendar`.
- Cards drawn before 3.5 still say Messages in the sidebar; build it as Chat.
- **The guide PDF** is now 3.5 (279 pages).

## What was new in 3.4 (since 3.3)

Engineering's asks after 3.3 are answered one by one in `ANSWERS-3.4.md`.

- **The web app on a phone** (PW.1–PW.11, section 26, WHIT-1006): under 640px the web app uses the phone app's parts. Cards `Pw*`.
- **Screens with no card** (SC.1–SC.18): Messages, Approvals › Decided, impact plans, agent credentials, whity-agent, saved blocks, welcome and joining, sign-up and public-form settings, plan limits, document themes, issuing a document, the two-column sign-in, Whity ID. Every Settings card now carries the full D1 sub-nav.
- **Every surface** (SU.1–SU.10): the verify page a QR opens, payment return, the calendar on a computer, the agent persona editor, a customer's own booking page, WhatsApp and SMS templates, link previews and the installer's terminal output. Every row of `13-app-map.md` now names a card.
- **Form printouts** (FP.1–FP.12, `handoff/print/FORM-PRINTOUTS.md`): any form prints a record that doubles as the approval certificate; six section heading styles; paid plans and trials customise every part, free workspaces print the standard layout. Cards in "Documents · Form printouts".
- **Emails**: 25 now (ten new: bookings, forms, signing, joining, a message reply), English and Arabic, in `handoff/emails/`.
- **Decisions**: hosted in the EU is confirmed (Q34.1); saved blocks' per-document parts use stable keys (E.23a, `handoff/editor/whity-document.ts`); section 19 is the store (Q34.3).
- **Dark mode on every card**, Arabic and email cards included.

## What was new in 3.3 (since 3.2)

- **Whity is a global tool** (WS.17): the sites never say it's built for Jordan; Jordan appears as where Whity comes from and as the first example of a local feature (JoFotara, CliQ). **Hosted in the EU** (WS.18): servers, database, files (Cloudflare R2) and backups (Cloudflare) in the EU; no GDPR claim yet.
- **Websites, finished** (WS.6–WS.23, section 29): every home section; the four module pages; a mega footer on every page; Security, Partners, About, Contact, Install (a one-line installer to write, spec in section 29), Privacy, Licence and 404; `llms.txt` and the brand kit. A contact form contract (`POST /api/public/contact`).
- **Lottie** (LT.1–LT.4, `handoff/motion/`): 31 files recoloured live from the tokens, a web player and Flutter code. In the app: empty states, done moments, the first run, waiting and errors, and an **Animations** setting (Full, Calm, Off). Approving one item plays `approve-set-down`; bulk approval stays the 200 ms set-down in code.
- **Emails** (EM.1, `handoff/emails/`): 15 emails in English and Arabic, white-label, ready to fill.
- **The guide PDF** is now 3.3 and covers every section, with the register as an appendix.

## What was new in 3.2 (since 2.9)

- **3.0 Whity mobile** (M.1–M.47, section 26): the Flutter app for phones and tablets, designed for one hand: 17px text, 48px targets, everything frequent under the thumb, a left- or right-hand setting, fully mirrored Arabic, two panes on tablets, themed by each installation. Cards `Tm*` (group Mobile).
- **3.1 Work and operations** (T, MT, AP, IN, MO, SU, AA, CH, AQ, AG, FL, OR, DC, X, section 27): sixteen everyday areas on web and phone. Tasks and boards run on **Taskaat** (taskaat.dev), the task engine with done-when checks, evidence and a review gate. One **Chat** for people and agents, where agents join like teammates. Flows across everything. Cards `Tpl*` and `Tm*`.
- **Roadmap** (R.1, R.2, section 28): the near-term low-hanging fruit and the long-term plan toward a full ERP, inventory and project tool with low-code customization.
- **3.2 Near-term pages** (N1–N9, section 28): contacts, custom fields, spreadsheet import, receiving and supplier bills, waste and adjustments, bank reconciliation, basic reports, the 80 mm receipt, agent notifications by email and WhatsApp. Group "Templates · Near term".
- **3.2 Business model** (BM.1–BM.11, section 11): on Whity Cloud, free up to 2 staff and 100 customers a month, then plans, and no plan limits on a self-hosted installation; Whity Cloud, dedicated hosting or your own server; white-label down to the domain; partners; four public promises. Approved for now on 9 November 2026.
- **3.2 Websites** (WS.1–WS.5, section 29): whity.dev (companies first, a door for builders), brand, health, id and docs, as cards (group Websites) and as build-ready static pages in English and Arabic in `sites/`.

## What's here

| Path | What it is |
| --- | --- |
| `00-decision-register.md` | Every decision with its number |
| `README.md`, `01-…` to `29-…md` | The guideline sections. 12 is the engineering handoff; 13 the app map (every screen with its cards); 18 the document editor; 19–25 the app screens; 26 mobile; 27 work and operations; 28 the roadmap; 29 the websites |
| `PLAN.md` | The build plan: editor M0–M8, app screens A0–A17, form printouts P1, websites W1–W8, emails E1–E2, each with "done when" |
| `ANSWERS-3.4.md` | Answers to engineering's asks after 3.3 |
| `cards.html` | Every card, by group, with live links, screenshots and sizes |
| `components/<Name>/` | One folder per card: `README.md` (the spec), `preview.html`, `index.html` (opens on its own; add `#theme=dark`) |
| `components/bundle.js`, `bundle.css`, `index.d.ts` | The reference React implementation (`window.Whity`) and its types |
| `screenshots/` | Every card in light and dark: the visual target |
| `tokens.json`, `tokens.css`, `fonts/` | Design tokens (light and dark) and fonts |
| `handoff/web/`, `handoff/flutter/` | CSS variables, typed tokens, Tailwind v4 additions; the Flutter brand extension |
| `handoff/editor/` | The document editor contract and its TipTap mapping |
| `handoff/icons/` (156), `handoff/logos/`, `assets/` | Icons, logos, social images |
| `handoff/motion/` | 31 Lottie files with their rules, the colour names, the web player and Flutter code (LT.1–LT.4) |
| `handoff/emails/` | 25 emails, English and Arabic, with placeholders (EM.1, SU.8) |
| `handoff/print/` | Form printouts: the engine's needs, theme options and blocks (FP) |
| `sites/` | The websites as static HTML in English and Arabic (20 pages each), with their own `README.md`; whity.dev reads its plans from `GET /api/public/plans` with a fallback (WS.5); includes `llms.txt`, `.well-known/security.txt` and the brand kit zip |
| `business/` | The one-page business model (English and Arabic) sent to reviewers; decisions BM.1–BM.11 in the register |
| `Whity-Brand-Guidelines-v3.5.pdf` | The whole guideline as one PDF (279 pages), with screens; the register is the appendix |
| `CLAUDE-snippet.md`, `HANDOFF-PROMPT.md` | Rules for `CLAUDE.md`, and the prompt to start with |

## Open points the owner may still change

- The phone's fourth tab is **Chat** (it was Agents); reversible.
- In Arabic, the main button follows the left- or right-hand setting rather than the reading direction; open to change.
- Values set while drawing the near-term pages (register, "Near-term pages (version 3.2)").

## Waiting for the owner

- A client's own form printout (its crest and quality marks) is set up in that client's workspace, outside the shared system (FP.12).
- The lawyer-checked privacy policy (its place is marked on the Privacy page) and the bracketed retention numbers there.
- Plan names and prices (`[plan name]`, `[price]`).
- Before launch: check every feature claim on the websites against what has shipped.

## What isn't designed

The plugin publisher's page and the public marketplace; everything marked "To design" in section 28 (Studio, sales and point of sale, HR and payroll, projects, the report builder, integrations, platform features). Server-side designs (tables, APIs, MCP tools, the Taskaat link) are for the team to propose; `PLAN.md` asks for them first.
