import { CliError } from './errors.js';

const REQUEST_TIMEOUT_MS = 5_000;
const POLL_MS = 3_000;
/** Long enough for a crash-looping worker to be seen restarting. */
const SETTLE_MS = 15_000;

export function localOrigin(env) {
  const bind = env.BIND_ADDRESS && env.BIND_ADDRESS !== '0.0.0.0' ? env.BIND_ADDRESS : '127.0.0.1';
  return `http://${bind}:${env.WEB_PORT_HOST ?? 3100}`;
}

async function getJson(url, deps) {
  try {
    const response = await deps.fetch(url, { signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS) });
    if (!response.ok) return { error: `${url} answered HTTP ${response.status}` };
    return { body: await response.json() };
  } catch (error) {
    return { error: `${url} did not answer: ${error.message}` };
  }
}

/** What is still wrong with the two tiers. Empty when both report the version. */
async function httpProblems(origin, plain, deps) {
  const problems = [];
  const health = await getJson(`${origin}/api/health`, deps);
  if (health.error) problems.push(health.error);
  else {
    if (health.body.status !== 'ok') problems.push(`/api/health status is "${health.body.status}"`);
    if (health.body.version !== plain) problems.push(`/api/health reports ${health.body.version}, expected ${plain}`);
  }
  const web = await getJson(`${origin}/web-build`, deps);
  if (web.error) problems.push(web.error);
  else if (web.body.core_version !== plain) {
    problems.push(`/web-build reports ${web.body.core_version}, expected ${plain}`);
  }
  const build = await getJson(`${origin}/api/build`, deps);
  if (build.error) problems.push(build.error);
  else if (build.body.pending_migration_count > 0) {
    problems.push(`/api/build reports ${build.body.pending_migration_count} pending migrations`);
  }
  return problems;
}

/** Services that are stopped or restarting. A command a worker lacks shows up here. */
async function serviceProblems(run) {
  const ids = await run.compose(['ps', '--all', '--quiet']);
  if (ids.code !== 0) return [`Could not list the services: ${ids.stderr.trim()}`];
  const list = ids.stdout.split(/\s+/).filter(Boolean);
  if (list.length === 0) return ['No services are running'];
  const format = '{{index .Config.Labels "com.docker.compose.service"}} {{.State.Status}} {{.RestartCount}}';
  const inspected = await run.docker(['inspect', '--format', format, ...list]);
  if (inspected.code !== 0) return [`Could not read the services: ${inspected.stderr.trim()}`];
  const problems = [];
  for (const line of inspected.stdout.trim().split(/\r?\n/)) {
    const [service, status, restarts] = line.trim().split(' ');
    if (status !== 'running') problems.push(`${service} is ${status}`);
    else if (service !== 'postgres' && Number(restarts) > 0) problems.push(`${service} keeps restarting`);
  }
  return problems;
}

async function renderProblems(run, plain, env) {
  if (!(env.COMPOSE_PROFILES ?? '').split(',').includes('render')) return [];
  const script = "fetch('http://127.0.0.1:8130/health').then((r)=>r.json()).then((b)=>console.log(b.core_version))";
  const result = await run.compose(['exec', '-T', 'render', 'node', '-e', script]);
  if (result.code !== 0) return [`The render service did not answer: ${result.stderr.trim()}`];
  const reported = result.stdout.trim();
  return reported === plain ? [] : [`The render service reports ${reported}, expected ${plain}`];
}

/** Waits for the install to report `version` on every tier, then checks nothing is crash-looping. */
export async function smoke({ run, env, version, deps, timeoutMs = 180_000 }) {
  const plain = version.replace(/^v/, '');
  const origin = localOrigin(env);
  let waited = 0;
  let problems = await httpProblems(origin, plain, deps);
  while (problems.length > 0 && waited < timeoutMs) {
    await deps.sleep(POLL_MS);
    waited += POLL_MS;
    problems = await httpProblems(origin, plain, deps);
  }
  if (problems.length === 0) {
    await deps.sleep(SETTLE_MS);
    problems = [...await serviceProblems(run), ...await renderProblems(run, plain, env)];
  }
  if (problems.length > 0) {
    throw new CliError(`The checks did not pass:\n${problems.map((p) => `  - ${p}`).join('\n')}`);
  }
}
