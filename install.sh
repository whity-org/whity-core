#!/bin/sh
# Whity Core on your own server (owner decision, 16 Nov 2026). The install is the
# `whity` command; this script only gets it for people who start from one line:
#
#   curl -fsSL https://whity.dev/install.sh | sh
#
# is the same as
#
#   npm install -g @whity/cli
#   whity install
#
# It checks for Node.js 20 or newer and npm, installs (or updates) the `whity` command
# from npm's @whity/cli,
# then runs `whity install` with the terminal attached, so its two questions (the
# web address and your email) can be answered even under `curl | sh`. Options are
# passed through: `sh -s -- --domain office.example.jo --email you@example.jo`.
# `whity install --help` lists them. The output follows SiteInstallerTerminal
# (SU.7): ✓ green, ✗ red, one line on what to do, "Nothing was changed"; Arabic
# when the system language is Arabic. It never asks for sudo on its own.
#
# Kept in whity-core (this file) and published at https://whity.dev/install.sh.

# Messages use typographic quotes on purpose.
# shellcheck disable=SC1111,SC1112

set -u

if [ -t 1 ] && [ "${NO_COLOR:-}" = "" ] && [ "${TERM:-}" != "dumb" ]; then
  GREEN="$(printf '\033[32m')"; RED="$(printf '\033[31m')"; BLUE="$(printf '\033[34m')"; BOLD="$(printf '\033[1m')"; OFF="$(printf '\033[0m')"
else
  GREEN=""; RED=""; BLUE=""; BOLD=""; OFF=""
fi
case "${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}" in
  ar|ar_*|ar-*|ar.*) AR=1 ;;
  *) AR=0 ;;
esac
# m <english> <arabic>: the line in the reader's language.
m() { if [ "$AR" = 1 ]; then printf '%s' "$2"; else printf '%s' "$1"; fi; }
# stop <what> <what to do> [command]: before anything has changed.
stop() {
  printf '%s✗%s %s\n  %s\n' "$RED" "$OFF" "$1" "$2"
  if [ "${3:-}" != "" ]; then printf '  %s%s%s\n' "$BOLD$BLUE" "$3" "$OFF"; fi
  printf '  %s\n' "$(m 'Nothing was changed.' 'لم يتغيّر شيء.')"
  exit 1
}

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
  stop "$(m "Node.js isn't installed" 'Node.js غير مُثبَّت')" \
    "$(m 'The installer is the whity command, which runs on Node.js 20 or newer. Install it, then run this again:' 'المُثبِّت هو الأمر whity، ويعمل على Node.js 20 أو أحدث. ثبّته ثم شغّل هذا مرة أخرى:')" \
    "https://nodejs.org/en/download"
fi
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
if [ "$NODE_MAJOR" -lt 20 ] 2>/dev/null; then
  stop "$(m "Node.js $(node -v) is too old" "Node.js $(node -v) قديم")" \
    "$(m 'The whity command needs Node.js 20 or newer. Update it, then run this again:' 'يحتاج الأمر whity إلى Node.js 20 أو أحدث. حدّثه ثم شغّل هذا مرة أخرى:')" \
    "https://nodejs.org/en/download"
fi

# Always the current release: a `whity` from an older @whity/cli can't do the server install.
{
  LOG="${TMPDIR:-/tmp}/whity-npm.$$.log"
  if ! npm install -g --no-audit --no-fund @whity/cli@latest >"$LOG" 2>&1; then
    if grep -q 'EACCES' "$LOG" 2>/dev/null; then
      stop "$(m "The whity command couldn't be installed" 'تعذّر تثبيت الأمر whity')" \
        "$(m 'npm can’t write to its global folder as this user. Run the two lines as root, or set an npm prefix you own:' 'لا يستطيع npm الكتابة في مجلده العام بهذا المستخدم. شغّل السطرين بصلاحية root، أو اضبط مجلد npm تملكه:')" \
        "sudo npm install -g @whity/cli && whity install"
    fi
    stop "$(m "The whity command couldn't be installed" 'تعذّر تثبيت الأمر whity')" \
      "$(m "npm said why in $LOG. Check this server can reach registry.npmjs.org, then run this again." "سبب npm في $LOG. تأكد أن هذا الخادم يصل إلى registry.npmjs.org ثم شغّل هذا مرة أخرى.")"
  fi
  rm -f "$LOG"
  printf '%s✓%s %s   %s\n' "$GREEN" "$OFF" "$(m 'Installed the whity command' 'ثُبّت الأمر whity')" "$(whity --version 2>/dev/null)"
}

# The questions read the terminal, not the pipe this script came through.
if (: </dev/tty) 2>/dev/null; then
  exec whity install "$@" </dev/tty
fi
exec whity install "$@"
