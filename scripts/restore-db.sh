#!/usr/bin/env bash
#
# WC-d: restore a PostgreSQL backup produced by backup-db.sh.
#
# Usage: restore-db.sh [--check] [<backup-file>]
#   <backup-file>  a local path to a *.sql.gz (plain) or *.sql.gz.enc (encrypted)
#                  backup. Pull it from S3 first if that is where it lives.
#                  pg_dump custom or tar archives (*.dump, *.tar, optionally .enc)
#                  are restored with pg_restore.
#                  Leave it out to restore the newest VALID dump in BACKUP_DIR
#                  (default /var/backups/whity); newer dumps that fail the check
#                  are named with the reason they were skipped.
#   --check        only check the file (or pick the newest valid one) and print
#                  what would be restored; touches no database.
#
# Before restoring anything, the file must pass scripts/lib/backup-check.sh
# (WHIT-1211, #1908): at least BACKUP_MIN_BYTES long (default 10240), it
# decrypts and passes `gzip -t`, it is a complete pg_dump (plain SQL with the
# "dump complete" trailer, or an archive `pg_restore --list` can read), and it
# has at least BACKUP_MIN_TABLE_DATA table-data entries (default 5). A file that
# fails is refused with the reason. Lower the floors only for a database that
# really is that small.
#
# Restores INTO the database named by DB_NAME — point that at a RESTORE TARGET
# (a clean/standby DB), never blindly at production. The caller is responsible
# for the target existing and being empty. After this returns, run
# `php public/index.php migrate run` and verify (the restore drill does both).
#
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD   (the restore TARGET)
#   BACKUP_ENCRYPTION_KEY                         (required iff the file is .enc)
#   BACKUP_DIR                                    (where to look when no file is named)
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/backup-check.sh
source "${here}/lib/backup-check.sh"

check_only=0
if [ "${1:-}" = "--check" ]; then
    check_only=1
    shift
fi
src="${1:-}"

if [ -z "$src" ]; then
    BACKUP_DIR="${BACKUP_DIR:-/var/backups/whity}"
    echo "[restore] no file named; looking for the newest valid dump in ${BACKUP_DIR}"
    if ! src="$(backup_newest_valid "$BACKUP_DIR")"; then
        echo "[restore] REFUSED: no valid dump in ${BACKUP_DIR}. Nothing was restored." >&2
        exit 1
    fi
    echo "[restore] newest valid dump: ${src}"
fi

[ -f "$src" ] || { echo "[restore] backup file not found: ${src}" >&2; exit 1; }
case "$src" in
    *.enc)
        [ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || { echo "[restore] ${src} is encrypted but BACKUP_ENCRYPTION_KEY is unset" >&2; exit 1; }
        ;;
esac

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

echo "[restore] checking ${src}"
if ! backup_check "$src" "$tmp"; then
    echo "[restore] REFUSED: ${src} is not a usable backup: ${BACKUP_CHECK_REASON}. Nothing was restored." >&2
    exit 1
fi
echo "[restore] ok: ${BACKUP_CHECK_FORMAT} dump, ${BACKUP_CHECK_BYTES} bytes, ${BACKUP_CHECK_TABLES} table-data entries"

if [ "$check_only" -eq 1 ]; then
    echo "[restore] --check: would restore ${src}"
    exit 0
fi

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:?DB_NAME (restore target) is required}"
DB_USER="${DB_USER:?DB_USER is required}"
export PGPASSWORD="${DB_PASSWORD:?DB_PASSWORD is required}"

echo "[restore] restoring into ${DB_NAME}@${DB_HOST}:${DB_PORT}"
if [ "$BACKUP_CHECK_FORMAT" = "archive" ]; then
    pg_restore -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        --no-owner --no-privileges --exit-on-error "$tmp"
else
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -q < "$tmp"
fi
echo "[restore] done. Next: run migrations and verify."
