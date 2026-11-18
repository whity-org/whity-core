#!/usr/bin/env bash
# shellcheck disable=SC2034  # BACKUP_CHECK_* are read by the scripts that source this file
#
# WHIT-1211 (#1908): can this backup file actually be restored?
#
# Sourced by scripts/restore-db.sh, scripts/backup-db.sh and
# ops/alerting/backup-freshness-textfile.sh, so the three agree on what a
# "valid" dump is. A dump is valid when:
#
#   1. the file is at least BACKUP_MIN_BYTES long (default 10240). A gzip of
#      nothing is 20 bytes, and that is what a backup written while PostgreSQL
#      was still starting looks like;
#   2. it decodes: decrypts with BACKUP_ENCRYPTION_KEY when it ends in .enc,
#      and passes `gzip -t` when it is gzip'd;
#   3. it is pg_dump output, read whole:
#        - a plain SQL dump must end with pg_dump's "dump complete" trailer
#          (a dump cut short has none);
#        - a custom or tar archive must pass `pg_restore --list`;
#   4. it holds at least BACKUP_MIN_TABLE_DATA table-data entries (default 5):
#      `COPY ... FROM stdin;` blocks in a plain dump, `TABLE DATA` entries in an
#      archive. A dump of a database with no tables has none.
#
# Usage (after `source`):
#
#   backup_check <file> [<decoded-out>]
#       0  valid
#       1  invalid; BACKUP_CHECK_REASON says why
#       2  encrypted but BACKUP_ENCRYPTION_KEY is unset, so only the size was
#          checked (the freshness exporter reports this as unverified)
#     Also sets BACKUP_CHECK_FORMAT (plain|archive), BACKUP_CHECK_TABLES and
#     BACKUP_CHECK_BYTES. When <decoded-out> is given, the decoded dump (plain
#     SQL or archive) is left there for the caller to restore.
#
#   backup_candidates <dir>
#     Prints the dump files in <dir>, newest first, one per line: whity_* files
#     ending in .sql, .sql.gz, .dump or .tar, optionally followed by .enc.
#
#   backup_newest_valid <dir>
#     Prints the newest valid dump in <dir>. Each newer one it passes over is
#     reported on stderr with the reason. Returns 1 when none is valid.

BACKUP_MIN_BYTES="${BACKUP_MIN_BYTES:-10240}"
BACKUP_MIN_TABLE_DATA="${BACKUP_MIN_TABLE_DATA:-5}"

_backup_check_fail() {
    BACKUP_CHECK_REASON="$1"
    return 1
}

backup_check() {
    local src="$1" out="${2:-}" own_out=0 inner work rc
    BACKUP_CHECK_REASON=""
    BACKUP_CHECK_FORMAT=""
    BACKUP_CHECK_TABLES=0
    BACKUP_CHECK_BYTES=0

    [ -f "$src" ] || { _backup_check_fail "file not found"; return 1; }
    BACKUP_CHECK_BYTES="$(wc -c < "$src" | tr -d ' ')"
    if [ "$BACKUP_CHECK_BYTES" -eq 0 ]; then
        _backup_check_fail "the file is empty (0 bytes)"; return 1
    fi
    if [ "$BACKUP_CHECK_BYTES" -lt "$BACKUP_MIN_BYTES" ]; then
        _backup_check_fail "only ${BACKUP_CHECK_BYTES} bytes, below the ${BACKUP_MIN_BYTES}-byte floor (BACKUP_MIN_BYTES)"
        return 1
    fi

    if [ -z "$out" ]; then
        out="$(mktemp)"
        own_out=1
    fi
    work="$(mktemp)"

    _backup_check_decode "$src" "$out" "$work"
    rc=$?
    if [ "$rc" -eq 0 ]; then
        _backup_check_contents "$out"
        rc=$?
    fi

    rm -f "$work"
    [ "$own_out" -eq 1 ] && rm -f "$out"
    return "$rc"
}

