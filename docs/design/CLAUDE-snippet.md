## Design system (docs/design)

- `docs/design/00-decision-register.md` is the source of truth for every visual and interaction decision. If code, a card or a guideline section disagrees with it, the register wins. Never change a decided value in code without changing the register first, and ask the owner before changing the register.
- Use tokens, never raw colours or Tailwind palette classes (`blue-500`, `emerald-600`, …). Tokens: `docs/design/tokens.json`, `handoff/web/tokens.css`, `handoff/web/theme.css`; Flutter: `handoff/flutter/`.
- **Sized for people over 50 (A.1–A.3):** web text 15px, nothing below 13px, controls 40px (32 small, 44 large), table rows 48px. Printed documents and the editor sheet keep their own scale (16px on the sheet, A.1, E.33). Phone (section 26): text 17px, targets 48px, frequent actions under the thumb. Common actions are labelled buttons; ⋯ holds only rare or destructive ones; icon-only buttons always have a label. Plain words everywhere; permission codes, ids and stack traces appear only when the person turns on Show technical details.
- Type: Noto Sans (Noto Sans Arabic); Readex Pro 600 only for document titles, brand headlines and the websites, never in product screens; Geist Mono for codes and amounts. **No font weight 700** anywhere (bold is 600).
- One filled main button per view, and it is the only thing on the plane (the hard offset down and to the left). Secondary buttons are outline.
- People appear as names with avatars, never as ids. Money is typed and shown in normal units with the currency's decimals (JOD 49.000), never minor units.
- Pages follow section 21: list pages, record pages (header, tabs, edit in place with the save bar), the six states, and confirming by how undoable an action is.
- Agents never change anything directly: what they do is a suggestion or a proposal a person accepts, attributed to the agent and the person it acts for, with the permission it used. Dashed means "not happened yet". Agents are the robot face on the tint, never sparkles. In Chat they appear as members, clearly marked.
- Tasks and boards are Taskaat's (T.1–T.4): Whity shows and edits them through Taskaat; don't build a second task engine.
- Every visible string has an English default and an Arabic label. Use logical CSS properties; numbers, codes and amounts stay Western digits and left to right. The name is always "Whity" in Latin letters: no Arabic name or logo.
- Example data uses Example Bakery and friends for companies and the Whity team for people (Amro Saleh, Awss Haddad, Ahmad Khalil, Ibrahim Mansour, Emad Nasser; WS.4); plan names and prices stay `[plan name]` and `[price]`.
- The reference for screens is `docs/design/components/` (each card's `README.md`, `index.html`) and `docs/design/screenshots/`; `13-app-map.md` maps each screen to its cards. Compare built screens with the screenshots in both themes (and Arabic where there's a card) before calling UI work done.
- Work one milestone per branch and pull request (`PLAN.md`). Pull requests merge on green CI; only a release into main needs the owner.
- The document editor: section 18, `docs/design/handoff/editor/` (contract and TipTap mapping) and `docs/design/PLAN.md`.
- **Global, hosted in the EU** (WS.17, WS.18): never describe Whity as built for one country; say "hosted in the EU" or "stored in the EU", not "GDPR compliant".
- **Motion** (06-motion, LT.4): Lottie only at the moments listed, one scene at most on a screen, never blocking, colours from the tokens, last frame for Calm, Off and reduce motion; loops only while really waiting.
- **Emails** (EM.1): build from `handoff/emails/`; one main button, 17px text, the workspace's brand, no actions inside approval emails.
