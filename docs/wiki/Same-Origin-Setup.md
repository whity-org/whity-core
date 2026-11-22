# Same-origin setup

How to run the official Whity images next to another app on **one origin**
(`https://campus.example.com`), with a reverse proxy deciding which app answers
each path (whity-org/whity-core#2386). No fork, no rebuilt image, and no
separate admin hostname.

A tested example is in [`ops/same-origin/`](../../ops/same-origin/): a Caddyfile,
a compose file, an `.env.example` and a smoke test. The rest of this page
explains what it does and why.

## Who answers what

| Paths | Goes to | Why |
|---|---|---|
| `/api`, `/api/*`, `/mcp`, `/mcp/*`, `/.well-known/oauth-protected-resource*` | the backend (`whity-core` image) | The API, MCP and MCP's OAuth discovery. The web app would forward them there anyway; sending them straight saves a hop. |
| Whity's root paths (listed below) and `/_next/*` | the web app (`whity-core/web` image) | Whity's pages, build files and assets. |
| Everything else, `/` included | your app | Whity does not use them. |

### The root paths Whity owns

`ops/same-origin/whity-paths.caddy` is the list as a Caddy matcher. It is generated from
the route manifest and checked in CI, so it changes with the routes. Regenerate it from
the image you run after every upgrade:

```bash
docker run --rm ghcr.io/whity-org/whity-core/web:<version> node web/scripts/route-manifest.mjs --caddy > whity-paths.caddy
```

At the time of writing:

- **Sign-in and onboarding:** `/login`, `/register`, `/forgot-password`,
  `/reset-password`, `/accept-invitation`, `/account-recovery`,
  `/verify-email`, `/welcome`, `/join`, `/onboarding`.
- **Public pages:** `/f`, `/book`, `/booking`, `/chat` (the public
  `/chat/<workspace>` and the team's `/chat`), `/quote`, `/verify`, `/status`.
- **Signed-in areas:** `/admin`, `/billing`, `/boards`, `/calendar`,
  `/contacts`, `/dashboard`, `/forms`, `/inventory`, `/licensing`,
  `/messages`, `/more`, `/ownership-offers`, `/sales`, `/settings`, `/setup`,
  `/tasks`, `/workspaces`.
- **Build and app files:** `/_next`, `/web-build`, `/sw.js`,
  `/manifest.webmanifest`, `/openapi.json`, `/app-icon`, `/push-icon`,
  `/favicon.ico`, `/favicon.svg`, `/motion`.

For a machine-readable copy with areas and ids, read `GET /web-build/routes.json`
([Stable routes and ids](Stable-Routes-And-Ids.md)).

### `/_next` and another Next.js app

Whity's web app uses no `basePath` or `assetPrefix`, so its assets live under
`/_next`. If your app is also Next.js, the two collide there. Give your app
its own `assetPrefix` (for example `/app-assets`) and route that prefix to it;
`/_next` stays Whity's.

## Settings for one origin

On the **backend** (`app`) container:

| Variable | Value | What it does |
|---|---|---|
| `APP_URL` | `https://campus.example.com` | The one origin. The SSO callback is `APP_URL/api/v1/auth/sso/<key>/callback`, and every link in emails and redirects starts with it. |
| `FRONTEND_URL` | unset | It defaults to `APP_URL`, which is right when both are one origin. |
| `WHITY_TRUSTED_PROXIES` | the proxy's address or range | Who may set the client IP (see below). |
| `WHITY_CLIENT_IP_SECRET` | 32+ random characters | Same, for the web app, which signs it. |

On the **web** container:

| Variable | Value | What it does |
|---|---|---|
| `WHITY_BACKEND_URL` | `http://app` | Where the web server reaches the backend, inside the network. |
| `TRUSTED_PROXY_HOPS` | `1` | One proxy (Caddy) sits in front: take the browser's host and address from it. |
| `WHITY_CLIENT_IP_SECRET` | same value as the backend | Signs the client IP the web app forwards. |
| `WHITY_PWA_SCOPE` | `/` (default) or a prefix like `/admin/` | Where the installed app's service worker reaches (see below). |

Only the proxy publishes a port. The backend and the web app must not be
reachable from outside the network.

## Client IP (CE-4)

Rate limits, the sign-in throttle and audit records use the client IP the
backend reads from `X-Whity-Client-Ip`. With `/api` going straight to the
backend, the proxy sets that header from the connecting address and drops any
copy the browser sent. The Caddyfile does both, including the
`X_Whity_Client_Ip` spelling, which PHP treats as the same header.

The backend believes the header only from a caller that proves it is the proxy:

- **By address:** `WHITY_TRUSTED_PROXIES` is a comma-separated list of
  addresses and CIDR ranges, for example `172.30.0.10` or `10.0.0.0/8`. Use it
  for a proxy that cannot sign, such as Caddy, nginx or a load balancer. Keep it
  as narrow as you can: anything in the range can claim any IP.
- **By signature:** with `WHITY_CLIENT_IP_SECRET` set on both containers, the
  web app adds `X-Whity-Client-Ip-Signature`, an HMAC-SHA256 of the IP and a
  timestamp. Signatures more than 5 minutes old are refused.

A caller that passes neither is counted under its own connecting address, so
rotating the header gets it nothing. With neither variable set, the backend
believes the header from anyone, as before, and is safe only if nothing but
the proxy can reach it. A shared origin needs one of them.

## Cookies, CSRF and sign-out

- **Cookies** (`access_token`, `refresh_token` and the sign-in flow cookies)
  are `Path=/api`, `HttpOnly` and `SameSite=Lax`, with no `Domain`. Your app's
  pages never receive them, and your app's cookies never reach `/api` unless
  you set them with `Path=/` (avoid names that start with `whity`).
- **CSRF** is a header check: the backend requires
  `X-Requested-With: XMLHttpRequest` on cookie-authenticated writes and on the
  sign-in POSTs. The browser client sends it on every call, so routing `/api`
  straight to the backend changes nothing, and a form on another site still
  cannot write.
- **Sign-out** is `POST /api/v1/auth/logout`. It clears the cookies and
  answers JSON; the web app then goes to `/login`. Nothing redirects across
  apps.

**One origin is one trust boundary.** The browser treats both apps as the same
site. Script running on any page of the origin, in your app or Whity, can call
`/api` with the signed-in person's cookies and the CSRF header, because that is
a same-origin request. `Path=/api` keeps the cookies out of your app's
requests; it does not keep your app's scripts out of Whity's API. Hold your
app to the same standard as Whity: no untrusted script, a strict CSP, and no
user HTML rendered unescaped. If you can't, use two origins.

## SSO

- The callback is `APP_URL/api/v1/auth/sso/<key>/callback`. Register exactly
  that address with the identity provider, for example
  `https://campus.example.com/api/v1/auth/sso/oidc/callback`.
- After a successful sign-in the backend redirects to the home page
  (`/dashboard`, or `navigation.home_path` / `WHITY_HOME_PATH`; see
  [Home page and back link](Home-Page-And-Back-Link.md)), unless
  `SSO_SUCCESS_URL` is set. Failures go to `/login?sso_error=<reason>`
  (`SSO_LOGIN_URL`), and a person with no workspace to `/welcome`
  (`SSO_NO_WORKSPACE_URL`). All of them are on the same origin.
- Discovery, token and JWKS requests go from the backend to the provider over
  https to a public address. A provider on a private network is refused.

## The installed app (service worker)

The web app's service worker used to register for `/`, which made it the
worker for every page on the origin. Now:

- It answers only navigations to Whity's own root paths. Your app's pages go
  to the network as if no worker were there, and never get Whity's offline
  page.
- `WHITY_PWA_SCOPE=/admin/` (on the web container) registers it, and the web
  manifest's `scope`, `start_url` and `id`, under that prefix only, so your
  app can register its own worker for `/`. Pages outside the prefix still work;
  they are just not part of the installed app. A worker a browser installed
  for the old scope is removed the next time it opens a Whity page.
- To turn the installed app off altogether: `app.installable` = off on the
  settings screen, or `PWA_ENABLED=false` on the backend.

## Hiding pages and changing home

Products that replace some of Whity's pages can hide them by id, and choose
where people land. See [Hiding pages](Hiding-Pages.md) and
[Home page and back link](Home-Page-And-Back-Link.md). The compose example
passes `WHITY_HIDDEN_NAV_IDS`, `WHITY_HOME_PATH` and the back-to-product link
through.

## Check it

```bash
cd ops/same-origin
cp .env.example .env   # fill in the secrets
docker compose up -d postgres
docker compose run --rm --no-deps app php public/index.php migrate run
docker compose up -d
./smoke.sh http://localhost:8080
```

Migrate before the app starts: on an empty database its workers fail to boot
and restart, and a migration run inside them would be killed with them. The
first operator sign-in then opens the instance set-up wizard, as on any new
install.

`smoke.sh` checks that `/` and `/courses/intro` reach your app, `/api/health`
and MCP discovery the backend, and `/login`, its `/_next` assets,
`/web-build/routes.json` and `/sw.js` the web app.

## How this was tested

On 22 November 2026 we ran this compose file with images built from the
branch, plus a stand-in app and a test OIDC provider. See the pull request for
the record. The test covered:

- each path reaching its upstream (the smoke test);
- password sign-in, and SSO sign-in through the callback on `APP_URL`, both
  landing on the home page;
- sign-out;
- the session cookies staying under `/api`;
- a spoofed `X-Whity-Client-Ip` sent straight to `/api` being replaced by
  Caddy's own;
- a hidden menu item answering 404;
- the service worker leaving the other app's pages alone.
