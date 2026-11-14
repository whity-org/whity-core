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
