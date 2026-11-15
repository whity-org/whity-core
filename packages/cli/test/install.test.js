import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { install } from '../src/commands/install.js';
import { parseEnv } from '../src/envfile.js';
import { fakeDeps, indexOfCall } from './support.js';

const MARKER = '.whity-install-in-progress';

function tempDir(t) {
  const root = mkdtempSync(join(tmpdir(), 'whity-cli-install-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  return join(root, 'Example Bakery');
}

test('a good install writes the files, runs the server tool and prints how to sign in', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  await install({ dir, version: 'v0.2.22' }, deps);

  const order = ['pull ghcr.io/whity-org/whity-core:v0.2.22', 'up -d --wait postgres', 'bin/whity-cli migrate run', 'bin/whity-cli seed', 'up -d --wait --wait-timeout']
    .map((needle) => indexOfCall(deps.calls, needle));
  assert.deepEqual(order, [...order].sort((a, b) => a - b));

  const env = parseEnv(readFileSync(join(dir, '.env'), 'utf8'));
  assert.equal(env.WHITY_VERSION, 'v0.2.22');
  assert.equal(env.COMPOSE_PROJECT_NAME, 'examplebakery');
  assert.equal(env.APP_ENV, 'production');
  assert.ok(env.JWT_SECRET.length >= 32 && env.ENCRYPTION_KEY.length >= 32 && env.RENDER_SHARED_SECRET.length >= 32);
  assert.equal(new Set([env.JWT_SECRET, env.ENCRYPTION_KEY, env.RENDER_SHARED_SECRET, env.DB_PASSWORD]).size, 4);
  assert.match(readFileSync(join(dir, '.gitignore'), 'utf8'), /^\.env$/m);
  assert.equal(existsSync(join(dir, MARKER)), false);

  const output = deps.logs.join('\n');
  assert.match(output, /Open +http:\/\/localhost:3100/);
  assert.match(output, /Whity is ready\./);
  assert.match(output, /whity update/);
  assert.match(output, /system@whity\.local/);
  assert.equal(output.includes(env.INITIAL_SYSTEM_ADMIN_PASSWORD), false, 'the password must not reach the terminal');
});

test('an install that failed can run again and keeps its secrets', async (t) => {
  const dir = tempDir(t);
  await assert.rejects(
    install({ dir, version: 'v0.2.22' }, fakeDeps({ fail: { 'migrate run': { code: 1, stdout: 'boom' } } })),
    /Migrations failed\.\nboom[\s\S]*run the same "whity install" again/,
  );
  const before = readFileSync(join(dir, '.env'), 'utf8');
  assert.equal(existsSync(join(dir, MARKER)), true);

  await install({ dir }, fakeDeps({ running: '0.2.22' }));
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), before);
  assert.equal(existsSync(join(dir, MARKER)), false);
});

test('a finished install is not overwritten', async (t) => {
  const dir = tempDir(t);
  await install({ dir, version: 'v0.2.22' }, fakeDeps({ running: '0.2.22' }));
  const before = readFileSync(join(dir, '.env'), 'utf8');

  await assert.rejects(install({ dir, version: 'v0.2.22' }, fakeDeps()), /already installed in[\s\S]*Nothing was changed/);
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), before);
});

test('an install that loses a race keeps the winner\'s secrets and touches nothing', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  // Another install writes .env after this one has checked for it.
  deps.portIsFree = async () => {
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, '.env'), 'WINNER=1\n');
    return true;
  };

  await assert.rejects(install({ dir, version: 'v0.2.22' }, deps), /already installed in[\s\S]*Nothing was changed/);
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), 'WINNER=1\n');
  assert.equal(existsSync(join(dir, MARKER)), false);
  assert.equal(existsSync(join(dir, 'docker-compose.yml')), false);
  assert.equal(deps.calls.some((line) => line.includes('pull')), false);
});

test('an install that stopped before writing .env starts over', async (t) => {
  const dir = tempDir(t);
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, MARKER), '');

  await install({ dir, version: 'v0.2.22' }, fakeDeps({ running: '0.2.22' }));
  assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).WHITY_VERSION, 'v0.2.22');
});

