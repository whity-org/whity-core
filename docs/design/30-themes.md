# Themes: the Whity UI theme standard

Status: **decided by the owner on 19 Nov 2026** (WHIT-1225), expansion (section 14) on 21 Nov 2026, and every part of the theme system made free in core the same day (TH.25). Register: "Themes (version 3.6)", TH.1–TH.25. Every recommendation in section 13 was accepted except TH.6, where the owner chose **headings and body**: a theme may set the whole font pair on product screens.

Owner's direction, 19 Nov 2026: themes are **tokens only**, in the spirit of Material 3 Expressive and the shadcn/ui theme customizer. **Applying a theme is free** in core, whether it comes from Whity, a plugin or the store.

**Changed 21 Nov 2026 (TH.25):** the owner moved to "everything is in whity": "I want to start a new approach... everything is in whity... and core supports almost everything that it makes sense to support... extra usage and users is what is paid, and outside integration plugins are paid like payment gateways, jofotara, etc..." On Whity Studio: "All into core". The theme editor, the importers and every other part of the theme system are **free core features**; there is no paid theme tool. What is paid is extra usage and users, and plugins that integrate outside services. Selling a theme in the store (TH.13) is unchanged.

## Summary

- **A theme is data, never CSS.** It is a versioned JSON file with a schema in the repo (`schemas/theme/v1.json`). Every value is an enum, a number in a range or a `#rrggbb` colour. Core compiles it into the CSS variables the app already uses, so `packages/ui` components don't change.
- **One seed colour is enough.** The engine builds tonal palettes from up to three seeds (primary, secondary, tertiary) and a neutral setting, then gives every colour role a light and a dark value. Any role can be overridden by hand. Today's main colour (2.8) becomes the primary seed, and the default scheme produces the same values `TenantTheme` produces now.
- **Six token groups, all closed:** colour, type (from an allowed font list), shape, density, elevation and motion. Each has a few named presets, like the shadcn customizer, plus a small number of bounded fine controls.
- **Inheritance:** Whity default → installation theme → chosen theme (Whity, plugin or store) → workspace adjustments → the person's own settings (light or dark, text size, animations, higher contrast). Later layers only fill in or change keys. They never remove a guard rail.
- **Guard rails run last, and they always win:** 4.5:1 text contrast with automatic fallback, 3:1 for borders and focus, the 17px and 48px minimums on phones, Arabic and RTL rules, status colours, agent marks, and print staying under E.44.
- **One resolved token set, many consumers.** The web app, `packages/ui`, the mobile app (M.15), document themes (E.44), emails and websites all read the same resolved output, `GET /api/v1/theme`.
- **Plugins ship themes as files** through a new SDK interface. Store themes are signed packages. The old route-based `PluginThemeInterface` is folded into this.
- **The theme editor is free in core** (TH.25): the live editor, generating a theme from a logo, presets to start from, import and export (including shadcn CSS and Material Theme Builder JSON), a preview across key screens, documents and the phone, and publishing to the store.

## 1. What exists today

| Thing | Where | What it does |
| --- | --- | --- |
| Main colour | `branding_main_color` (`#rrggbb`), `TenantTheme.php` and `web/lib/tenant-theme.ts` (kept in step by a fixture test) | Derives `primary`, `brand`, `ring`, `chart-1`, tints, `sidebar-primary*`, the four plane shadows and `doc-accent` in light and dark from one colour (2.8, `09-white-label.md`) |
| Injecting it | `web/lib/branding-context.tsx`, `brand-theme-css.ts` | A `<style data-brand-theme>` with `:root{…}` and `.dark{…}`. Every name and value is checked against patterns before it reaches the tag |
| Plugin colour overrides | `PluginThemeInterface` (SDK 1.12), `GET /api/theme` | At most one plugin, served through its own route. Hex values only, limited to known token names. App UI only |
| Base tokens | `src/design/tokens/base.json` v3.0.0, `generate-tokens.js` → `web/app/globals.css`, `packages/tokens/generated/*` (`@whity/tokens` 1.1.0), Flutter tokens | shadcn-shaped colour roles in OKLCH, the type scale, radius, sizes, plane, motion and document sizes |
| Personal display | `profiles.text_size` (`standard`, `larger`), `animations` (`full`, `calm`, `off`), light, dark or system | Per person, `DisplayPreferencesApiHandler` |
| Document themes | E.44–E.47, `DOCUMENT-THEMES.md`, `DocumentThemeResolver` | A closed set for print: accent, letterhead, accent on headings, title face, large print. Frozen at publish |
| Mobile | M.13, M.15 | Takes the installation's published tokens. The 17px and 48px minimums hold, and a pair that fails contrast falls back to Whity's |

The gap: what a workspace can change today is one colour. The owner has widened "What stays" in `09-white-label.md` from colour only to the whole token set, while keeping the rules that protect people.

## 2. What we take from Material 3 Expressive and shadcn/ui

**From Material 3 (and M3 Expressive, 2025):**

- **Dynamic colour from a seed.** One source colour gives five tonal palettes (primary, secondary, tertiary, neutral, neutral variant). Each palette runs from tone 0 to tone 100, and every colour role is a tone from one of them. Contrast between a role and its "on" role is structural, because tones far apart always contrast. Scheme variants (tonal spot, neutral, vibrant, expressive, monochrome, fidelity, content) change how the palettes are taken from the seed. Contrast levels (standard, medium, high) move tones further apart.
- **Container roles and surface steps.** `primaryContainer` and `onPrimaryContainer`, and five surface container levels from lowest to highest, instead of shadow elevation.
- **A shape scale** from extra small (4dp) to extra extra large (48dp) plus full, and in Expressive, shape morphing: a shape changes on press or selection, for example a round button becoming squarer.
- **Motion springs** in place of easing curves: a spatial spring (position and size, may overshoot) and an effects spring (colour and opacity, never overshoots). Each comes in fast, default and slow, under an "expressive" or a "standard" scheme.
- **Emphasised type.** The 15-style scale (display, headline, title, body and label, each large, medium and small) gains emphasised versions with a heavier weight for key moments.

**From the shadcn/ui customizer:**

- **The variable set is the contract.** Components read `--primary`, `--card`, `--radius` and the rest, so a theme is just a block of variables. Whity's tokens already follow this shape (`base.json`), and `packages/ui` is built on it.
- **A few big choices:** base colour (neutral, stone, zinc, gray or slate), theme colour, radius (0, 0.3, 0.5, 0.75 or 1rem), font and light or dark, with "copy code" for the result. Simple enough for anyone, and the result is still a coherent design.
- **OKLCH everywhere** since Tailwind v4, which Whity already uses.

