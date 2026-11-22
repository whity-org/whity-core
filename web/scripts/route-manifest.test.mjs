// node --test web/scripts/route-manifest.test.mjs  (whity-org/whity-core#2386)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildManifest, diffManifests, idProblems, idsFor, scanRoutes, toPath } from './route-manifest.mjs';

const WEB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function appTree(files) {
  const root = mkdtempSync(path.join(tmpdir(), 'routes-'));
  for (const file of files) {
    const full = path.join(root, file);
    mkdirSync(path.dirname(full), { recursive: true });
    writeFileSync(full, 'export default function Page() { return null; }\n');
  }
  return root;
}

const IDS = {
  nav: { calendar: '/calendar', meetings: '/calendar/meetings', users: '/admin/users', chat: '/chat' },
  settings: { bookings: '/calendar/settings' },
};

test('dynamic segments become :name, catch-alls :name*', () => {
  assert.equal(toPath([]), '/');
  assert.equal(toPath(['admin', 'users', '[id]']), '/admin/users/:id');
  assert.equal(toPath(['mcp', '[...path]']), '/mcp/:path*');
  assert.equal(toPath(['docs', '[[...slug]]']), '/docs/:slug*?');
});

test('route groups set the area and leave the path', () => {
  const app = appTree([
    '(protected)/calendar/page.tsx',
    '(editor)/admin/documents/page.tsx',
    'login/page.tsx',
    'join/[workspace]/page.tsx',
    'f/[slug]/page.tsx',
    'sw.js/route.ts',
    'mcp/route.ts',
    '_private/page.tsx',
  ]);
  const byPath = Object.fromEntries(scanRoutes(app).map((r) => [r.path, r]));
  assert.equal(byPath['/calendar'].area, 'protected');
  assert.equal(byPath['/admin/documents'].area, 'editor');
  assert.equal(byPath['/login'].area, 'auth');
  assert.equal(byPath['/join/:workspace'].area, 'auth');
  assert.equal(byPath['/f/:slug'].area, 'public');
  assert.equal(byPath['/sw.js'].kind, 'file');
  assert.equal(byPath['/mcp'].kind, 'forwarder');
  assert.equal(byPath['/_private'], undefined);
});

test('a route sits under the id whose href is its closest ancestor', () => {
  assert.deepEqual(idsFor('/calendar/meetings/:id', IDS.nav), ['meetings']);
  assert.deepEqual(idsFor('/calendar/rooms', IDS.nav), ['calendar']);
  assert.deepEqual(idsFor('/admin/users-old', IDS.nav), []);
  assert.deepEqual(idsFor('/x', { a: '/x', b: '/x' }), ['a', 'b']);
});

test('since is kept for known routes, new ones take the current version, public pages get no ids', () => {
  const app = appTree(['(protected)/calendar/page.tsx', '(protected)/calendar/settings/page.tsx', 'chat/[workspace]/page.tsx']);
  const previous = { routes: [{ path: '/calendar', since: '0.2.7' }] };
  const m = buildManifest({ appDir: app, ids: IDS, previous, coreVersion: '0.2.32' });
  const byPath = Object.fromEntries(m.routes.map((r) => [r.path, r]));
  assert.equal(byPath['/calendar'].since, '0.2.7');
  assert.equal(byPath['/calendar/settings'].since, '0.2.32');
  assert.deepEqual(byPath['/calendar/settings'].settings_ids, ['bookings']);
  assert.deepEqual(byPath['/chat/:workspace'].nav_ids, []);
});

test('a new route without a readable version fails loudly', () => {
  const app = appTree(['(protected)/new/page.tsx']);
  assert.throws(() => buildManifest({ appDir: app, ids: IDS, previous: null, coreVersion: null }), /needs a version/);
});

test('the release-notes diff names added and removed routes', () => {
  const md = diffManifests({ routes: [{ path: '/a' }, { path: '/b' }] }, { routes: [{ path: '/b' }, { path: '/c' }] });
  assert.match(md, /added:\*\* `\/c`/);
  assert.match(md, /removed:\*\* `\/a`/);
  assert.equal(diffManifests({ routes: [{ path: '/a' }] }, { routes: [{ path: '/a' }] }), '');
});

test('the stable-id registry matches what the repository declares today', () => {
  const ids = JSON.parse(readFileSync(path.join(WEB, 'lib', 'routes', 'stable-ids.json'), 'utf8'));
  assert.deepEqual(idProblems(ids), []);
});

test('an id the server declares but the registry lacks is a problem', () => {
  const ids = JSON.parse(readFileSync(path.join(WEB, 'lib', 'routes', 'stable-ids.json'), 'utf8'));
  delete ids.nav.calendar;
  assert.ok(idProblems(ids).some((p) => p.includes('"calendar"')));
});

test('a registry id that nothing declares any more must be moved to removed', () => {
  const ids = JSON.parse(readFileSync(path.join(WEB, 'lib', 'routes', 'stable-ids.json'), 'utf8'));
  ids.nav['gone-page'] = '/gone';
  assert.ok(idProblems(ids).some((p) => p.includes('"gone-page"') && p.includes('removed')));
});

test('the checked-in manifest is well formed', () => {
  const m = JSON.parse(readFileSync(path.join(WEB, 'routes.json'), 'utf8'));
  assert.equal(m.schema, 1);
  for (const r of m.routes) {
    assert.match(r.path, /^\//);
    assert.ok(['page', 'file', 'forwarder'].includes(r.kind), r.path);
    assert.ok(['auth', 'protected', 'editor', 'public'].includes(r.area), r.path);
    assert.match(r.since, /^\d+\.\d+\.\d+$/, r.path);
  }
  assert.ok(m.routes.some((r) => r.path === '/web-build/routes.json'));
});
