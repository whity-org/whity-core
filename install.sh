#!/bin/sh
# Whity: run it on your own machine, in one line (WS.20).
#
#   curl -fsSL https://whity.dev/install.sh | sh
#   curl -fsSL https://whity.dev/install.sh | sh -s -- --dir ~/whity --version v0.2.29 --empty
#
# What it does, in order. Each step prints one line as it starts and a tick
# when it is done; a failure says what went wrong and the command to retry.
#
#   1. Checks the system: macOS, Linux, or Windows under WSL.
#   2. Checks Docker (and that it is running), Node.js 20 or newer, and git.
#      If one is missing it prints one line with where to get it and stops
#      before changing anything.
#   3. Clones https://github.com/whity-org/whity-core.git into ./whity-core
#      (or --dir); --version checks out that tag.
#   4. Runs `make setup`: Docker Compose starts FrankenPHP and PostgreSQL,
#      migrates and seeds. --empty leaves out the sample data.
#   5. Starts the web app (npm install, npm run dev) in the background and
#      waits for http://localhost:3000.
#   6. Makes sure admin@whity.localhost exists with a one-time password, prints it,
#      and opens the browser.
#
# It never asks for sudo. Read it before you run it; it is short.
#
# Kept in whity-core (this file) and published at https://whity.dev/install.sh,
# so the script and the code it installs change together. The terminal on
# whity.dev/install shows exactly what this prints (docs/design/29-websites.md).

set -u

REPO_URL="https://github.com/whity-org/whity-core.git"
DIR="whity-core"
VERSION=""
EMPTY=0
# Not admin@localhost: an address without a dot in its domain fails the
# email check the seed applies, and the seed would quietly fall back to
# system@whity.local. .localhost is reserved for this machine (RFC 6761).
ADMIN_EMAIL="admin@whity.localhost"
WEB_URL="http://localhost:3000"
MANUAL="https://whity.dev/install#manual"

# ── output ──────────────────────────────────────────────────────────────────

if [ -t 1 ] && [ "${NO_COLOR:-}" = "" ]; then
  GREEN="$(printf '\033[32m')"; RED="$(printf '\033[31m')"; DIM="$(printf '\033[2m')"; BOLD="$(printf '\033[1m')"; OFF="$(printf '\033[0m')"
else
  GREEN=""; RED=""; DIM=""; BOLD=""; OFF=""
fi

step() { printf '%s… ' "$1"; }
ok() { printf '%s✓%s%s\n' "$GREEN" "$OFF" "${1:+ $1}"; }
# fail <what went wrong> [command to retry]: for a step that changed something.
fail() {
  printf '%s✗%s\n%s\n' "$RED" "$OFF" "$1"
  if [ "${2:-}" != "" ]; then printf 'Retry with: %s\n' "$2"; fi
  exit 1
}
# stop <one plain line>: for the checks, before anything has changed.
stop() { printf '%s✗%s\n%s\n' "$RED" "$OFF" "$1"; exit 1; }

usage() {
  cat <<EOF
Usage: install.sh [--dir PATH] [--version TAG] [--empty]

  --dir PATH      Where to put Whity (default: ./whity-core)
  --version TAG   Check out this release, for example v0.2.29
  --empty         Leave out the sample data
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dir) [ $# -ge 2 ] || { usage; exit 2; }; DIR="$2"; shift 2 ;;
    --dir=*) DIR="${1#--dir=}"; shift ;;
    --version) [ $# -ge 2 ] || { usage; exit 2; }; VERSION="$2"; shift 2 ;;
    --version=*) VERSION="${1#--version=}"; shift ;;
    --empty) EMPTY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1"; usage; exit 2 ;;
  esac
done

printf '%sWhity installer%s\n' "$BOLD" "$OFF"

# ── 1. the system ───────────────────────────────────────────────────────────

step "Checking your system"
case "$(uname -s 2>/dev/null || echo unknown)" in
  Darwin) SYSTEM="macOS" ;;
  Linux)
    if grep -qi microsoft /proc/version 2>/dev/null; then SYSTEM="Windows (WSL)"; else SYSTEM="Linux"; fi ;;
  *) stop "This installer runs on macOS, Linux and Windows under WSL. For anything else, follow the manual steps: $MANUAL" ;;
esac
ok "$SYSTEM"

# ── 2. what it needs, checked before anything changes ───────────────────────

step "Checking Docker, Node.js and git"
if ! command -v docker >/dev/null 2>&1; then
  stop "Docker isn't installed. Get Docker Desktop at https://docs.docker.com/get-docker/ and run this line again."
fi
if ! docker info >/dev/null 2>&1; then
  stop "Docker is installed but not running. Start Docker Desktop (or the Docker service) and run this line again."
fi
if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  stop "Docker Compose isn't available. Update Docker at https://docs.docker.com/get-docker/ and run this line again."
fi
if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
  stop "Node.js isn't installed. Get Node.js 20 or newer at https://nodejs.org/ and run this line again."
fi
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
if [ "$NODE_MAJOR" -lt 20 ] 2>/dev/null; then
  stop "Node.js $(node -v) is too old. Get Node.js 20 or newer at https://nodejs.org/ and run this line again."
fi
if ! command -v git >/dev/null 2>&1; then
  stop "git isn't installed. Get it at https://git-scm.com/downloads and run this line again."
fi
if command -v curl >/dev/null 2>&1; then
  probe() { curl -fsS -o /dev/null --max-time 3 "$1"; }
elif command -v wget >/dev/null 2>&1; then
  probe() { wget -q -O /dev/null -T 3 "$1"; }