**What Whity does differently, and why:**

- **OKLCH, not HCT, for the maths.** `TenantTheme` already works in OKLCH and the register's values are written in it (2.8b). OKLCH lightness predicts contrast well enough for our use, and keeping one colour model means one implementation in PHP, TypeScript and Dart. Tonal palettes are lightness steps of the seed's hue, with chroma capped per tone.
- **The shadcn role names stay.** We don't adopt M3's role names in the app. M3's ideas map onto roles we already have (section 3.1); the container and surface roles are added as new tokens.
- **Presets first, fine controls second.** As in shadcn, the main choices are named presets. As in M3, the engine works out everything else.
- **Guard rails are not options.** M3 lets an app turn off contrast checks and shadcn has none. Whity's are fixed (section 6).

## 3. The closed token set

A theme sets **choices**. The engine turns choices into **tokens**. Tokens are what CSS, Flutter and the renderer read. A theme file never contains a raw token value, except colour overrides.

### 3.1 Colour

**Input (theme file):**

| Key | Values | Default |
| --- | --- | --- |
| `colour.seeds.primary` | `#rrggbb` | Whity Blue `#0961bb` (2.1) |
| `colour.seeds.secondary` | `#rrggbb` or absent (derived) | derived |
| `colour.seeds.tertiary` | `#rrggbb` or absent (derived) | derived |
| `colour.scheme` | `calm` · `tonal` · `vibrant` · `expressive` · `monochrome` | `calm` |
| `colour.neutral` | `pure` · `warm` · `cool` · `tinted` | `pure` (chroma 0, 2.3) |
| `colour.contrast` | `standard` · `high` | `standard` |
| `colour.overrides.light` / `.dark` | map of role name → `#rrggbb` | empty |

**Schemes** (what each does with the seeds):

| Scheme | Neutrals | Secondary and tertiary | Like |
| --- | --- | --- | --- |
| `calm` (Whity default) | from `colour.neutral` (pure grey by default) | not used on screens; charts keep 2.7 | today's Whity: one colour, grey everything else. Produces exactly `TenantTheme`'s output for the primary |
| `tonal` | tinted with the primary hue, chroma ≤ 0.012 | secondary = primary hue, low chroma | M3 tonal spot |
| `vibrant` | tinted, chroma ≤ 0.02 | secondary hue +60°, tertiary +120° unless given | M3 vibrant |
| `expressive` | tinted, chroma ≤ 0.02 | tertiary from the complementary side unless given; containers more saturated | M3 expressive |
| `monochrome` | pure | none; primary is near-black in light and near-white in dark, the seed stays only in charts and the plane | M3 monochrome, shadcn neutral |

**Output roles.** Each in light and dark. The ones marked *new* are added to `base.json`:

