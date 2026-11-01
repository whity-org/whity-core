import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
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
  assert.match(output, /http:\/\/localhost:3100\/login/);
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

  await assert.rejects(install({ dir, version: 'v0.2.22' }, fakeDeps()), /already holds an install/);
  assert.equal(readFileSync(join(dir, '.env'), 'utf8'), before);
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
    /Docker was not found/,
  );
  await assert.rejects(
    install({ dir: tempDir(t) }, fakeDeps({ fail: { 'version --format': { code: 1, stderr: 'pipe not found' } } })),
    /not running[\s\S]*pipe not found/,
  );
});
