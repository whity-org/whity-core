import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { restoreSteps } from '../src/backup.js';
import { update } from '../src/commands/update.js';
import { CliError } from '../src/errors.js';
import { COMPOSE, fakeDeps, indexOfCall, installFolder } from './support.js';

const MARKER = '.whity-update-in-progress.json';

async function runUpdate(deps, dir, version = 'v0.2.22', extra = {}) {
  return update({ dir, version, ...extra }, deps);
}

function versionIn(dir) {
  return /^WHITY_VERSION=(.*)$/m.exec(readFileSync(join(dir, '.env'), 'utf8'))[1];
}

test('a good update runs its steps in the safe order and finishes clean', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps();
  await runUpdate(deps, dir);

  const order = [
    'pull ghcr.io/whity-org/whity-core:v0.2.22',
    'pull ghcr.io/whity-org/whity-core/web:v0.2.22',
    'pull ghcr.io/whity-org/whity-core/render:v0.2.22',
    'compose stop',
    'pg_dump',
    'bin/whity-cli migrate run',
    'up -d --wait --wait-timeout',
  ].map((needle) => indexOfCall(deps.calls, needle));
  assert.deepEqual(order, [...order].sort((a, b) => a - b));

  assert.equal(versionIn(dir), 'v0.2.22');
  assert.equal(readFileSync(join(dir, 'docker-compose.yml'), 'utf8'), COMPOSE);
  assert.equal(existsSync(join(dir, MARKER)), false);
  assert.equal(existsSync(join(dir, 'docker-compose.next.yml')), false);
});

test('the backup keeps the old config beside the dump', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const before = readFileSync(join(dir, '.env'), 'utf8');
  await runUpdate(fakeDeps(), dir);

  const [id] = readdirSync(join(dir, 'backups'));
  assert.match(id, /^pre-v0\.2\.22-20261101-100000$/);
  assert.equal(readFileSync(join(dir, 'backups', id, '.env'), 'utf8'), before);
  assert.equal(readFileSync(join(dir, 'backups', id, 'docker-compose.yml'), 'utf8'), 'old compose\n');
  assert.equal(readFileSync(join(dir, 'backups', id, 'whity_core.dump'), 'utf8'), 'PGDMP');
});

test('a pull failure changes nothing and stops nothing', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps({ fail: { 'pull ghcr.io/whity-org/whity-core/render': { code: 1, stderr: 'manifest unknown' } } });

  await assert.rejects(runUpdate(deps, dir), /Nothing has been changed/);
  assert.equal(deps.calls.some((line) => line.includes('compose stop')), false);
  assert.equal(versionIn(dir), 'v0.2.21');
});

test('a denied pull points at the stale ghcr.io login', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps({ fail: { 'pull ghcr.io': { code: 1, stderr: 'denied' } } });
  await assert.rejects(runUpdate(deps, dir), /docker logout ghcr\.io/);
});

test('a compose file the settings cannot satisfy stops before anything changes', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps({ fail: { 'config --quiet': { code: 1, stderr: 'NEW_SECRET must be set' } } });

  await assert.rejects(runUpdate(deps, dir), /NEW_SECRET must be set/);
  assert.equal(deps.calls.some((line) => line.startsWith('pull')), false);
  assert.equal(existsSync(join(dir, 'docker-compose.next.yml')), false);
});

for (const [name, options, pattern] of [
  ['pg_dump fails', { fail: { pg_dump: { code: 1, stderr: 'disk full' } } }, /disk full/],
  ['the dump is an empty file', { dumpBytes: '' }, /empty file/],
  ['the dump holds no table data', { fail: { 'pg_restore --list': { code: 0, stdout: '; just a header\n' } } }, /no table data/],
  ['the dump will not restore', { fail: { 'pg_restore -U': { code: 1, stderr: 'bad archive' } } }, /bad archive/],
  ['a restored table has fewer rows', { counts: { 'copy:profiles': 1 } }, /2 rows live but 1 in the backup/],
  ['the proof tables are empty', { counts: { 'live:tenants': 0, 'copy:tenants': 0 } }, /tenants has no rows/],
]) {
  test(`a backup is refused when ${name}, and the old version comes back`, async (t) => {
    const { dir, cleanup } = installFolder();
    t.after(cleanup);
    const deps = fakeDeps(options);

    await assert.rejects(runUpdate(deps, dir), (error) => {
      assert.ok(error instanceof CliError);
      assert.match(error.message, pattern);
      assert.match(error.message, /Nothing has been changed\. Whity is back on v0\.2\.21/);
      return true;
    });
    assert.equal(versionIn(dir), 'v0.2.21', 'the version must not move');
    assert.equal(deps.calls.some((line) => line.includes('migrate run')), false, 'no migration without a backup');
    assert.equal(deps.calls.at(-1), 'compose up -d', 'Whity must be started again');
    assert.equal(existsSync(join(dir, MARKER)), false);
  });
}

