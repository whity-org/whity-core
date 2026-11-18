# Migrations

How Whity's database migrations are written, run and kept safe on a big
PostgreSQL database. Production is PostgreSQL: every company shares the same
tables, told apart by `tenant_id`, so a table that grows with companies grows
with all of them at once. The tests also run the migrations on SQLite.

- [Running them](#running-them)
- [The schema baseline](#the-schema-baseline)
- [Writing a safe migration](#writing-a-safe-migration)
- [The lint rules](#the-lint-rules)
- [Numbers: how they are reserved](#numbers-how-they-are-reserved)

## Running them

```bash
php public/index.php migrate status          # every migration: Executed or Pending
php public/index.php migrate plan            # what run would do; changes nothing
php public/index.php migrate run             # run what is pending
php public/index.php migrate run --no-baseline   # replay every migration, even on an empty database
```

`run` prints each migration with how long it took, then the total:

```
  Running: 309_add_booking_notes... ✓ 41 ms
  Running: plugin:Glossary:CreateGlossaryTermsTable... ✓ 42 ms

✓ Successfully ran 2 migration(s) in 0.2 s
```

`plan` (also `run --dry-run`) lists what would run and what the [lint](#the-lint-rules)
says about each core migration, without touching the database. Run it before a
deployment that carries migrations.

When a migration fails, `run` stops and says which one, after how long, the
database's error and SQLSTATE, what it means in plain words when that is known
(a lock timeout, a statement timeout, a duplicate row, a deadlock), what state
the database is in, and what to do next.

The format of `migrate status` doesn't change: `deploy-develop.ps1` reads its
`Executed` and `<name> Pending` lines to know what a deployment will run.

### Timeouts and retries (PostgreSQL)

`run` sets two limits on its own connection:

| Setting | Default | Why |
| --- | --- | --- |
| `MIGRATION_LOCK_TIMEOUT` | `5s` | While an `ALTER TABLE` waits for its lock, every query on that table queues behind it. Giving up after 5 seconds keeps a busy app answering. |
| `MIGRATION_STATEMENT_TIMEOUT` | `30min` | No single statement should run this long; batch it instead (below). |
| `MIGRATION_LOCK_RETRIES` | `3` | A migration that gave up on a lock is run again after 2 s, 4 s, 8 s. |

Values are PostgreSQL durations (`500ms`, `5s`, `30min`); `0` turns a limit
off. Retrying is safe because migrations are idempotent (below). If a migration
still can't get its lock, look in `pg_stat_activity` for the long transaction
holding it, or run again at a quieter time.

## The schema baseline

A fresh install doesn't replay every migration. `database/baseline/` holds the
database that migrations 001 to N leave behind, and `migrate run` on an
**empty** database loads it in one transaction, records 001 to N as applied,
and then runs the migrations after N as usual.

| Fresh install (Postgres 15, local Docker) | Time |
| --- | --- |
| Replaying migrations 001 to 308, then plugins and translations | 2 min 13 s |
| Loading the baseline (5.0 s), then plugins and translations | 13.7 s |

An existing install never takes the baseline: it has tables, so it keeps
running the numbered migrations exactly as before.

Files:

- `manifest.json`: which migrations the baseline stands for, and a fingerprint
  of their source.
- `baseline.pgsql.sql`: the PostgreSQL schema and seed rows.
- `baseline.sqlite.sql`: the same for the SQLite test engine. The test suite
  loads it too, which saves about 9 s per test process.

Rows that differ per install aren't stored as they were: timestamps written
during the replay are a placeholder set to the install's own time on load, and
the bootstrap administrator gets its password from
`INITIAL_SYSTEM_ADMIN_PASSWORD` (or a printed random one) and its email from
`INITIAL_SYSTEM_ADMIN_EMAIL`, as migrations 036 and 095 do.

### How we know it is the same database

`tests/Database/MigrationBaselineEquivalenceTest.php` builds the database both
ways, by replaying every migration and from the baseline, and compares tables,
columns in order, defaults, constraints, indexes, triggers, functions, views,
sequences, extensions and every seeded row. It runs on SQLite everywhere and on
PostgreSQL in the dialect shards.

### When a baseline migration changes

Migrations that have shipped must not change. If one does, its fingerprint no
longer matches: `migrate run` refuses the baseline and replays instead (and
says so), and CI fails (`php scripts/migration-baseline.php check` and the
equivalence test) until the baseline is made again.

### Moving the baseline forward

Do it when many migrations have piled up after N, not every release. All the
migrations up to the new N must already be on `develop`.

```bash
# A PostgreSQL 15 server you may create databases on, and pg_dump for it.
export BASELINE_PG_DSN='pgsql:host=127.0.0.1;port=5432;dbname=postgres'
export BASELINE_PG_USER=whity BASELINE_PG_PASSWORD=whity_dev
export BASELINE_PG_DUMP='pg_dump -h 127.0.0.1 -p 5432 -U whity'
php scripts/migration-baseline.php generate --through=NNN
```

It clears the `INITIAL_*` and render variables first, replays 001 to NNN into
a scratch PostgreSQL database and a SQLite one, and writes the three files. The
output is deterministic: running it twice gives the same files. Run it with the
SQLite version CI uses (the manifest records the one it was made with); a newer
SQLite can build a slightly different SQLite schema, and the equivalence test
then says so and skips.

Commit the three files, run the equivalence test on PostgreSQL, and the
migrations up to NNN stop being linted in CI (they have run everywhere that
matters).

## Writing a safe migration

A migration is a file `database/migrations/NNN_snake_case.php` with a class in
`Database\Migrations` and static `up(Database $db)` and `down(Database $db)`.

### Always

- **Idempotent.** It must be safe to run twice, because a failed run is fixed
  by running again. `CREATE TABLE IF NOT EXISTS`, `CREATE INDEX … IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS`, `DROP … IF EXISTS`, `INSERT … ON CONFLICT DO NOTHING`.
  PostgreSQL has no `ADD CONSTRAINT IF NOT EXISTS`: look in `pg_constraint`
  first, or catch the duplicate.
- **Expect a big table.** Any table with a `tenant_id` will have rows from
  every company. Assume millions.
- **Not in one transaction.** Core migrations run statement by statement, so
  the concurrent and batched forms below work. It also means a migration that
  fails half way has applied half: another reason it must be idempotent.
- **Two releases for anything destructive.** Running code reads the old shape
  while the new release starts. Add the new column, backfill, switch the code,
  and drop the old column in a later release.

### Index on an existing table

```php
use Whity\Database\Migration\SafeIndex;

SafeIndex::create($db, 'idx_bookings_tenant_start', 'bookings', 'tenant_id, starts_at');
SafeIndex::create($db, 'uq_bookings_ref', 'bookings', 'tenant_id, ref', unique: true, where: 'ref IS NOT NULL');
```

On PostgreSQL that is `CREATE INDEX CONCURRENTLY`: reads and writes carry on
while it builds. A concurrent build that failed leaves an invalid index;
`SafeIndex` drops it and builds again, so the migration can be re-run. On
SQLite it is a plain `CREATE INDEX IF NOT EXISTS`. Plain `CREATE INDEX` is fine
on a table the same migration creates.

### New column

- `ADD COLUMN IF NOT EXISTS x TEXT` (nullable): instant.
- `ADD COLUMN IF NOT EXISTS x TEXT NOT NULL DEFAULT 'open'`: instant. A
  constant default, `now()` or `CURRENT_TIMESTAMP` is stored once, not written
  into every row.
- Not `SERIAL`, `GENERATED … STORED`, or a default such as
  `gen_random_uuid()` or `random()` on an existing table: each rewrites every row
  under an exclusive lock. Add it nullable, backfill in batches, then set the
  default for new rows.
- Not `NOT NULL` without a default: it fails on any table with rows.

### NOT NULL, foreign keys, CHECK, UNIQUE on an existing table

Each of these checks every row while holding a lock. Split the check off:

```sql
ALTER TABLE bookings ADD CONSTRAINT fk_bookings_profile
    FOREIGN KEY (profile_id) REFERENCES profiles(id) NOT VALID;   -- instant
ALTER TABLE bookings VALIDATE CONSTRAINT fk_bookings_profile;     -- scans, but doesn't block writes
```

- NOT NULL: add `CHECK (x IS NOT NULL) NOT VALID`, validate it, then
  `SET NOT NULL` (PostgreSQL uses the valid check and skips the scan).
- UNIQUE or PRIMARY KEY: build the unique index with `SafeIndex::create`, then
  `ADD CONSTRAINT … UNIQUE USING INDEX …`.

### Changing a type

Most type changes rewrite the table and its indexes. Widening a `VARCHAR`, or
`VARCHAR` to `TEXT`, only changes the catalogue and is fine. For anything else:
new column, backfill, switch the code, drop the old one later.

### Changing rows (backfills)

One `UPDATE` over a big table holds row locks on all of it until it finishes
and leaves the table bloated. Batch it; each batch commits on its own:

```php
use Whity\Database\Migration\Backfill;

Backfill::inBatches(
    $db,
    table: 'bookings',
    set: "status_v2 = CASE status WHEN 'ok' THEN 'confirmed' ELSE status END",
    where: 'status_v2 IS NULL',   // rows still to do: makes a re-run cheap
);

Backfill::deleteInBatches($db, 'notification_deliveries', "status = 'expired'");
```

Batches walk the `id` (or another unique key) in order, 5,000 rows at a time
by default. Write `where` so that a done row no longer matches, and the
backfill is idempotent. An `UPDATE … WHERE id = :id` in a loop is already one
row at a time and is fine.

### Locks to avoid

`LOCK TABLE`, `VACUUM FULL`, `CLUSTER`, `REINDEX` without `CONCURRENTLY` and
`REFRESH MATERIALIZED VIEW` without `CONCURRENTLY` hold an exclusive lock for as
long as they run. Don't put them in a migration.

## The lint rules

`php scripts/ci-migration-lint.php` reads the SQL in each migration's `up()`
(not comments, not `down()`) and flags the patterns above. CI runs it in the
static-analysis job on every migration newer than the baseline, and fails on
an error. A risky change to a large table (one with a `tenant_id`, or listed in
`database/migration-lint.json`) is an error; to any other existing table, a
warning. Tables the same migration creates are empty, so nothing is flagged on
them.

| Rule | Flags | Safe form |
| --- | --- | --- |
| `index-not-concurrent` | `CREATE INDEX` on an existing table without `CONCURRENTLY` | `SafeIndex::create` |
| `add-column-rewrite` | adding a `SERIAL`, `GENERATED … STORED` or volatile-default column | nullable column, batched backfill |
| `add-column-not-null` | `NOT NULL` column with no `DEFAULT` | add a default, or fill then set |
| `alter-column-type` | `ALTER COLUMN … TYPE` (a warning for `VARCHAR`/`TEXT`, usually widening) | new column and backfill |
| `set-not-null` | `SET NOT NULL` on an existing table | `CHECK … NOT VALID`, validate, then set |
| `constraint-not-valid` | `ADD CONSTRAINT` foreign key, `CHECK`, `UNIQUE`, `PRIMARY KEY` checked at once | `NOT VALID` then `VALIDATE`, or `USING INDEX` |
| `unbatched-backfill` | `UPDATE`/`DELETE` over a large table, or an `UPDATE` with no `WHERE` | `Backfill::inBatches` |
| `long-lock` | `LOCK TABLE`, `VACUUM FULL`, `CLUSTER`, `REINDEX`, non-concurrent `REFRESH` | leave them out |
| `not-idempotent` | `CREATE`/`ADD COLUMN` without `IF NOT EXISTS`, `DROP` without `IF EXISTS` | the guarded form |

It is a lint, not a parser, so it can be wrong. When it is wrong for one
migration, say why in that file and the rule is off for it:

```php
// migration-lint: allow index-not-concurrent (the table was created in this release and is still empty)
```

`php scripts/ci-migration-lint.php --all` reports on every migration with totals;
`php scripts/ci-migration-lint.php path/to/file.php` checks one.

## Numbers: how they are reserved

Migration numbers are three digits and must be unique and in order: the number
is the order they run in, and every install records each one by its file name.

- **Ask before you number.** Parallel lanes would otherwise pick the same next
  number. The orchestrator session hands out numbers (and SDK minors) per lane
  before a whity-core PR is opened, and keeps the list of who holds which.
- **CI checks it.** `scripts/ci-migration-number-collision.php` (the
  history-guards job) fails a branch whose new migration reuses a number
  already on `develop`. `MigrationSchemaTest` fails when a number below the
  newest ten is missing, which usually means a merged migration was deleted or
  renamed.
- **A number that will never be used** (reserved and then not needed) is
  listed in `$permanentlySkipped` in `tests/Database/MigrationSchemaTest.php`
  with its reason, once the orchestrator confirms nothing holds it.
- **Never renumber or rename a merged migration.** Installs have recorded it
  by name; a new name runs it again.
