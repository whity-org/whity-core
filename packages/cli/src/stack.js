import { CliError } from './errors.js';
import { parseEnv } from './envfile.js';
import { m } from './messages.js';
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

  /**
   * Starts everything, then waits for the services that have a healthcheck.
   * Waiting on all of them fails on Compose 2.33 and newer: the workers turn their
   * healthcheck off, and `up --wait` refuses a service with none ("has no
   * healthcheck configured") although everything is running.
   */
  const startAndWait = async (extra = [], options = {}) => {
    const started = await compose(['up', '-d', ...extra], options);
    if (started.code !== 0) return started;
    const config = await compose(['config', '--format', 'json']);
    let services = [];
    try {
      services = Object.entries(JSON.parse(config.stdout).services ?? {})
        .filter(([, service]) => !service.healthcheck?.disable)
        .map(([name]) => name);
    } catch {
      // An older Compose without --format json: wait for everything, as before.
    }
    return compose(['up', '-d', '--wait', '--wait-timeout', '180', ...services], options);
  };

  return {
    docker,
    compose,
    startAndWait,
    /** Runs a command in a new container of the install's current image. */
    cli: (args, options = {}) => compose(['run', '--rm', '-T', 'frankenphp', 'php', 'bin/whity-cli', ...args], options),
    pullImages: async (version, { announce = true, ...options } = {}) => {
      for (const image of IMAGES) {
        if (announce) deps.log(m.pullingImage(`${image}:${version}`));
        const result = await docker(['pull', `${image}:${version}`], options);
        if (result.code !== 0) throw pullError(`${image}:${version}`, result);
      }
    },
  };
}

function pullError(image, result) {
  const hint = /denied/i.test(result.stderr) ? m.pullDeniedHint() : '';
  return new CliError(m.pullFailed(image, result.stderr.trim()) + hint);
}

export async function checkDocker(deps) {
  const server = await deps.exec(['version', '--format', '{{.Server.Version}}'], { env: deps.processEnv });
  if (server.code === 127) throw new CliError(m.dockerMissing());
  if (server.code !== 0) {
    throw new CliError(m.dockerStopped(server.stderr.trim()));
  }
  const compose = await deps.exec(['compose', 'version', '--short'], { env: deps.processEnv });
  if (compose.code !== 0) {
    throw new CliError(m.composeMissing());
  }
  const major = Number.parseInt(compose.stdout.trim().replace(/^v/, ''), 10);
  if (!(major >= 2)) throw new CliError(m.composeTooOld(compose.stdout.trim()));
  return server.stdout.trim();
}

/** A command's own output for an error message: stdout, without the PHP tool's colour codes or Compose's progress lines. */
export function outputOf(result) {
  const text = result.stdout.trim() || result.stderr.trim();
  return text.replace(/\x1b\[[0-9;]*m/g, '');
}
