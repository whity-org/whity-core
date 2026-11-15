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
