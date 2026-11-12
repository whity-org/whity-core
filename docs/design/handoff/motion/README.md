# Whity motion: Lottie library (LT.1–LT.4)

Thirty-one real Lottie files (Bodymovin 5.7, 60 fps), written in code by `mklottie.py` so they stay exact to the brand and easy to change. Open or polish any of them in LottieFiles or After Effects.

| File | What it shows | Where it's used |
| --- | --- | --- |
| `approve-set-down.json` | Interactive. A proposal sets down on the plane when approved; Undo plays it backwards. | whity.dev Agents (try it), whity.dev/approvals, MB01 story 3 "You decide", the phone motion card |
| `brand-sheet-platform.json` | Brand story. The platform rises, the workspace's own sheet (its logo colour) sets down on it. | whity.dev "Your name on it" (pick a colour), MB01 story 1, brand.whity.dev |
| `agents-propose.json` | Brand story. An agent appears and drafts three dashed proposals. | MB01 story 2, docs |
| `all-caught-up.json` | Empty state. Finished items slide away and a tick draws in. | Inbox and Approvals when nothing waits (web and phone) |
| `updating.json` | Loading. The only animation that loops, while something is really loading. | Phone "Updating", long reports |
| `money-invoice.json` | Module. An invoice fills in, its QR appears, it is paid. | whity.dev/money |
| `stock-batches.json` | Module. Boxes stack, a batch tag swings on, the level rises. | whity.dev/stock |
| `spot-languages.json` | Spot. English and Arabic speech bubbles. | whity.dev Works locally |
| `spot-jofotara.json` | Spot. A receipt with its QR, accepted. | whity.dev Works locally |
| `spot-cliq.json` | Spot. A payment moves from phone to phone. | whity.dev Works locally |
| `spot-whatsapp.json` | Spot. A message arrives and is read. | whity.dev Works locally |
| `spot-jod.json` | Spot. Dinars stack up. | whity.dev Works locally |
| `spot-code.json` | Spot. Code brackets draw in. | whity.dev For builders |
| `spot-plugin.json` | Spot. An app slots into the platform. | whity.dev For builders |
| `spot-mcp.json` | Spot. An agent connects through a permission. | whity.dev For builders |
| `documents-sign.json` | Module. A letter is signed, its QR appears, it is sealed. | whity.dev/documents |
| `security-lock.json` | Page. A sheet sets down on the platform and a lock closes on it. | whity.dev/security |
| `empty-invoices.json` | In-app empty state. A dashed invoice floats over its plane, not set down yet, and a plus pops on. | Money: no invoices yet (web and phone) |
| `empty-stock.json` | In-app empty state. A dashed box lands on an empty shelf, and a plus pops on. | Stock: no items yet |
| `empty-documents.json` | In-app empty state. A dashed page rises out of a folder, and a plus pops on. | Documents, library and templates: nothing yet |
| `empty-people.json` | In-app empty state. Three dashed people appear, and a plus pops on the middle one. | People and contacts: nobody yet; first invitation |
| `no-results.json` | In-app empty state. A magnifying glass looks over three pages and finds nothing. | Any search or filter with no results |
| `invoice-sent.json` | In-app done. The invoice lifts off its plane and flies away; a tick lands where it was. 1.4 s. | After sending an invoice, a quote or a reminder |
| `payment-received.json` | In-app done. A coin drops into the wallet and a tick pops on. 1.4 s. | A payment arrives (toast, the invoice, the phone notification screen) |
| `stock-received.json` | In-app done. A box lands on the shelf, the level rises, a tick pops on. 1.4 s. | Goods received against a purchase order; a count saved |
| `setup-done.json` | In-app first run. The platform rises, three sheets set down, the workspace's own logo appears, a tick. 2.8 s, once. | End of company setup (web onboarding, phone onboarding) |
| `offline.json` | In-app waiting. The plug pulls out of the socket and a dashed gap shows; then it holds still. | Offline banner screen, "can't reach the server" |
| `uploading.json` | In-app loading (loops). An arrow rises off a page, again and again, while a file really uploads. | Uploads, imports, attaching files |
| `waiting-reply.json` | In-app waiting (loops). A receipt waits beside a clock while the tax system answers. | E-invoice waiting for the tax system; any outside system slow to answer |
| `went-wrong.json` | In-app error. The page slips on its plane and a calm badge shows. Nothing is lost. | 500 page, "Something went wrong" panels |
| `not-found.json` | In-app error. An empty dashed slot draws on the plane and three dots wait. | 404 page, a link to something deleted |

## In the app (LT.4)

The app uses Lottie at five kinds of moment, on the web and the phone, recoloured to each workspace's brand and to dark mode. One scene at most on a screen; the words always say what happened.

