# Whity design 3.5: start here

This zip is the whole Whity design (brand, web app, the web app on a phone, mobile app, the near-term pages, form printouts, emails, the websites) and the plan to build it. It is meant to be given to Claude Code, in the whity-core repository.

## 1. Put it in the repository

From the root of whity-core:

```
unzip -o whity-design-3.5.1.zip -d .
```

Everything lands in `docs/design/`, plus this `START-HERE.md` at the root (step 1 moves it into `docs/design/`). The zip replaces the 3.4 files in `docs/design/`; it doesn't contain the team's own files (`IMPLEMENTATION-NOTES.md`, `DESIGN-ASKS.md`, the agent and MCP notes), so those stay as they are.

## 2. Give Claude Code this prompt

Copy everything between the lines into Claude Code, opened at the repository root.

---

You're updating Whity's design in `docs/design/` from 3.4 to 3.5 and folding the new work into the build plan. Work carefully and in order, and stop where I say stop.

**Read first, in this order:**
1. `docs/design/HANDOFF.md`: what's new since 3.4.
2. `docs/design/CLAUDE-snippet.md`: the hard rules (two are new: the calendar and one chat).
3. `docs/design/00-decision-register.md`, the 3.5 sections: CV (the calendar) and UC (one chat).
4. `docs/design/27-work-and-operations.md` and `docs/design/13-app-map.md`: where the new screens live and their cards.
5. `docs/design/PLAN.md`: new milestones A16 and A17.

**Step 1 — organise (one pull request, no feature code):**
- Create a branch `design/3.5`. Move `START-HERE.md` from the repository root into `docs/design/`.
- Replace the Whity design snippet in `CLAUDE.md` with `CLAUDE-snippet.md`.
- Check that every card named in `13-app-map.md` and `PLAN.md` has a folder in `components/` and two screenshots. List anything missing.
- Update `docs/design/IMPLEMENTATION-NOTES.md`: add A16 and A17 with what's done, partly done or missing, where each card lands, the server work (the merged chat model, bots, blocking, read receipts per channel, the calendar's per-person settings), and an order and estimate. Mark A15's Messages and phone calendar work as replaced by A16 and A17 where it hasn't started. Put anything that looks wrong under "Questions for the owner".
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
| Build the calendar | `docs/design/27-work-and-operations.md`, the `TplCalendar*` cards |
| Build chat | `docs/design/27-work-and-operations.md`, the `TplChat*` cards |
| See what changed since 3.4 | `docs/design/HANDOFF.md` |
| Build the websites | `docs/design/29-websites.md`, `docs/design/sites/` |
| Themes | `docs/design/30-themes.md`, register "Themes (version 3.6)" |
| Build the emails | `docs/design/handoff/emails/` (25 emails, English and Arabic) |
| Add the animations | `docs/design/handoff/motion/` (31 Lottie files, the web player, Flutter code) and `06-motion.md` |
| Read it all as one document | `docs/design/Whity-Brand-Guidelines-v3.5.pdf` |
| Get the logos, fonts, icons | `docs/design/handoff/`, `docs/design/fonts/` |
