# Whity CLI

[العربية](Whity-CLI-ar.md)

`whity` installs and updates a self-hosted Whity on Docker. It comes from the npm package `whity`
(the same command is also published as `@whity/cli`; install one, not both) and runs on Linux,
macOS and Windows (PowerShell 5.1 and newer).

It is not `bin/whity-cli`. That is the PHP tool that runs on the server against the database
([CLI Reference](CLI_REFERENCE.md)). `whity` runs on your machine and calls `bin/whity-cli`
inside the containers, so migrations and seeding always use the code of the installed release.

## What you need

- A Linux server with a domain pointing at it (an A record), and ports 80 and 443 free. To try
  Whity on your own computer instead, skip the domain (see below).
- Node 20 or newer
- Docker with Compose v2. On a Linux server: `curl -fsSL https://get.docker.com | sh`

## Install

```bash
npm install -g whity
whity install
```

Or in one line, which checks for Node and runs those two:

```bash
curl -fsSL https://whity.dev/install.sh | sh
```

It asks two questions: the web address for Whity (for example `office.example.jo`) and your email,
which becomes the first owner. Then, one line per step:

```
Whity Core installer  ·  free and open source (AGPL-3.0)

✓ Checked this server   Ubuntu 24.04 · 4 CPUs · 8 GB · 61 GB free
✓ Docker found   27.3.1
? Web address for Whity › office.examplebakery.jo
? Your email (first owner) › amro@examplebakery.jo

✓ Downloaded Whity Core 0.2.29
✓ Started the database and the app
✓ Got a certificate for office.examplebakery.jo
✓ Made your owner account

Whity is ready.
  Open   https://office.examplebakery.jo
  Sign in as amro@examplebakery.jo with the INITIAL_SYSTEM_ADMIN_PASSWORD in /root/whity/.env, ...
  Update later with: whity update
```