# Decrypt and decompress <src> into <out>. <work> is scratch space.
_backup_check_decode() {
    local src="$1" out="$2" work="$3" inner="$1" stage="$1"

    case "$src" in
        *.enc)
            if [ -z "${BACKUP_ENCRYPTION_KEY:-}" ]; then
                BACKUP_CHECK_REASON="encrypted and BACKUP_ENCRYPTION_KEY is unset, so only the size was checked"
                return 2
            fi
            if ! openssl enc -d -aes-256-cbc -pbkdf2 -in "$src" -out "$work" \
                    -pass "pass:${BACKUP_ENCRYPTION_KEY}" 2>/dev/null; then
                _backup_check_fail "it does not decrypt with BACKUP_ENCRYPTION_KEY (wrong key, or not an encrypted backup)"
                return 1
            fi
            inner="${src%.enc}"
            stage="$work"
            ;;
    esac

    case "$inner" in
        *.gz)
            if ! gzip -t < "$stage" 2>/dev/null; then
                _backup_check_fail "gzip -t fails: the archive is damaged or cut short"
                return 1
            fi
            gzip -dc < "$stage" > "$out" || { _backup_check_fail "it does not decompress"; return 1; }
            ;;
        *)
            cp "$stage" "$out"
            ;;
    esac
    return 0
}

# Is <file> (already decoded) a complete pg_dump with enough table data?
_backup_check_contents() {
    local file="$1" list

    if [ ! -s "$file" ]; then
        _backup_check_fail "it decodes to nothing (0 bytes): pg_dump wrote no output"
        return 1
    fi

    if [ "$(head -c 5 "$file" | tr -d '\000')" = "PGDMP" ] ||! head -n 30 "$file" | grep -a 'PostgreSQL database dump' >/dev/null; then
        # Not a plain SQL dump: a custom (PGDMP) or tar archive, or garbage.
        BACKUP_CHECK_FORMAT="archive"
        if ! command -v pg_restore >/dev/null 2>&1; then
            _backup_check_fail "not a plain SQL dump, and pg_restore is not installed to read it as an archive"
            return 1
        fi
        if ! list="$(pg_restore --list "$file" 2>/dev/null)"; then
            _backup_check_fail "pg_restore --list cannot read it: not a pg_dump archive, or damaged"
            return 1
        fi
        BACKUP_CHECK_TABLES="$(printf '%s\n' "$list" | grep -c ' TABLE DATA ' || true)"
    else
        BACKUP_CHECK_FORMAT="plain"
        if ! tail -n 5 "$file" | grep -a 'PostgreSQL database dump complete' >/dev/null; then
            _backup_check_fail "the SQL dump has no \"dump complete\" trailer: pg_dump stopped before the end"
            return 1
        fi
        BACKUP_CHECK_TABLES="$(grep -ac '^COPY .* FROM stdin;$' "$file" || true)"
        if [ "$BACKUP_CHECK_TABLES" -eq 0 ]; then
            # pg_dump --inserts: count the tables that have INSERTs instead.
            BACKUP_CHECK_TABLES="$(grep -ao '^INSERT INTO [^ (]*' "$file" | sort -u | wc -l | tr -d ' ')"
        fi
    fi

    if [ "$BACKUP_CHECK_TABLES" -lt "$BACKUP_MIN_TABLE_DATA" ]; then
        _backup_check_fail "only ${BACKUP_CHECK_TABLES} table-data entries, below the minimum of ${BACKUP_MIN_TABLE_DATA} (BACKUP_MIN_TABLE_DATA)"
        return 1
    fi
    return 0
}

backup_candidates() {
    local dir="$1"
    [ -d "$dir" ] || return 0
    find "$dir" -maxdepth 1 -type f -name 'whity_*' -printf '%T@ %p\n' 2>/dev/null \
        | { grep -E '\.(sql|sql\.gz|dump|tar)(\.enc)?$' || true; } \
        | sort -rn \
        | cut -d' ' -f2-
}

backup_newest_valid() {
    local dir="$1" f found=1
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        if backup_check "$f"; then
            printf '%s\n' "$f"
            found=0
            break
        fi
        echo "[backup-check] skipping ${f}: ${BACKUP_CHECK_REASON}" >&2
    done < <(backup_candidates "$dir")
    return "$found"
}
