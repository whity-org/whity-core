#!/bin/sh
# Whity: run it on your own machine, in one line (WS.20).
#
#   curl -fsSL https://whity.dev/install.sh | sh
#   curl -fsSL https://whity.dev/install.sh | sh -s -- --dir ~/whity --version v0.2.29 --empty
#
# What it does, in order. Each step prints one line: ✓ done (green), ✗ stopped
# (red), ! a warning (amber), each followed by one indented line on what to do;
# a check that stops says "Nothing was changed". No stack traces: the details go
# to a log, or to the terminal with --verbose. Colour only on a terminal that
# supports it; the marks carry the meaning. Arabic when the system language is
# Arabic (LANG=ar…); commands and addresses stay in Latin. The output follows
# the SiteInstallerTerminal card (SU.7); what it does follows WS.20.
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
VERBOSE=0
# Not admin@localhost: an address without a dot in its domain fails the
# email check the seed applies, and the seed would quietly fall back to
# system@whity.local. .localhost is reserved for this machine (RFC 6761).
ADMIN_EMAIL="admin@whity.localhost"
WEB_URL="http://localhost:3000"
MANUAL="https://whity.dev/install#manual"

# ── output ──────────────────────────────────────────────────────────────────

if [ -t 1 ] && [ "${NO_COLOR:-}" = "" ] && [ "${TERM:-}" != "dumb" ]; then
  GREEN="$(printf '\033[32m')"; RED="$(printf '\033[31m')"; AMBER="$(printf '\033[33m')"; BLUE="$(printf '\033[34m')"
  DIM="$(printf '\033[2m')"; BOLD="$(printf '\033[1m')"; OFF="$(printf '\033[0m')"; LIVE=1
else
  GREEN=""; RED=""; AMBER=""; BLUE=""; DIM=""; BOLD=""; OFF=""; LIVE=0
fi

# Arabic when the system language is Arabic; commands and addresses stay Latin.
case "${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}" in
  ar|ar_*|ar-*|ar.*) AR=1 ;;
  *) AR=0 ;;
esac
# m <english> <arabic>: the line in the reader's language.
m() { if [ "$AR" = 1 ]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }

CURRENT=""
# begin <what>: a long step shows it is under way (on a terminal only).
begin() {
  CURRENT="$1"
  if [ "$LIVE" = 1 ] && [ "$VERBOSE" = 0 ]; then printf '%s… %s%s' "$DIM" "$1" "$OFF"; fi
}
clear_line() { if [ "$LIVE" = 1 ] && [ "$VERBOSE" = 0 ]; then printf '\r\033[K'; fi; }
# ok [what] [detail]: ✓ the step, its detail dimmed beside it.
ok() {
  clear_line
  printf '%s✓%s %s' "$GREEN" "$OFF" "${1:-$CURRENT}"
  if [ "${2:-}" != "" ]; then printf '   %s%s%s' "$DIM" "$2" "$OFF"; fi
  printf '\n'
}
# warn <what> <what to do>: ! in amber, and carry on.
warn() { clear_line; printf '%s!%s %s\n  %s\n' "$AMBER" "$OFF" "$1" "$2"; }
# cmdline <command>: a command to run, on its own line.
cmdline() { printf '  %s%s%s\n' "$BOLD$BLUE" "$1" "$OFF"; }
# stop <what> <what to do> [command]: a check, before anything has changed.
stop() {
  clear_line
  printf '%s✗%s %s\n  %s\n' "$RED" "$OFF" "$1" "$2"
  if [ "${3:-}" != "" ]; then cmdline "$3"; fi
  printf '  %s\n' "$(m 'Nothing was changed.' 'لم يتغيّر شيء.')"
  exit 1
}
# fail <what went wrong> [command to retry]: a step that had already changed something.
fail() {
  clear_line
  printf '%s✗%s %s\n' "$RED" "$OFF" "$1"
  if [ "${2:-}" != "" ]; then
    printf '  %s\n' "$(m 'Try again with:' 'أعد المحاولة بهذا الأمر:')"
    cmdline "$2"
  fi
  exit 1
}
# run <command…>: the command's own output goes to the log, or to the terminal with --verbose.
run() { if [ "$VERBOSE" = 1 ]; then "$@"; else "$@" >>"$LOG" 2>&1; fi; }

