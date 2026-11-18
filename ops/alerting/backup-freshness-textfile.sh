#!/usr/bin/env bash
#
# WC-f: export DB-backup freshness as a Prometheus metric.
#
# scripts/backup-db.sh writes ${BACKUP_DIR}/.last-success (a UTC timestamp line)
# after every successful backup. This turns that marker into the metric the
# WhityBackupStale / WhityBackupMetricMissing rules alert on:
#
#   whity_backup_last_success_timestamp_seconds  <unix-seconds>
#
# WHIT-1211 (#1908): a fresh marker is not enough. A backup written while
# PostgreSQL was still starting is a 20-byte gzip of nothing, and the marker
# and the file's age both say it is fine. So the newest dump in BACKUP_DIR is
# also checked with scripts/lib/backup-check.sh (size floor, decrypt + gzip -t,
# complete pg_dump, minimum table-data entries) and exported for the
# WhityBackupLatestInvalid rule:
#
#   whity_backup_latest_valid                  1 usable, 0 empty or invalid
#                                              (also 0 when there is no dump)
#   whity_backup_latest_verified               1 contents checked, 0 only the
#                                              size (encrypted, no key here)
#   whity_backup_latest_size_bytes             size of the newest dump file
#   whity_backup_latest_table_data_entries     table-data entries found in it
#
# Give it BACKUP_ENCRYPTION_KEY (same as the backup) so encrypted dumps are
# checked whole; without it only the size floor applies. BACKUP_MIN_BYTES and
# BACKUP_MIN_TABLE_DATA tune the floors. The result is cached per file (path,
# size, mtime) in BACKUP_CHECK_CACHE, so a dump is decoded once, not every run.
# BACKUP_CHECK_LIB points at backup-check.sh if this script was copied out of
# the release tree.
#
# Run it from cron a few minutes AFTER the backup cron, writing into the
# node_exporter textfile-collector directory so node_exporter scrapes it:
#
#   */15 * * * *  BACKUP_DIR=/var/backups/whity \
#     TEXTFILE_DIR=/var/lib/node_exporter/textfile_collector \
#     bash /opt/whity/ops/alerting/backup-freshness-textfile.sh
#
# Writes atomically (temp + mv) so node_exporter never reads a half-written file.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/whity}"
TEXTFILE_DIR="${TEXTFILE_DIR:-/var/lib/node_exporter/textfile_collector}"
marker="${BACKUP_DIR}/.last-success"
out="${TEXTFILE_DIR}/whity_backup.prom"
cache="${BACKUP_CHECK_CACHE:-${TEXTFILE_DIR}/.whity_backup_check.cache}"
lib="${BACKUP_CHECK_LIB:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../../scripts/lib/backup-check.sh}"

mkdir -p "$TEXTFILE_DIR"
tmp="$(mktemp "${out}.XXXX")"
trap 'rm -f "$tmp"' EXIT

{
    echo '# HELP whity_backup_last_success_timestamp_seconds Unix time of the last successful whity-core DB backup.'
    echo '# TYPE whity_backup_last_success_timestamp_seconds gauge'
    if [ -f "$marker" ]; then
        # The marker holds an ISO-8601 UTC stamp (e.g. 20260706T023000Z); convert
        # to epoch seconds. `date -d` handles it on GNU coreutils (Linux hosts).
        raw="$(head -n1 "$marker")"
        if epoch="$(date -u -d "$raw" +%s 2>/dev/null)"; then
            echo "whity_backup_last_success_timestamp_seconds ${epoch}"
        else
            # Fall back to the marker file's own mtime if the contents don't parse.
            echo "whity_backup_last_success_timestamp_seconds $(stat -c %Y "$marker")"
        fi
    fi
    # If the marker is absent we emit no sample, so `absent()` fires
    # WhityBackupMetricMissing rather than reporting a misleading 0.

    if [ -f "$lib" ]; then
        # shellcheck source=scripts/lib/backup-check.sh
        source "$lib"
        latest="$(backup_candidates "$BACKUP_DIR" | sed -n 1p)"
        valid=0 verified=0 size=0 tables=0 reason="no dump file in ${BACKUP_DIR}"
        if [ -n "$latest" ]; then
            key="${latest} $(stat -c '%s %Y' "$latest") ${BACKUP_MIN_BYTES} ${BACKUP_MIN_TABLE_DATA} ${BACKUP_ENCRYPTION_KEY:+key}"
            if [ -f "$cache" ] && [ "$(head -n1 "$cache")" = "$key" ]; then
                read -r valid verified size tables < <(sed -n 2p "$cache")
                reason="$(sed -n 3p "$cache")"
            else
                rc=0
                backup_check "$latest" || rc=$?
                size="$BACKUP_CHECK_BYTES"
                tables="$BACKUP_CHECK_TABLES"
                reason="$BACKUP_CHECK_REASON"
                case "$rc" in
                    0) valid=1 verified=1 ;;
                    2) valid=1 verified=0 ;;   # size floor passed, contents unreadable here
                    *) valid=0 verified=1 ;;
                esac
                printf '%s
%s %s %s %s
%s
' "$key" "$valid" "$verified" "$size" "$tables" "$reason" > "$cache" 2>/dev/null || true
            fi
        fi
        if [ "$valid" -eq 0 ]; then
            echo "[backup-freshness] newest backup ${latest:-(none)} is empty or invalid: ${reason}" >&2
        fi
        echo '# HELP whity_backup_latest_valid 1 if the newest whity-core DB dump passes the backup check, 0 if it is empty or invalid (or missing).'
        echo '# TYPE whity_backup_latest_valid gauge'
        echo "whity_backup_latest_valid ${valid}"
        echo '# HELP whity_backup_latest_verified 1 if the newest dump contents were checked, 0 if only its size was (encrypted, no key).'
        echo '# TYPE whity_backup_latest_verified gauge'
        echo "whity_backup_latest_verified ${verified}"
        echo '# HELP whity_backup_latest_size_bytes Size in bytes of the newest whity-core DB dump file.'
        echo '# TYPE whity_backup_latest_size_bytes gauge'
        echo "whity_backup_latest_size_bytes ${size}"
        echo '# HELP whity_backup_latest_table_data_entries Table-data entries in the newest whity-core DB dump.'
        echo '# TYPE whity_backup_latest_table_data_entries gauge'
        echo "whity_backup_latest_table_data_entries ${tables}"
    else
        echo "[backup-freshness] WARNING: ${lib} not found; the newest dump is not checked (set BACKUP_CHECK_LIB)." >&2
    fi
} > "$tmp"

mv "$tmp" "$out"
trap - EXIT
