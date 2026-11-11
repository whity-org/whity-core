#!/usr/bin/env node
// Plan the Playwright shards by recorded seconds, and keep those timings (WHIT-935).
//
// `npx playwright test --shard=N/M` deals test groups out in order, which
// balances test COUNT, not time: on run 42780786869 the four shards' test steps
// took 181 to 276 s. This planner balances recorded per-file seconds instead,
// with the same greedy heaviest-first rule scripts/ci-test-shard.php uses for
// the PHPUnit shards.
//
//   node scripts/e2e-shard.mjs plan <shard> <total>
//
//     Prints this shard's spec files, one per line, as anchored path patterns
//     for `npx playwright test` (run from web/). Fails when there is no spec
//     file, when the shards do not cover every spec exactly once, or when this
//     shard is empty. A spec missing from the timings still runs, weighted at
//     the median. The `setup` project is a dependency, so Playwright runs it in
//     every shard whatever files are passed, exactly as with --shard.
//
//   node scripts/e2e-shard.mjs timings <out.json> <report.json|dir> [...] [--keep-from=<old.json>]
//
//     Rebuilds the timings from Playwright JSON reports (directories expand to
//     their *.json). --keep-from carries over files the reports did not run;
//     a spec in neither is recorded as 0 s, as it matches no Playwright project.
//     Every E2E run publishes a fresh `e2e-timings` artifact; commit it over
//     scripts/e2e-timings.json when the plan drifts. Stale timings cost balance,
//     never coverage.

import { readFileSync, readdirSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = join(fileURLToPath(new URL('.', import.meta.url)), '..');
const webRoot = join(repoRoot, 'web');
const timingsFile = join(repoRoot, 'scripts', 'e2e-timings.json');

/** Every *.spec.ts under web/e2e, as web-relative forward-slash paths, sorted. */
export function listSpecs(root = webRoot) {
  const out = [];
  const walk = (dir) => {
    for (const name of readdirSync(dir)) {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) {
        if (name !== 'node_modules') walk(path);
      } else if (name.endsWith('.spec.ts')) {
        out.push(relative(root, path).split(sep).join('/'));
      }
    }
  };
  walk(join(root, 'e2e'));
  return out.sort();
}