usage() {
  cat <<EOF
Usage: install.sh [--dir PATH] [--version TAG] [--empty] [--verbose]

  --dir PATH      Where to put Whity (default: ./whity-core)
  --version TAG   Check out this release, for example v0.2.29
  --empty         Leave out the sample data
  --verbose       Show each command's own output instead of keeping it in a log
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dir) [ $# -ge 2 ] || { usage; exit 2; }; DIR="$2"; shift 2 ;;
    --dir=*) DIR="${1#--dir=}"; shift ;;
    --version) [ $# -ge 2 ] || { usage; exit 2; }; VERSION="$2"; shift 2 ;;
    --version=*) VERSION="${1#--version=}"; shift ;;
    --empty) EMPTY=1; shift ;;
    --verbose|-v) VERBOSE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1"; usage; exit 2 ;;
  esac
done

printf '\n%sWhity Core %s%s  ·  %s%s%s\n\n' "$BOLD$BLUE" "$(m 'installer' 'المُثبِّت')" "$OFF" "$DIM" "$(m 'free and open source (AGPL-3.0)' 'مجاني ومفتوح المصدر (AGPL-3.0)')" "$OFF"

# ── 1. the system ───────────────────────────────────────────────────────────

case "$(uname -s 2>/dev/null || echo unknown)" in
  Darwin) SYSTEM="macOS"; MEM_KB="$(( $(sysctl -n hw.memsize 2>/dev/null || echo 0) / 1024 ))" ;;
  Linux)
    if grep -qi microsoft /proc/version 2>/dev/null; then SYSTEM="Windows (WSL)"; else SYSTEM="Linux"; fi
    MEM_KB="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)" ;;
  *) stop "$(m "This system isn't supported" 'هذا النظام غير مدعوم')" \
       "$(m "The installer runs on macOS, Linux and Windows under WSL. For anything else, follow the manual steps:" 'يعمل المُثبِّت على macOS وLinux وWindows عبر WSL. لغير ذلك اتبع الخطوات اليدوية:')" "$MANUAL" ;;
esac
CPUS="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo '?')"
MEM_GB="$(( ${MEM_KB:-0} / 1048576 ))"
ok "$(m 'Checked this system' 'فُحص هذا النظام')" "$SYSTEM · $CPUS CPUs · ${MEM_GB} GB"

# ── 2. what it needs, checked before anything changes ───────────────────────

GET_DOCKER="https://docs.docker.com/get-docker/"
if ! command -v docker >/dev/null 2>&1; then
  stop "$(m "Docker isn't installed" 'Docker غير مُثبَّت')" "$(m 'Install Docker Desktop, then run this again:' 'ثبّت Docker Desktop ثم شغّل هذا مرة أخرى:')" "$GET_DOCKER"
fi
if ! docker info >/dev/null 2>&1; then
  stop "$(m "Docker isn't running" 'Docker لا يعمل')" "$(m 'Start Docker Desktop (or the Docker service), then run this again.' 'شغّل Docker Desktop (أو خدمة Docker) ثم شغّل هذا مرة أخرى.')"
fi
if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  stop "$(m "Docker Compose isn't available" 'Docker Compose غير متاح')" "$(m 'Update Docker, then run this again:' 'حدّث Docker ثم شغّل هذا مرة أخرى:')" "$GET_DOCKER"
fi
ok "$(m 'Docker found' 'وُجد Docker')" "$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
  stop "$(m "Node.js isn't installed" 'Node.js غير مُثبَّت')" "$(m 'Install Node.js 20 or newer, then run this again:' 'ثبّت Node.js 20 أو أحدث ثم شغّل هذا مرة أخرى:')" "https://nodejs.org/"
fi
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
if [ "$NODE_MAJOR" -lt 20 ] 2>/dev/null; then
  stop "$(m "Node.js $(node -v) is too old" "Node.js $(node -v) قديم")" "$(m 'Install Node.js 20 or newer, then run this again:' 'ثبّت Node.js 20 أو أحدث ثم شغّل هذا مرة أخرى:')" "https://nodejs.org/"
fi
if ! command -v git >/dev/null 2>&1; then
  stop "$(m "git isn't installed" 'git غير مُثبَّت')" "$(m 'Install git, then run this again:' 'ثبّت git ثم شغّل هذا مرة أخرى:')" "https://git-scm.com/downloads"
fi
ok "$(m 'Node.js and git found' 'وُجد Node.js وgit')" "$(node -v 2>/dev/null)"
if command -v curl >/dev/null 2>&1; then
  probe() { curl -fsS -o /dev/null --max-time 3 "$1"; }