test('an address that could never sign in is refused before anything is written', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps();
  await assert.rejects(install({ dir, url: 'http://192.168.1.20:3100' }, deps), /needs HTTPS/);
  assert.equal(deps.calls.length, 0);
  assert.equal(existsSync(dir), false);
});

test('https addresses and localhost are accepted', async (t) => {
  for (const url of ['https://app.example.com', 'http://localhost:3100', 'http://127.0.0.1:3100']) {
    const dir = tempDir(t);
    await install({ dir, version: 'v0.2.22', url }, fakeDeps({ running: '0.2.22' }));
    assert.equal(parseEnv(readFileSync(join(dir, '.env'), 'utf8')).APP_URL, url);
  }
});

test('a busy port is refused before anything is written', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps();
  deps.portIsFree = async (port) => port !== 3100;
  await assert.rejects(install({ dir, version: 'v0.2.22' }, deps), /Port 3100 is in use/);
  assert.equal(existsSync(dir), false);
});

test('versions older than the compose file supports are refused', async (t) => {
  await assert.rejects(install({ dir: tempDir(t), version: 'v0.2.19' }, fakeDeps()), /supports v0\.2\.21 and newer/);
});

test('missing Docker and a stopped daemon each get their own message', async (t) => {
  await assert.rejects(
    install({ dir: tempDir(t) }, fakeDeps({ fail: { 'version --format': { code: 127 } } })),
    /Docker isn't installed[\s\S]*get\.docker\.com/,
  );
  await assert.rejects(
    install({ dir: tempDir(t) }, fakeDeps({ fail: { 'version --format': { code: 1, stderr: 'pipe not found' } } })),
    /not running[\s\S]*pipe not found/,
  );
});

test('a server install asks the two questions, puts Caddy in front and prints the card\'s lines in order', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  const asked = [];
  deps.ask = async (question) => {
    asked.push(question);
    return question.startsWith('Web address') ? 'https://Office.ExampleBakery.jo/' : 'amro@examplebakery.jo';
  };
  deps.serverFacts = () => ({ os: 'Ubuntu 24.04', cpus: 4, memGb: 8, diskGb: 61 });
  deps.resolves = async () => true;
  await install({ dir, version: 'v0.2.22' }, deps);

  assert.deepEqual(asked, ['Web address for Whity', 'Your email (first owner)']);
  const env = parseEnv(readFileSync(join(dir, '.env'), 'utf8'));
  assert.equal(env.APP_URL, 'https://office.examplebakery.jo');
  assert.equal(env.CORS_ALLOWED_ORIGINS, 'https://office.examplebakery.jo');
  assert.equal(env.WHITY_DOMAIN, 'office.examplebakery.jo');
  assert.equal(env.TRUSTED_PROXY_HOPS, '1');
  assert.equal(env.INITIAL_SYSTEM_ADMIN_EMAIL, 'amro@examplebakery.jo');

  const caddyfile = readFileSync(join(dir, 'Caddyfile'), 'utf8');
  assert.match(caddyfile, /^office\.examplebakery\.jo:443 \{/m);
  assert.match(caddyfile, /reverse_proxy web:3000/);
  assert.match(caddyfile, /email amro@examplebakery\.jo/);
  const override = readFileSync(join(dir, 'docker-compose.override.yml'), 'utf8');
  assert.match(override, /image: caddy:/);
  assert.match(override, /"80:80"/);
  assert.match(override, /"443:443"/);
  indexOfCall(deps.calls, 'pull --quiet postgres caddy');

  const lines = deps.logs.join('\n');
  const order = ['Whity Core installer', 'Checked this server   Ubuntu 24.04', 'Docker found   27.3.1',
    'Downloaded Whity Core 0.2.22', 'Started the database and the app', 'Got a certificate for office.examplebakery.jo',
    'Made your owner account', 'Whity is ready.', 'https://office.examplebakery.jo', 'Update later with: whity update']
    .map((needle) => {
      const at = lines.indexOf(needle);
      assert.notEqual(at, -1, `missing "${needle}" in:\n${lines}`);
      return at;
    });
  assert.deepEqual(order, [...order].sort((a, b) => a - b));
  assert.equal(lines.includes(env.INITIAL_SYSTEM_ADMIN_PASSWORD), false, 'the password must not reach the terminal');
});

