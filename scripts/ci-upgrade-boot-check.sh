#!/usr/bin/env bash
#
# An existing install must survive the documented upgrade order: new image first,
# then `migrate run` inside the running container. v0.2.27 did not. Its worker
# read a table a later migration creates, crash-looped at boot, and left no
# container to run the migrations in. Every other check starts from a fresh
# database, or runs only the migrate command, so none of them could see it.
#
# This builds a database with the last published release, starts the code under
# test on that old schema, requires the worker to come up, runs `migrate run` in
# that container, and requires it to come up again.
#
# Environment:
#   WHITY_UPGRADE_FROM_IMAGE  release that builds the old schema. Default: the
#                             latest published GitHub Release of GITHUB_REPOSITORY.
#   WHITY_UPGRADE_TO_IMAGE    image to upgrade to. Default: the FROM image.
#   WHITY_UPGRADE_APP_ARGS    extra `docker run` arguments for the upgraded app,
#                             e.g. "-v $PWD:/app" to run a checkout.

set -euo pipefail

# Keeps Git Bash from rewriting /app in the arguments when run on Windows.
export MSYS_NO_PATHCONV=1

from_image="${WHITY_UPGRADE_FROM_IMAGE:-}"
if [ -z "$from_image" ]; then
  : "${GITHUB_REPOSITORY:?set WHITY_UPGRADE_FROM_IMAGE or GITHUB_REPOSITORY}"
  tag="$(gh release view --repo "$GITHUB_REPOSITORY" --json tagName --jq .tagName)"
  from_image="ghcr.io/${GITHUB_REPOSITORY,,}:${tag}"
fi
to_image="${WHITY_UPGRADE_TO_IMAGE:-$from_image}"
read -r -a app_args <<<"${WHITY_UPGRADE_APP_ARGS:-}"

suffix="$$"
network="whity-upgrade-$suffix"
postgres="whity-upgrade-pg-$suffix"
app="whity-upgrade-app-$suffix"

common_env=(
  -e DB_HOST="$postgres"
  -e DB_PORT=5432
  -e DB_NAME=whity_core
  -e DB_USER=whity
  -e DB_PASSWORD=whity_upgrade
  -e JWT_SECRET=ci_upgrade_jwt_secret_min_32_chars_aaaa
  -e ENCRYPTION_KEY=ci_upgrade_encryption_key_min_32_chars_bb
)
seed_env=(
  -e APP_ENV=development
  -e INITIAL_ADMIN_PASSWORD=upgrade_admin_password_123
  -e INITIAL_USER_PASSWORD=upgrade_user_password_1234
  -e INITIAL_SYSTEM_ADMIN_PASSWORD=upgrade_sysadmin_password_12
  -e INITIAL_SUPERUSER_PASSWORD=upgrade_superuser_password_12
)

cleanup() {
  docker rm -f "$app" "$postgres" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
  echo "::error::$1"
  docker logs --tail 40 "$app" 2>&1 | cut -c1-600 || true
  exit 1
}

# Asks from inside the container, so no host port is needed and the check runs
# the same on a runner and on a machine with other stacks on it.
app_get() {
  docker exec "$app" php -r '
    $body = @file_get_contents("http://127.0.0.1:8080" . $argv[1], false,
        stream_context_create(["http" => ["timeout" => 5, "ignore_errors" => true]]));
    if ($body === false || !str_contains($http_response_header[0] ?? "", " 200")) { exit(1); }
    echo $body;' "$1"
}

wait_for_worker() {
  local stage="$1" i state
  for i in $(seq 1 30); do
    state="$(docker inspect -f '{{.State.Status}}' "$app")"
    if [ "$state" != "running" ]; then
      fail "The worker exited $stage (container is $state): boot crashed."
    fi
    if app_get /api/health >/dev/null 2>&1; then
      echo "Worker is up $stage."
      return 0
    fi
    sleep 2
  done
  fail "The worker did not answer /api/health within 60 seconds $stage."
}

echo "Upgrade under test: $from_image -> $to_image"
docker network create "$network" >/dev/null

docker run -d --name "$postgres" --network "$network" \
  -e POSTGRES_USER=whity -e POSTGRES_PASSWORD=whity_upgrade -e POSTGRES_DB=whity_core \
  pgvector/pgvector:pg15 >/dev/null
for _ in $(seq 1 30); do
  # A TCP probe: the image's init phase answers on the socket before it is ready.
  if docker exec "$postgres" pg_isready -h 127.0.0.1 -U whity -d whity_core >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
docker exec "$postgres" pg_isready -h 127.0.0.1 -U whity -d whity_core

echo "Building the schema of the previous release..."
docker run --rm --network "$network" "${common_env[@]}" "${seed_env[@]}" "$from_image" php public/index.php migrate run | tail -n 2
docker run --rm --network "$network" "${common_env[@]}" "${seed_env[@]}" "$from_image" php public/index.php seed | tail -n 1

docker run -d --name "$app" --network "$network" "${common_env[@]}" \
  -e APP_ENV=production \
  -e CADDY_GLOBAL_OPTIONS="auto_https off" \
  -e SERVER_NAME=":8080" \
  -e SERVER_ROOT="public/" \
  -e FRANKENPHP_CONFIG="worker /app/public/index.php 4" \
  ${app_args[@]+"${app_args[@]}"} \
  "$to_image" >/dev/null

wait_for_worker "on the previous release's schema"
echo "Before migrating: $(app_get /api/build)"

docker exec "$app" php public/index.php migrate run | tail -n 2 \
  || fail "migrate run failed inside the upgraded container."
docker restart "$app" >/dev/null
wait_for_worker "after migrate run"

build="$(app_get /api/build)"
echo "After migrating: $build"
if ! grep -Eq '"pending_migration_count": *0[,}]' <<<"$build"; then
  fail "migrations are still pending after migrate run."
fi

echo "Upgrade check passed."
