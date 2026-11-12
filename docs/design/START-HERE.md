# Whity design 3.3: start here

This zip is the whole Whity design (brand, web app, mobile app, the near-term pages, the websites) and the plan to build it. It is meant to be given to Claude Code, in the whity-core repository.

## 1. Put it in the repository

From the root of whity-core:

```
unzip whity-design-3.3.zip -d .
```

Everything lands in `docs/design/`, plus this `START-HERE.md` at the root (step 1 moves it into `docs/design/`). If an older `docs/design/` exists (2.2, 2.9 or 3.2), the zip replaces its files; keep anything the team added (for example `IMPLEMENTATION-NOTES.md`).

## 2. Give Claude Code this prompt

Copy everything between the lines into Claude Code, opened at the repository root.

---

You're organising and then building Whity from a finished design in `docs/design/` (version 3.3). Work carefully and in order, and stop where I say stop.

**Read first, in this order:**
1. `docs/design/HANDOFF.md`: what's in the folder, what's new since 3.2, open points, what isn't designed.
2. `docs/design/CLAUDE-snippet.md`: the hard rules.
3. `docs/design/00-decision-register.md`: the source of truth.
4. `docs/design/13-app-map.md`: every screen with its cards, then `12-engineering-handoff.md`.
5. `docs/design/PLAN.md`: the milestones (editor M0–M8, app A0–A12, websites W1–W8, emails E1), each with "done when".
6. Skim `docs/design/cards.html` to see what each card looks like, and `docs/design/sites/README.md`.

**Step 1 — organise (one pull request, no feature code):**
- Create a branch `design/3.3`. Move `START-HERE.md` from the repository root into `docs/design/`.
- Add `CLAUDE-snippet.md` to the repository's `CLAUDE.md`, replacing any older Whity design snippet.
- Check that `docs/design/` is complete: every card listed in `cards.html` has a folder in `components/` and two screenshots; every card named in `13-app-map.md` and `PLAN.md` exists. List anything missing.
- If the repository already has its own copies of tokens, fonts, icons or logos, list where they are and how they differ from `docs/design/handoff/`. Don't replace them yet.
- Find where each website lives (whity.dev, health.whity.dev, id.whity.dev, brand.whity.dev, docs.whity.dev). If one isn't in this repository, say so; don't create new repositories.
- Write `docs/design/IMPLEMENTATION-NOTES.md`: for each milestone in `PLAN.md`, what's already done, partly done or missing in the code, where each card lands (file paths), the server work it needs (tables, APIs, MCP tools, the Taskaat link), anything in the design that doesn't fit the code, and an order and an estimate. Put anything that looks wrong or impossible under "Questions for the owner".
- Don't change the design files and don't change `00-decision-register.md`.
- Open a pull request with the `CLAUDE.md` change and the notes. **Stop and summarise.**

**Every later milestone (after I approve):** one branch and pull request per milestone, in the order of `PLAN.md` unless I say otherwise; build to the cards; compare what you build with `docs/design/screenshots/` at the sizes in `cards.html`, in light and dark, and in Arabic where there's a card; run the tests; check each "done when"; stop for review before the next.

**Hard rules:** the register wins; tokens only; web text 15px and phone text 17px, nothing below 13px, no weight 700; plain words, technical details only on request; names, never ids; agents only suggest or propose, never change things directly; tasks live in Taskaat; every string has an English default and an Arabic label; the name is "Whity" in Latin letters; example companies, the Whity team as people, and `[price]` placeholders; Whity is a global tool hosted in the EU (WS.17, WS.18); Lottie only where LT.4 says, never blocking, with the Animations setting respected.

---

## 3. What's in here, in one look

| Want to… | Open |
| --- | --- |
| See every screen | `docs/design/cards.html` (open in a browser) |
| Know why something looks the way it does | `docs/design/00-decision-register.md` |
| Know what to build next | `docs/design/PLAN.md` and `28-roadmap.md` |
| Find a screen's design | `docs/design/13-app-map.md` → `components/<Card>/` |
| Build the mobile app | `docs/design/26-mobile.md`, the `Tm*` cards, `handoff/flutter/` |
| Build the websites | `docs/design/29-websites.md`, `docs/design/sites/` |
| Build the emails | `docs/design/handoff/emails/` (15 emails, English and Arabic) |
| Add the animations | `docs/design/handoff/motion/` (31 Lottie files, the web player, Flutter code) and `06-motion.md` |
| Read it all as one document | `docs/design/Whity-Brand-Guidelines-v3.3.pdf` |
| Get the logos, fonts, icons | `docs/design/handoff/`, `docs/design/fonts/` |
