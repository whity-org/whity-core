import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { backup, restoreSteps } from '../backup.js';
import { CliError } from '../errors.js';
import { parseEnv, setEnvValue } from '../envfile.js';
import { assertSupported, compareVersions, composeFor, latestVersion, normalizeVersion } from '../release.js';
import { localOrigin, smoke } from '../smoke.js';
import { checkDocker, outputOf, stack } from '../stack.js';

/** Present while the database may not match the running version. Holds the restore steps. */
const MARKER = '.whity-update-in-progress.json';
const NEXT_COMPOSE = 'docker-compose.next.yml';

function restoreMessage({ reason, from, to, steps }) {
  return `${reason}\n\n`
    + `The update from ${from} to ${to} did not finish and Whity may be down.\n`
    + `To go back to ${from} and the data from before the update, run these one at a time\n`
    + '(they are the same in PowerShell, bash and zsh):\n\n'
    + steps.map((line) => `  ${line}`).join('\n');
}

function stamp(date) {
  return date.toISOString().replace(/[-:]/g, '').replace(/\..*/, '').replace('T', '-');
}

async function readHealthVersion(env, deps) {
  try {
    const response = await deps.fetch(`${localOrigin(env)}/api/health`, { signal: AbortSignal.timeout(5_000) });
    return response.ok ? (await response.json()).version ?? null : null;
  } catch {
    return null;
  }
}

/**
 * The images download while Whity keeps running, so downtime starts only after
 * they are here. The writers stop before the backup, so the backup holds every
 * change the old version accepted and the restore loses nothing.
 */
export async function update(options, deps) {
  const { dir } = options;
  const envPath = join(dir, '.env');
  const composePath = join(dir, 'docker-compose.yml');
  const markerPath = join(dir, MARKER);
  if (!existsSync(envPath) || !existsSync(composePath)) {
    throw new CliError(`No install found in ${dir}. Use --dir to point at one, or run: whity install`);
  }

  if (existsSync(markerPath) && !options.force) {
    const earlier = JSON.parse(readFileSync(markerPath, 'utf8'));
    throw new CliError(restoreMessage({
      reason: `An earlier update from ${earlier.from} to ${earlier.to} did not finish.`,
      ...earlier,
    }) + '\n\nIf you fixed the cause and want to try again without restoring, add --force.');
  }

  deps.log('[1/8] Checking Docker and the installed version');
  await checkDocker(deps);
  const envText = readFileSync(envPath, 'utf8');
  const env = parseEnv(envText);
  const from = env.WHITY_VERSION;
  if (!from) throw new CliError(`${envPath} has no WHITY_VERSION, so this tool can't tell what is installed.`);

  const to = options.version ? normalizeVersion(options.version) : await latestVersion(deps);
  assertSupported(to);
  const order = compareVersions(to, from);
  if (order === 0) {
    deps.log(`Already on ${from}. Nothing to do.`);
    return;
  }
  if (order < 0) {
    throw new CliError(`${to} is older than the installed ${from}. Migrations only go forward, so downgrading isn't possible. To go back, restore a backup.`);
  }

  const running = await readHealthVersion(env, deps);
  if (running && `v${running}` !== from && !options.force) {
    throw new CliError(`${envPath} says ${from} but Whity reports ${running}. Sort that out first, or add --force.`);
  }
  deps.log(`  ${from} -> ${to}`);

  const run = stack(dir, envText, deps);

  deps.log(`[2/8] Checking the compose file of ${to}`);
  const nextCompose = await composeFor(to, deps);
  const nextPath = join(dir, NEXT_COMPOSE);
  writeFileSync(nextPath, nextCompose);
  try {
    const checked = await run.compose(['-f', NEXT_COMPOSE, 'config', '--quiet']);
    if (checked.code !== 0) {
      throw new CliError(`${to} needs a change to your settings first. Nothing has been changed.\n${checked.stderr.trim()}`);
    }
  } finally {
    rmSync(nextPath);
  }

  deps.log(`[3/8] Pulling ${to} (Whity keeps running)`);
  try {
    await run.pullImages(to);
  } catch (error) {
    throw new CliError(`${error.message}\n\nNothing has been changed. Whity is still running ${from}.`);
  }

  deps.log('[4/8] Stopping Whity, except the database');
  const services = await run.compose(['config', '--services']);
  if (services.code !== 0) throw new CliError(`Could not read the services.\n${services.stderr.trim()}`);
  const writers = services.stdout.split(/\r?\n/).map((s) => s.trim()).filter((s) => s && s !== 'postgres');
  const restart = async () => {
    await run.compose(['up', '-d']);
  };
  const stopped = await run.compose(['stop', ...writers]);
  if (stopped.code !== 0) {
    await restart();
    throw new CliError(`Could not stop Whity. Nothing has been changed.\n${stopped.stderr.trim()}`);
  }

  deps.log('[5/8] Backing up the database and proving the backup restores');
  const id = `pre-${to}-${stamp(deps.now())}`;
  let relativeDir;
  try {
    const up = await run.compose(['up', '-d', '--wait', 'postgres']);
    if (up.code !== 0) throw new CliError(`The database did not start.\n${up.stderr.trim()}`);
    relativeDir = await backup({ dir, run, env, id, deps });
  } catch (error) {
    await restart();
    throw new CliError(`${error.message}\n\nNothing has been changed. Whity is back on ${from}.`);
  }

  const steps = restoreSteps({ dir, relativeDir, env, marker: MARKER });
  writeFileSync(markerPath, JSON.stringify({ from, to, steps }, null, 2));
  const failed = (reason) => new CliError(restoreMessage({ reason, from, to, steps }));

  setEnvValue(envPath, 'WHITY_VERSION', to);
  writeFileSync(composePath, nextCompose);
  const nextEnv = readFileSync(envPath, 'utf8');
  const nextRun = stack(dir, nextEnv, deps);

  deps.log('[6/8] Running migrations');
  const migrated = await nextRun.cli(['migrate', 'run']);
  if (migrated.code !== 0) {
    throw failed(`Migrations failed.\n${outputOf(migrated)}`);
  }

  deps.log(`[7/8] Starting Whity ${to}`);
  const started = await nextRun.compose(['up', '-d', '--wait', '--wait-timeout', '180', '--remove-orphans']);
  if (started.code !== 0) {
    throw failed(`Whity did not start.\n${started.stderr}`.trim());
  }

  deps.log('[8/8] Checking /api/health, /web-build and the services');
  try {
    await smoke({ run: nextRun, env, version: to, deps });
  } catch (error) {
    if (error instanceof CliError) throw failed(error.message);
    throw error;
  }

  rmSync(markerPath);
  deps.log('');
  deps.log(`Whity is now on ${to}.`);
  deps.log(`  Backup from before the update: ${join(dir, relativeDir)}`);
}
