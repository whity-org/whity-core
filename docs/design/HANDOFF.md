# Whity design system 2.9: handoff

This folder is the Whity brand and design system, exported for building in whity-core. It replaces 2.2. In whity-core it was merged into `docs/design/` rather than unzipped, to keep decisions the repository already had; `IMPLEMENTATION-NOTES.md` lists them.

**Source of truth:** `00-decision-register.md`. Every value in this folder comes from it. Where anything disagrees with the register, the register wins. Change the register first, then the files.

## What's new since 2.2

- **2.3 Plugin store** (S.1–S.12, section 19), aligned with the built app (`plugins:install` and `plugins:upload`, one sidebar item, operator tools).
- **2.4 Home** (H.1–H.4, section 20): one page per person, led by what waits for them; server statistics move to Platform › System.
- **2.5 Page patterns and People** (P.1–P.4, V.1–V.4, section 21): list pages, record pages with tabs and the save bar, the six page states, confirming by how undoable an action is; Inbox and every People screen.
- **2.6 Forms** (F.1–F.4, section 22), **2.7 Documents and routing** (D.1–D.4, section 23), **2.8 AI and agents** (AI.1–AI.4, section 24), **2.9 Workspace, billing and settings** (W.1–W.4, section 25).
- **Ease of use** (A.1–A.4): management screens are used mostly by people over 50. Product text is **15px**, nothing below **13px**, controls **40px** (32 small, 44 large), table rows **48px**; common actions are labelled buttons; plain words everywhere, with codes, ids and stack traces only behind **Show technical details**. Tokens, components and every card are updated.

With 2.9, **every screen in the web app inventory of 1 November 2026 has a design** (`13-app-map.md` lists each screen with its cards).

## What's here

| Path | What it is |
| --- | --- |
| `00-decision-register.md` | Every decision with its number |
| `README.md`, `01-…` to `25-…md` | The guideline sections. 12 is the engineering handoff; 13 the app map; 18 the document editor; 19–25 the app screens |
| `Whity-Brand-Guidelines-v2.9.pdf` | The same guideline as a PDF, with screens |
| `tokens.json`, `tokens.css`, `fonts/` | Design tokens (light and dark) and fonts |
| `handoff/web/`, `handoff/flutter/` | CSS variables, typed tokens, Tailwind v4 additions; the Flutter brand extension |
| `handoff/editor/` | The document editor contract and its TipTap mapping |
| `handoff/icons/`, `handoff/logos/`, `assets/` | Icons, logos, social images, motion |
| `components/bundle.js`, `bundle.css`, `index.d.ts` | The reference React implementation (`window.Whity`) and its types |
| `components/<Name>/` | One folder per card: `README.md` (the spec), `preview.html`, `index.html` (opens on its own; add `#theme=dark`) |
| `cards.html` | Every card with links and sizes |
| `screenshots/` | Every card in both themes: the visual target |
| `PLAN.md` | The build plan: the editor milestones, then the app screens |
| `CLAUDE-snippet.md`, `HANDOFF-PROMPT.md` | Rules for `CLAUDE.md`, and the prompt to start with |

## What isn't designed

Agent notifications (email, push, WhatsApp), the mobile approval screen, the 80 mm thermal receipt, the plugin publisher's page and the public marketplace. Server-side designs (tables, APIs, MCP tools, sandbox protocol) are for the team to propose; `PLAN.md` asks for them first.
