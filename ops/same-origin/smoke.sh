#!/usr/bin/env sh
# Smoke test for a shared origin (whity-org/whity-core#2386).
#   ./smoke.sh http://localhost:8080
# Checks that each path reaches the right upstream. Exits non-zero on the first miss.
set -eu
ORIGIN="${1:-http://localhost:8080}"
fail=0

check() { # name, path, expected substring
  body=$(curl -fsS --max-time 15 "$ORIGIN$2" 2>/dev/null) || { echo "FAIL $1: $2 did not answer 2xx"; fail=1; return; }
  case "$body" in
    *"$3"*) echo "ok   $1: $2" ;;
    *) echo "FAIL $1: $2 answered, but without '$3'"; fail=1 ;;
  esac
}

check "other app owns /"            "/"                       "Example Bakery app"
check "other app owns its paths"    "/courses/intro"          "Example Bakery app"
check "backend answers /api"        "/api/health"             '"status"'
check "web answers its pages"       "/login"                  "_next/static"
check "web serves its manifest"     "/web-build/routes.json"  '"routes"'
check "web serves its worker"       "/sw.js"                  "Whity app worker"
# MCP answers 404 while it is switched off (mcp.enabled), so check who answered:
# the backend's API responses carry its strict CSP; the web app's pages do not.
headers=$(curl -sS -o /dev/null -D - --max-time 15 "$ORIGIN/.well-known/oauth-protected-resource" 2>/dev/null || true)
case "$headers" in
  *"default-src 'none'"*) echo "ok   MCP discovery reaches the backend: /.well-known/oauth-protected-resource" ;;
  *) echo "FAIL MCP discovery: /.well-known/oauth-protected-resource did not reach the backend"; fail=1 ;;
esac

asset=$(curl -fsS --max-time 15 "$ORIGIN/login" | grep -o '/_next/static/[^"]*\.js' | head -1 || true)
if [ -n "$asset" ] && curl -fsS -o /dev/null --max-time 15 "$ORIGIN$asset"; then
  echo "ok   web assets: $asset"
else
  echo "FAIL web assets: /_next/static did not load"; fail=1
fi

exit $fail