elif command -v wget >/dev/null 2>&1; then
  probe() { wget -q -O /dev/null -T 3 "$1"; }
else
  stop "$(m "Neither curl nor wget is installed" 'لا يوجد curl ولا wget')" "$(m 'One of them is needed to check that Whity has started. Install one, then run this again.' 'نحتاج أحدهما للتأكد من أن Whity بدأ. ثبّت أحدهما ثم شغّل هذا مرة أخرى.')"
fi
if [ -e "$DIR" ]; then
  stop "$(m "$DIR already exists" "$DIR موجود مسبقاً")" "$(m 'Choose another folder with --dir, or remove it and run this again.' 'اختر مجلداً آخر بـ --dir، أو احذفه وشغّل هذا مرة أخرى.')"
fi
if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx 'whity_postgres\|whity_frankenphp'; then
  stop "$(m 'Whity is already set up in Docker here' 'Whity مُعدّ مسبقاً في Docker هنا')" "$(m 'Use that one, or remove its containers and run this again.' 'استخدمه، أو احذف حاوياته وشغّل هذا مرة أخرى.')"
fi
if probe "$WEB_URL" 2>/dev/null; then
  stop "$(m "Something is already running at $WEB_URL" "شيء ما يعمل مسبقاً على $WEB_URL")" "$(m 'Stop it, then run this again.' 'أوقفه ثم شغّل هذا مرة أخرى.')"
fi
if [ "${MEM_KB:-0}" -gt 0 ] && [ "$MEM_KB" -lt 2097152 ]; then
  warn "$(m 'Less than 2 GB of memory' 'أقل من 2 غيغابايت من الذاكرة')" "$(m 'Whity will run, but slowly. 4 GB is better.' 'سيعمل Whity لكن ببطء. 4 غيغابايت أفضل.')"
fi

# From here on things change, so every failure says how to retry.
ARGS=""
[ "$DIR" != "whity-core" ] && ARGS="$ARGS --dir $DIR"
[ "$VERSION" != "" ] && ARGS="$ARGS --version $VERSION"
[ "$EMPTY" = 1 ] && ARGS="$ARGS --empty"
RETRY_ALL="rm -rf $DIR && curl -fsSL https://whity.dev/install.sh | sh -s --$ARGS"
mkdir -p "${TMPDIR:-/tmp}" 2>/dev/null
LOG="${TMPDIR:-/tmp}/whity-install.$$.log"

# ── 3. the code ─────────────────────────────────────────────────────────────

begin "$(m "Downloaded Whity Core into $DIR" "تم تنزيل Whity Core إلى $DIR")"
if [ "$VERSION" != "" ]; then
  run git clone --quiet --depth 1 --branch "$VERSION" "$REPO_URL" "$DIR" \
    || fail "$(m "Whity $VERSION couldn't be downloaded. Check that the version exists at https://github.com/whity-org/whity-core/releases." "تعذّر تنزيل Whity $VERSION. تأكد من وجود الإصدار في https://github.com/whity-org/whity-core/releases.")" "$RETRY_ALL"
else
  run git clone --quiet --depth 1 "$REPO_URL" "$DIR" \
    || fail "$(m "Whity couldn't be downloaded from GitHub. Check your connection." 'تعذّر تنزيل Whity من GitHub. تحقّق من الاتصال.')" "$RETRY_ALL"
fi
cd "$DIR" || fail "$(m "$DIR couldn't be opened." "تعذّر فتح $DIR.")" "$RETRY_ALL"
ok "" "$VERSION"

# The first sign-in: a random password the seed applies when it creates the
# account. It is printed once at the end and taken out of .env after use.
PASSWORD="$(od -An -N12 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')"
[ "${#PASSWORD}" -ge 16 ] || fail "$(m "A password couldn't be made." 'تعذّر إنشاء كلمة مرور.')" "$RETRY_ALL"
if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || : > .env; fi
{
  echo ""
  echo "# Written by install.sh: the first sign-in."
  echo "INITIAL_SYSTEM_ADMIN_EMAIL=$ADMIN_EMAIL"
  echo "INITIAL_SYSTEM_ADMIN_PASSWORD=$PASSWORD"
} >> .env
mkdir -p .whity-install
cat "$LOG" >.whity-install/setup.log 2>/dev/null; rm -f "$LOG"
LOG="$(pwd)/.whity-install/setup.log"
WEBLOG="$(pwd)/.whity-install/web.log"