| Kind | Files | Length | Plays |
| --- | --- | --- | --- |
| Empty | `empty-invoices`, `empty-stock`, `empty-documents`, `empty-people`, `no-results`, `all-caught-up` | 1–1.6 s | Once a session per list (`data-once`), then its last frame |
| Done | `approve-set-down` (one item), `invoice-sent`, `payment-received`, `stock-received`, `documents-sign` | 1.2–1.5 s | Each time; never holds a button |
| First run | `setup-done`, `brand-sheet-platform`, `money-invoice`, `agents-propose` | up to 3 s | Once ever per person |
| Waiting | `waiting-reply`, `uploading`, `updating` (loop), `offline` (then still) | loops | Only while really waiting; stop with `WhityLottie.done(el)` |
| Errors | `went-wrong`, `not-found` | 1.6 s | On the 500 and 404 pages and panels |

**Animations setting** (Account › Preferences, phone Settings): **Full** (default), **Calm** (the last frame fades in), **Off** (the last frame, still). The device's reduce-motion setting means Off. On the web, set `<html data-motion="full|calm|off">` and the player follows; in Flutter, read the setting and `MediaQuery.disableAnimations`.

## Approving: one item plays the file, bulk stays in code (LT.4, replaces 5.4)

Approving **one** item (a proposal card, a record, the phone's decision card) plays `approve-set-down.json` (1.2 s); Undo plays it backwards. **Bulk** approval stays the 200 ms set-down done in code: the dashed edge (1.5px, main colour at 60%) turns solid in the main colour, the plane (`--plane-primary-rest`) slides in underneath, and the badge changes from "Waiting for your approval" to "Approved · <time>". Easing cubic-bezier(0.2, 0, 0, 1); Undo plays it backwards in 200 ms; reduced motion jumps to the end. Bulk approval then shows one toast with the count.

## Rules (M.19, M.23, M.25, LT.2)

- **Motion explains something.** Brand-story files tell why Whity exists; UI files show what a decision does. Nothing moves only to look good.
- **Play once, never loop forever.** Animations start when they're scrolled into view and stop on their last frame; clicking or tapping replays them. Only `updating.json`, `uploading.json` and `waiting-reply.json` loop, and only while something is really loading or waiting.
- **Try it yourself.** `approve-set-down.json` is played by the person: Approve plays it forwards, Undo plays it backwards (marker `approve`, frames 0–72).
- **Less motion.** When the system asks for less motion, show the last frame (Approve and Undo jump between the two states). No information lives only in the motion.
- **No text inside.** The same file works in English and Arabic; the words stay in the page. The plane doesn't mirror in Arabic, like the mark.

## Colours

Every fill and stroke is named after a colour token: `c:primary`, `c:plane`, `c:card`, `c:line`, `c:muted`, `c:ink`, `c:soft`, `c:ok`, `c:tint`, `c:tenant`, `c:onprimary`. The files carry light-theme defaults; players swap in the live tokens, so the animations follow dark mode and each workspace's own colour (white-label).

| Name | Token |
| --- | --- |
| `c:primary` | `--primary` |
| `c:plane` | `--plane-deep` |
| `c:card` | `--card` |
| `c:line` | `--border` |
| `c:muted` | `--muted` |
| `c:ink` | `--foreground` |
| `c:soft` | `--muted-foreground` |
| `c:ok` | `--success` |
| `c:tint` | `--primary-tint` |
| `c:tenant` | the workspace's brand colour (`--tenant-color`) |
| `c:onprimary` | `--primary-foreground` |

## Web

`whity-lottie.js` with lottie-web's light build (`lottie_light.min.js` 5.12.2, MIT; host it yourself):

```html
<div class="w-lottie" data-lottie="/assets/lottie/money-invoice.json" data-play="view">
  <div class="w-lottie__fallback"><!-- shown if the library can't load --></div>
</div>

<div data-lottie-scope data-state="waiting" data-say-approved="Approved." data-say-undone="Undone.">
  <div class="w-lottie" data-lottie="/assets/lottie/approve-set-down.json" data-play="manual"></div>
  <button data-lottie-do="approve">Approve</button> <button data-lottie-do="undo">Undo</button>
  <p data-lottie-say aria-live="polite"></p>
</div>
```

`data-play` is `view` (once, when seen), `manual` (Approve and Undo) or `loop` (loading only). `data-lottie-colour="#1a7f5a" data-lottie-target="#id"` recolours `c:tenant` and replays. The player rebuilds on theme changes and keeps the current frame.

## Flutter

Use the `lottie` package. Recolour by name with value delegates, from the installation's tokens:

```dart
Lottie.asset('assets/lottie/approve-set-down.json',
  controller: controller,
  delegates: LottieDelegates(values: [
    ValueDelegate.color(const ['**', 'c:primary', '**'], value: theme.primary),
    ValueDelegate.color(const ['**', 'c:plane', '**'], value: theme.planeDeep),
    ValueDelegate.color(const ['**', 'c:card', '**'], value: theme.card),
    // … one per token in the table above
  ]),
);
```

Approve: `controller.animateTo(1)`; Undo: `controller.animateBack(0)`; less motion (`MediaQuery.disableAnimations`): `controller.value = 1` or `0`.
