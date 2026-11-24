# Setup pitfalls

Problems people have actually hit while setting up or running Whity themselves, and the setting
that fixes each one. Each entry gives what you see, why it happens, and the fix. If you
find a new one, add it here.

For a first install, start with [Installation](Installation.md) or [Whity CLI](Whity-CLI.md);
this page is for when something doesn't behave.

## Installing from a source checkout

**`db-init` exits 255 with `vendor/autoload.php not found`.**
The dev image has no `vendor/` and your checkout is mounted over `/app`. Run `composer install`
on the host before `docker compose up`. See [Installation, step 2](Installation.md#2-install-php-dependencies-on-the-host--before-starting-the-stack).

**On Windows, every `Whity\Sdk\*` class fails to autoload inside the container.**
Composer symlinks the SDK path repository, and a Windows symlink does not survive the bind mount.
Install with `COMPOSER_MIRROR_PATH_REPOS=1 composer install`, or reinstall `vendor/` with the
`composer:2` image. See [Installation](Installation.md#on-windows-mirror-the-sdk-path-repository-instead-of-symlinking-it).

**In Git Bash on Windows, `docker exec … /app/...` says the file doesn't exist.**
Git Bash rewrites `/app/...` into a Windows path. Prefix the command with `MSYS_NO_PATHCONV=1`,
or run it from PowerShell.

**You changed PHP code, but nothing changes: a new route 404s, or a save answers 200 and stores nothing.**
FrankenPHP runs in worker mode and keeps the old code in memory. Restart the `frankenphp`
container after editing `src/` or `public/index.php`. Restarting also stops any test run inside
that container.

## Images and Docker

**Pulling `ghcr.io/whity-org/*` images fails with `denied`.**
The images are public. A stale `ghcr.io` login in Docker is the cause: run `docker logout ghcr.io`.

**`docker compose up -d` pulls new images when you meant to keep the pinned ones.**
The staging compose file sets `pull_policy: always` on `web`. Add `--pull never` when your
images are already pinned in `.env`.

**Uploads and rendered PDFs disappear after an image update.**
`/app/storage` lived inside the container. Put it in a named volume and mount that volume on the
app **and every worker** (queue worker, events relay, scheduler). Files lost before the move
cannot be recovered.

**Never add `-v` to `docker compose down` unless you mean to wipe everything.** It deletes the
database volume and the stored files. Take a backup first ([Backups and Restore](Backups-and-Restore.md)).

**On Windows, scheduled jobs (backups, deploys) don't run after a reboot.**
Docker Desktop only starts once someone signs in to Windows. Sign in after a restart, or run
Docker as a service.

## Workers

**Domain events are never delivered (notifications, follow-ups stay pending).**
Run an events relay next to the queue worker: `php bin/whity-cli events:relay`. It exists only
in `bin/whity-cli`; `public/index.php` has no such command.

**Documents finished in the background have no verification QR, or links in emails are relative.**
The workers have no `APP_URL`. Give every worker the same `APP_URL` as the app (for example under
a shared `x-worker` environment block), then recreate the workers.

**`APP_URL` is also required on the app itself** behind a TLS-terminating proxy. Without it, the
SSO callback is relative and the identity provider answers `invalid_request`.

## Upgrading

**Arabic (or any other language) labels are missing after an upgrade.**
`migrate run` does not load new labels. After every upgrade run
`php bin/whity-cli i18n:sync --all`. See [Internationalization](Internationalization.md).

**Run migrations from the new image before restarting the app.**
Use a one-off container of the new image (for example
`docker compose run --rm --no-deps queue-worker php public/index.php migrate run`), then `up -d`.
Before 0.2.28 a release that read a new table at boot crash-looped the app on an unmigrated
database. That is fixed, but migrating first is still the safe order. Back up before
migrating; see [Core Update](Core-Update.md).

**Languages don't show locally at all.**
The language switcher is behind the `i18n.enabled` app setting; turn it on.

## Plugins

**A plugin's tables are missing after you mounted it.**
Plugin migrations run only once the plugin folder is in place. Run `migrate run` after mounting
it. If the plugin was already enabled when you mounted the new folder, turn it off and on again
(`php bin/whity-cli plugin disable <Name>`, then `enable <Name>`) so its migrations run.

**An updated plugin still behaves like the old version.**
Restart the app container and every worker after copying the new build in (worker mode again).

**A plugin passes its own tests but fails on a real core.**
Plugin unit tests use fakes. Before calling a plugin done, try it on a running core: check its
permissions became rows, and that its counter names match `[a-z][a-z0-9_:-]*`. After five server
errors the host's circuit breaker turns the plugin off.

**In live mode a paid store plugin (such as Whity Pay) refuses new sales: "not licensed".**
In live mode a paid plugin asks the Whity store for its licence through Whity ID. Connect the
installation to the store. Only the installation that *is* the store sets
`PLUGIN_STORE_SELF_LICENSED=1` on the app container, because it cannot license itself. Test
mode never asks for a licence, so this shows up only after switching to live.

## Sign-in, proxies and public pages

**Every visitor shares one rate limit, or the logs show the proxy's address.**
Set `TRUSTED_PROXY_HOPS` to the number of proxies in front of the web app (1 for a single
Cloudflare tunnel or Caddy). See [Deployment Guide](DEPLOYMENT_GUIDE.md#trusted_proxy_hops-nextjs-app-env-var)
and [Same-origin setup](Same-Origin-Setup.md).

**SSO or MCP sign-in against an identity provider running on your own machine fails.**
Core only fetches discovery and keys over `https` from public addresses; `localhost` and
private IPs are refused on purpose. Put the identity provider behind a public https name (a
tunnel works) for an end-to-end test.

**Password sign-in is off and nobody can get in.**
Turn it back on from the server: `php bin/whity-cli auth password-sign-in on`, or set
`WHITY_PASSWORD_SIGN_IN=force` in the app's environment. Turn it off again afterwards. See
[Whity ID only sign-in](Whity-ID-Only-Sign-In.md).

**Workspace join links and public forms refuse every submission.**
They need a bot check. Set up Turnstile in Settings › Public forms, or, until you do, set the
app setting `public_forms.allow_without_bot_check` to `1`. Remove it once Turnstile is set.

## Checking in a browser on your own machine

**`/login` on `localhost:<port>` goes straight to a dashboard you didn't sign in to.**
Cookies ignore ports, so a session from another local stack is reused. Use
`http://127.0.0.1:<port>`, or a fresh browser profile.

**With `next dev`, the login form submits as a plain GET and nothing hydrates.**
The dev server refuses its assets on `127.0.0.1`. For `next dev` use `localhost` in a fresh
profile. Release images (`next start`) work on either.

**QR links you scan point at `localhost`.**
Issued links use `APP_URL`'s host, so scan tests must run on that host.
