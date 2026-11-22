#!/usr/bin/env node
/**
 * The route manifest (whity-org/whity-core#2386).
 *
 * Lists every route the web app serves: its path, its area, the nav and
 * settings ids it sits under, and the core version that added it. A product
 * that runs this web image next to its own app (one origin, a proxy in front)
 * reads it to know which paths are Whity's, and the hide-by-id route guard
 * reads it to map a path to the ids an operator may hide.
 *
 *   node web/scripts/route-manifest.mjs            regenerate web/routes.json
 *   node web/scripts/route-manifest.mjs --check    fail if it is out of date (CI, prebuild)
 *   node web/scripts/route-manifest.mjs --diff <old routes.json>
 *                                                  added/removed routes as Markdown (release notes)
 *
 * WHY A CHECKED-IN FILE rather than one written only into the build: `since`
 * is history, and history is not in the tree. A route keeps the version it was
 * first listed with; a new route takes the CoreVersion of the checkout that
 * adds it. Keeping the file in git is also what lets the release compare it
 * with the previous tag and list what was added and removed.
 *
 * The build serves exactly this file at /web-build/routes.json (the route
 * handler imports it, so it is inside the image), and `prebuild` runs --check,
 * so an image can never ship a manifest that disagrees with its own pages.
 *
 * Stable ids: lib/routes/stable-ids.json. --check also fails when an id that
 * the server or the settings area declares is missing there, or when one listed
 * there is no longer declared anywhere and was not moved to `removed`.
 */
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const WEB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const APP = path.join(WEB, 'app');
const MANIFEST = path.join(WEB, 'routes.json');
const IDS = path.join(WEB, 'lib', 'routes', 'stable-ids.json');
const REPO = path.resolve(WEB, '..');

export const SCHEMA_VERSION = 1;

/** Top-level folders outside the route groups that are sign-in and onboarding. */
const AUTH_SEGMENTS = new Set([
  'login',
  'register',
  'forgot-password',
  'reset-password',
  'accept-invitation',
  'account-recovery',
  'verify-email',
  'welcome',
  'onboarding',
  'join',
]);

/** Route handlers that pass a request on to the backend unchanged. */
const FORWARDERS = new Set(['/api/:path*', '/mcp', '/mcp/:path*', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-protected-resource/mcp/:path*']);

/** Every page.tsx and route.ts under app/, as route entries without `since`. */
export function scanRoutes(appDir = APP) {
  const routes = [];
  const walk = (dir, segments, group) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        if (entry.name.startsWith('_') || entry.name.startsWith('@')) continue;
        const isGroup = /^\(.+\)$/.test(entry.name);
        walk(full, isGroup ? segments : [...segments, entry.name], isGroup && group === null ? entry.name.slice(1, -1) : group);
        continue;
      }
      const page = /^page\.(tsx|ts|jsx|js)$/.test(entry.name);
      const handler = /^route\.(tsx|ts|jsx|js)$/.test(entry.name);
      if (!page && !handler) continue;
      const routePath = toPath(segments);
      routes.push({
        path: routePath,
        kind: page ? 'page' : FORWARDERS.has(routePath) ? 'forwarder' : 'file',
        area: areaOf(segments, group, page),
      });
    }
  };
  walk(appDir, [], null);
  return routes.sort((a, b) => a.path.localeCompare(b.path));
}

/** `[id]` → `:id`, `[...path]` → `:path*`, `[[...path]]` → `:path*?`. */
export function toPath(segments) {
  if (segments.length === 0) return '/';
  return '/' + segments.map((s) => {
    let m = /^\[\[\.\.\.(.+)\]\]$/.exec(s);
    if (m) return `:${m[1]}*?`;
    m = /^\[\.\.\.(.+)\]$/.exec(s);
    if (m) return `:${m[1]}*`;
    m = /^\[(.+)\]$/.exec(s);
    if (m) return `:${m[1]}`;
    return s;
  }).join('/');
}

function areaOf(segments, group, isPage) {
  if (group === 'editor') return 'editor';
  if (group === 'protected') return 'protected';
  if (isPage && AUTH_SEGMENTS.has(segments[0])) return 'auth';
  return 'public';
}

function signedIn(area) {
  return area === 'protected' || area === 'editor';
}

/** Whether `href` is `routePath` or one of its ancestors, segment by segment. */
function covers(href, routePath) {
  const h = href.split('/').filter(Boolean);
  const r = routePath.split('/').filter(Boolean);
  if (h.length > r.length) return false;
  return h.every((seg, i) => seg === r[i]);
}