# ── 4. server and database ──────────────────────────────────────────────────

begin "$(m 'Started the database and the server' 'بدأت قاعدة البيانات والخادم')"
DETAILS="$(m "The details are in $LOG." "التفاصيل في $LOG.")"
if command -v make >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1; then
  run make setup || fail "$(m "The server didn't start." 'لم يبدأ الخادم.') $DETAILS" "cd $DIR && make setup"
else
  # `make setup` without make or the standalone docker-compose: its two steps.
  { run $COMPOSE up -d && run sh ./scripts/init-db.sh; } \
    || fail "$(m "The server didn't start." 'لم يبدأ الخادم.') $DETAILS" "cd $DIR && $COMPOSE up -d && sh ./scripts/init-db.sh"
fi
if [ "$EMPTY" = 0 ]; then
  run docker exec whity_frankenphp php public/index.php seed --with-fixtures \
    || fail "$(m "The sample data didn't load." 'لم تُحمَّل البيانات التجريبية.') $DETAILS" "cd $DIR && docker exec whity_frankenphp php public/index.php seed --with-fixtures"
fi
ok

# ── 5. the web app ──────────────────────────────────────────────────────────

begin "$(m 'Started the web app' 'بدأ تطبيق الويب')"
WEBDETAILS="$(m "The details are in $WEBLOG." "التفاصيل في $WEBLOG.")"
if [ "$VERBOSE" = 1 ]; then (cd web && npm install --no-audit --no-fund); else (cd web && npm install --no-audit --no-fund >"$WEBLOG" 2>&1); fi \
  || fail "$(m "The web app's packages didn't install." 'لم تُثبَّت حزم تطبيق الويب.') $WEBDETAILS" "cd $DIR/web && npm install"
(cd web && nohup npm run dev >>"$WEBLOG" 2>&1 &)
i=0
until probe "$WEB_URL" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 180 ]; then
    fail "$(m "The web app didn't answer at $WEB_URL within 3 minutes." "لم يستجب تطبيق الويب على $WEB_URL خلال 3 دقائق.") $WEBDETAILS" "cd $DIR/web && npm run dev"
  fi
  sleep 1
done
ok "" "$WEB_URL"

# ── 6. the sign-in ──────────────────────────────────────────────────────────

begin "$(m 'Made your sign-in' 'أُنشئ حساب الدخول')"
FOUND="$(docker exec whity_postgres psql -U whity -d whity_core -tAc "SELECT 1 FROM profile_emails WHERE email = '$ADMIN_EMAIL'" 2>/dev/null || true)"
[ "$FOUND" = "1" ] || fail "$(m "$ADMIN_EMAIL wasn't created." "لم يُنشأ $ADMIN_EMAIL.") $DETAILS" "cd $DIR && docker exec whity_frankenphp php public/index.php seed"
# The password has done its job in .env; it does not stay on disk.
sed -i.bak '/^INITIAL_SYSTEM_ADMIN_PASSWORD=/d' .env 2>/dev/null; rm -f .env.bak
ok

printf '\n%s%s%s\n' "$GREEN" "$(m 'Whity is ready.' 'Whity جاهز.')" "$OFF"
printf '  %s   %s%s%s\n' "$(m 'Open' 'افتح')" "$BOLD$BLUE" "$WEB_URL" "$OFF"
printf '  %s %s%s%s\n' "$(m "Sign in as $ADMIN_EMAIL with this password:" "سجّل الدخول باسم $ADMIN_EMAIL بكلمة المرور هذه:")" "$BOLD" "$PASSWORD" "$OFF"
printf '  %s%s%s\n' "$DIM" "$(m 'It is shown only this once. Change it after you sign in.' 'تظهر هذه المرة فقط. غيّرها بعد الدخول.')" "$OFF"
printf '  %s %s%s%s\n' "$(m 'Stop it later with:' 'لإيقافه لاحقاً:')" "$BOLD$BLUE" "cd $DIR && $COMPOSE stop" "$OFF"

case "$SYSTEM" in
  macOS) open "$WEB_URL" >/dev/null 2>&1 || true ;;
  "Windows (WSL)") { command -v wslview >/dev/null 2>&1 && wslview "$WEB_URL"; } >/dev/null 2>&1 || cmd.exe /c start "$WEB_URL" >/dev/null 2>&1 || true ;;
  *) xdg-open "$WEB_URL" >/dev/null 2>&1 || true ;;
esac
