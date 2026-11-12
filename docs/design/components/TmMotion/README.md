# Mobile motion and resistance

Every animation explains an interaction (M.19). Four of them:

- **Destructive swipe** (M.20, M.24): the row follows the thumb at about a third of its speed (thumb 120, row 40), so it feels heavy. The red grows as it goes; at **70%** a firm haptic tick marks the point of no return. Let go before it and the row springs back; let go after and it's deleted, with **Undo** for 10 seconds. Declining works the same way.
- **Tier 3 won't move** (M.21): a swipe on a tier-3 card (clearing two-step, approving a password reset, anything irreversible) moves it 10px at most; it tilts slightly, shudders twice and settles back, with two short buzzes. A lock badge shows on the card, and its main button reads **Verify to approve**.
- **Then it asks:** the verification sheet rises: "This needs you to verify", why it can't be swiped, **Verify with Face ID** (M.8), Not now. After Face ID the full decision opens to check before approving.
- **Approve sets it down:** the story's motion (`TmOnboardStory`) for real: the dashed card drops onto its plane and turns solid in 200 ms, Undo shows, and the next card slides up from below.

Timing: ease-out, 200 to 400 ms; springs are critically damped for resistance (no bounce on destructive actions). Reduce motion (M.25): movement becomes fades; resistance stays as haptics and colour; the tier-3 shudder becomes two buzzes and the sheet.
