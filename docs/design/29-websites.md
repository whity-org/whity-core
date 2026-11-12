# Websites

The public Whity sites, redesigned on the 3.x brand (WS.1–WS.3, 8 November 2026). Each is drawn as a design-system card (group **Websites**) and delivered as build-ready static pages in English and Arabic (the handoff package's `sites/` folder).

## Who each site is for

| Site | For | What changed |
| --- | --- | --- |
| **whity.dev** | Companies first, then builders (WS.1) | Was written for developers. Now it opens on what Whity does for a small company (one place, agents prepare, you approve), and offers a second, quieter door to builders under the hero and again as a blue "For builders" section |
| **brand.whity.dev** | Partners, press, builders, the team | One page with a side index: the mark, downloads, colour, type, the plane, agents, voice, Arabic, using the name, white-label |
| **health.whity.dev** | Customers checking if something's wrong | One plain sentence on top ("2 parts of Whity aren't working"), every part with 90 days of history, what's happening with the next update time, updates by email or RSS |
| **id.whity.dev** | Everyone signing in | Sign in (email, passkey, Apple, Google), approving on the phone with a number to match, and the account page (workspaces, sign-in and security) — M.26–M.33 |
| **docs.whity.dev** | Both | Search first, then the same two doors: Using Whity (companies) and Building on Whity (builders); an article layout with a side index and "on this page" |

## The home page, top to bottom

1. **Hero** (WS.6): "Your company's work, in one place. Agents prepare it. You approve it." with Start free and Book a demo. Beside it, the web Home (Waiting for you, with agents' proposals dashed) and, in front of it, the phone with the same proposal and its Approve button. On phones only the web Home shows. Book a demo opens Whity's own booking page (`TplBookingPage`): 30 minutes with the team, in Arabic or English.
2. **Second door:** "Building software for companies? Whity is open source →", small and dashed, under the buttons.
3. **What it does** (WS.7): four large cards for what owners care about most (invoices and money, stock and recipes, approvals, documents and signing), each with a small screen and a link to its own page; then a compact row for tasks and boards, chat, appointments and making it yours (linking to the docs for now).
4. **Agents** (WS.8, LT.2): one proposal visitors try themselves (Approve sets it down, Undo plays it back) beside the three steps, which light up as it goes; then "Agents never".
5. **Works locally** (WS.9, WS.17): "Local where it matters": Arabic and English in both directions, your tax system's e-invoicing (Jordan's JoFotara first), local payments, cards and cash (like CliQ in Jordan), WhatsApp, and your currency to the right decimals, each with a small animation, beside a receipt visitors switch between English and Arabic.
6. **Your name on it:** the white-label promise, with the brand animation (the workspace's sheet sets down on Whity's platform) and "Try a colour" buttons that recolour it.
7. **Plans** (BM.3–BM.5): Free (5 people, 500 customers a month, JOD 0), Team (25, 5,000) and Business (100, 25,000) at `[currency] [price]` (the currency comes from the backend; the built-in copy says JOD); a line on dedicated hosting, your own server and reselling (BM.2, BM.10); then the four promises (BM.11).
8. **For builders** (WS.11): a terminal that types the real install commands (from whity.dev/install) with Copy and Play again; open source (AGPL core, MIT SDK), build apps and agents can run it (MCP), each with a small animation; and one line for agencies linking to a partners page. Install takes three commands today; a single-line installer would make this section stronger.
9. **Questions** (WS.12): eight straight answers, always open, then "Book 30 minutes with the team". **Final call:** on its own sheet on the plane, with four ticks. **Footer:** every Whity site, live status from `health.whity.dev/status` (`{"status": "ok" | "degraded" | "down"}`; a plain link if it doesn't answer in 3 seconds), the four promises and Back to top.

## Rules

- **Whity is a global tool (WS.17).** The sites never say Whity is built for companies in Jordan or the region. Jordan appears only as where Whity comes from (built in Amman, Amman time for calls) and as the first example of a local feature ("Jordan's JoFotara first", "like CliQ in Jordan"). Data is "stored in the EU" (WS.18), with no GDPR claim; inside one country means a dedicated installation there for a fee, or the company's own server. Example companies and amounts in screens may stay Jordanian; they are examples.

- Same tokens, fonts and icons as the app; the sites add one stylesheet (`site.css`, classes `s-*`). The only scripts are `plans.js` and the Lottie player (`whity-lottie.js` with lottie-web's light build); every page works without them.
- 17px body text, 48px buttons, high contrast: the readers are often 50+.
- The plane (hard offset, down and to the left) only under the one main button and the hero sheet; it does not mirror in Arabic, like the mark.
- Arabic pages are fully mirrored under `/ar/`; the name stays "Whity" in Latin letters; numbers stay Western digits.
- Example companies (Example Bakery, Amman Coffee Co.) and the Whity team as the people (WS.4); placeholders for prices and limits. **Every feature claim is checked against what has shipped before launch.**
- The status page runs apart from Whity so it stays up when Whity doesn't, and says plainly what isn't working.

## Small details (WS.10)

- The hero sets down once on load: the web Home lands on its plane, then the phone slides in. Pressing Approve on the hero's phone updates the web Home behind it (the count drops, the row turns solid), and Undo puts it back.
- Sections ease in once as they come into view (10 px, 400 ms); nothing stays hidden for more than 4 seconds.
- The main button lifts 1 px and its plane grows from 3 to 5 px on hover; clickable cards lift onto the plane; informational cards never move.
- The nav gets a line under it once the page scrolls.
- All of it is in `whity-site.js` and `site.css`; none of it runs when less motion is asked for, and every page reads and works without it.

## Mega footer (WS.14)

Every whity.dev page ends with the same footer, top to bottom:

1. **Who we are:** the reversed logo, "The platform a company's systems sit on. Open source, for companies anywhere.", live status from health.whity.dev, the other language, and a colour switch (same as my device, light, dark). The choice is kept in the browser (`whity.theme`); with nothing saved the page follows the device.
2. **Talk to the team:** thirty minutes in Arabic or English, the time in Amman now (`Intl`, Asia/Amman, updated every 15 seconds), Book a call and Start free.
3. **Everything Whity does:** eight white tiles (the four module pages, then tasks and boards, chat, appointments, make it yours, which link to the docs until their pages exist). When the row comes into view the plane draws out under it and the tiles set down one after another, 70 ms apart.
4. **Links:** Product, Company (About, Partners, Contact, Brand), Builders (Docs, Install, GitHub, Plugin SDK, llms.txt), Trust (Security, Status, Privacy, Licence, Trademark), Your account.
5. **The name:** a giant, faint "Whity" whose letters rise onto a dark plane when it comes into view. It is a picture of the word, hidden from screen readers; the logo itself is unchanged.
6. **Legal line:** © 2026 Whity and the trademark line, the four promises, Back to top.

The band is #0a3d74 with the blueprint grid (#071d38 in dark mode). Text is at least 14.5 px, links 16 px. A soft light follows the mouse on computers only. Everything is in `sitemods.mega_footer`, `site.css` and `whity-site.js`; if the scripts don't run, everything shows in its final place.

## Install (WS.20)

`whity.dev/install`: "Run Whity on your own machine, in one line." with the terminal (copy, types once), a note to read the script first, the five steps the line takes, the manual steps from the README with the line's options, and where next (Whity Cloud, the SDK, GitHub issues). The home page's builders terminal shows the same one line.

### The installer script (`https://whity.dev/install.sh`), for the team to write

1. Detect the system (macOS, Linux, Windows under WSL); anything else: say so and link to the manual steps.
2. Check `docker` (and that it's running) and `node`; if either is missing, print one plain line with where to get it, and stop **without changing anything**.
3. Clone `https://github.com/whity-org/whity-core.git` into `./whity-core` (or `--dir`); a `--version` checks out that tag.
4. Run `make setup` (Docker Compose: FrankenPHP and PostgreSQL 15, migrate, seed; `--empty` skips the sample data).
5. `cd web && npm install && npm run dev` in the background; wait for http://localhost:3000.
6. Create `admin@localhost` with a one-time password, print it, and open the browser.

Each step prints one line as it starts and a ✓ when done; any failure prints what went wrong and the command to retry. Never asks for `sudo`. The terminal output on the page is exactly what the script prints.

## Privacy (WS.21)

`whity.dev/privacy`: "Privacy, in plain words." Six cards (what we keep, why, where, who can see it, how long, what you can do), four more answers (your customers and staff, cookies, agents, changes), and the full policy's place, marked "To be added", for the lawyer's text (numbered sections, a table of contents, 68 characters a line at most). Numbers in brackets (`[30]` days to delete, `[35]` days for backups) are to be decided.

## Licence (WS.22)

`whity.dev/license`: "Free to use. Open to read." What you can do, what you must do, and the plugin exception; a "Can I…?" table (run it unchanged, run it under your brand, a closed-source plugin, change Core and offer it online, use the name); the trademark; and links to the licence text on GitHub and gnu.org. "This is a guide, not legal advice."

## 404 (WS.23)

`whity.dev/404`: the `not-found` animation, "This page isn't here.", and five ways on. Serve it for any unknown path, in the visitor's language folder.

## Contact page (WS.19)

`whity.dev/contact` (Arabic under `/ar/`):

1. **Hero:** "Talk to us." "Book a call, send a message or email the right address. We read everything." and the time in Amman.
2. **Who are you?** Four choices as tabs (arrow keys move between them, a soft highlight slides to the chosen one), each with one line and its own buttons:
   - *I'm new to Whity:* Book a call, Write a message, hello@whity.dev.
   - *My company uses Whity:* Open Whity (Help is in its menu), Read the guides, support@whity.dev.
   - *I run Whity Core myself:* Open an issue on GitHub, Read the docs, Install guide.
   - *I'm an agency or host:* Book a partners call, See Partners, partners@whity.dev.
   Choosing "agency or host" also sets the form's topic to Partnering. Without scripts all four show one under another.
3. **Write to us:** topic chips (A question, A demo, Partnering, Something else), your name, company (optional), email or phone, your message, Send message. "We only use this to reply to you." Missing fields get a plain sentence under them and the first one is focused. While sending the button says "Sending…"; on success the form is replaced by a green tick that draws in and "Thanks, Ahmad. Your message is with us."; on failure the message stays and hello@whity.dev is offered.
4. **The right address:** hello@ (questions, anything else), support@ (companies already using Whity), partners@ (agencies and hosts), security@ (with How to report), each with Copy.

No reply-time promise.

### Contact form contract (v1)

`POST https://app.whity.dev/api/public/contact`, JSON (or a normal form post when scripts are off: reply with a redirect back to `contact.html?sent=1`):

```json
{ "topic": "question | demo | partners | other", "name": "Ahmad Khalil", "company": "", "contact": "ahmad@example.com", "message": "…", "lang": "en | ar", "website": "" }
```

`website` is a hidden trap field: if it has anything in it, drop the message quietly. Answer `2xx` when the message is saved (it lands in the Whity team's inbox in Whity); anything else shows the failure line. Allow `https://whity.dev` in CORS, rate-limit by address, and never send the message back in the response.

## About page (WS.16)

`whity.dev/about` (Arabic under `/ar/`), top to bottom:

1. **Hero:** "We kept building the same system. So we built it once, and opened it.", Start free and Read the code. Beside it, three cards ("Client 1, 2, 3", each with invoices, stock, approvals, documents, sign-in) fan out under "The same system, built again for every client", then slide together into one "Built once" card that lands on the plane: "One base, open for everyone". Pure CSS, plays once on load; people who ask for less motion see the end state.
2. **Facts:** started 2026; built in Amman, Jordan (where Whity comes from, not who it's for); open source; Arabic and English.
3. **What we promise:** the four promises (BM.11).
4. **A note from the founder:** two short paragraphs, signed Amro K. Saleh, Founder, with initials instead of a photo.
5. **Final call:** Start free or Book a call, then the footer.

No street address and no registered company name until there is one.

## Partners page (WS.15)

`whity.dev/partners` (Arabic under `/ar/`), top to bottom:

1. **Hero:** "Build your business on Whity.", Book a call and Ways to work with us. Beside it, a sign-in page on the plane that visitors make their own: they type their agency's name (the address becomes `id.<name>.jo`, the initial and the "Sign in to" line follow) and pick one of four colours. The card sets down again after each change. "This is what your clients see. Whity's name doesn't appear anywhere."
2. **Four ways to work with us:** sell it as your own (agencies), set it up for companies, build plugins, run it for clients (on-premises licence for regulated companies). Each with who it's for and three ticks.
3. **How it starts:** book a call, agree the terms, start with one client. The line between the steps draws on view and the steps arrive one by one.
4. **Our partners:** "None yet. We're looking for the first." with four empty slots, the first marked "Could be you".
5. **Questions:** what it costs to join (nothing; terms on the call), whether clients see Whity (no), whether Core needs a partnership (no), where data is kept.
6. **Final call:** "Let's talk about your clients", Book a call (`app.whity.dev/book/whity/partners`).

Terms are contact-based for now: the page shows no partner price or revenue share (BM.10) until they're set.

## Security page (WS.13)

`whity.dev/security`: a plain promise with Report a problem and Read the code; six areas, three ticks each; "What we don't have yet" (no certifications or outside audits so far, said plainly); how to report (email security@whity.dev, keep it private until fixed, we reply and keep you posted, credit by name with permission), a "Thank you" list that starts empty, and `/.well-known/security.txt`. A "Where your data is" block (WS.18) says "Hosted in the EU" and lists the providers: EU servers for the servers and database (provider not named), Cloudflare R2 for files (EU), Cloudflare for backups (EU), Cloudflare for domains and connections (worldwide network, stores nothing).

## Module pages (WS.7)

One page per main module at `whity.dev/money`, `/stock`, `/approvals` and `/documents` (Arabic under `/ar/`), all from one template:

1. **Hero:** a breadcrumb back to What it does, the module's name, a one-sentence promise, Start free and Book a demo, and a screen of that module on the plane.
2. **What you can do:** three cards, each with a short paragraph and three ticks.
3. **Agents:** two dashed proposals an agent would prepare in this module, with Decline and Approve.
4. **Works with:** the other three modules.
5. **Questions:** three plain answers, including the awkward ones (signatures and the law, the tax system being down).
6. **Final call:** "Free for up to 5 people", then the footer.

Screens are drawn in HTML and CSS, not images, so they stay sharp, translate and follow the theme. Pages for tasks, chat, appointments and customising come later, from the same template.

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

Cards: `SiteHome`, `SiteHomeAr`, `SiteModMoney`, `SiteModMoneyAr`, `SiteModStock`, `SiteModApprovals`, `SiteModDocuments`, `SiteBrand`, `SiteStatus`, `SiteId`, `SiteDocs`.
