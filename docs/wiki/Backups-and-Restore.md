# Database backups & restore (WC-d)

Automated, encrypted, retained PostgreSQL backups for a sovereign deployment,
plus a rehearsed restore procedure. The scripts are **activate-by-config**: they
do nothing until you set the environment below.

## Backup — `scripts/backup-db.sh`

Runs **on the deployment host** (where the DB lives), scheduled by the host's
cron / the compose `cron` service — *not* in CI (GitHub can't reach a sovereign
DB). It runs `pg_dump | gzip`, encrypts the result at rest (AES-256), optionally
uploads to S3-compatible storage, prunes old local copies, and writes
`${BACKUP_DIR}/.last-success` for a freshness metric/alert.

| Env | Purpose | Default |
|---|---|---|
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASSWORD` | source database (same as the app) | host/5432/whity_core |
| `BACKUP_ENCRYPTION_KEY` | AES-256 passphrase — **set this** (≥32 chars) | *(unset ⇒ NOT encrypted, warns)* |
| `BACKUP_DIR` | local staging dir | `/var/backups/whity` |
| `BACKUP_RETENTION_DAYS` | local prune horizon | `14` |
| `BACKUP_S3_BUCKET` | upload target (needs the `aws` CLI) | *(unset ⇒ local only)* |
| `BACKUP_S3_ENDPOINT` | endpoint for S3-compatible stores (MinIO, R2, …) | *(AWS default)* |
| `BACKUP_WAIT_FOR_DB_SECONDS` | wait this long for `pg_isready` before dumping | `120` |
| `BACKUP_MIN_BYTES` | a dump smaller than this is invalid | `10240` |
| `BACKUP_MIN_TABLE_DATA` | a dump with fewer table-data entries is invalid | `5` |

**Every dump is checked before it counts** (`scripts/lib/backup-check.sh`,
#1908). The dump is written to a temporary `.partial_…` name, then it must be
at least `BACKUP_MIN_BYTES`, pass `gzip -t`, end with pg_dump's `dump complete`
trailer and hold at least `BACKUP_MIN_TABLE_DATA` `COPY` blocks. Only then is it
renamed, encrypted, uploaded and the marker written. A dump that fails is kept
as `whity_….sql.gz.invalid` for inspection and the script exits non-zero
without touching the marker. Lower the floors only for a database that really
is that small (a fresh install with no data).

**Schedule it** (host crontab example — nightly at 02:30):

```cron
30 2 * * *  cd /opt/whity && BACKUP_ENCRYPTION_KEY=… BACKUP_S3_BUCKET=… \
            DB_USER=… DB_PASSWORD=… bash scripts/backup-db.sh >> /var/log/whity-backup.log 2>&1
```

**Alert on staleness and on bad dumps**: `ops/alerting/backup-freshness-textfile.sh`
exports the marker as `whity_backup_last_success_timestamp_seconds`
(`WhityBackupStale`) and also checks the newest dump in `BACKUP_DIR`, exporting
`whity_backup_latest_valid` (`WhityBackupLatestInvalid` fires on 0), plus
`whity_backup_latest_verified`, `whity_backup_latest_size_bytes` and
`whity_backup_latest_table_data_entries`. Give the exporter the same
`BACKUP_ENCRYPTION_KEY` so encrypted dumps are checked whole; without it only
the size floor applies and `whity_backup_latest_verified` is 0. A fresh marker
alone is not proof: a 20-byte gzip of nothing is fresh too.

## Restore — `scripts/restore-db.sh [--check] [<backup-file>]`

Restores a backup into the database named by `DB_NAME` — point that at a **clean
restore target / standby**, never blindly at production. Decrypts `.enc` backups
with `BACKUP_ENCRYPTION_KEY`. Plain SQL dumps go through `psql`; pg_dump custom
or tar archives (`*.dump`, `*.tar`) through `pg_restore`.

It refuses a file that fails the same check as the backup (empty, below the
size floor, damaged, cut short, or too little table data) and says why. With no
file named it walks `BACKUP_DIR` newest first, names each newer dump it skips
with the reason, and restores the newest **valid** one. `--check` does the check
(and the pick) and stops, without touching a database:

```sh
BACKUP_DIR=/var/backups/whity BACKUP_ENCRYPTION_KEY=… bash scripts/restore-db.sh --check
```

After it returns, run migrations and verify:

```sh
# pull from S3 first if that is where the backup lives, then:
DB_NAME=whity_restore BACKUP_ENCRYPTION_KEY=… bash scripts/restore-db.sh whity_….sql.gz.enc
DB_NAME=whity_restore php public/index.php migrate run     # must be a no-op
DB_NAME=whity_restore php public/index.php migrate status  # must be clean
```

## Rehearsed drill (RTO/RPO)

`.github/workflows/backup-restore-drill.yml` runs the real backup→restore
round-trip (encryption on) against a throwaway PostgreSQL weekly, on demand, and
on any PR that touches the scripts — asserting the restored copy has the seeded
data and is migration-consistent, and printing the restore duration (RTO).
It also proves the guard: an empty (20-byte) dump and a damaged one must be
refused by `restore-db.sh`, the no-file restore must skip them for the real
dump, and the freshness exporter must report `whity_backup_latest_valid 0`.

- **RPO** is set by the backup *cadence* (nightly cron ⇒ ≤24h). Tighten by
  scheduling more frequently and/or enabling WAL archiving (future work).
- **RTO** is the restore duration the drill reports, plus your host's provision +
  DNS cutover time. Re-run the drill after any schema/scale change.

Practice a real restore-to-clean-stack (download from S3 → restore → migrate →
smoke `/api/health`) before relying on it in an incident.