/** The ids whose href is the closest ancestor of the route (ties all count). */
export function idsFor(routePath, idMap) {
  let best = -1;
  let ids = [];
  for (const [id, href] of Object.entries(idMap)) {
    if (!covers(href, routePath)) continue;
    const depth = href.split('/').filter(Boolean).length;
    if (depth > best) {
      best = depth;
      ids = [id];
    } else if (depth === best) {
      ids.push(id);
    }
  }
  return ids.sort();
}

export function readCoreVersion() {
  if (process.env.WHITY_BUILD_CORE_VERSION) return process.env.WHITY_BUILD_CORE_VERSION;
  try {
    const src = readFileSync(path.join(REPO, 'src', 'Core', 'CoreVersion.php'), 'utf8');
    return /const VERSION = '([^']+)'/.exec(src)?.[1] ?? null;
  } catch {
    return null;
  }
}

/** The manifest the app tree describes, keeping each known route's `since`. */
export function buildManifest({ appDir = APP, ids, previous, coreVersion }) {
  const since = new Map((previous?.routes ?? []).map((r) => [r.path, r.since]));
  const routes = scanRoutes(appDir).map((r) => {
    const known = since.get(r.path);
    if (!known && !coreVersion) {
      throw new Error(`New route ${r.path} needs a version, and CoreVersion could not be read.`);
    }
    return {
      path: r.path,
      kind: r.kind,
      area: r.area,
      // Only signed-in screens sit under a menu. A public page that shares a
      // prefix (the public /chat/:workspace and the team's /chat) is not hidden
      // with the menu item.
      nav_ids: signedIn(r.area) ? idsFor(r.path, ids.nav) : [],
      settings_ids: signedIn(r.area) ? idsFor(r.path, ids.settings) : [],
      since: known ?? coreVersion,
    };
  });
  return { schema: SCHEMA_VERSION, routes };
}

/** Markdown for the release notes: what this manifest adds and drops against an older one. */
export function diffManifests(oldManifest, newManifest) {
  const before = new Set((oldManifest?.routes ?? []).map((r) => r.path));
  const after = new Set(newManifest.routes.map((r) => r.path));
  const added = [...after].filter((p) => !before.has(p)).sort();
  const removed = [...before].filter((p) => !after.has(p)).sort();
  if (added.length === 0 && removed.length === 0) return '';
  const lines = [];
  if (added.length) lines.push(`- **Web routes added:** ${added.map((p) => `\`${p}\``).join(', ')}.`);
  if (removed.length) lines.push(`- **Web routes removed:** ${removed.map((p) => `\`${p}\``).join(', ')}.`);
  lines.push('  The full list is at `/web-build/routes.json` (#2386).');
  return lines.join('\n') + '\n';
}

/**
 * The paths the web app answers on a shared origin, as a Caddy named matcher
 * (#2386, docs/wiki/Same-Origin-Setup.md): every top-level segment of a page
 * or file route, plus Next's own assets. The root page and the forwarders
 * (/api, /mcp, /.well-known) are left out: the proxy sends the forwarders
 * straight to the backend, and `/` belongs to the other app.
 */
export function caddyMatcher(manifest, extra = ['_next', 'favicon.ico', 'favicon.svg', 'motion']) {
  const segments = new Set(extra);
  for (const route of manifest.routes) {
    if (route.kind === 'forwarder') continue;
    const first = route.path.split('/')[1];
    if (first && !first.startsWith(':')) segments.add(first);
  }
  const paths = [...segments].sort().flatMap((s) => [`/${s}`, `/${s}/*`]);
  return `# Generated by: node web/scripts/route-manifest.mjs --caddy\n@whity path ${paths.join(' ')}\n`;
}

/** Sources that declare ids, relative to the repo root. Absent ones (an image build) are skipped. */
const ID_SOURCES = {
  nav: [
    'public/index.php',
    'src/Core/Calendar/CommissionFeatures.php',
    'src/Core/Calendar/FrontDeskFeatures.php',
    'src/Core/Convening/ConveningFeatures.php',
  ],
  settings: [
    'src/Api/SettingsApiHandler.php',
    'web/components/settings/settings-area.tsx',
    'web/lib/settings/my-settings.ts',
  ],
};

/**
 * Problems with the stable-id registry, or [] when the sources are absent.
 *
 * Two directions. An id the server's navigation or the settings tabs declare
 * that the registry lacks is an id nobody promised anything about. An id the
 * registry lists that no source mentions any more was removed without saying so.
 */
