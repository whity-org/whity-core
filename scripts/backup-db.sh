#!/usr/bin/env bash
#
# WC-d: automated, encrypted, retained PostgreSQL backup.
#
# Runs WHERE the database lives (the deployment host's cron/scheduler), not in
# CI — a sovereign deployment's DB is not reachable from GitHub. Produces a
# gzip'd pg_dump, encrypts it at rest (AES-256, when a key is configured),
# optionally uploads to S3-compatible storage, prunes old local copies, and
# writes a success marker for a backup-success metric/alert.
#
# Everything is config-driven so this is a no-op-until-configured, activate-by-
# env step:
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD   (same as the app)
#   BACKUP_DIR                 local staging dir            (default /var/backups/whity)
#   BACKUP_ENCRYPTION_KEY      AES-256 passphrase           (STRONGLY recommended; >=32 chars)
#   BACKUP_RETENTION_DAYS      local prune horizon          (default 14)
#   BACKUP_S3_BUCKET           if set, upload there (needs the `aws` CLI)
#   BACKUP_S3_ENDPOINT         custom endpoint for S3-compatible stores (optional)
#   BACKUP_WAIT_FOR_DB_SECONDS how long to wait for pg_isready first (default 120)
#   BACKUP_MIN_BYTES / BACKUP_MIN_TABLE_DATA  floors for the dump check below
#
# WHIT-1211 (#1908): the dump is written to a .partial_ file and checked with
# scripts/lib/backup-check.sh (size floor, gzip -t, complete pg_dump, enough
# table data) BEFORE it gets its real name, is encrypted or uploaded, or the
# marker is touched. A dump taken while PostgreSQL was still starting (a gzip of
# nothing, 20 bytes) is kept as *.invalid for inspection and the run fails, so
# it never becomes "the newest backup" and the freshness alert goes red.
#
# Exit non-zero on any failure so the scheduler surfaces it (feeds the alert).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/backup-check.sh
source "${here}/lib/backup-check.sh"

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-whity_core}"
DB_USER="${DB_USER:?DB_USER is required}"
export PGPASSWORD="${DB_PASSWORD:?DB_PASSWORD is required}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/whity}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
ENC_KEY="${BACKUP_ENCRYPTION_KEY:-}"

mkdir -p "$BACKUP_DIR"
ts="$(date -u +%Y%m%dT%H%M%SZ)"
dump="${BACKUP_DIR}/whity_${DB_NAME}_${ts}.sql.gz"

partial="${BACKUP_DIR}/.partial_whity_${DB_NAME}_${ts}.sql.gz"
trap 'rm -f "$partial"' EXIT

# After a reboot the restart policy can start this before PostgreSQL accepts
# connections (depends_on is not honoured then). Wait for it.
wait_s="${BACKUP_WAIT_FOR_DB_SECONDS:-120}"
if command -v pg_isready >/dev/null 2>&1; then
    waited=0
    until pg_isready -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME"; do
        if [ "$waited" -ge "$wait_s" ]; then
            echo "[backup] FAILED: ${DB_HOST}:${DB_PORT} not ready after ${wait_s}s; no backup taken." >&2
            exit 1
        fi
        sleep 2
        waited=$((waited + 2))
    done
fi

echo "[backup] pg_dump ${DB_NAME}@${DB_HOST}:${DB_PORT} -> ${dump}"
# pipefail makes a failed pg_dump fail this line; the dot-file .partial_ name
# keeps a half-written file from ever looking like a backup.
pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    --no-owner --no-privileges | gzip -9 > "$partial"

if ! backup_check "$partial"; then
    mv "$partial" "${dump}.invalid"
    echo "[backup] FAILED: the dump is not usable: ${BACKUP_CHECK_REASON}. Kept as ${dump}.invalid; marker not updated." >&2
    exit 1
fi
mv "$partial" "$dump"
trap - EXIT
echo "[backup] checked: ${BACKUP_CHECK_BYTES} bytes, ${BACKUP_CHECK_TABLES} table-data entries"

artifact="$dump"
if [ -n "$ENC_KEY" ]; then
    enc="${dump}.enc"
    openssl enc -aes-256-cbc -pbkdf2 -salt -in "$dump" -out "$enc" -pass "pass:${ENC_KEY}"
    rm -f "$dump"
    artifact="$enc"
    echo "[backup] encrypted at rest (AES-256): ${artifact}"
else
    echo "[backup] WARNING: BACKUP_ENCRYPTION_KEY not set — backup is NOT encrypted at rest." >&2
fi

if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    if command -v aws >/dev/null 2>&1; then
        endpoint=()
        [ -n "${BACKUP_S3_ENDPOINT:-}" ] && endpoint=(--endpoint-url "${BACKUP_S3_ENDPOINT}")
        echo "[backup] uploading to s3://${BACKUP_S3_BUCKET}/"
        aws s3 cp "${endpoint[@]}" "$artifact" "s3://${BACKUP_S3_BUCKET}/$(basename "$artifact")"
    else
        echo "[backup] WARNING: BACKUP_S3_BUCKET set but the 'aws' CLI is not installed — kept local only." >&2
    fi
fi

# Prune old local copies (remote lifecycle policies handle S3 retention).
find "$BACKUP_DIR" -maxdepth 1 -name 'whity_*.sql.gz*' -type f -mtime +"$RETENTION_DAYS" -delete 2>/dev/null || true

# Success marker: a monitoring probe / alert can assert this file is fresh.
printf '%s\n' "$ts" > "${BACKUP_DIR}/.last-success"
echo "[backup] done: ${artifact}"
