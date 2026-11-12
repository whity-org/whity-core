# Motion

Short, calm and only where it explains a change. Everything settles on one curve, without bounce.

| Token | Duration | Use |
| --- | --- | --- |
| `dur-fast` | 120 ms | Hover, press, focus, the button lift |
| `dur-base` | 180 ms | State changes: switches, tabs, selection, the bulk bar rising |
| `dur-slow` | 250 ms | Things entering: dialogs, drawers, the mobile sidebar |
| `ease-out` | cubic-bezier(0.2, 0, 0, 1) | Every transition |

## The signature moment: set down

When a person approves an agent's proposal, the loose sheet is set down on the platform: the dashed edge turns solid in the main colour and the plane slides in underneath. Approving **one** item plays the Lottie `approve-set-down.json` (1.2 s, LT.4); **bulk** approval stays the **200 ms** set-down in code (decision 5.4). Undo plays it backwards and the card turns dashed again. File: `approve-set-down.mp4` in Motion; the exact values are in `handoff/motion/README.md`.

## Timed things

- **Toast with Undo**: 10 seconds, a thin bar runs down along its foot and pauses while the pointer or focus is on it.
- **Undo window**: after the toast, the activity item keeps Undo with a meter for the tool's window (for example 30 minutes for email).
- **Tooltips** appear after 500 ms on hover and at once on keyboard focus.

## Rules

- The main button lifts 1px up-right on hover as its plane grows from 3 to 5px, and presses back down when clicked. That is the only movement on buttons.
- Clickable cards lift onto the plane; informational cards never move.
- Floating layers fade and move 4–8px into place; drawers slide from the end edge (from the left in Arabic).
- Show `Skeleton` rows for loads over 300 ms instead of a spinner.
- The mark never animates in the product. On a splash screen it may set the sheet down once (200 ms).
- **Reduced motion** shows the end state only: no movement, toasts without the running bar, skeletons hold still.

## Lottie library (LT.1–LT.4)

Thirty-one real Lottie files live in `handoff/motion/` with their rules, colour names and code for the web and Flutter (its `README.md`). They play once when seen, never loop forever (except while loading), stop on their last frame when less motion is asked for, carry no text, and take their colours from the live tokens, so they follow dark mode and each workspace's colour. `approve-set-down` is the one people play themselves: Approve forwards, Undo backwards.

## Lottie in the app (LT.4)

Lottie makes the app's quiet moments clearer and friendlier: empty lists, things done, the first run, waiting, and errors. Cards: `TplMoments` (web), `TmMoments` (phone).

- **Where:** empty states (`empty-invoices`, `empty-stock`, `empty-documents`, `empty-people`, `no-results`, `all-caught-up`); done moments (`approve-set-down` for one item, `invoice-sent`, `payment-received`, `stock-received`); first run (`setup-done` and the brand stories); waiting (`waiting-reply`, `uploading`, `updating`, `offline`); errors (`went-wrong`, `not-found`).
- **How long:** everyday moments 1–1.5 s; first-time and rare moments up to 3 s. One scene at most on a screen.
- **How often:** empty states once a session per list; first-run scenes once ever per person; done moments each time; loops only while really waiting, stopped the moment it's done.
- **Never in the way:** buttons work while anything plays; nothing waits for an animation to end; the words say what happened, so the picture is never the only signal.
- **Your brand:** every file is recoloured by colour name to the workspace's brand and to dark mode.
- **Animations setting:** Full (default), Calm (the last frame fades in), Off (still). Reduce motion on the device means Off.