export function idProblems(ids, repo = REPO) {
  const problems = [];
  const read = (rel) => {
    try {
      return readFileSync(path.join(repo, rel), 'utf8');
    } catch (error) {
      if (error && error.code === 'ENOENT') return null;
      throw error;
    }
  };
  const index = read('public/index.php');
  const settingsApi = read('src/Api/SettingsApiHandler.php');
  if (index === null || settingsApi === null) return problems;

  // Core nav items: the first navigation.register listener, id then href.
  const start = index.indexOf("$hookManager->listen('navigation.register'");
  const end = index.indexOf('});', start);
  const block = index.slice(start, end);
  for (const m of block.matchAll(/'id' => '([^']+)'[\s\S]*?'href' => '([^']+)'/g)) {
    if (!(m[1] in ids.nav)) problems.push(`nav id "${m[1]}" (${m[2]}) is declared in public/index.php but missing from lib/routes/stable-ids.json.`);
    else if (ids.nav[m[1]] !== m[2]) problems.push(`nav id "${m[1]}" points at ${m[2]} in public/index.php but ${ids.nav[m[1]]} in stable-ids.json.`);
  }
  const tabsBlock = settingsApi.slice(settingsApi.indexOf('private const TABS'));
  for (const m of tabsBlock.slice(0, tabsBlock.indexOf('];')).matchAll(/\['id' => '([^']+)', 'href' => '([^']+)'/g)) {
    if (!(m[1] in ids.settings)) problems.push(`settings id "${m[1]}" is a tab in SettingsApiHandler but missing from stable-ids.json.`);
  }

  for (const kind of ['nav', 'settings']) {
    const text = ID_SOURCES[kind].map(read).filter((t) => t !== null).join('\n');
    for (const id of Object.keys(ids[kind])) {
      const quoted = [`'${id}'`, `"${id}"`, `${id}:`];
      // Constants: CommissionFeatures::COMMISSION = 'calendar-commission' is still a quoted mention.
      if (!quoted.some((q) => text.includes(q))) {
        problems.push(`${kind} id "${id}" is in stable-ids.json but no longer declared. Move it to removed.${kind} with the version that drops it.`);
      }
      if (id in (ids.removed?.[kind] ?? {})) problems.push(`${kind} id "${id}" is both current and removed.`);
    }
  }
  return problems;
}

export function serialize(manifest) {
  return JSON.stringify(manifest, null, 2) + '\n';
}

const CADDY_PATHS = path.join(REPO, 'ops', 'same-origin', 'whity-paths.caddy');

/** A text file's contents, or null when it is missing. */
function readTextOrNull(file) {
  try {
    return readFileSync(file, 'utf8');
  } catch (error) {
    if (error && error.code === 'ENOENT') return null;
    throw error;
  }
}

/** A JSON file's contents, or null when it is missing or empty. Read once, no exists-then-read. */
function readJsonOrNull(file) {
  let text;
  try {
    text = readFileSync(file, 'utf8');
  } catch (error) {
    if (error && error.code === 'ENOENT') return null;
    throw error;
  }
  return text.trim() === '' ? null : JSON.parse(text);
}

function main(argv) {
  const ids = JSON.parse(readFileSync(IDS, 'utf8'));
  const previous = readJsonOrNull(MANIFEST);

  if (argv.includes('--caddy')) {
    process.stdout.write(caddyMatcher(previous ?? { routes: [] }));
    return 0;
  }

  const diffAt = argv.indexOf('--diff');
  if (diffAt !== -1) {
    const oldFile = argv[diffAt + 1];
    const old = oldFile ? readJsonOrNull(oldFile) : null;
    process.stdout.write(diffManifests(old, previous ?? { routes: [] }));
    return 0;
  }

  const manifest = buildManifest({ ids, previous, coreVersion: readCoreVersion() });
  const problems = idProblems(ids);

  if (argv.includes('--check')) {
    // Compared as JSON, not bytes: a Windows checkout turns the file's line
    // endings into CRLF, which says nothing about whether it is current.
    const stale = previous === null || serialize(previous) !== serialize(manifest);
    if (stale) problems.unshift('web/routes.json is out of date. Run `npm run routes:generate --workspace web` and commit it.');
    // The shared-origin example's matcher (absent from an image build: skipped).
    const caddy = readTextOrNull(CADDY_PATHS);
    if (caddy !== null && caddy.replace(/\r\n/g, '\n') !== caddyMatcher(manifest)) {
      problems.push('ops/same-origin/whity-paths.caddy is out of date. Run `npm run routes:generate --workspace web` and commit it.');
    }
    for (const p of problems) process.stderr.write(`::error::${p}\n`);
    if (problems.length) return 1;
    process.stdout.write(`web/routes.json lists ${manifest.routes.length} routes and is up to date.\n`);
    return 0;
  }

  writeFileSync(MANIFEST, serialize(manifest));
  if (readTextOrNull(CADDY_PATHS) !== null) writeFileSync(CADDY_PATHS, caddyMatcher(manifest));
  for (const p of problems) process.stderr.write(`warning: ${p}\n`);
  process.stdout.write(`Wrote web/routes.json (${manifest.routes.length} routes).\n`);
  return 0;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  process.exit(main(process.argv.slice(2)));
}
