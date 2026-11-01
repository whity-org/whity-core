import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { CliError } from '../errors.js';
import { m } from '../messages.js';
import { newEnvFile, parseEnv } from '../envfile.js';
import { assertSupported, composeFor, latestVersion, normalizeVersion } from '../release.js';
import { smoke } from '../smoke.js';
import { checkDocker, outputOf, stack } from '../stack.js';

/** Present from when .env is written until the install works, so a failed install can be run again. */
const MARKER = '.whity-install-in-progress';

function projectName(dir) {
  const name = basename(dir).toLowerCase().replace(/[^a-z0-9_-]/g, '');
  return /^[a-z0-9]/.test(name) ? name : `whity${name}`;
}

function parsePort(value, fallback, flag) {
  if (value === undefined) return fallback;
  const port = Number(value);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new CliError(m.badPort(flag), 2);
  return port;
}

/**
 * Outside development the API marks its session cookies Secure, and browsers
 * only keep those over HTTPS or on localhost. Any other http address would show
 * the login page and then never stay signed in, with no error to explain it.
 */
function assertUsableUrl(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    throw new CliError(m.badUrl(url), 2);
  }
  const local = ['localhost', '127.0.0.1'].includes(parsed.hostname);
  if (parsed.protocol !== 'https:' && !local) {
    throw new CliError(m.httpsNeeded(url), 2);
  }
}

export async function install(options, deps) {
  const { dir } = options;
  if (options.url) assertUsableUrl(options.url);
  const envPath = join(dir, '.env');
  const resuming = existsSync(join(dir, MARKER));
  if (existsSync(envPath) && !resuming) {
    throw new CliError(m.alreadyInstalled(dir));
  }

  deps.log(m.installStep1());
  await checkDocker(deps);

  let envText;
  let version;
  if (resuming) {
    // The database may already use these secrets, so an earlier attempt's stay.
    envText = readFileSync(envPath, 'utf8');
    version = parseEnv(envText).WHITY_VERSION;
    deps.log(m.installContinuing(version));
  } else {
    version = options.version ? normalizeVersion(options.version) : await latestVersion(deps);
    assertSupported(version);
    const webPort = parsePort(options.webPort, 3100, '--web-port');
    const apiPort = parsePort(options.apiPort, 8100, '--api-port');
    const bind = options.bind ?? '127.0.0.1';
    for (const port of [webPort, apiPort]) {
      if (!(await deps.portIsFree(port, bind))) {
        throw new CliError(m.portInUse(port));
      }
    }
    envText = newEnvFile({
      version,
      project: projectName(dir),
      url: options.url ?? `http://localhost:${webPort}`,
      webPort,
      apiPort,
      bind,
      adminEmail: options.adminEmail ?? 'system@whity.local',
    });
  }

  deps.log(m.installStep2(dir));
  const composeText = await composeFor(version, deps);
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, MARKER), '');
  writeFileSync(join(dir, 'docker-compose.yml'), composeText);
  if (!resuming) {
    writeFileSync(envPath, envText, { mode: 0o600 });
    writeFileSync(join(dir, '.gitignore'), '.env\nbackups/\n');
  }

  const env = parseEnv(envText);
  const run = stack(dir, envText, deps);
  const step = async (label, result) => {
    if (result.code === 0) return;
    throw new CliError(m.installStepFailed(label, outputOf(result), envPath));
  };

  deps.log(m.installStep3(version));
  await run.pullImages(version);
  await step(m.labelPullOthers(), await run.compose(['pull', '--quiet', 'postgres']));

  deps.log(m.installStep4());
  await step(m.labelStartDatabase(), await run.compose(['up', '-d', '--wait', 'postgres']));

  deps.log(m.installStep5());
  await step(m.labelMigrations(), await run.cli(['migrate', 'run']));
  await step(m.labelSeed(), await run.cli(['seed']));

  deps.log(m.installStep6());
  await step(m.labelStartWhity(), await run.compose(['up', '-d', '--wait', '--wait-timeout', '180']));
  try {
    await smoke({ run, env, version, deps });
  } catch (error) {
    throw new CliError(m.installChecksFailed(error.message, dir));
  }
  rmSync(join(dir, MARKER));

  deps.log(m.installed({ version, url: env.APP_URL, email: env.INITIAL_SYSTEM_ADMIN_EMAIL, envPath }));
}
