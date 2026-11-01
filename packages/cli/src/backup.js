import { copyFileSync, mkdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { CliError } from './errors.js';
import { m } from './messages.js';

const SCRATCH_DB = 'whity_backup_check';
const CONTAINER_DUMP = '/tmp/whity-backup-check.dump';
/** Tables every install has rows in, so a real backup can't have none. */
const PROOF_TABLES = ['tenants', 'profiles'];

function fail(step, result) {
  return new CliError(m.backupStepFailed(step, result.stderr.trim()));
}

async function psqlCount(run, env, db, table) {
  const result = await run.compose([
    'exec', '-T', 'postgres', 'psql', '-U', env.DB_USER, '-d', db, '-At', '-c', `SELECT count(*) FROM ${table}`,
  ]);
  if (result.code !== 0) throw fail(m.backupStepCount(table, db), result);
  return Number(result.stdout.trim());
}

/**
 * Proves the dump by restoring it into a scratch database and comparing row
 * counts with the live one. A dump can be a non-empty file and still restore
 * nothing, so its size proves very little. Call this with the writers stopped,
 * or the counts can differ for good reasons.
 */
async function prove(run, env, relativeDump) {
  const exec = (args) => run.compose(['exec', '-T', 'postgres', ...args]);
  const dropScratch = () => exec(['dropdb', '-U', env.DB_USER, '--if-exists', '--force', SCRATCH_DB]);

  const copied = await run.compose(['cp', relativeDump, `postgres:${CONTAINER_DUMP}`]);
  if (copied.code !== 0) throw fail(m.backupStepCopy(), copied);

  try {
    const listed = await exec(['pg_restore', '--list', CONTAINER_DUMP]);
    if (listed.code !== 0) throw fail(m.backupStepRead(), listed);
    const tables = listed.stdout.split(/\r?\n/).filter((line) => line.includes('TABLE DATA')).length;
    if (tables === 0) throw new CliError(m.backupNoTableData());

    await dropScratch();
    const created = await exec(['createdb', '-U', env.DB_USER, SCRATCH_DB]);
    if (created.code !== 0) throw fail(m.backupStepCreate(), created);
    const restored = await exec([
      'pg_restore', '-U', env.DB_USER, '-d', SCRATCH_DB, '--no-owner', '--exit-on-error', CONTAINER_DUMP,
    ]);
    if (restored.code !== 0) throw fail(m.backupStepRestore(), restored);

    const counts = [];
    for (const table of PROOF_TABLES) {
      const live = await psqlCount(run, env, env.DB_NAME, table);
      const copy = await psqlCount(run, env, SCRATCH_DB, table);
      if (live !== copy) {
        throw new CliError(m.backupRowsDiffer(table, live, copy));
      }
      if (copy === 0) throw new CliError(m.backupNoRows(table));
      counts.push(`${table}: ${copy}`);
    }
    return { tables, counts };
  } finally {
    await dropScratch();
    await exec(['rm', '-f', CONTAINER_DUMP]);
  }
}

/** Dumps the database and keeps the config that goes with it, then proves the dump restores. */
export async function backup({ dir, run, env, id, deps }) {
  const relativeDir = `backups/${id}`;
  mkdirSync(join(dir, relativeDir), { recursive: true });
  copyFileSync(join(dir, '.env'), join(dir, relativeDir, '.env'));
  copyFileSync(join(dir, 'docker-compose.yml'), join(dir, relativeDir, 'docker-compose.yml'));

  const relativeDump = `${relativeDir}/${env.DB_NAME}.dump`;
  const dump = await run.compose(
    ['exec', '-T', 'postgres', 'pg_dump', '-U', env.DB_USER, '-Fc', env.DB_NAME],
    { stdoutFile: join(dir, relativeDump) },
  );
  if (dump.code !== 0) throw fail(m.backupStepDump(), dump);
  const bytes = statSync(join(dir, relativeDump)).size;
  if (bytes === 0) throw new CliError(m.backupEmptyFile());

  const proof = await prove(run, env, relativeDump);
  deps.log(m.backupProved(relativeDump, bytes, proof.tables, proof.counts));
  return relativeDir;
}

/**
 * The restore, as commands that run the same in PowerShell 5.1, bash and zsh.
 * `compose cp` and in-container files replace `<` redirection, which PowerShell
 * doesn't have. Dropping the database also removes tables a newer migration
 * added, which `pg_restore --clean` would leave behind.
 */
export function restoreSteps({ dir, relativeDir, env, marker }) {
  const user = env.DB_USER;
  const db = env.DB_NAME;
  return [
    `cd "${dir}"`,
    'docker compose down',
    `cp ${relativeDir}/.env .env`,
    `cp ${relativeDir}/docker-compose.yml docker-compose.yml`,
    'docker compose up -d --wait postgres',
    `docker compose cp ${relativeDir}/${db}.dump postgres:/tmp/restore.dump`,
    `docker compose exec -T postgres dropdb -U ${user} --force ${db}`,
    `docker compose exec -T postgres createdb -U ${user} ${db}`,
    `docker compose exec -T postgres pg_restore -U ${user} -d ${db} --no-owner --exit-on-error /tmp/restore.dump`,
    'docker compose exec -T postgres rm /tmp/restore.dump',
    'docker compose up -d --wait',
    `rm ${marker}`,
  ];
}
