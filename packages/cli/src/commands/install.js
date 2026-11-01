import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { CliError } from '../errors.js';
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
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new CliError(`${flag} must be a port number.`, 2);
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
    throw new CliError(`--url "${url}" is not a valid address.`, 2);
  }
  const local = ['localhost', '127.0.0.1'].includes(parsed.hostname);
  if (parsed.protocol !== 'https:' && !local) {
    throw new CliError(
      `Signing in needs HTTPS on any address other than localhost, so ${url} wouldn't work.\n`
      + 'Put a reverse proxy with a certificate in front of Whity and pass --url https://your-address.',
      2,
    );
  }
}

export async function install(options, deps) {
  const { dir } = options;
  if (options.url) assertUsableUrl(options.url);
  const envPath = join(dir, '.env');
  const resuming = existsSync(join(dir, MARKER));
  if (existsSync(envPath) && !resuming) {
    throw new CliError(`${dir} already holds an install. To move it to a newer release, run: whity update`);
  }

  deps.log('[1/6] Checking Docker');
  await checkDocker(deps);

  let envText;
  let version;
  if (resuming) {
    // The database may already use these secrets, so an earlier attempt's stay.
    envText = readFileSync(envPath, 'utf8');
    version = parseEnv(envText).WHITY_VERSION;
    deps.log(`  Continuing the unfinished install of ${version}`);
  } else {
    version = options.version ? normalizeVersion(options.version) : await latestVersion(deps);
    assertSupported(version);
    const webPort = parsePort(options.webPort, 3100, '--web-port');
    const apiPort = parsePort(options.apiPort, 8100, '--api-port');
    const bind = options.bind ?? '127.0.0.1';
    for (const port of [webPort, apiPort]) {
      if (!(await deps.portIsFree(port, bind))) {
        throw new CliError(`Port ${port} is in use. Choose another with --web-port or --api-port.`);
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

  deps.log(`[2/6] Writing ${dir}`);
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
    throw new CliError(
      `${label} failed.\n${outputOf(result)}\n`
      + `Fix the cause, then run the same "whity install" again to continue: it keeps ${envPath}.`,
    );
  };

  deps.log(`[3/6] Pulling ${version}`);
  await run.pullImages(version);
  await step('Pulling the other images', await run.compose(['pull', '--quiet', 'postgres']));

  deps.log('[4/6] Starting the database');
  await step('Starting the database', await run.compose(['up', '-d', '--wait', 'postgres']));

  deps.log('[5/6] Running migrations and seed');
  await step('Migrations', await run.cli(['migrate', 'run']));
  await step('Seed', await run.cli(['seed']));

  deps.log('[6/6] Starting Whity and checking it');
  await step('Starting Whity', await run.compose(['up', '-d', '--wait', '--wait-timeout', '180']));
  try {
    await smoke({ run, env, version, deps });
  } catch (error) {
    throw new CliError(
      `${error.message}\nSee what happened with: docker compose logs (in ${dir}).\n`
      + 'Then run the same "whity install" again to continue.',
    );
  }
  rmSync(join(dir, MARKER));

  deps.log('');
  deps.log(`Whity ${version} is running.`);
  deps.log(`  Sign in at: ${env.APP_URL}/login`);
  deps.log(`  Email:      ${env.INITIAL_SYSTEM_ADMIN_EMAIL}`);
  deps.log(`  Password:   the INITIAL_SYSTEM_ADMIN_PASSWORD value in ${envPath}`);
  deps.log('');
  deps.log('Change that password after you sign in, and back up the .env file.');
  deps.log('It holds the keys your data is encrypted with.');
}
