import { CliError } from './errors.js';
import { parseEnv } from './envfile.js';
import { IMAGES } from './release.js';

const COMPOSE_VARS = ['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES'];

/**
 * Compose prefers the shell's variables over .env, so a DB_PASSWORD or
 * WHITY_VERSION left in the shell would silently override the install's own.
 * Docker runs without them.
 */
export function childEnv(processEnv, envText) {
  const env = { ...processEnv };
  for (const key of [...COMPOSE_VARS, ...Object.keys(parseEnv(envText))]) delete env[key];
  return env;
}

/** Docker and Compose commands for one install folder. */
export function stack(dir, envText, deps) {
  const env = childEnv(deps.processEnv, envText);
  const docker = (args, options = {}) => deps.exec(args, { cwd: dir, env, ...options });
  const compose = (args, options = {}) => docker(['compose', ...args], options);

  return {
    docker,
    compose,
    /** Runs a command in a new container of the install's current image. */
    cli: (args) => compose(['run', '--rm', '-T', 'frankenphp', 'php', 'bin/whity-cli', ...args]),
    pullImages: async (version) => {
      for (const image of IMAGES) {
        deps.log(`  ${image}:${version}`);
        const result = await docker(['pull', `${image}:${version}`]);
        if (result.code !== 0) throw pullError(`${image}:${version}`, result);
      }
    },
  };
}

function pullError(image, result) {
  const hint = /denied/i.test(result.stderr)
    ? '\nThe images are public, so this usually means an old ghcr.io login. Run: docker logout ghcr.io'
    : '';
  return new CliError(`Could not pull ${image}.\n${result.stderr.trim()}${hint}`);
}

export async function checkDocker(deps) {
  const missing = 'Docker was not found. Install Docker (Docker Desktop on Windows and macOS), then run this again.';
  const server = await deps.exec(['version', '--format', '{{.Server.Version}}'], { env: deps.processEnv });
  if (server.code === 127) throw new CliError(missing);
  if (server.code !== 0) {
    throw new CliError(`Docker is installed but not running. Start it, then run this again.\n${server.stderr.trim()}`);
  }
  const compose = await deps.exec(['compose', 'version', '--short'], { env: deps.processEnv });
  if (compose.code !== 0) {
    throw new CliError('Docker Compose v2 was not found. Install the docker compose plugin, then run this again.');
  }
  const major = Number.parseInt(compose.stdout.trim().replace(/^v/, ''), 10);
  if (!(major >= 2)) throw new CliError(`Docker Compose v2 or newer is needed. Found ${compose.stdout.trim()}.`);
}

/** A command's own output for an error message: stdout, without the PHP tool's colour codes or Compose's progress lines. */
export function outputOf(result) {
  const text = result.stdout.trim() || result.stderr.trim();
  return text.replace(/\x1b\[[0-9;]*m/g, '');
}
