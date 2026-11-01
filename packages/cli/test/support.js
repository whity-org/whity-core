import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { newEnvFile } from '../src/envfile.js';

export const COMPOSE = 'services: {}\n';

const ok = (stdout = '') => ({ code: 0, stdout, stderr: '' });

function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status });
}

/**
 * A stand-in for Docker and the network that behaves like a healthy install, and
 * records every docker command. `fail` maps a substring of a command to the
 * result that command gets instead, so a test can break one step.
 */
export function fakeDeps({ running = '0.2.21', target = '0.2.22', fail = {}, dumpBytes = 'PGDMP', counts = {} } = {}) {
  const calls = [];
  const logs = [];
  let live = running;

  const exec = async (args, options = {}) => {
    const line = args.join(' ');
    calls.push(line);
    for (const [needle, result] of Object.entries(fail)) {
      if (line.includes(needle)) return { stdout: '', stderr: '', ...result };
    }
    if (options.stdoutFile) {
      writeFileSync(options.stdoutFile, dumpBytes);
      return ok();
    }
    if (line.includes('compose version')) return ok('2.30.0');
    if (line.includes('config --services')) return ok('postgres\nfrankenphp\nweb\nqueue-worker\n');
    if (line.includes('pg_restore --list')) return ok('1; TABLE DATA public users\n');
    if (line.includes('count(*) FROM')) {
      const table = /FROM (\w+)/.exec(line)[1];
      const inScratch = line.includes('whity_backup_check');
      return ok(`${counts[inScratch ? `copy:${table}` : `live:${table}`] ?? 2}\n`);
    }
    if (line.includes('ps --all --quiet')) return ok('abc\n');
    if (args[0] === 'inspect') return ok('frankenphp running 0\npostgres running 3\n');
    if (line.includes('exec -T render node')) return ok(`${target}\n`);
    if (line.includes('up -d --wait --wait-timeout')) live = target;
    return ok();
  };

  const fetch = async (url) => {
    const text = String(url);
    if (text.endsWith('/api/health')) return json({ status: 'ok', version: live });
    if (text.endsWith('/web-build')) return json({ core_version: live });
    if (text.endsWith('/api/build')) return json({ pending_migration_count: 0 });
    if (text.endsWith('/releases/latest')) {
      return new Response(null, {
        status: 302,
        headers: { location: 'https://github.com/whity-org/whity-core/releases/tag/v0.2.22' },
      });
    }
    return new Response(COMPOSE, { status: 200 });
  };

  return {
    calls,
    logs,
    exec,
    fetch,
    portIsFree: async () => true,
    log: (line) => logs.push(line),
    sleep: async () => {},
    now: () => new Date('2026-11-01T10:00:00Z'),
    processEnv: {},
  };
}

/** A temporary install folder. Remove it with the returned `cleanup`. */
export function installFolder({ version = 'v0.2.21' } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'whity-cli-test-'));
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, '.env'), newEnvFile({
    version,
    project: 'test',
    url: 'http://localhost:3100',
    webPort: 3100,
    apiPort: 8100,
    bind: '127.0.0.1',
    adminEmail: 'system@whity.local',
  }));
  writeFileSync(join(dir, 'docker-compose.yml'), 'old compose\n');
  return { dir, cleanup: () => rmSync(dir, { recursive: true, force: true }) };
}

/** The position of the first recorded command containing `needle`; fails the lookup loudly. */
export function indexOfCall(calls, needle) {
  const at = calls.findIndex((line) => line.includes(needle));
  if (at === -1) throw new Error(`No docker command contained "${needle}":\n${calls.join('\n')}`);
  return at;
}
