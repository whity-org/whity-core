# Whity sites (3.3)

Build-ready static pages for the Whity sites (decisions WS.1–WS.23), in English and Arabic.

| Site | Pages | What it's for |
| --- | --- | --- |
| `whity.dev/` | `index.html`, `money.html`, `stock.html`, `approvals.html`, `documents.html`, `security.html`, `partners.html`, `about.html`, `contact.html`, `install.html`, `privacy.html`, `license.html`, `404.html`, `llms.txt`, `.well-known/security.txt`, and the same pages under `ar/` | The home page: two doors, **companies first** (what Whity does, agents, works locally, your brand, plans from the backend), then **builders** (one-line install, SDK, MCP); the module pages; and the company pages. Every page ends with the mega footer (WS.14) |
| `brand.whity.dev/` | `index.html`, `ar/`, `whity-brand-kit-3.3.zip` | The brand guide: mark, downloads, colour, type, the plane, agents, voice, Arabic, using the name, white-label |
| `health.whity.dev/` | `index.html`, `ar/` | Status: one plain sentence on top, every part with 90 days of history, what's happening, updates by email or RSS |
| `id.whity.dev/` | `index.html` (sign in), `approve.html` (number match), `account.html`, `ar/` | Whity ID: one account for every workspace (M.26–M.33) |
| `docs.whity.dev/` | `index.html`, `first-invoice.html`, `ar/` | Docs: search, Using Whity for companies, Building on Whity for builders, an article layout |

- `assets/tokens.css` and `assets/fonts/` come from the design system; `assets/site.css` is the sites' own layer. Scripts: `assets/plans.js` (plans from the backend), `assets/whity-site.js` (small details, footer, contact form), `assets/whity-lottie.js` with `assets/lottie_light.min.js` (animations); every page works without them; theme follows the system setting (`data-theme` on `<html>` forces one).
- Arabic pages are under `ar/` with `dir="rtl"`; the name stays "Whity" in Latin letters; numbers stay Western digits.
- Copy that names prices or features uses placeholders (`[price]`, `[n] people`) until they're real. **Before launch, check every feature claim against what has shipped.**
- The status page shows example data based on the live page on 8 November 2026; wire it to the real `/status` JSON.
- Turn these into the real sites in whatever stack each one uses (whity.dev is a static site today; id.whity.dev is the identity provider's own templates). Keep the HTML structure and class names so the design stays exact.

## Plans come from the backend (WS.5)

The plans on whity.dev (prices, limits, features, which plan is highlighted, the dedicated-hosting "from" price) are read from Whity's backend, so changing a plan in Whity changes the website without a redeploy.

**Fallback, in order:** live data → the last good copy saved in the visitor's browser (up to 14 days) → the plans built into the page. The page is complete without JavaScript; `assets/plans.js` only refreshes it. The built-in copy comes from the same endpoint when the site is built (`mksites.py` tries it first) and from `assets/plans.default.json` when the backend can't be reached. The section reports what it used in `data-plans-source` (`live`, `saved`, `built-in`).

**Endpoint (to build in Whity Core):** `GET https://app.whity.dev/api/public/plans`

- Public, no sign-in, read-only; built from the plan entitlement bundles (ADR 0010) plus a small marketing layer (tagline, features to show, highlight, order, visible on the website).
- Headers: `Content-Type: application/json`, `Access-Control-Allow-Origin: https://whity.dev`, `Cache-Control: public, max-age=300`.
- Answers within 3 seconds or the page keeps its built-in plans.

```json
{
  "version": 1,
  "currency": "JOD",
  "decimals": 3,
  "updatedAt": "2026-11-09",
  "plans": [
    {
      "id": "team",
      "highlight": true,
      "name": { "en": "Team", "ar": "الفريق" },
      "tagline": { "en": "For growing teams", "ar": "للفرق المتنامية" },
      "price": { "monthly": "29.000" },
      "limits": { "staff": 25, "customersPerMonth": 5000 },
      "features": [ { "en": "Your own web address and sign-in", "ar": "عنوانك وصفحة دخولك الخاصة" } ],
      "cta": { "label": { "en": "Start free", "ar": "ابدأ مجاناً" }, "href": "https://app.whity.dev/register?plan=team" }
    }
  ],
  "dedicatedHosting": { "fromMonthly": "150.000" }
}
```

Rules the page checks (anything else is ignored and the fallback is used): `version` is 1; 1–4 plans; prices are decimal strings in normal units (`"29.000"`, never minor units) or `null` for "not set" (shown as `JOD [price]`); limits are whole numbers; up to 6 features, each with an English text (Arabic optional, English is the fallback); links are `https://`. The page writes the limit lines itself ("Up to 25 people", "حتى 25 شخصاً") and shows numbers in Western digits. All text is inserted as text, never as HTML.
