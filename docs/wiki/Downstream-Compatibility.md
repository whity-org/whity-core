# Compatibility for products built on Whity

What a product that runs the official Whity images, unforked, can rely on
from one minor release to the next (whity-org/whity-core#2386).

## The promise

The interfaces listed under [What is stable](#what-is-stable) are stable
across minor releases (0.2.x to 0.2.y, and 1.x to 1.y once 1.0 ships):

- **Nothing listed there is renamed or changes meaning.** A field, id,
  variable, setting key or header keeps its name and what it does.
- **Additions can arrive in any release.** These include new routes, new ids,
  new optional fields, and new variables and settings. Read what you know and
  ignore the rest.
- **A removal is announced in the release notes.** That is the `Removed`
  section of `CHANGELOG.md` and the GitHub release page for the version that
  makes it. Each release's notes also list the web routes it added and removed,
  compared with the previous release's `web/routes.json`.
- **A major release (1.0, 2.0) may change anything.** Its notes list what
  changed.

Two older promises still apply: the HTTP API's deprecation policy (at least 6
months, with `Deprecation` and `Sunset` headers; see
[API Deprecation & Lifecycle Policy](API-Deprecation-Lifecycle.md)), and
semantic versioning of the plugin SDK. This page adds what a product beside
Whity needs on top of them.

## What is stable

### Routes and ids

| What | Where | Details |
|---|---|---|
| Route manifest format | `GET /web-build/routes.json` | `schema: 1` and the fields `path`, `kind`, `area`, `nav_ids`, `settings_ids`, `since`. A change in a field's meaning raises `schema`. See [Stable routes and ids](Stable-Routes-And-Ids.md). |
| Menu ids | `GET /api/navigation`, `web/lib/routes/stable-ids.json` | Core ids, and `plugin-<featureId>` for plugin screens. |
| Settings page ids | `GET /api/settings/tabs`, the Settings area | The `settings` list in `stable-ids.json`. In hide lists they are written `settings.<id>`. |
| Hidden-ids endpoint | `GET /api/navigation/hidden` | `{"data": {"nav": [...], "settings": [...]}}` |

### Environment variables

| Variable | Container | Since | Page |
|---|---|---|---|
| `WHITY_HIDDEN_NAV_IDS` | backend | 0.2.32 | [Hiding pages](Hiding-Pages.md) |
| `WHITY_HOME_PATH` | backend | 0.2.33 | [Home page and back link](Home-Page-And-Back-Link.md) |
| `WHITY_BACK_TO_PRODUCT_URL`, `WHITY_BACK_TO_PRODUCT_LABEL` | backend | 0.2.33 | [Home page and back link](Home-Page-And-Back-Link.md) |
| `WHITY_TRUSTED_PROXIES` | backend | 0.2.33 | [Same-origin setup](Same-Origin-Setup.md) |
| `WHITY_CLIENT_IP_SECRET` | backend and web | 0.2.33 | [Same-origin setup](Same-Origin-Setup.md) |
| `WHITY_PWA_SCOPE` | web | 0.2.33 | [Same-origin setup](Same-Origin-Setup.md) |
| `APP_URL`, `FRONTEND_URL` and the `*_URL` link overrides (`SSO_SUCCESS_URL`, `SSO_LOGIN_URL`, …; `HOME_URL` since 0.2.33) | backend | before 0.2.32 | [Same-origin setup](Same-Origin-Setup.md), `src/Core/Http/FrontendUrls.php` |
| `PWA_ENABLED`, `TRUSTED_PROXY_HOPS`, `WHITY_BACKEND_URL` | backend / web | before 0.2.32 | [Same-origin setup](Same-Origin-Setup.md) |

The route manifest and hiding pages ship in 0.2.32; the home page, the back
link and the same-origin pieces in 0.2.33, as planned on #2386. `/web-build`
and `/api/health` say which version you run.

### Instance settings

The setting keys below, with the values they accept, are stable:

- `navigation.hidden_ids`, `navigation.home_path`,
  `navigation.back_to_product_url`, `navigation.back_to_product_label`;
- `app.installable`;
- the theming keys under [What you can theme](#what-you-can-theme).

### Headers and responses

- `X-Whity-Client-Ip`, and `X-Whity-Client-Ip-Signature` in its `v1=` format:
  `v1=<unix time>.<hex HMAC-SHA256 of "v1\n<time>\n<ip>">`.
- Session cookies are `Path=/api`, `HttpOnly` and `SameSite=Lax`, with no
  `Domain`. The CSRF rule is `X-Requested-With: XMLHttpRequest` on
  cookie-authenticated writes.
- The public branding response (`GET /api/branding`) keeps these fields:
  `siteName`, `logoWideUrl`, `logoSquareUrl`, `faviconUrl`, `mainColor`,
  `theme`, `installable`, `currency`, `homePath` and `backToProduct`.

## What is not covered

Anything not listed above can change in any release without notice:

- labels, words and translations;
- icons, layout and the order or grouping of menu items;
- CSS class names, the DOM, `data-testid` values and component structure;
- `/_next` chunk names and the build's file layout;
- the internal API between the web app and the backend, unless it is part of
  the documented HTTP API;
- pages that the route manifest does not list.

Don't style or script Whity's pages from outside them. Use the theming keys
below. If you need something they don't cover, ask for it in an issue.

## What you can theme

Theming is set at instance level, on the system workspace (Settings ›
Branding, `/admin/settings/branding`). Every workspace inherits it unless it
sets its own; a workspace can override each item.

| What | Setting key | Value |
|---|---|---|
| Name | `site_name` | Up to 120 characters. It replaces "Whity" in the sidebar, page titles and the installed app's name. |
| Wide logo | `branding_logo_wide` | Uploaded through `POST /api/v1/branding/global/assets/logo_wide`: PNG, WebP or SVG (sanitised). |
| Square logo | `branding_logo_square` | `POST /api/v1/branding/global/assets/logo_square`: PNG, WebP or SVG. Used in the collapsed sidebar and as the app icon. |
| Favicon | `branding_favicon` | `POST /api/v1/branding/global/assets/favicon`: ICO or PNG. Falls back to the square logo. |
| Main colour | `branding_main_color` | `#rrggbb`. The light and dark token sets are derived from it, and it is darkened if a label could not be read on it. |
| Theme | `branding_theme` | A theme id (`vendor.name`) from the installation's theme library, or empty for Whity's own. This is the installation theme. |
| Theme adjustments | `branding_theme_overrides` | JSON. `shape` is one of `square`, `crisp`, `whity`, `soft`, `round`, `pebble`, `cut`. `density` is one of `compact`, `standard`, `comfortable`. `font` is a font pair id from the list below. `light` and `dark` hold colour overrides, which pass the same contrast guard rails. |

**Font pairs** (`font`): `noto` (the default), `plex`, `readex`, `cairo`,
`kufi`, `rubik`, `alexandria`, `naskh` and `ibm-kufi`. Each is a Latin face
and an Arabic face in the 400, 500 and 600 weights. The fonts are built into
the web image and the phone app, so a theme names an id, never a file or a
URL.

**What you can't theme today:**

- **A font outside that list.** It would need a runtime font layer: fonts
  loaded at runtime and their licences checked. That is planned in the themes
  work, not part of this promise.
- **Custom CSS or layout.** There is no stylesheet hook, by design. Every
  visible value goes through the tokens and their guard rails, so contrast and
  the 13px floor hold for every theme.
- **Printed documents.** They keep their own type scale and themes
  ([Document themes](Document-Themes.md)).