else
  stop "curl or wget is needed to check that Whity has started. Install one of them and run this line again."
fi
if [ -e "$DIR" ]; then
  stop "$DIR already exists. Choose another folder with --dir, or remove it and run this line again."
fi
if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx 'whity_postgres\|whity_frankenphp'; then
  stop "A Whity is already set up in Docker on this machine. Use that one, or remove its containers and run this line again."
fi
if probe "$WEB_URL" 2>/dev/null; then
  stop "Something is already running at $WEB_URL. Stop it and run this line again."
fi
ok

# From here on things change, so every failure says how to retry.
ARGS=""
[ "$DIR" != "whity-core" ] && ARGS="$ARGS --dir $DIR"
[ "$VERSION" != "" ] && ARGS="$ARGS --version $VERSION"
[ "$EMPTY" = 1 ] && ARGS="$ARGS --empty"
RETRY_ALL="rm -rf $DIR && curl -fsSL https://whity.dev/install.sh | sh -s --$ARGS"

# ── 3. the code ─────────────────────────────────────────────────────────────

step "Getting Whity into $DIR"
if [ "$VERSION" != "" ]; then
  git clone --quiet --depth 1 --branch "$VERSION" "$REPO_URL" "$DIR" >/dev/null 2>&1 \
    || fail "Couldn't download Whity $VERSION. Check that the version exists at https://github.com/whity-org/whity-core/releases." "$RETRY_ALL"
else
  git clone --quiet --depth 1 "$REPO_URL" "$DIR" >/dev/null 2>&1 \
    || fail "Couldn't download Whity from GitHub. Check your connection." "$RETRY_ALL"
fi
cd "$DIR" || fail "Couldn't open $DIR." "$RETRY_ALL"
ok

# The first sign-in: a random password the seed applies when it creates the
# account. It is printed once at the end and taken out of .env after use.
PASSWORD="$(od -An -N12 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')"
[ "${#PASSWORD}" -ge 16 ] || fail "Couldn't make a password." "$RETRY_ALL"
if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || : > .env; fi
{
  echo ""
  echo "# Written by install.sh: the first sign-in."
  echo "INITIAL_SYSTEM_ADMIN_EMAIL=$ADMIN_EMAIL"
  echo "INITIAL_SYSTEM_ADMIN_PASSWORD=$PASSWORD"
} >> .env
mkdir -p .whity-install
LOG="$(pwd)/.whity-install/setup.log"
WEBLOG="$(pwd)/.whity-install/web.log"

# ── 4. server and database ──────────────────────────────────────────────────

step "Starting the server and database"
if command -v make >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1; then
  make setup >"$LOG" 2>&1 || fail "The server didn't start. The details are in $LOG." "cd $DIR && make setup"
else
  # `make setup` without make or the standalone docker-compose: its two steps.
  { $COMPOSE up -d && sh ./scripts/init-db.sh; } >"$LOG" 2>&1 \
    || fail "The server didn't start. The details are in $LOG." "cd $DIR && $COMPOSE up -d && sh ./scripts/init-db.sh"
fi
if [ "$EMPTY" = 0 ]; then
  docker exec whity_frankenphp php public/index.php seed --with-fixtures >>"$LOG" 2>&1 \
    || fail "The sample data didn't load. The details are in $LOG." "cd $DIR && docker exec whity_frankenphp php public/index.php seed --with-fixtures"
fi
ok

# ── 5. the web app ──────────────────────────────────────────────────────────

step "Starting the web app"
(cd web && npm install --no-audit --no-fund >"$WEBLOG" 2>&1) \
  || fail "The web app's packages didn't install. The details are in $WEBLOG." "cd $DIR/web && npm install"
(cd web && nohup npm run dev >>"$WEBLOG" 2>&1 &)
i=0
until probe "$WEB_URL" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 180 ]; then
    fail "The web app didn't answer at $WEB_URL within 3 minutes. The details are in $WEBLOG." "cd $DIR/web && npm run dev"
  fi
  sleep 1
done
ok "$WEB_URL"

# ── 6. the sign-in ──────────────────────────────────────────────────────────

step "Creating your sign-in"
FOUND="$(docker exec whity_postgres psql -U whity -d whity_core -tAc "SELECT 1 FROM profile_emails WHERE email = '$ADMIN_EMAIL'" 2>/dev/null || true)"
[ "$FOUND" = "1" ] || fail "$ADMIN_EMAIL wasn't created. The details are in $LOG." "cd $DIR && docker exec whity_frankenphp php public/index.php seed"
# The password has done its job in .env; it does not stay on disk.
sed -i.bak '/^INITIAL_SYSTEM_ADMIN_PASSWORD=/d' .env 2>/dev/null; rm -f .env.bak
ok

printf '\n%sWhity is running at %s%s\n' "$BOLD" "$WEB_URL" "$OFF"
printf 'Sign in as %s with this password: %s%s%s\n' "$ADMIN_EMAIL" "$BOLD" "$PASSWORD" "$OFF"
printf '%sIt is shown only this once. Change it after you sign in.%s\n' "$DIM" "$OFF"
printf '%sTo stop Whity: cd %s && %s stop%s\n' "$DIM" "$DIR" "$COMPOSE" "$OFF"

case "$SYSTEM" in
  macOS) open "$WEB_URL" >/dev/null 2>&1 || true ;;
  "Windows (WSL)") { command -v wslview >/dev/null 2>&1 && wslview "$WEB_URL"; } >/dev/null 2>&1 || cmd.exe /c start "$WEB_URL" >/dev/null 2>&1 || true ;;
  *) xdg-open "$WEB_URL" >/dev/null 2>&1 || true ;;
esac