- Surfaces: `background`, `card`, `popover`, `sidebar`, `muted`, `secondary`, `accent`, `table-header`, `divider`, `border`, `input`, plus *new* `surface-1`…`surface-5` (M3's container levels, for nested panels and the phone).
- Ink: every `*-foreground`, `muted-foreground`.
- Action: `primary`, `primary-foreground`, `brand`, `ring`, `primary-tint`, `primary-tint-foreground`, `sidebar-primary`, `sidebar-primary-foreground`, *new* `primary-container`, `on-primary-container`, *new* `secondary-container`, `on-secondary-container`, *new* `tertiary`, `tertiary-foreground`, `tertiary-container`, `on-tertiary-container`.
- Brand surfaces: `plane-deep`, the four plane shadows, `blueprint*`, `doc-accent`.
- Charts: `chart-1` is always the primary (2.7). `chart-2…5` keep 2.7's categorical set unless the scheme is `vibrant` or `expressive`, which use secondary and tertiary for 2 and 3. Even then the 0.08 OKLab difference under colour blindness (2.7) is checked, and the 2.7 colour is kept if it fails.
- **Not generated:** `success`, `warning`, `error`, `destructive`, `info` and their tints (2.5). See TH.7.

**Overrides.** Any output role can be set by hand per mode. An override is checked against its pairs (section 6.1) and falls back if it fails.

### 3.2 Type

| Key | Values | Default |
| --- | --- | --- |
| `type.family` | an id from the allowed font list (below) | `noto` |
| `type.headingFamily` | an id from the list, or `same` (TH.6: a theme may set heading and body) | `same` |
| `type.scale` | `standard` (15) · `roomy` (16) · `large` (17): the body size in px; the rest of the scale moves with it. Nothing below 15 (A.1) | `standard` |
| `type.titleWeight` | `500` · `600` | `600` |
| `type.emphasis` | `quiet` · `expressive`: expressive gives page titles and key numbers one scale step more and tighter tracking (−1%) | `quiet` |

**Allowed font list (v1).** Every entry is a Latin face paired with an Arabic face, licensed OFL, self-hosted as woff2 in `@whity/tokens`, and bundled in the mobile app. No web font URLs; a theme names an id, never a file.

| Id | Latin | Arabic | Notes |
| --- | --- | --- | --- |
| `noto` | Noto Sans | Noto Sans Arabic | Whity default (3.2, 3.3) |
| `plex` | IBM Plex Sans | IBM Plex Sans Arabic | Corporate, banks and government |
| `readex` | Readex Pro | Readex Pro | For headings and body since TH.6 (3.1 updated) |
| `cairo` | Cairo | Cairo | Rounded, friendly; popular in Jordan and the Gulf |
| `tajawal` | Tajawal | Tajawal | Light, modern |
| `almarai` | Almarai | Almarai | Bold, retail |
| `kufi` | Noto Sans | Noto Kufi Arabic | Formal Arabic headings |
| `rubik` | Rubik | Rubik | Soft geometric; retail and salons (TH.20) |
| `alexandria` | Alexandria | Alexandria | Contemporary Kufi; tech and schools (TH.20) |
| `naskh` | Noto Serif | Noto Naskh Arabic | Classic, for law and print-like brands; product screens only, print keeps E.46 (TH.20) |
| `ibm-kufi` | IBM Plex Sans | IBM Plex Sans Arabic for text, Noto Kufi Arabic for headings | Formal with a corporate body (TH.20) |

Mono stays Geist Mono (3.4). New fonts join through a PR that adds the files, licence and metrics, never through a theme.

**Fixed whatever the theme:** weights 400, 500 and 600 only (no 700, 3.6); Arabic at 100% of the Latin size (3.7a) and line height 1.7; Western digits (3.7b); tabular figures for numbers.

### 3.3 Shape

| Key | Values | Default |
| --- | --- | --- |
| `shape.preset` | `square` · `crisp` · `whity` · `soft` · `round` · `pebble` · `cut` (TH.17) | `whity` |
| `shape.morph` | `off` · `on` (the main button squares off while pressed, a selected chip goes from pill to rounded; M3 Expressive, TH.18) | `on` for `round` and `pebble`, `off` for the rest |

| Preset | Control radius | Card | Dialog | Like |
| --- | --- | --- | --- | --- |
| `square` | 0 | 0 | 0 | shadcn radius 0 |
| `crisp` | ⅛ of the height | 6 | 8 | shadcn 0.3 |
| `whity` | ¼ of the height (4.2) | 12 | 16 | today |
| `soft` | ⅓ of the height | 16 | 24 | M3 medium to large |
| `round` | half the height (pill) | 20 | 28 | M3 Expressive full |
| `pebble` | half the height (pill) | 16 | 24 | Round's buttons with Soft's cards (TH.17) |
| `cut` | ⅛ of the height, bevelled | 6 | 8 | M3 cut corners, for formal brands; rounded where the browser has no `corner-shape` (TH.17) |

Avatars, the 16px mark and status dots don't follow the preset, and `rounded-full` pills are never bevelled.

**Morph** (TH.18) uses `--radius-control-pressed` (half the control radius) on the main button at its standard size and `--radius-control-selected` (¼ of the small control height) on a selected toggle chip. It moves over `--dur-morph`, which is 0 for people on Calm or Off and under reduced motion, so they see the end state with no movement.

**Rules that keep content clear of the corner** (all presets, added 21 November 2026 after the round preset cut text in textareas and comment cards):

- **A pill is half the control's own height, never `999px`.** The control radius is written in pixels for each control size (16 / 20 / 22 at standard density). A single-line control of that height reads as a pill; anything that uses the control radius and grows (a textarea, a chip field that wraps, a comment card) keeps that corner instead of turning into a stadium. `rounded-full` stays for things whose height never changes: avatars, dots, single-line chips and badges.
- **Padding grows with the corner.** A field that holds chips or other painted children pads them by `--spacing-inset-control` (`p-inset-control`), which is 30% of the control radius and never under 4px; a radius of r keeps its curve within 0.3r of each edge at 45°.
- **Nested corners.** A container whose children run edge to edge (a segmented control, a table in a card, a card with a banner or image) clips with `overflow-hidden`, so the children follow its curve. A child inset by padding p uses the outer radius less p (`--radius-control-inner` for the control radius).
- **Checked in CI.** `packages/ui/scripts/shape-clip-check.mjs` renders the fields, chips, editor and comments, tables, tabs, segmented controls, menus, popovers, cards, dialogs, the sidebar and the phone bar under every preset and density, left to right light and right to left dark, at desktop and phone width, and fails when a corner cuts text or a painted child pokes past a curve.

### 3.4 Density

`density`: `compact` · `standard` · `comfortable`. It moves control height, table row height and gaps on the 4px grid:

| | Control | Small control | Table row | Group gap |
| --- | --- | --- | --- | --- |
| `compact` | 36 | 32 | 44 | 12 |
| `standard` | 40 | 32 | 48 | 16 |
| `comfortable` | 44 | 36 | 52 | 20 |

`compact` is as small as A.1 (sized for people over 50) allows: no control under 32px, no text under 13px.

On phones density is ignored for targets: every target stays at least 48 × 48 (M.5).

### 3.5 Elevation

| Key | Values | Default |
| --- | --- | --- |
| `elevation.style` | `plane` · `soft` · `flat` · `outline` (TH.19) | `plane` |
| `elevation.float` | `light` · `standard` · `strong` (the floating-layer shadow, 4.4) | `standard` |

- `plane`: the hard offset under the main button, approved agent work and hovered clickable cards (4.3).
- `soft`: the same places get a soft shadow in the primary's plane tone instead of the offset.
- `flat`: no offset; those places get a 2px border in `plane-deep`.
- `outline`: no shadows anywhere. The plane is a 2px border as in `flat`, and floating layers (menus, popovers, dialogs) get a 1px border in the mode's border colour instead of the float shadow, so `elevation.float` has no effect. For high-contrast and print-like brands.

Whatever the style, **approved agent work must look different from a waiting proposal** (solid against dashed, 4.3). That difference is a guard rail, not a token.

### 3.6 Motion

| Key | Values | Default |
| --- | --- | --- |
| `motion.preset` | `calm` · `standard` · `expressive` · `brisk` (TH.19) | `calm` |

- `calm`: today's 06-motion: 120, 180 and 250ms on `cubic-bezier(0.2, 0, 0, 1)`, no bounce.
- `standard`: M3 standard springs (no visible overshoot). On the web they are emitted as `linear()` easings with matching durations, so CSS needs no runtime library.
- `brisk`: the standard springs' curve over 0.8 of their durations, for busy front desks.
- `expressive`: M3 expressive spatial springs (a small overshoot on things entering and on the main button's press). Colour and opacity always use the effects spring, which never overshoots.

The person's **Animations** setting (Full, Calm, Off) and the device's reduce-motion setting always win (06-motion, LT.4): Calm and reduce motion mean the `calm` preset with no movement, whatever the theme.

## 4. The theme file

A theme is one JSON file. Schema: `schemas/theme/v1.json` (JSON Schema 2020-12) in whity-core, copied into `@whity/tokens` and the SDK. `additionalProperties: false` at every level.

```json
{
  "$schema": "https://whity.dev/schema/theme/v1.json",
  "schemaVersion": 1,
  "id": "acme.harbour",
  "version": "1.2.0",
  "name": { "en": "Harbour", "ar": "الميناء" },
  "description": { "en": "Deep teal, soft corners, calm motion." },
  "author": { "name": "Acme Studio", "url": "https://acme.example" },
  "colour": {
    "seeds": { "primary": "#0b6e74", "tertiary": "#c2652a" },
    "scheme": "tonal",
    "neutral": "cool",
    "contrast": "standard",
    "overrides": { "light": {}, "dark": { "background": "#0d1214" } }
  },
  "type": { "family": "plex", "headingFamily": "same", "scale": "standard", "titleWeight": 600, "emphasis": "quiet" },
  "shape": { "preset": "soft", "morph": "off" },
  "density": "standard",
  "elevation": { "style": "soft", "float": "standard" },
  "motion": { "preset": "standard" },
  "documents": { "accentUse": "title", "titleFace": "readex" }
}
```

Rules:

- **Every key is optional** except `schemaVersion`, `id`, `version` and `name`. A missing key comes from the layer below (section 5).
- **`id`** is `vendor.slug` (lowercase, dots and hyphens). `whity.*` is reserved. A workspace's own theme gets `ws-<tenant id>.<slug>`.
- **`version`** is semver. A workspace keeps the version it chose until someone updates it, like a plugin.
- **`documents`** is optional and only holds E.44–E.47 keys (`accentUse`, `titleFace`, `largePrint`, `letterhead`). It sets the defaults of the workspace's document theme when the theme is applied. It can never set print sizes, fonts beyond E.46 or colours other than the accent.
- **No logo, name or address.** Those are branding (`09-white-label.md`), not a theme. A theme shared through the store must look right with anyone's logo.
- **No URLs, CSS, selectors, images or scripts** anywhere. The validator rejects any string that isn't a known enum, an id, a `#rrggbb` colour or text in `name` and `description` (plain text, 200 characters at most).
- **`schemaVersion`** changes only when the format breaks. Core reads every earlier version through a migration in the validator; a newer version than core knows is refused with "Update Whity to use this theme."

**Signing.** A theme from the store is a package: the JSON file plus a detached signature from the store's key (the same key ring and verifier as signed plugin packages in Whity ID's store, `store-in-whity-id`). Core checks the signature and the hash on install and refuses a theme that fails. A theme a plugin ships is covered by the plugin's own package signature. A workspace's own theme (made in Studio or imported) is unsigned: it is validated, and that's enough, because it can't do anything except choose values.

**The resolved theme.** Core compiles the layers (section 5) into a resolved theme: `{ hash, light: {token: value}, dark: {…}, type: {…}, shape: {…}, density: {…}, motion: {…}, adjustments: [...] }`. It is cached by hash. `GET /api/v1/theme` returns it with an `ETag`. The web app, the phone, the renderer and emails all read this, never the source file.

## 5. Inheritance

From the bottom up. Each layer is a partial theme; a later layer sets only the keys it names.

1. **Whity default.** `whity.default` in core: `base.json` as it is today.
2. **Installation theme.** For self-hosters and partners: the system tenant's chosen theme, for every workspace on the installation that hasn't chosen its own. On app.whity.dev this is the Whity default.
3. **Chosen theme.** The workspace picks one installed theme: Whity's, a plugin's or a store theme. Free.
4. **Workspace adjustments.** Small changes on top of the chosen theme without making a new theme: the primary seed (today's main colour, so 2.8 keeps working exactly as now), and the colour overrides. Anything more than that is a theme of its own, made in Studio.
5. **The person.** Light, dark or system; text size Standard or Larger (25 W.3); Animations Full, Calm or Off; and, *new*, **Higher contrast** (sets `colour.contrast: high` for that person only).
6. **Guard rails** (section 6) run on the result. They are not a layer anyone can change.

Documents add their own layer on top, under E.44 (section 8).

A plugin theme that is uninstalled, or a store theme that is withdrawn, leaves the workspace on layer 2 with its adjustments kept, and the admin sees "Your theme was removed, so the installation's theme is in use."

## 6. Guard rails

Guard rails run after every layer and can't be turned off. When one changes a value, the change goes into `adjustments` and the admin sees it in words ("Buttons' text was too faint on Harbour's teal, so the teal was darkened a little"), as 2.8a does today.

### 6.1 Contrast

- **Text pairs, 4.5:1:** every role against its foreground (`primary`/`primary-foreground`, `card`/`card-foreground`, every container and its "on" role, `muted-foreground` on `background` and on `card`, `primary` as text on `background` and `card`, `doc-accent` on white).
- **Non-text, 3:1:** `input` on `card` (2.6), `ring` against `background` and `card`, `border` of the main button against the page, chart series against the card.
- **Higher contrast:** text 7:1 and non-text 4.5:1.
- **Fallback, in order:** pick white or near-black for the foreground (2.8a); then move the role's lightness away from its pair in 0.01 steps, keeping hue and chroma; if it still fails, or an override was given, use the Whity default for that pair. Never leave a failing pair.

### 6.2 Size and touch

- Web (A.1–A.3): body text at least 15px, nothing below 13px except counters and badges (3.5a), controls at least 32px. Text size Larger always gives a 17px body.
- Phone: body text 17px, nothing below 14px, every target at least 48 × 48 (M.5), and the phone's own text size is followed. `type.scale` and `density` can make things bigger on a phone, never smaller.
- Focus is always visible: a 2px ring in `ring` with a 2px offset (4.6b), whatever the shape or elevation.

### 6.3 Arabic and RTL

- Every font choice has an Arabic face (section 3.2). A theme can't choose a Latin-only face.
- Arabic at 100% of the Latin size, line height 1.7, Western digits.
- Layout mirrors in RTL; the plane never mirrors (4.3); motion that slides comes from the end edge (06-motion).

### 6.4 What a theme never changes

- **Status colours** (2.5): success, warning, error and info keep their meaning and their tested contrast (but see TH.7).
- **Agent marks**: a waiting proposal is dashed and flat, approved work is solid with its elevation; impact tiers, "by an agent" attribution and approval steps look the same in every theme. They protect the workspace's own customers (09 "What stays").
- **Layout**: the shell, breakpoints, gutters and reading widths (4.5). Themes are tokens, not layouts.
- **Icons**: Tabler, one stroke width (5.x).
- **Print** stays under E.44 (section 8).

## 7. How this reconciles with "What stays" in 09-white-label

`09-white-label.md` says a tenant changes one colour, and greys, status colours, type, shape, layout and components stay. The owner has widened this to tokens. The owner approved this on 19 Nov 2026, and 09 now says:

> **What a theme changes:** colour (from one to three seed colours, with overrides), type (from the allowed font list), shape, density, elevation and motion. See `30-themes.md`.
>
> **What stays:** status colours, layout, components and icons, and the rules that protect people: contrast, the minimum sizes, Arabic and RTL, the plane rules, approvals, scopes and attribution for agents. A partner can't remove them: they protect the partner's own customers.

BM.5 still holds: the logo and colour are free on every plan, and so is applying any theme. Since TH.25 so is making one: the editor is part of core.

## 8. Document themes (E.44) consume the same tokens

- The document theme keeps its own closed set (E.44–E.47). Print doesn't take the app's fonts, shape, density or motion: printed documents follow stage 6 and E.33, so they print and fax cleanly.
- What documents take from the app theme: the **accent** defaults to the resolved `doc-accent` (the primary, contrast-checked against white, as now). The app theme's optional `documents` block sets the workspace document theme's defaults when the theme is applied; the admin can still change them in Documents › Document themes (SC.15).
- E.46 is unchanged: the title is Readex Pro or Noto Sans. A theme whose `type.family` is `plex` doesn't change printed titles (see TH.11 for whether it should).
- Published templates keep their frozen theme (E.44, E.38). Changing the app theme reaches print only when a person republishes.

## 9. The phone (M.13, M.15) consumes the same tokens

- The app reads `GET /api/v1/theme` on connecting and when the `ETag` changes, and themes itself from the resolved values (M.15). This replaces the separate token publishing described for M.15; the resolved theme is the published tokens.
- Fonts come from the allowed list, all bundled in the app, so no font is downloaded at runtime.
- Motion presets map to Flutter springs (`SpringDescription`) with the same values as the web's `linear()` curves.
- The phone guard rails (6.2) run again on the device, after the phone's own text size is applied.
- Switching workspace re-themes the app (M.17).

## 10. Plugins ship themes through the SDK

- **New interface** `PluginThemesInterface::themes(): list<string>`: paths to theme JSON files inside the plugin. Core validates each file on install and lists it in the workspace's theme picker as "From <plugin>". A plugin can ship several.
- **The old `PluginThemeInterface`** (one plugin, a route returning hex overrides, `GET /api/theme`) is deprecated. While it lives, its overrides are read as a theme with only `colour.overrides`. `GET /api/theme` keeps answering until the next SDK major, built from the resolved theme.
- **Plugins can't apply themes on their own.** A person with `settings:branding` picks the theme. An agent can propose a theme change (it's undoable, so "Ask first" by default).
- **The store** lists themes as their own kind of item, with a preview of key screens (section 11), "Used by n workspaces" and the publisher's trust mark (S.4). Installing and applying is free; a publisher can sell a theme through Whity Pay like any paid plugin.
- The SDK minor for this comes from the sdk lane (sdk-0231); it is not numbered here.

## 11. The theme editor (free in core since 21 Nov 2026)

This section first described Whity Studio, a paid tool for making themes. On 21 Nov 2026 the owner put it "All into core" (TH.25): everything below is a free core feature, for every workspace, on top of applying, listing and switching themes, the primary seed and overrides (layer 4), the tweaks (TH.15), export (TH.16) and importing a theme file. Making a theme needs `settings:write`, like choosing one:

- **Live editor.** Every choice in section 3 with the app re-theming as you go, light and dark side by side.
- **Seed generator.** Pick seeds by hand, from a colour wheel with harmony rules (complementary, analogous, triadic), or **from the logo**: the editor extracts the dominant colours of the uploaded logo and proposes seeds, like M3 dynamic colour from an image.
- **Presets to start from.** Ten or so Whity-made themes (for clinics, salons, law firms, schools, retail…) to copy and change.
- **Import and export.** The Whity theme file both ways; import from shadcn CSS variables, a Tailwind v4 `@theme` block or Material Theme Builder JSON (mapped to seeds and overrides, then checked); export resolved tokens as CSS, JSON, Tailwind or Flutter.
- **Preview across key screens:** Home, a list, a record, a form, the approval card, a dialog, a chart, the sign-in page, an email, a printed document and the phone, in English and Arabic, light and dark, with a contrast report for every pair.
- **Versions.** History, compare and restore, and publishing a new version to the workspaces using it.
- **Publish to the store** as the theme's publisher.

**The live editor (built, WHIT-1225).**
- **Where:** Settings › Branding › Theme, from "Make a theme" or a theme's Edit / Make a copy (`/admin/settings/branding/theme?from=<id>`), for people with `settings:write` in a workspace.
- **What it edits:** every choice in section 3 is a closed control: the main, second and accent colours, colour style, greys and contrast; colours set by hand per role and mode; text and heading fonts, text size, title weight and emphasis; corners, morph and each family's corners (14.2); spacing; shadows, shadow strength and animations. "Default" removes the choice, so the theme follows Whity's.
- **The preview:** every change goes to `POST /api/v1/themes/preview`, the same compiler and guard rails a theme gets in use. The answer carries every colour for both modes, so light and dark sit side by side whatever mode the person is in. It also says what the guard rails change and lists every problem at once.
- **How the preview is drawn:** the draft's values go only onto two `[data-theme-preview]` elements (`previewThemeCss`), which recompute the shape values as `:root` does. They hold the real components: tabs, a card with a field, check box, switch and buttons, a table, badges and the chart colours. The page around them keeps the workspace's theme.
- **Saving:** saving goes through the theme import with `source: "editor"`. The workspace's own theme (imported or made here) gets its next patch version; any other is saved as a copy under `workspace.<name>` with no author. "Save and use" also sets `branding_theme`.
- **Not in this step:** generating seeds from the logo or a colour wheel, presets to start from, the wider preview across key screens, the contrast report, versions, export of resolved tokens, the side-by-side diff and publishing. The last three are the next steps of TH.24.

The editor is built last in the order of TH.24, after the per-component overrides. Whether the wider Studio product (records and pages, R.2) moves the same way is for the business-model register, not this spec.

## 12. Build plan (phase 2, after approval)

Free engine in core, one PR per step where it helps review:

1. **Schema and validator.** `schemas/theme/v1.json`; validator in PHP (server, authoritative) and TypeScript (the editor and the preview), sharing one fixture suite; the error messages in words.
2. **Generator.** Extend `TenantTheme` into `ThemeCompiler` (PHP) and `compileTheme` (TS): palettes, schemes, roles, type, shape, density, elevation, motion, guard rails, `adjustments`. The `calm` scheme must reproduce today's `TenantTheme` fixture exactly.
3. **Storage and API.** Migration 323: `themes` (installed theme files, by id and version, source, signature status) and `workspace_theme` (chosen id and version, adjustments). Migration 324: move `branding_main_color` into each workspace's adjustments as the primary seed (idempotent, no customer data lost). `GET /api/v1/theme` (resolved, public like branding, `ETag`), `GET/PUT /api/v1/workspace/theme`, `POST /api/v1/themes/import`. Permission `settings:branding`. OpenAPI and MCP tools follow the usual rules.
4. **Runtime in `web/` and `packages/ui`.** The branding provider becomes a theme provider: one `<style data-theme>` with the resolved variables for `:root` and `.dark`, still re-checked by pattern before it's written (as `brand-theme-css.ts` does). `packages/ui` reads radius, sizes, elevation and motion from variables instead of fixed values where it doesn't already. Fonts for the chosen family load from `@whity/tokens`.
5. **`@whity/tokens` 1.2.0.** The new roles in `base.json` (`surface-*`, containers, tertiary), the allowed fonts, the schema, and a `compile` export for others to use.
6. **The Theme picker.** In workspace settings: installed themes as cards with a small preview, "Use this theme", the main colour and overrides (what Branding has today), and what the guard rails changed, in words. No editor.

The editor, store theme listings and the SDK interface follow as their own pieces of work.

### 12.1 What phase 2 built (WHIT-1225), and where it differs from the plan

- **Schema and validator:** `schemas/theme/v1.json` and `ThemeValidator` (PHP), kept in step by `ThemeEngineTest`. The schema ships as `@whity/tokens/theme.schema.json`. The TypeScript validator and compiler wait for Studio, which is where a browser needs them; core's picker previews what the server compiles.
- **Compiler:** `ThemeCompiler` (OKLCH, on `TenantTheme`'s maths). Calm reproduces `TenantTheme` exactly for the tokens 2.8 derives; the guard rails can now also fix the tint text, the sidebar text and the focus ring, which 2.8 never checked (a very light main colour used to leave them under 4.5:1 and 3:1). Springs become a CSS `linear()` curve, with fast, base and slow capped at 200, 350 and 500ms so hover stays quick.
- **Whity's own themes:** Whity (default), Harbour, Ink, Bloom and Desert, in `src/Core/Theme/themes/`.
- **Storage:** migration 323 `themes` (a workspace's imported themes). The choice and the overrides are settings, `branding_theme` and `branding_theme_overrides`, so the global value is the installation theme with no extra table. **The main colour stays `branding_main_color`** and is read as the primary seed, rather than moved into the overrides: everything that already reads it (sign-up, templates, documents, push) keeps working. Migration 324 adds `profiles.contrast` for Higher contrast.
- **API:** the resolved theme rides on the existing `GET /api/v1/branding` (`theme` with `vars`, `fonts`, `adjustments`, and `themeHigh`) instead of a new `GET /api/v1/theme`, so the web app, public pages and the phone read one endpoint. `GET/POST /api/v1/themes` and `DELETE /api/v1/themes/{themeId}` list, import and remove. Choosing is `PATCH /api/v1/settings`.
- **Web and `packages/ui`:** one `<style>` from `brandThemeCss`, every value re-checked by pattern; Higher contrast under `[data-contrast="high"]`; the radius utilities read `--wy-radius-*`; the font pairs load through next/font without preloading.
- **Fonts:** Tajawal and Almarai are left out for now: they have no 600 weight, so semi-bold would show at 700 (3.6). The list in code is Noto, IBM Plex, Readex Pro, Cairo, and Noto Sans with Noto Kufi Arabic.
- **Not applied yet:** `shape.morph`, `type.titleWeight` and `type.emphasis` are stored and validated but no component reads them; they land with the components that morph and the title styles. The `documents` block is stored but does not yet set the document theme's defaults.
- **Plugin themes (SDK 1.125):** `Whity\Sdk\Contribute\PluginThemesInterface::getThemes()` returns theme files, checked on load (ids under `whity.` refused, the first plugin keeps a shared id) and listed as "From <plugin>" in workspaces where the plugin is on. Plugins read the result through their context, `$ctx->themes()` (`Theme\WorkspaceTheme`: `current()` and `installed()`), with no workspace argument (D2) and no way to apply a theme. `PluginThemeInterface` is deprecated, working through 2.0.x. The CLI and the queue worker list Whity's own and imported themes only, since they pass no plugin loader yet.

### 12.2 Free tweaks and export (TH.15, TH.16)

- **Tweaks:** `shape`, `density` and `font` sit in `branding_theme_overrides` next to the colour overrides (`{light, dark, shape, density, font}`), so there is no new setting and no migration. `ThemeTweaks::apply` puts them on the chosen theme before it compiles, in the resolver and in the picker's previews; a font pair sets body and headings together. They stay when the workspace chooses another theme.
- **Export:** `GET /api/v1/themes/export` (settings:read) returns the theme in use as a file, with the main colour as the primary seed, the colour overrides and the tweaks folded in. A copy of one of Whity's own themes is named `workspace.<name>`, since `whity.` can't be imported.
- **Newer versions:** `POST /api/v1/themes?check=1` saves nothing and lists what the file changes in the installed version, field by field; the picker says it in words and asks before replacing.

## 13. Owner's decisions (19 Nov 2026)

| # | Question | Answer |
| --- | --- | --- |
| TH.4 | Five schemes, Calm as the default | Accepted as recommended |
| TH.6 | Fonts on product screens | **Headings and body** (not the recommendation of headings only). A theme may set the whole Latin and Arabic pair from the allowed list; 3.1 is updated. The A.1 and M.5 minimums hold, and fonts stay out of print |
| TH.7 | Status colours fixed | Accepted as recommended |
| TH.9 | Soft and flat elevation | Allowed, as recommended; agent marks stay |
| TH.10 | Expressive springs | Allowed, as recommended; a person's Calm or Off wins |
| TH.11 | Theme fonts in print | No, as recommended (E.46 stays) |
| TH.12 | Higher contrast per person | Yes, as recommended |
| TH.13 | Selling themes | Yes, through Whity Pay, as recommended |
| TH.14 | What the free picker does | Choose a theme, set the primary seed and overrides; the rest is Studio. As recommended. **Superseded 21 Nov 2026 by TH.25:** the rest is core too |

TH.1–TH.3, TH.5 and TH.8 were decided as written in this spec.

## 14. Expansion (decided 21 November 2026)

**Status: decided by the owner on 21 Nov 2026** (register TH.15–TH.24): every question in 14.9 was answered as recommended. Building follows 14.9 question 10, one PR per step. The decisions in section 13 stand:
- tokens only;
- applying any theme is free;
- the editor was part of paid Whity Studio; since TH.25 (same day) it is free in core;
- plugins can ship themes.

Every addition below stays a value in the theme file (section 4), checked by the guard rails (section 6), so a theme still can't add CSS, URLs or code. The questions and the owner's answers are in 14.9.

### 14.1 More shape and the corner rules (builds on the §3.3 corner rules in #2340)

- **Two more presets:**
  - `pebble`: Soft's corners on cards and dialogs, a full pill on single-line controls. It sits between Soft and Round, for people who like Round's buttons but not its cards.
  - `cut`: M3's cut corners (a 45° chamfer) at Crisp's sizes, for formal brands such as law firms and banks.
  - `cut` needs `corner-shape`, which isn't in every browser yet. Where it's missing the browser draws rounded corners instead, so nothing breaks.
- **Shape morph switched on** (`shape.morph`, stored since 12.1). Two things change shape on press or selection:
  - the main button gets squarer while pressed;
  - a selected chip goes from pill to rounded.

  It follows the person's Animations setting: Calm and Off show the end state with no movement.
- **The corner rules stay global.** The corner rules from §3.3 apply to every preset, and every new preset joins the CI check before it can ship:
  - a pill is half the height;
  - padding grows with the corner;
  - nested corners use the outer radius less the padding.

### 14.2 Per-component shape overrides

A theme may give one of a fixed list of component families its own preset:

```json
"shape": { "preset": "soft", "components": { "buttons": "round", "cards": "crisp" } }
```

- **Families (closed list):** `buttons`, `fields` (inputs, selects, text areas), `chips` (chips, badges, tags), `cards`, `dialogs` (dialogs, sheets, popovers, menus), `tables`, `navigation` (sidebar items, tabs, segmented controls).
- **Values:** a preset name only, never pixels, so the corner rules and the CI check still cover every combination.
- **Guard rails:**
  - `fields` and `cards` never go past Round's cap, because they grow;
  - a container and its edge-to-edge children (tables in cards, tabs in a page header) get matching inner radii automatically.
- Out of scope: per-screen or per-component pixels, and avatars or status dots (they stay round, §3.3).

**Built (WHIT-1225):**
- The theme file's `shape.components` takes any of the seven families, each a preset name (`schemas/theme/v1.json`). The compiler writes that preset's whole radius set at the theme's density as `--wy-<family>-radius-*`, with its own corner (`--wy-<family>-corner-shape`) and morph, which follows the theme's `shape.morph` or the family preset's default. A family on the theme's own preset writes nothing.
- Every radius utility reads a `--shape-*` property, set on `:root` and again on each family's elements, found by their `data-slot` (`SHAPE_FAMILIES` in `src/design/tokens/generate-tokens.js`): buttons (`button`, `icon-button`); fields (inputs, text areas, select triggers, search, tags, bilingual, phone, code, date and time triggers); chips (badges, actor chips, the phone filter chips); cards; dialogs (dialog, alert, sheet, popover, select, menus, menu bar, command palette); tables (the table container, the data table); navigation (sidebar nav, tab lists, toggle groups, view toggle, menu bar).
- Anything inside a family's element without a family of its own follows it, so a container and its children match; edge-to-edge children are clipped by the container (§3.3).
- Round is already the largest preset and its pill is half the control's own height, so `fields` and `cards` can't pass Round's cap.
- The free shape tweak (TH.15) changes the theme's preset; families the theme set keep their own.
- The CI check (`shape-clip-check.mjs`) runs two mixes as well as every preset: Round with every family on another preset, and Square with every family rounded.
- Making them in the editor is the last step of TH.24.

### 14.3 Density

- **Per area:** `density` stays one value, plus optional `density.tables` and `density.forms`. A clinic can have roomy forms and compact appointment tables.
- **Per person**, like Higher contrast: a **Compact / Standard / Comfortable** choice in Language and display that wins over the theme's. It never goes below A.1 (Compact is the floor), and it's off on phones, where 48px targets hold (M.5).
- Spacing inside cards and dialogs follows density as well as the shape rule.

### 14.4 Typography pairs, Arabic first

A pair is chosen by its **Arabic** face, and the Latin face follows. The list grows only through a PR that adds the files, licence (OFL) and metrics (§3.2):

| Id | Arabic | Latin | Why | Has 400/500/600 |
| --- | --- | --- | --- | --- |
| `noto` | Noto Sans Arabic | Noto Sans | default | yes |
| `plex` | IBM Plex Sans Arabic | IBM Plex Sans | corporate, government | yes |
| `readex` | Readex Pro | Readex Pro | modern, one family | yes |
| `cairo` | Cairo | Cairo | rounded, friendly | yes |
| `kufi` | Noto Kufi Arabic (headings) | Noto Sans | formal headings | yes |
| *new* `rubik` | Rubik | Rubik | soft geometric, retail and salons | yes |
| *new* `alexandria` | Alexandria | Alexandria | contemporary Kufi, tech and schools | yes |
| *new* `naskh` | Noto Naskh Arabic (body) | Noto Serif | classic, law and print-like | yes |
| *new* `ibm-kufi` | IBM Plex Sans Arabic (body) + Noto Kufi Arabic (headings) | IBM Plex Sans | formal with a corporate body | yes |

- Tajawal and Almarai stay out until they have a 600 weight (12.1).
- The picker previews each pair with one Arabic and one English sentence at body and title size.
- **Arabic metrics:** each pair carries its own line height and size-adjust, so Arabic stays at 100% of the Latin size (3.7a) and lines don't jump when the language changes.

### 14.5 Elevation and motion

- **Elevation:**
  - add `outline` (no shadows anywhere; floating layers get a 1px border and the plane becomes a 2px border), for high-contrast and print-like brands;
  - `elevation.float` already has three steps; keep them.
- **Motion:**
  - add `motion.preset: brisk`: M3 standard springs at 0.8× the durations, for busy front desks;
  - morph (14.1) uses the theme's spring.

  The person's Animations setting still wins (06-motion, LT.4).

### 14.6 More themes, one per workspace template

The first seven workspace templates ship in the free `workspace-templates` store package, not in core. Their themes ship there too, through the plugin theme interface (SDK 1.125), as "From Workspace templates". Choosing a template offers its theme when the workspace is created, and the person can change it at any time, for free. A manifest names its theme with a new optional `theme` key, SDK 1.131 (built in core). The template picker shows "Use its look" with the theme's name, on by default; core sets the theme only while the workspace still has the installation's theme, and offers nothing when the theme is not installed.

| Template | Theme | Like |
| --- | --- | --- |
| Coffee house | `templates.roastery` | warm brown and cream, Cairo, soft |
| Study house | `templates.library` | quiet green, Plex, crisp, roomy |
| Meeting rooms space | `templates.studio` | tonal violet, Readex, round, brisk |
| Salon | `templates.salon` | Bloom's pink, Rubik, round with crisp cards, expressive |
| Clinic | `templates.clinic` | calm teal, Plex, soft, comfortable |
| Bakery or Café | `templates.market` | Desert's warm tones, Cairo, pebble |
| Car dealership | `templates.showroom` | Ink with a red accent, Alexandria, cut, outline |

Whity's own five (Whity, Harbour, Ink, Bloom, Desert) stay in core for every workspace.

### 14.7 Theme import and export

All free in core (TH.16, TH.25).

| What | Where |
| --- | --- |
| Export the theme in use as a Whity theme file (JSON v1) | the picker (built, #2343) |
| Import a Whity theme file | the picker |
| Re-import a newer version of an imported theme | says what changed in words before replacing (built, #2343); the editor adds a side-by-side diff |
| Import from shadcn CSS variables, a Tailwind v4 `@theme`, or Material Theme Builder JSON | the picker's "Import from another tool" (built, WHIT-1225, see below); the editor will open the result to change it |
| Export resolved tokens as CSS, JSON, Tailwind or Flutter | the picker's Export menu for the theme in use; `POST /themes/tokens` for a draft (built, WHIT-1225, see below) |
| Share a theme with another workspace you own | export and import now; a "Use in…" button with the editor |
| Store packages (signed, hashed, through Whity ID, per the store move) | install free; a publisher may sell a theme through Whity Pay (TH.13); publishing from the editor |

**The importers (built, WHIT-1225, TH.25).** `POST /api/v1/themes/convert` (`settings:write`) turns what another tool made into a Whity theme file and saves nothing. The picker shows the result with a preview and what was kept in words, then adds it through the same checked import as a theme file (TH.16), so a newer version still says what it changes (`ThemeImporter`).

- **shadcn:** the `:root` and `.dark` blocks, including inside `@layer base`. Colours can be oklch, hsl, shadcn's bare `h s% l%`, rgb or hex; alpha is dropped.
- **Tailwind v4:** the `@theme` block's `--color-*`, following `var()` within the block.
- **Material Theme Builder JSON:** `coreColors` become the seeds, and the light and dark schemes become the roles Whity has. Surface containers lowest to highest become surfaces 1 to 5, and the scheme is Tonal.
- **Colours:** the main colour (`primary`) becomes the seed. Each colour whose role Whity has becomes a colour set by hand in its mode. Status colours stay Whity's (TH.7), and the guard rails still apply when the theme is used, so the result says how many colours they will change.
- **Radius and font:** `--radius` (shadcn's `rounded-lg`) becomes the nearest preset: 0 is Square, up to 6px Crisp, up to 11px Whity, up to 15px Soft, and larger Round. A font becomes its pair only when Whity has it with an Arabic face; otherwise the theme keeps Noto Sans and says so.
- **Id and name:** the id is `imported.<name>`, and the name is plain text.
- **Not yet built:** the side-by-side diff and publishing, the rest of section 11.


**Token export (built, WHIT-1225, TH.25).** `ThemeTokenExport` writes a theme's resolved tokens for another tool. Resolved means what the workspace sees:
- every colour for light and dark after the guard rails;
- every radius and size with Whity's defaults (`ThemeCompiler::resolvedShape`), and each family's own corners (14.2);
- the fonts by family name, never Whity's CSS variables (`ThemeFonts::families`);
- the motion timings.

The formats:
- **CSS:** custom properties on `:root`, with dark under `.dark`.
- **JSON:** the W3C Design Tokens Community Group format. A spring easing has no token type, so it is a described `linear()`.
- **Tailwind:** a Tailwind v4 file with `@theme` (`--color-*`, `--radius-*` including `--radius-<family>-*`, heights as `--spacing-*`, fonts and easing) and dark under `.dark` in `@layer base`.
- **Flutter:** a Dart class in the shape of whity_tokens' `AppTokens` (`lightColors`, `darkColors`, `radius`, `size`, fonts), plus Material 3 `lightScheme` and `darkScheme` and a `theme(Brightness)`.

Where it is available:
- `GET /api/v1/themes/export/tokens?format=` exports the theme in use with everything the workspace set. The picker's Export menu offers it beside the theme file.
- `POST /api/v1/themes/tokens` exports a draft.
- The theme name goes into a comment as plain text, and nothing in it can close the comment.

### 14.8 Free and paid

This table first split the theme system between free core and paid Whity Studio. Since TH.25 there is no split: **every row is free in core**, for every workspace.

| Feature | Core |
| --- | --- |
| Apply any theme: Whity's, a plugin's, imported, from the store | free |
| Main colour, colour overrides, Higher contrast, per-person density | free |
| Shape preset, density and font pair on top of the chosen theme (TH.15) | free |
| Per-component shape overrides (14.2): applying and making them | free |
| Live editor, seed generator, logo colours, presets to start from, preview across key screens, contrast report, versions, publishing (11) | free |
| Export and import as in 14.7 | free |

Paid stays where TH.25 puts it: extra usage and users, and plugins that integrate outside services. A theme a publisher sells in the store is the publisher's price, not a Whity tier.

### 14.9 Questions for the owner (answered 21 Nov 2026: all as recommended, TH.15–TH.24)

1. **Free tweaks.** Can a free workspace change the shape preset, density and font pair on top of a theme, as it changes the main colour today? Recommended: **yes**. They're single choices from closed lists, cheap to support, and they answer most "make it ours" requests. Studio keeps everything that needs an editor. *(Since TH.25 the editor is free in core too.)*
2. **New shape presets.** Add Pebble and Cut (14.1)? Recommended: **both**, with Cut falling back to rounded corners where `corner-shape` isn't supported.
3. **Morph on by default** in the Round and Pebble presets? Recommended: **yes**, with Calm and Off showing no movement.
4. **Per-component overrides** (14.2): the seven families and presets only, no pixels? Recommended: **yes**.
5. **Per-person density** in Language and display (14.3)? Recommended: **yes**, desktop only, never below A.1.
6. **The four new font pairs** (Rubik, Alexandria, Naskh, IBM Plex with Kufi)? Recommended: **all four**. Naskh is for product screens only if TH.6 still allows the whole pair; print keeps E.46.
7. **Outline elevation and brisk motion** (14.5)? Recommended: **yes**.
8. **A theme per workspace template** (14.6), shipped in the templates package and offered at creation? Recommended: **yes**.
9. **Free export** of the theme in use (14.7)? Recommended: **yes**. A workspace's look is its own data.
10. **Order of work.** Recommended:
    - free tweaks and export;
    - presets and morph;
    - fonts;
    - template themes;
    - per-component overrides;
    - Studio's importers. *(Since TH.25: the editor and importers, free in core.)*