/** Greedy longest-first onto the lightest shard; ties break on path, then lowest shard. */
export function planShards(files, timings, shards) {
  if (!(shards >= 1)) throw new Error(`shard count must be >= 1, got ${shards}`);
  const known = files.filter((f) => f in timings).map((f) => Number(timings[f])).sort((a, b) => a - b);
  const fallback = known.length === 0 ? 1 : known[Math.floor(known.length / 2)];
  const weighted = files
    .map((f) => [f, f in timings ? Number(timings[f]) : fallback])
    .sort((a, b) => b[1] - a[1] || (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
  const plan = Array.from({ length: shards }, () => ({ files: [], seconds: 0 }));
  for (const [file, seconds] of weighted) {
    let lightest = 0;
    for (let i = 1; i < shards; i++) if (plan[i].seconds < plan[lightest].seconds) lightest = i;
    plan[lightest].files.push(file);
    plan[lightest].seconds += seconds;
  }
  return plan;
}

/** Seconds per spec file from Playwright JSON reports: every result, retries included. */
export function timingsFromReports(reports, specs) {
  const timings = {};
  const visit = (suite, file) => {
    const here = suite.file ?? file;
    for (const spec of suite.specs ?? []) {
      const specFile = spec.file ?? here;
      for (const test of spec.tests ?? []) {
        for (const result of test.results ?? []) {
          const match = specs.find((s) => s === `e2e/${specFile}` || s === specFile || specFile.endsWith(`/${s}`));
          if (match) timings[match] = (timings[match] ?? 0) + (result.duration ?? 0) / 1000;
        }
      }
    }
    for (const child of suite.suites ?? []) visit(child, here);
  };
  for (const report of reports) for (const suite of report.suites ?? []) visit(suite, suite.file);
  return Object.fromEntries(Object.keys(timings).sort().map((k) => [k, Math.round(timings[k] * 10) / 10]));
}

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function main(argv) {
  const [command, ...args] = argv;

  if (command === 'plan' && args.length === 2) {
    const shard = Number(args[0]);
    const total = Number(args[1]);
    if (!Number.isInteger(total) || !Number.isInteger(shard) || total < 1 || shard < 1 || shard > total) {
      console.error(`::error::invalid shard ${args[0]}/${args[1]}`);
      return 2;
    }
    const specs = listSpecs();
    if (specs.length === 0) {
      console.error('::error::no spec files under web/e2e — refusing to run an empty shard');
      return 1;
    }
    const timings = existsSync(timingsFile) ? JSON.parse(readFileSync(timingsFile, 'utf8')) : {};
    const plan = planShards(specs, timings, total);
    const assigned = plan.flatMap((s) => s.files).sort();
    if (assigned.length !== specs.length || assigned.some((f, i) => f !== specs[i])) {
      console.error('::error::E2E shard plan does not cover every spec file exactly once');
      return 1;
    }
    const mine = plan[shard - 1];
    if (mine.files.length === 0) {
      console.error(`::error::shard ${shard}/${total} selected 0 of ${specs.length} spec files`);
      return 1;
    }
    const unknown = specs.filter((f) => !(f in timings)).length;
    console.error(
      `Shard ${shard}/${total}: ${mine.files.length} of ${specs.length} spec files, ${mine.seconds.toFixed(0)} s recorded ` +
        `(shards: ${plan.map((s) => s.seconds.toFixed(0)).join(' / ')})` +
        (unknown > 0 ? `, ${unknown} file(s) without a timing weighted at the median` : ''),
    );
    // Playwright reads each argument as a case-insensitive regex over the
    // absolute path; anchoring on `/e2e/<name>$` keeps `roles.spec.ts` from
    // also selecting a future `user-roles.spec.ts`. `[\\/]` so the same
    // pattern matches a Windows path when run locally.
    for (const file of mine.files) {
      console.log('[\\\\/]' + file.split('/').map(escapeRegex).join('[\\\\/]') + '$');
    }
    return 0;
  }

  if (command === 'timings' && args.length >= 2) {
    const [out, ...inputs] = args;
    let keep = {};
    const paths = [];
    for (const input of inputs) {
      if (input.startsWith('--keep-from=')) {
        const from = input.slice('--keep-from='.length);
        keep = existsSync(from) ? JSON.parse(readFileSync(from, 'utf8')) : {};
      } else if (existsSync(input) && statSync(input).isDirectory()) {
        for (const name of readdirSync(input)) if (name.endsWith('.json')) paths.push(join(input, name));
      } else {
        paths.push(input);
      }
    }
    if (paths.length === 0) {
      console.error('e2e-shard: no Playwright JSON reports found');
      return 2;
    }
    const specs = listSpecs();
    const fresh = timingsFromReports(paths.map((p) => JSON.parse(readFileSync(p, 'utf8'))), specs);
    const merged = { ...Object.fromEntries(Object.entries(keep).filter(([f]) => specs.includes(f))), ...fresh };
    // A spec no report ran and no old timing knows matches no Playwright
    // project, so it never runs: record 0 rather than let the planner weigh it
    // at the median. A shard whose report went missing keeps its old timings.
    for (const spec of specs) if (!(spec in merged)) merged[spec] = 0;
    const sorted = Object.fromEntries(Object.keys(merged).sort().map((k) => [k, merged[k]]));
    writeFileSync(out, JSON.stringify(sorted, null, 2) + '\n');
    const sum = Object.values(sorted).reduce((a, b) => a + b, 0);
    console.log(`e2e-shard: ${Object.keys(sorted).length} files, ${sum.toFixed(0)} s total -> ${out}`);
    return 0;
  }

  console.error(
    'Usage:\n  node scripts/e2e-shard.mjs plan <shard> <total>\n' +
      '  node scripts/e2e-shard.mjs timings <out.json> <report.json|dir> [...] [--keep-from=<old.json>]',
  );
  return 2;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exit(main(process.argv.slice(2)));
}
