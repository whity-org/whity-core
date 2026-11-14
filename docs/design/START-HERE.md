# Whity design 3.4: start here

This zip is the whole Whity design (brand, web app, the web app on a phone, mobile app, the near-term pages, form printouts, emails, the websites) and the plan to build it. It is meant to be given to Claude Code, in the whity-core repository.

## 1. Put it in the repository

From the root of whity-core:

```
unzip -o whity-design-3.4.zip -d .
```

Everything lands in `docs/design/`, plus this `START-HERE.md` at the root (step 1 moves it into `docs/design/`). The zip replaces the 3.3 files in `docs/design/`; it doesn't contain the team's own files (`IMPLEMENTATION-NOTES.md`, `DESIGN-ASKS.md`, the agent and MCP notes), so those stay as they are.

## 2. Give Claude Code this prompt

Copy everything between the lines into Claude Code, opened at the repository root.

---

You're updating Whity's design in `docs/design/` from 3.3 to 3.4 and folding the new work into the build plan. Work carefully and in order, and stop where I say stop.

**Read first, in this order:**
1. `docs/design/HANDOFF.md`: what's new since 3.3, open points, what isn't designed.
2. `docs/design/ANSWERS-3.4.md`: the answers to your asks after 3.3.
3. `docs/design/CLAUDE-snippet.md`: the hard rules (three are new: the web app on a phone, public pages, form printouts).
4. `docs/design/00-decision-register.md`, the 3.4 sections: PW, SC, Q34 and E.23a, FP, SU.
5. `docs/design/13-app-map.md`: every route now names its cards.
6. `docs/design/PLAN.md`: new milestones A14, A15, P1 and E2.

**Step 1 — organise (one pull request, no feature code):**
- Create a branch `design/3.4`. Move `START-HERE.md` from the repository root into `docs/design/`.
- Replace the Whity design snippet in `CLAUDE.md` with `CLAUDE-snippet.md`.
- Check that every card named in `13-app-map.md` and `PLAN.md` has a folder in `components/` and two screenshots. List anything missing.
- Apply E.23a in the code that stores saved blocks if it already exists (stable keys; the one-time migration from positions), or note it for M7.
- Update `docs/design/IMPLEMENTATION-NOTES.md`: add A14, A15, P1 and E2 with what's done, partly done or missing, where each card lands, the server work, and an order and estimate. Close the design asks that 3.4 answers (WHIT-821, 826, 827, 828, 829, 830, 906, 915, 939, 945, 946, 1006) and say which card answers each. Put anything that looks wrong under "Questions for the owner".
- Don't change the design files or the register.
- Open the pull request. **Stop and summarise.**

**After that:** one branch and pull request per milestone, in the order of `PLAN.md` unless I say otherwise; build to the cards; compare with `docs/design/screenshots/` in light and dark, and in Arabic where there's a card; run the tests; check each "done when". Pull requests merge on green CI; only a release into main needs me.

---

## 3. What's in here, in one look

| Want to… | Open |
| --- | --- |
| See every screen | `docs/design/cards.html` (open in a browser) |
| Know why something looks the way it does | `docs/design/00-decision-register.md` |
| Know what to build next | `docs/design/PLAN.md` and `28-roadmap.md` |
| Find a screen's design | `docs/design/13-app-map.md` → `components/<Card>/` |
| Build the mobile app | `docs/design/26-mobile.md`, the `Tm*` cards, `handoff/flutter/` |
| Build the web app for phones | `docs/design/26-mobile.md` (“The web app on a phone”), the `Pw*` cards |
| Build form printouts | `docs/design/handoff/print/FORM-PRINTOUTS.md`, the `DocForm*` cards, `TplFormPrintStyle` |
| See what changed since 3.3 | `docs/design/HANDOFF.md`, `docs/design/ANSWERS-3.4.md` |
| Build the websites | `docs/design/29-websites.md`, `docs/design/sites/` |
| Build the emails | `docs/design/handoff/emails/` (25 emails, English and Arabic) |
| Add the animations | `docs/design/handoff/motion/` (31 Lottie files, the web player, Flutter code) and `06-motion.md` |
| Read it all as one document | `docs/design/Whity-Brand-Guidelines-v3.4.pdf` |
| Get the logos, fonts, icons | `docs/design/handoff/`, `docs/design/fonts/` |
