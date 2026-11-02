You're building Whity's screens in whity-core from a finished design in `docs/design/` (version 2.9). Work carefully and in order.

Read these first, in this order:
1. `docs/design/HANDOFF.md`: what's in the folder, what's new since 2.2, and what isn't designed.
2. `docs/design/CLAUDE-snippet.md`: the hard rules. Add it to the repository's `CLAUDE.md` (replacing an older Whity snippet if there is one) as the first change on your branch.
3. `docs/design/00-decision-register.md`: the source of truth, especially Ease of use (A.1–A.4) and sections 2.3–2.9.
4. `docs/design/12-engineering-handoff.md`, `13-app-map.md` and sections 19–25.
5. `docs/design/PLAN.md`: the editor milestones (M0–M8) and the app-screen milestones (A0–A6), each with "done when" checks.

Then do **survey only**:
- Create a branch `design/2.9-survey`.
- Compare the repository with section 12's changes (items 1–15) and with `13-app-map.md`, screen by screen, and write `docs/design/IMPLEMENTATION-NOTES.md`: what's done, partly done or missing; where each card lands in the code; anything in the design that doesn't fit the code; the server work each milestone needs; an order and an estimate per milestone.
- Don't write feature code, don't change the design files, and don't change `00-decision-register.md`. If something in the design looks wrong or impossible, write it under "Questions for the owner".
- Open a pull request with the notes and the `CLAUDE.md` change, then stop and summarise.

For every later milestone (after I approve): one branch and pull request; compare what you build with `docs/design/screenshots/` (light, dark, and Arabic where there's a card) at the sizes in `docs/design/cards.html`; run the tests; stop for review before the next.

Hard rules: the register wins; tokens only; 15px text, nothing below 13px, no weight 700; plain words, technical details only on request; names, never ids; agents only suggest or propose, never change things directly; every string has an English default and an Arabic label.
