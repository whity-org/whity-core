import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { backup, restoreSteps } from '../backup.js';
import { CliError } from '../errors.js';
import { parseEnv, setEnvValue } from '../envfile.js';
import { m } from '../messages.js';
import { assertSupported, compareVersions, composeFor, latestVersion, normalizeVersion } from '../release.js';
import { localOrigin, smoke } from '../smoke.js';
import { checkDocker, outputOf, stack } from '../stack.js';

/** Present while the database may not match the running version. Holds the restore steps. */
const MARKER = '.whity-update-in-progress.json';
const NEXT_COMPOSE = 'docker-compose.next.yml';

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
    throw new CliError(m.noInstall(dir));
  }

  if (existsSync(markerPath) && !options.force) {
    const earlier = JSON.parse(readFileSync(markerPath, 'utf8'));
    throw new CliError(m.restore({
      reason: m.earlierUnfinished(earlier.from, earlier.to),
      ...earlier,
    }) + m.forceHint());
  }

  deps.log(m.updateStep1());
  await checkDocker(deps);
  const envText = readFileSync(envPath, 'utf8');
  const env = parseEnv(envText);
  const from = env.WHITY_VERSION;
  if (!from) throw new CliError(m.noVersionInEnv(envPath));

  const to = options.version ? normalizeVersion(options.version) : await latestVersion(deps);
  assertSupported(to);
  const order = compareVersions(to, from);
  if (order === 0) {
    deps.log(m.alreadyCurrent(from));
    return;
  }
  if (order < 0) {
    throw new CliError(m.noDowngrade(to, from));
  }

  const running = await readHealthVersion(env, deps);
  if (running && `v${running}` !== from && !options.force) {
    throw new CliError(m.versionMismatch(envPath, from, running));
  }
  deps.log(m.updatePlan(from, to));

  const run = stack(dir, envText, deps);

  deps.log(m.updateStep2(to));
  const nextCompose = await composeFor(to, deps);
  const nextPath = join(dir, NEXT_COMPOSE);
  writeFileSync(nextPath, nextCompose);
  try {
    const checked = await run.compose(['-f', NEXT_COMPOSE, 'config', '--quiet']);
    if (checked.code !== 0) {
      throw new CliError(m.settingsNeedChange(to, checked.stderr.trim()));
    }
  } finally {
    rmSync(nextPath);
  }

  deps.log(m.updateStep3(to));
  try {
    await run.pullImages(to);
  } catch (error) {
    throw new CliError(m.pullLeftRunning(error.message, from));
  }

  deps.log(m.updateStep4());
  const services = await run.compose(['config', '--services']);
  if (services.code !== 0) throw new CliError(m.servicesUnreadable(services.stderr.trim()));
  const writers = services.stdout.split(/\r?\n/).map((s) => s.trim()).filter((s) => s && s !== 'postgres');
  const restart = async () => {
    await run.compose(['up', '-d']);
  };
  const stopped = await run.compose(['stop', ...writers]);
  if (stopped.code !== 0) {
    await restart();
    throw new CliError(m.stopFailed(stopped.stderr.trim()));
  }

  deps.log(m.updateStep5());
  const id = `pre-${to}-${stamp(deps.now())}`;
  let relativeDir;
  try {
    const up = await run.compose(['up', '-d', '--wait', 'postgres']);
    if (up.code !== 0) throw new CliError(m.databaseDidNotStart(up.stderr.trim()));
    relativeDir = await backup({ dir, run, env, id, deps });
  } catch (error) {
    await restart();
    throw new CliError(m.backupLeftRunning(error.message, from));
  }

  const steps = restoreSteps({ dir, relativeDir, env, marker: MARKER });
  writeFileSync(markerPath, JSON.stringify({ from, to, steps }, null, 2));
  const failed = (reason) => new CliError(m.restore({ reason, from, to, steps }));

  setEnvValue(envPath, 'WHITY_VERSION', to);
  writeFileSync(composePath, nextCompose);
  const nextEnv = readFileSync(envPath, 'utf8');
  const nextRun = stack(dir, nextEnv, deps);

  deps.log(m.updateStep6());
  const migrated = await nextRun.cli(['migrate', 'run']);
  if (migrated.code !== 0) {
    throw failed(m.migrationsFailed(outputOf(migrated)));
  }

  deps.log(m.updateStep7(to));
  const started = await nextRun.compose(['up', '-d', '--wait', '--wait-timeout', '180', '--remove-orphans']);
  if (started.code !== 0) {
    throw failed(m.startFailed(started.stderr));
  }

  deps.log(m.updateStep8());
  try {
    await smoke({ run: nextRun, env, version: to, deps });
  } catch (error) {
    if (error instanceof CliError) throw failed(error.message);
    throw error;
  }

  rmSync(markerPath);
  deps.log(m.updated(to, join(dir, relativeDir)));
}