test('a proof that fails partway still cleans up the scratch database', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps({ fail: { 'pg_restore -U': { code: 1, stderr: 'bad archive' } } });

  await assert.rejects(runUpdate(deps, dir));
  const restoreAt = indexOfCall(deps.calls, 'pg_restore -U');
  assert.ok(deps.calls.slice(restoreAt).some((line) => line.includes('dropdb') && line.includes('whity_backup_check')));
});

for (const [name, options, pattern] of [
  ['migrations fail', { fail: { 'migrate run': { code: 1, stdout: '\x1b[0;31mMigration failed: boom\x1b[0m' } } }, /Migrations failed\.\nMigration failed: boom\n/],
  ['Whity does not start', { fail: { 'up -d --wait --wait-timeout': { code: 1, stderr: 'unhealthy' } } }, /Whity did not start/],
]) {
  test(`when ${name} the update stops and says how to restore`, async (t) => {
    const { dir, cleanup } = installFolder();
    t.after(cleanup);
    const deps = fakeDeps(options);

    await assert.rejects(runUpdate(deps, dir), (error) => {
      assert.match(error.message, pattern);
      assert.match(error.message, /pg_restore -U whity -d whity_core/);
      assert.match(error.message, /backups\/pre-v0\.2\.22-20261101-100000\/\.env \.env/);
      return true;
    });
    assert.equal(existsSync(join(dir, MARKER)), true);
  });
}

test('a smoke failure stops the update and says how to restore', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps();
  const realFetch = deps.fetch;
  deps.fetch = async (url) => (String(url).endsWith('/web-build')
    ? new Response(JSON.stringify({ core_version: '0.2.21' }))
    : realFetch(url));

  await assert.rejects(runUpdate(deps, dir), (error) => {
    assert.match(error.message, /\/web-build reports 0\.2\.21, expected 0\.2\.22/);
    assert.match(error.message, /pg_restore -U whity/);
    return true;
  });
});

test('a worker that keeps restarting fails the checks', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  const deps = fakeDeps({ fail: { inspect: { code: 0, stdout: 'events-relay running 4\n' } } });
  await assert.rejects(runUpdate(deps, dir), /events-relay keeps restarting/);
});

test('an unfinished update blocks the next one until restored or forced', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  await assert.rejects(runUpdate(fakeDeps({ fail: { 'migrate run': { code: 1 } } }), dir));

  const blocked = fakeDeps({ running: '0.2.22' });
  await assert.rejects(runUpdate(blocked, dir, 'v0.2.23'), /did not finish[\s\S]*pg_restore[\s\S]*--force/);
  assert.equal(blocked.calls.length, 0, 'it must not touch anything');

  await runUpdate(fakeDeps({ running: '0.2.22', target: '0.2.23' }), dir, 'v0.2.23', { force: true });
  assert.equal(versionIn(dir), 'v0.2.23');
  assert.equal(existsSync(join(dir, MARKER)), false);
});

test('same version is a no-op and a downgrade is refused', async (t) => {
  const { dir, cleanup } = installFolder({ version: 'v0.2.22' });
  t.after(cleanup);
  const same = fakeDeps({ running: '0.2.22' });
  await runUpdate(same, dir);
  assert.equal(same.calls.some((line) => line.includes('stop')), false);

  await assert.rejects(runUpdate(fakeDeps(), dir, 'v0.2.21'), /older than the installed/);
});

test('a running version that disagrees with .env stops the update', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  await assert.rejects(runUpdate(fakeDeps({ running: '0.2.20' }), dir), /says v0\.2\.21 but Whity reports 0\.2\.20/);
});

test('no install in the folder is an error', async (t) => {
  const { dir, cleanup } = installFolder();
  t.after(cleanup);
  writeFileSync(join(dir, 'x'), '');
  await assert.rejects(update({ dir: join(dir, 'missing'), version: 'v0.2.22' }, fakeDeps()), /No install found/);
});

test('restore steps use nothing a shell treats differently', () => {
  const steps = restoreSteps({
    dir: 'C:\\Users\\Example Bakery\\whity',
    relativeDir: 'backups/pre-v0.2.22-1',
    env: { DB_USER: 'whity', DB_NAME: 'whity_core' },
    marker: MARKER,
  });
  assert.equal(steps[0], 'cd "C:\\Users\\Example Bakery\\whity"');
  for (const line of steps.slice(1)) {
    assert.doesNotMatch(line, /[<>|&;$`\\]|&&/, line);
  }
});
