import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { main } from '../src/cli.js';
import { realDeps } from '../src/deps.js';
import { parseEnv, setEnvValue } from '../src/envfile.js';
import { compareVersions, composeFor, latestVersion, normalizeVersion } from '../src/release.js';
import { childEnv, outputOf } from '../src/stack.js';
import { fakeDeps } from './support.js';

test('parseEnv reads values and skips comments and CRLF', () => {
  assert.deepEqual(parseEnv('# note\r\nA=1\r\n\r\nB="two words"\nC=x=y\n'), { A: '1', B: 'two words', C: 'x=y' });
});

test('setEnvValue changes one line and keeps the others', () => {
  const dir = mkdtempSync(join(tmpdir(), 'whity-env-'));
  try {
    const path = join(dir, '.env');
    writeFileSync(path, '# keep me\nA=1\nB=2\n');
    setEnvValue(path, 'A', '9');
    assert.equal(readFileSync(path, 'utf8'), '# keep me\nA=9\nB=2\n');
    setEnvValue(path, 'NEW', 'x');
    assert.equal(parseEnv(readFileSync(path, 'utf8')).NEW, 'x');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('versions normalize and compare numerically', () => {
  assert.equal(normalizeVersion('0.2.22'), 'v0.2.22');
  assert.equal(normalizeVersion('v0.2.22'), 'v0.2.22');
  assert.throws(() => normalizeVersion('latest'), /not a release version/);
  assert.throws(() => normalizeVersion('v0.2'), /not a release version/);
  assert.ok(compareVersions('v0.2.9', 'v0.2.10') < 0);
  assert.ok(compareVersions('v1.0.0', 'v0.9.9') > 0);
  assert.equal(compareVersions('v0.2.2', 'v0.2.2'), 0);
});

test('latestVersion reads the redirect and reports anything else as a failure', async () => {
  assert.equal(await latestVersion(fakeDeps()), 'v0.2.22');
  const noRedirect = { fetch: async () => new Response('', { status: 200 }) };
  await assert.rejects(latestVersion(noRedirect), /Could not find the latest release/);
  const offline = { fetch: async () => { throw new Error('offline'); } };
  await assert.rejects(latestVersion(offline), /Could not find the latest release/);
});

test('composeFor uses the release asset, and the bundled copy only on a 404', async () => {
  const bundled = readFileSync(new URL('../templates/docker-compose.yml', import.meta.url), 'utf8');
  const answering = (body, status) => ({ fetch: async () => new Response(body, { status }) });

  assert.equal(await composeFor('v0.2.23', answering('from the release', 200)), 'from the release');
  assert.equal(await composeFor('v0.2.21', answering('', 404)), bundled);
  await assert.rejects(composeFor('v0.2.23', answering('', 503)), /HTTP 503/);
  await assert.rejects(composeFor('v0.2.23', { fetch: async () => { throw new Error('offline'); } }), /offline/);
});

test('shell variables cannot override the install', () => {
  const env = childEnv(
    { PATH: '/bin', DB_PASSWORD: 'wrong', WHITY_VERSION: 'v0.0.1', COMPOSE_FILE: 'x.yml', COMPOSE_PROFILES: 'y' },
    'DB_PASSWORD=right\nWHITY_VERSION=v0.2.22\n',
  );
  assert.deepEqual(env, { PATH: '/bin' });
});

test('outputOf removes colour codes and prefers stdout', () => {
  assert.equal(outputOf({ stdout: '\x1b[1;33mhi\x1b[0m\n', stderr: 'noise' }), 'hi');
  assert.equal(outputOf({ stdout: '', stderr: 'only this\n' }), 'only this');
});

test('docker that cannot start is reported as 127, also while writing a dump', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'whity-exec-'));
  try {
    const { exec } = realDeps();
    const env = { PATH: dir };
    assert.equal((await exec(['version'], { env })).code, 127);
    const result = await exec(['version'], { env, stdoutFile: join(dir, 'd.dump') });
    assert.equal(result.code, 127);
    // Node's own 'close' follows 'error' a tick later; a second close of the file would throw here.
    await new Promise((done) => setTimeout(done, 50));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('the command line routes, refuses and reports', async () => {
  const run = async (...argv) => {
    const deps = fakeDeps();
    const code = await main(argv, deps);
    return { code, text: deps.logs.join('\n') };
  };

  assert.match((await run('--help')).text, /whity install \[version\]/);
  assert.match((await run()).text, /whity update \[version\]/);
  assert.match((await run('--version')).text, /^\d+\.\d+\.\d+$/);

  for (const later of ['login', 'plugin']) {
    const planned = await run(later);
    assert.equal(planned.code, 2);
    assert.match(planned.text, /planned/);
  }
  assert.equal((await run('frobnicate')).code, 2);
  assert.equal((await run('--no-such-flag')).code, 2);
  assert.equal((await run('update', 'v1.0.0', 'extra')).code, 2);
});

test('every line of help is plain ASCII, which PowerShell 5.1 can print', async () => {
  const deps = fakeDeps();
  await main(['--help'], deps);
  assert.match(deps.logs.join('\n'), /^[\x00-\x7f]*$/);
});
