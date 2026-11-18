#!/usr/bin/env node
// npm audit with a reviewed allowlist (owner decision 2026-11-17).
//
// Runs `npm audit --json` in the current folder and fails when an advisory at
// or above the level (default: high) is present, unless the advisory is in
// .github/npm-audit-allowlist.json. `npm audit` itself has no way to skip one
// advisory, which is why this exists: an advisory with no patched version in
// existence otherwise forces the whole gate down to `critical`, letting every
// other high advisory through for as long as it lasts.
//
// An allowlist entry is only for an advisory that has NO fix. Every run checks
// that this is still true: once the latest published version of the package
// is outside the advisory's vulnerable range, a fix exists, and the gate fails
// and says to update the package and delete the entry. So an entry cannot
// outlive its reason silently.
//
// Usage: node scripts/npm-audit-gate.mjs [--level high|critical|moderate|low]
//        (run it in the folder whose lockfile you are auditing)

import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RANK = { info: 0, low: 1, moderate: 2, high: 3, critical: 4 };
const args = process.argv.slice(2);
const levelArg = args.indexOf('--level');
const level = levelArg >= 0 ? args[levelArg + 1] : 'high';
if (!(level in RANK)) {
  console.error(`Unknown level "${level}". Use one of: ${Object.keys(RANK).join(', ')}.`);
  process.exit(2);
}

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const allowlist = JSON.parse(readFileSync(join(repoRoot, '.github', 'npm-audit-allowlist.json'), 'utf8')).advisories;
const allowed = new Map(allowlist.map((a) => [a.id, a]));

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const audit = spawnSync(npm, ['audit', '--json'], { encoding: 'utf8', shell: process.platform === 'win32', maxBuffer: 64 * 1024 * 1024 });
let report;
try {
  report = JSON.parse(audit.stdout);
} catch {
  // Not JSON means npm audit itself failed (no lockfile, registry down).
  // That must fail the gate, never pass it.
  console.error('npm audit did not return a report:\n' + (audit.stdout || '') + (audit.stderr || ''));
  process.exit(2);
}
if (report.error) {
  console.error('npm audit failed: ' + JSON.stringify(report.error));
  process.exit(2);
}

// The advisories themselves are the objects in `via`; string entries only
// point at another package that carries one.
const advisories = new Map();
for (const vuln of Object.values(report.vulnerabilities ?? {})) {
  for (const via of vuln.via ?? []) {
    if (typeof via !== 'object' || !via.url) continue;
    const id = via.url.split('/').pop();
    advisories.set(id, { id, name: via.name, severity: via.severity, range: via.range, title: via.title, url: via.url });
  }
}

const blocking = [];
const skipped = [];
for (const adv of advisories.values()) {
  if (RANK[adv.severity] < RANK[level]) continue;
  if (allowed.has(adv.id)) skipped.push(adv);
  else blocking.push(adv);
}

// An allowlisted advisory must still have no fix.
const fixed = [];
for (const adv of skipped) {
  const latest = execFileSync(npm, ['view', adv.name, 'version'], { encoding: 'utf8', shell: process.platform === 'win32' }).trim();
  // On Windows npm is a .cmd and needs a shell, where `<` and `>` in a range
  // are redirects; quote the spec there. CI runs on Linux with no shell.
  const spec = `${adv.name}@${adv.range}`;
  const inRange = execFileSync(npm, ['view', process.platform === 'win32' ? `"${spec}"` : spec, 'version', '--json'], { encoding: 'utf8', shell: process.platform === 'win32' }).trim();
  const vulnerable = inRange === '' ? [] : [].concat(JSON.parse(inRange));
  if (!vulnerable.includes(latest)) fixed.push({ ...adv, latest });
}

for (const adv of skipped) {
  const entry = allowed.get(adv.id);
  console.log(`Allowed until a fix exists: ${adv.id} (${adv.severity}) ${adv.name} ${adv.range}: ${adv.title}. ${entry.reason}`);
}
for (const adv of fixed) {
  console.error(`A fix is out for ${adv.id}: ${adv.name} ${adv.latest} is outside ${adv.range}. Update ${adv.name} and remove the entry from .github/npm-audit-allowlist.json.`);
}
for (const adv of blocking) {
  console.error(`Blocking: ${adv.id} (${adv.severity}) ${adv.name} ${adv.range}: ${adv.title} ${adv.url}`);
}

if (blocking.length || fixed.length) process.exit(1);
console.log(`npm audit: nothing at ${level} or above apart from ${skipped.length} allowed advisory(ies).`);
