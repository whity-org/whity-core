# Restoring the database

**Last drilled: 2026-09-11. Restore took 239 seconds from a cold container.**

A backup nobody has restored is a hypothesis. This procedure has been executed
end to end and the numbers below are measured, not estimated — so during an
incident you are following something that has worked rather than something that
looked reasonable when it was written.

## What the drill proved

A backup taken at 13:13 restored into an empty PostgreSQL container and matched
production exactly:

| | Restored | Live | |
|---|---|---|---|
| tables | 171 | 171 | ✅ |
| tenants | 4 | 4 | ✅ |
| invoices | 5 | 5 | ✅ |
| documents | 15 | 15 | ✅ |
| migrations | 240 | 240 | ✅ |
| audit_log | 365 | 366 | one row written after the snapshot |

That last row is the point-in-time boundary, not data loss: exactly one audit
event was created on live after the backup ran. **A restore recovers everything
up to the moment the dump started, and nothing after it.** Plan for that gap.

Timings, cold:

- container ready: **~15s**
- restore: **~239s**
- total: **~4 minutes** for a 1.6 MB gzip / ~10 MB SQL

## The procedure

```bash
BACKUP=/c/Projects/Whity/backups/whity_core_YYYYMMDD-HHMMSS.sql.gz

# 1. Verify the archive BEFORE trusting it. --check refuses an empty, too
#    small, damaged or cut-short dump and touches no database. gzip -t alone
#    is not enough: a gzip of nothing (20 bytes) passes it.
bash scripts/restore-db.sh --check "$BACKUP"

# 2. A throwaway server. No published port and no named volume, so a mistake
#    here cannot reach anything real.
docker run -d --name whity_restore_drill \
  -e POSTGRES_USER=whity -e POSTGRES_PASSWORD=drill-only -e POSTGRES_DB=whity_core \
  pgvector/pgvector:pg15

# 3. WAIT FOR A REAL QUERY, not pg_isready. See the warning below.
until docker exec whity_restore_drill psql -U whity -d whity_core -tAc 'SELECT 1' >/dev/null 2>&1
do sleep 2; done

# 4. Restore.
gzip -dc "$BACKUP" | docker exec -i whity_restore_drill psql -U whity -d whity_core

# 5. Compare against live before believing it.
docker exec whity_restore_drill psql -U whity -d whity_core -tAc "
  SELECT (SELECT count(*) FROM information_schema.tables WHERE table_schema='public')
      ||' '||(SELECT count(*) FROM tenants)
      ||' '||(SELECT count(*) FROM core_schema_migrations);"

# 6. Clean up.
docker rm -f -v whity_restore_drill
```

## Which backup? The newest valid one, not the newest file

After a reboot the backup container can start before PostgreSQL accepts
connections (the restart policy brings containers back without honouring
`depends_on`). A loop shaped `pg_dump … | gzip > file && touch marker` then
writes a **20-byte archive**, a valid gzip of nothing, and marks it a success.
From then on the newest file, by name and by time, is the worst one, and
"restore the newest" restores an empty database (#1908).

So never pick a dump by date alone:

```bash
# Name no file: restore-db.sh checks BACKUP_DIR newest first, says which newer
# dumps it skipped and why, and uses the newest that passes.
BACKUP_DIR=/var/backups/whity bash scripts/restore-db.sh --check
```

A dump passes when it is at least `BACKUP_MIN_BYTES` long (default 10240), it
decrypts and passes `gzip -t`, it is a complete pg_dump (a plain SQL dump ends
with the `dump complete` trailer; a custom or tar archive passes
`pg_restore --list`), and it has at least `BACKUP_MIN_TABLE_DATA` table-data
entries (default 5). Anything else is refused with the reason, before any
database is touched. The freshness exporter runs the same check on the newest
dump and `WhityBackupLatestInvalid` fires when it fails, so an empty backup is
an alert, not a surprise during an incident.

**If you write your own backup loop**, do what `scripts/backup-db.sh` does:

1. wait until the database answers (`pg_isready`, better a real `SELECT 1`);
2. dump to a temporary name with `set -o pipefail` (or dump to a plain file and
   check `pg_dump`'s own exit status before compressing);
3. require a size over a floor, compress, and test the archive;
4. only then give it its real name and update the marker.

## Two traps this drill fell into, so you do not

**`pg_isready` lies during startup.** The official image starts Postgres, runs
initdb, *shuts it down*, and starts it again. `pg_isready` reports ready during
that first phase, so a restore fired then dies with `FATAL: the database system
is shutting down` — and psql exits before writing anything. The first run of
this drill restored **nothing** and reported success. Wait for a query that
actually returns a row.

**psql writes `psql: error:`, not `ERROR`.** A check for `^ERROR` in the output
finds nothing and declares an all-clear over a restore that wrote zero tables.
Count rows in the restored database; do not grade the log.

Both failures produced a confident success message. The only check that could
not be fooled was counting tables afterwards.

## Restoring for real

Into the live container, when the data is already gone:

```bash
docker exec whity_staging_postgres psql -U whity -d postgres -c \
  "DROP DATABASE whity_core; CREATE DATABASE whity_core OWNER whity;"
gzip -dc "$BACKUP" | docker exec -i whity_staging_postgres psql -U whity -d whity_core
docker restart whity_staging_frankenphp
```

**Take a fresh dump of the broken database first**, even if it looks empty. A
wrong diagnosis is recoverable; a wrong diagnosis plus an overwritten original
is not.

## What is not covered

The dump is `--no-owner --no-privileges` and carries schema plus data only. It
does **not** contain: the `.env.staging` secrets, uploaded files outside the
database, or the Docker volumes themselves. Losing the host means restoring
those separately — they are not in this archive, and nothing here will warn you
at the moment you assume they are.
