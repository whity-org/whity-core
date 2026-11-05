## Design system (docs/design)

- `docs/design/00-decision-register.md` is the source of truth for every visual and interaction decision. If code, a card or a guideline section disagrees with it, the register wins. Never change a decided value in code without changing the register first, and ask the owner before changing the register.
- Use tokens, never raw colours or Tailwind palette classes (`blue-500`, `emerald-600`, …). Tokens: `docs/design/tokens.json`, `handoff/web/tokens.css`, `handoff/web/theme.css`.
- **Sized for people over 50 (A.1–A.3):** product text 15px, nothing below 13px, controls 40px (32 small, 44 large), table rows 48px. Printed documents and the editor sheet keep their own scale (16px on the sheet, A.1, E.33). Common actions are labelled buttons; ⋯ holds only rare or destructive ones; icon-only buttons always have a label on hover and focus. Plain words everywhere; permission codes, ids and stack traces appear only when the person turns on Show technical details.
- Type: Noto Sans (Noto Sans Arabic); Readex Pro 600 only for document titles and brand headlines, never in product screens; Geist Mono for codes. **No font weight 700** anywhere (bold is 600).
- One filled main button per view, and it is the only thing on the plane. Secondary buttons are outline.
- People appear as names with avatars, never as ids. Money is typed and shown in normal units with the currency's decimals (JOD 49.000), never minor units.
- Pages follow section 21: list pages, record pages (header, tabs Overview / Access / History, edit in place with the save bar), the six states (loading, empty, filtered, error, read-only, access denied), and confirming by how undoable an action is (Undo, a dialog naming the consequence, or typing the name).
- Agents never change anything directly: what they do is a suggestion or a proposal a person accepts, attributed to the agent and the person it acts for, with the permission it used. Dashed means "not happened yet".
- Every visible string has an English default and an Arabic label. Use logical CSS properties; numbers, codes and amounts stay Western digits and left to right.
- Example data uses Example Bakery and friends; plan names and prices stay `[plan name]` and `[price]`.
- The reference for screens is `docs/design/components/` (each card's `README.md`, `index.html`) and `docs/design/screenshots/`; `13-app-map.md` maps each route to its cards. Compare built screens with the screenshots in both themes before calling UI work done.
- Work one milestone per branch and pull request (`PLAN.md`). Pull requests merge on green CI; only a release into main needs the owner.
- The document editor: section 18, `docs/design/handoff/editor/` (contract and TipTap mapping) and `docs/design/PLAN.md`.
