import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { CliError } from '../errors.js';
import { m } from '../messages.js';
import { newEnvFile, parseEnv } from '../envfile.js';
import { readIfPresent } from '../files.js';
import { assertSupported, composeFor, latestVersion, normalizeVersion } from '../release.js';
import { smoke } from '../smoke.js';
import { checkDocker, outputOf, stack } from '../stack.js';

/** Present from when .env is written until the install works, so a failed install can be run again. */
const MARKER = '.whity-install-in-progress';

/** The web server in front on a server install; it gets and renews the certificate. */
export const CADDY_IMAGE = 'caddy:2.8.4';

/** How long to wait for Let's Encrypt before saying what to check. */
const CERTIFICATE_WAIT_MS = 180_000;
const CERTIFICATE_POLL_MS = 5_000;

const DOMAIN = /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/;
// No quotes or spaces: the address is also used in a database lookup and the Caddyfile.
// No quotes or backslashes (it goes into SQL and the .env), and no `#`, which
// docker compose reads as the start of a comment in an unquoted .env value
// (WHIT-1290, #2208).
const EMAIL = /^[^@\s'"\\#]+@[^@\s'"\\#]+\.[^@\s'"\\#]+$/;

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
  // Written unquoted to .env, where `#` starts a comment for docker compose
  // (WHIT-1290, #2208); a site address has no use for one, or for spaces or quotes.
  if (/[#\s'"\\]/.test(url)) {
    throw new CliError(m.badUrl(url), 2);
  }
  const local = ['localhost', '127.0.0.1'].includes(parsed.hostname);
  if (parsed.protocol !== 'https:' && !local) {
    throw new CliError(m.httpsNeeded(url), 2);
  }
}

/** A domain as people type it ("https://Office.Example.jo/") as Caddy wants it. */
function cleanDomain(input) {
  return input.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
}

function siteUrl(domain, httpsPort) {
  return httpsPort === 443 ? `https://${domain}` : `https://${domain}:${httpsPort}`;
}

/** Caddy in front, in the override file: `whity update` replaces docker-compose.yml and keeps this. */
function caddyFiles(domain, httpsPort, email) {
  const caddyfile = `# Written by whity install. Caddy gets and renews the certificate for this address.
{
\temail ${email}
}

${domain}:${httpsPort} {
\tencode zstd gzip
\treverse_proxy web:3000
}
`;
  const override = `# Written by whity install: Caddy serves Whity over HTTPS at ${siteUrl(domain, httpsPort)}.
# whity update replaces docker-compose.yml and leaves this file alone.
services:
  caddy:
    image: ${CADDY_IMAGE}
    restart: unless-stopped
    ports:
      - "80:80"
      - "${httpsPort}:${httpsPort}"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - web
volumes:
  caddy_data:
  caddy_config:
`;
  return { caddyfile, override };
}

/** The answer to a question on the terminal, or the given value; null when nobody can be asked. */
async function answer(given, question, hint, deps) {
  if (given !== undefined) return given;
  if (!deps.ask) return null;
  return deps.ask(question, hint);
}

export async function install(options, deps) {
  const { dir } = options;
  if (options.domain !== undefined && options.url !== undefined) throw new CliError(m.domainAndUrl(), 2);
  if (options.url) assertUsableUrl(options.url);
  const envPath = join(dir, '.env');
  const existingEnv = readIfPresent(envPath);
  const resuming = existingEnv !== null && readIfPresent(join(dir, MARKER)) !== null;
  if (existingEnv !== null && !resuming) {
    throw new CliError(m.alreadyInstalled(dir));
  }
  const loud = options.verbose ? { stream: true } : {};
  const status = (line) => deps.status?.(m.doing(line));

  deps.log(m.header());
  deps.log('');
  const facts = deps.serverFacts?.();
  if (facts) deps.log(m.done(m.checkedServer(), m.serverFacts(facts)));
  const docker = await checkDocker(deps);
  deps.log(m.done(m.dockerFound(), docker));

  let envText;
  let version;
  if (resuming) {
    // The database may already use these secrets, so an earlier attempt's stay.
    envText = existingEnv;
    version = parseEnv(envText).WHITY_VERSION;
    // The .env may have been edited since: its email goes into SQL, so it is
    // checked like a typed one (WHIT-1290, #2208).
    const resumedEmail = parseEnv(envText).INITIAL_SYSTEM_ADMIN_EMAIL ?? '';
    if (!EMAIL.test(resumedEmail)) throw new CliError(m.badEmail(resumedEmail), 2);
    deps.log(m.installContinuing(version));
  } else {
    version = options.version ? normalizeVersion(options.version) : await latestVersion(deps);
    assertSupported(version);
    const webPort = parsePort(options.webPort, 3100, '--web-port');
    const apiPort = parsePort(options.apiPort, 8100, '--api-port');
    const httpsPort = parsePort(options.port, 443, '--port');
    const bind = options.bind ?? '127.0.0.1';

    // A server install, when there's a domain: asked on a terminal unless --url says otherwise.
    let domain = null;
    if (options.url === undefined) {
      const typed = await answer(options.domain, m.askDomain(), m.askDomainExample(), deps);
      if (typed) {
        domain = cleanDomain(typed);
        if (!DOMAIN.test(domain) || httpsPort === 80) throw new CliError(m.badDomain(typed), 2);
      }
    }
    let email = options.adminEmail;
    if (domain !== null || email === undefined) {
      email = (await answer(options.adminEmail, m.askEmail(), '', deps)) || undefined;
    }
    if (email !== undefined && !EMAIL.test(email)) throw new CliError(m.badEmail(email), 2);
    if (deps.ask && (options.domain === undefined || options.adminEmail === undefined)) deps.log('');

    for (const port of [webPort, apiPort]) {
      if (!(await deps.portIsFree(port, bind))) {
        throw new CliError(m.portInUse(port));
      }
    }
    if (domain !== null) {
      for (const port of [80, httpsPort]) {
        if (!(await deps.portIsFree(port, '0.0.0.0'))) throw new CliError(m.serverPortInUse(port));
      }
      if (deps.resolves && !(await deps.resolves(domain))) throw new CliError(m.domainUnresolved(domain));
    }
    if (facts && facts.memGb < 2) deps.log(m.warning(m.lowMemory(), m.lowMemoryTodo()));

    envText = newEnvFile({
      version,
      project: projectName(dir),
      url: domain !== null ? siteUrl(domain, httpsPort) : (options.url ?? `http://localhost:${webPort}`),
      webPort,
      apiPort,
      bind,
      adminEmail: email ?? 'system@whity.local',
      site: domain !== null ? { domain, httpsPort } : null,
    });
  }

  const env = parseEnv(envText);
  const site = env.WHITY_DOMAIN ? { domain: env.WHITY_DOMAIN, httpsPort: Number(env.WHITY_HTTPS_PORT || 443) } : null;

  status(m.downloading(version));
  const composeText = await composeFor(version, deps);
  mkdirSync(dir, { recursive: true });
  if (!resuming) {
    // Exclusive: another install that got here first keeps its secrets, because
    // replacing them would lock it out of a database it already set up.
    try {
      writeFileSync(envPath, envText, { mode: 0o600, flag: 'wx' });
    } catch (error) {
      if (error.code === 'EEXIST') throw new CliError(m.alreadyInstalled(dir));
      throw error;
    }
    writeFileSync(join(dir, '.gitignore'), '.env\nbackups/\n');
  }
  writeFileSync(join(dir, MARKER), '');
  writeFileSync(join(dir, 'docker-compose.yml'), composeText);
  if (site) {
    const files = caddyFiles(site.domain, site.httpsPort, env.INITIAL_SYSTEM_ADMIN_EMAIL);
    writeFileSync(join(dir, 'Caddyfile'), files.caddyfile);
    writeFileSync(join(dir, 'docker-compose.override.yml'), files.override);
  }

  const run = stack(dir, envText, deps);
  const step = async (label, result) => {
    if (result.code === 0) return;
    throw new CliError(m.installStepFailed(label, outputOf(result), envPath));
  };

  await run.pullImages(version, { ...loud, announce: false });
  await step(m.labelPullOthers(), await run.compose(['pull', '--quiet', 'postgres', ...(site ? ['caddy'] : [])], loud));
  deps.log(m.done(m.downloaded(version)));

  status(m.starting());
  await step(m.labelStartDatabase(), await run.compose(['up', '-d', '--wait', 'postgres'], loud));
  await step(m.labelMigrations(), await run.cli(['migrate', 'run'], loud));
  await step(m.labelSeed(), await run.cli(['seed'], loud));
  await step(m.labelStartWhity(), await run.startAndWait([], loud));
  try {
    await smoke({ run, env, version, deps });
  } catch (error) {
    throw new CliError(m.installChecksFailed(error.message, dir));
  }
  deps.log(m.done(m.started()));

  let certificate = true;
  if (site) {
    status(m.gettingCertificate(site.domain));
    certificate = await waitForCertificate(env.APP_URL, deps);
    if (certificate) deps.log(m.done(m.gotCertificate(site.domain)));
    else deps.log(m.warning(m.noCertificate(site.domain), m.noCertificateTodo(site.domain, site.httpsPort), `cd ${dir} && docker compose restart caddy`));
  }

  status(m.makingOwner());
  await step(m.labelOwner(), await ownerExists(run, env));
  rmSync(join(dir, MARKER));
  deps.log(m.done(m.madeOwner()));

  deps.log('');
  if (certificate) deps.log(m.ready());
  else deps.log(m.readyNoCertificate());
  deps.log(m.openAt(env.APP_URL));
  deps.log(m.signInAs(env.INITIAL_SYSTEM_ADMIN_EMAIL, envPath));
  deps.log(m.keepEnv());
  deps.log(m.updateLater());
}

/** Whether the site answers over HTTPS, which it only does once Caddy has the certificate. */
async function waitForCertificate(url, deps) {
  for (let waited = 0; waited <= CERTIFICATE_WAIT_MS; waited += CERTIFICATE_POLL_MS) {
    try {
      const response = await deps.fetch(`${url}/login`, { signal: AbortSignal.timeout(CERTIFICATE_POLL_MS) });
      if (response.ok) return true;
    } catch {
      // Not yet: no certificate, or DNS not there yet.
    }
    await deps.sleep(CERTIFICATE_POLL_MS);
  }
  return false;
}

/** The seed made the owner account: a row answers; otherwise the step fails with what psql said. */
async function ownerExists(run, env) {
  // A SQL literal, quotes doubled, even though EMAIL already refuses them (WHIT-1290).
  const literal = String(env.INITIAL_SYSTEM_ADMIN_EMAIL).replaceAll("'", "''");
  const sql = `SELECT 1 FROM profile_emails WHERE lower(email) = lower('${literal}')`;
  const result = await run.compose(['exec', '-T', 'postgres', 'psql', '-U', env.DB_USER, '-d', env.DB_NAME, '-tAc', sql]);
  if (result.code === 0 && result.stdout.trim() !== '1') {
    return { code: 1, stdout: '', stderr: `${env.INITIAL_SYSTEM_ADMIN_EMAIL}: no account` };
  }
  return result;
}