test('--domain and --email answer the questions, and --port moves HTTPS', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  deps.ask = async () => assert.fail('nothing should be asked');
  await install({ dir, version: 'v0.2.22', domain: 'office.example.jo', adminEmail: 'owner@example.jo', port: '8443' }, deps);
  const env = parseEnv(readFileSync(join(dir, '.env'), 'utf8'));
  assert.equal(env.APP_URL, 'https://office.example.jo:8443');
  assert.match(readFileSync(join(dir, 'docker-compose.override.yml'), 'utf8'), /"8443:8443"/);
});

test('without a certificate yet it says what to check, finishes, and never claims one', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  const fetch = deps.fetch;
  deps.fetch = async (url, options) => (new URL(String(url)).hostname === 'office.example.jo'
    ? Promise.reject(new Error('certificate')) : fetch(url, options));
  await install({ dir, version: 'v0.2.22', domain: 'office.example.jo', adminEmail: 'owner@example.jo' }, deps);
  const lines = deps.logs.join('\n');
  assert.match(lines, /! No certificate for office\.example\.jo yet/);
  assert.match(lines, /docker compose restart caddy/);
  assert.equal(lines.includes('Got a certificate'), false);
  assert.equal(lines.includes('Whity is ready.'), false);
  assert.match(lines, /Made your owner account/);
});

test('a server install refuses, before writing anything, a taken port 80, a bad domain and an unresolved one', async (t) => {
  const port80 = fakeDeps();
  port80.portIsFree = async (port) => port !== 80;
  let dir = tempDir(t);
  await assert.rejects(install({ dir, domain: 'office.example.jo', adminEmail: 'a@b.jo' }, port80), /Port 80 is in use[\s\S]*Nothing was changed/);
  assert.equal(existsSync(dir), false);

  const port443 = fakeDeps();
  port443.portIsFree = async (port) => port !== 443;
  await assert.rejects(install({ dir, domain: 'office.example.jo', adminEmail: 'a@b.jo' }, port443), /Port 443 is in use[\s\S]*--port 8443/);

  await assert.rejects(install({ dir, domain: 'not a domain', adminEmail: 'a@b.jo' }, fakeDeps()), /isn't a web address[\s\S]*Nothing was changed/);
  await assert.rejects(install({ dir, domain: 'office.example.jo', adminEmail: "o'x@b.jo" }, fakeDeps()), /isn't an email address/);

  const unresolved = fakeDeps();
  unresolved.resolves = async () => false;
  dir = tempDir(t);
  await assert.rejects(install({ dir, domain: 'office.example.jo', adminEmail: 'a@b.jo' }, unresolved), /doesn't point anywhere yet[\s\S]*A record/);
  assert.equal(existsSync(dir), false);
  assert.equal(unresolved.calls.some((line) => line.includes('pull')), false);
});

test('a missing owner account fails the install instead of printing how to sign in', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22', fail: { profile_emails: { code: 0, stdout: '\n' } } });
  await assert.rejects(install({ dir, version: 'v0.2.22' }, deps), /Finding the owner account failed/);
  assert.equal(existsSync(join(dir, MARKER)), true, 'so the same command can continue');
});

test('it waits only for services with a healthcheck, which Compose 2.33 insists on', async (t) => {
  const dir = tempDir(t);
  const deps = fakeDeps({ running: '0.2.22' });
  await install({ dir, version: 'v0.2.22' }, deps);
  const wait = deps.calls[indexOfCall(deps.calls, 'up -d --wait --wait-timeout')];
  assert.match(wait, /--wait-timeout 180 postgres web$/);
  assert.ok(indexOfCall(deps.calls, 'compose up -d') < indexOfCall(deps.calls, 'up -d --wait --wait-timeout'));
});