Before it changes anything it checks Docker, the ports and that the domain resolves; if one is
missing it says what to do and "Nothing was changed". It installs the latest release into
`./whity` (name one with `whity install v0.2.29`; the oldest it supports is v0.2.21), writes the
files below with generated secrets, starts the database, runs migrations and seed, starts
everything and checks it. [Caddy](https://caddyserver.com) sits in front and gets the certificate
from Let's Encrypt; the step only shows ✓ once the address answers over HTTPS. If Let's Encrypt
can't reach the server yet, it says what to check (the A record, ports 80 and 443) and how to try
again, and finishes the rest.

The password stays in `.env`, as `INITIAL_SYSTEM_ADMIN_PASSWORD`, so it doesn't end up in your
terminal history. Change it after you sign in.

| Option | Default | |
|---|---|---|
| `--domain <name>` | asked | The web address. The installer puts Caddy in front and gets its certificate. |
| `--email <address>` | asked | The first owner's email (`--admin-email` also works). |
| `--port <port>` | `443` | The HTTPS port, when 443 is taken. Port 80 is still needed for the certificate. |
| `--dir <path>` | `./whity` | Install folder. Its name also names the Compose project. |
| `--verbose` | | Show each Docker command's own output. |
| `--url <url>` | `http://localhost:<web port>` | Instead of `--domain`: the address, behind a proxy you run yourself. |
| `--web-port <port>` | `3100` | Port for the web app. |
| `--api-port <port>` | `8100` | Port for the API. |
| `--bind <address>` | `127.0.0.1` | Address the ports are published on. |

Given `--domain` and `--email`, or run without a terminal, it asks nothing. Leaving the web address
empty installs for this computer only, at `http://localhost:3100`.

If an install fails, fix the cause and run the same command again. It carries on with the same
secrets.

### Your own proxy instead of Caddy

Outside development the API marks its session cookies `Secure`, and browsers keep those only over
HTTPS or on localhost. So `whity install` refuses a plain `http://` address other than localhost:
the login page would load, but nobody could stay signed in.

To run your own reverse proxy with a certificate in front of the web port, install with its address:

```bash
whity install --url https://app.example.com
```

Also set `TRUSTED_PROXY_HOPS` in `.env` so the API sees each client's real address
([Deployment Guide](DEPLOYMENT_GUIDE.md)). Then run `docker compose up -d` in the install folder.

## What is in the install folder

| File | |
|---|---|
| `.env` | Settings and secrets. Keep it private, and back it up: a database backup can't decrypt what `ENCRYPTION_KEY` protects without it. Every setting here reaches the app and the workers. |
| `docker-compose.yml` | The compose file of the installed release. `whity update` replaces it. |
| `docker-compose.override.yml` | Compose merges it in and `whity update` never touches it. With `--domain` the installer writes it with the Caddy service; after that it is yours. Use it for changes such as mounting a plugin. |
| `Caddyfile` | With `--domain`: the address Caddy serves and gets the certificate for. |
| `backups/` | One folder per update. |

Run the usual Compose commands in this folder: `docker compose ps`, `docker compose logs -f web`.
Uploaded files live in the `storage` volume and the database in `postgres_data`. **`docker compose
down -v` deletes both.**

The compose file ships with each release as the `whity-compose.yml` asset, from
`docker/production/docker-compose.yml`. Releases made before the asset existed use the copy inside
the package.

## Update

```bash
whity update
```

This moves to the latest release. Name a version with `whity update v0.2.22`. Use `--dir` if the
install isn't in `./whity`.

1. Reads the installed version. It refuses a downgrade, because migrations only go forward.
2. Checks that your settings satisfy the new compose file.
3. Pulls all three images at the new version. Whity keeps running, so a slow download costs no
   downtime and a failed one changes nothing.
4. Stops everything except the database. Downtime starts here.
5. Backs up the database and proves the backup: it restores it into a scratch database and compares
   row counts with the live one. With writers stopped, the backup holds every accepted change.
6. Runs migrations with the new image, through `bin/whity-cli`.
7. Starts the new version.
8. Checks that `/api/health`, `/web-build` and the render service report the new version, that no
   migrations are pending, and that no service is stopped or restarting.

If a step before migrations fails, Whity goes back to running the old version and nothing has
changed. The backup covers the database and the config. It doesn't copy uploaded files, which stay
in their volume across updates.

### If an update fails

From migrations onward, the update stops and prints the exact commands to go back. They are the
same in PowerShell, bash and zsh. **Run them one at a time**: `docker compose exec` reads its input,
so it would swallow the following lines if you pasted them all at once.

They have this shape, with your own paths and names filled in:

```bash
cd "<install folder>"
docker compose down
cp backups/<backup>/.env .env
cp backups/<backup>/docker-compose.yml docker-compose.yml
docker compose up -d --wait postgres
docker compose cp backups/<backup>/whity_core.dump postgres:/tmp/restore.dump
docker compose exec -T postgres dropdb -U whity --force whity_core
docker compose exec -T postgres createdb -U whity whity_core
docker compose exec -T postgres pg_restore -U whity -d whity_core --no-owner --exit-on-error /tmp/restore.dump
docker compose exec -T postgres rm /tmp/restore.dump
docker compose up -d --wait
rm .whity-update-in-progress.json
```

Running `whity update` again prints them again and changes nothing. If you fixed the cause and want
to carry on without restoring, add `--force`.

## Language

The install speaks Arabic when the system language is Arabic (`LANG=ar_JO.UTF-8` and the like) and
the console can show it: Linux, macOS and Windows Terminal. Commands, addresses and versions stay
in Latin. Windows PowerShell 5.1's classic console garbles Arabic and doesn't lay it out right to
left, so there the tool stays in English with plain marks (`ok`, `x`) instead of ✓ and ✗ (WHIT-840).
The update's messages are English for now. All messages live in `packages/cli/src/messages.js`, the
Arabic in `messages-ar.js`.

## Troubleshooting

- **`denied` while pulling.** The images are public, so this usually means an old `ghcr.io` login in
  Docker. Run `docker logout ghcr.io`.
- **A port is in use.** Choose others with `--web-port` and `--api-port`.

## Planned

Later versions add commands under the same `whity` name, tracked as WHIT-841 and WHIT-842:

- `whity login`, then documents, agents, approvals and MCP setup. These go through the API, so
  permissions, tenant isolation and the audit log still apply.
- `whity plugin`: create a plugin, check its manifest against the SDK, and package it.
