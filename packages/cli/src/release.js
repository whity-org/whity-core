import { readFileSync } from 'node:fs';
import { CliError } from './errors.js';
import { m } from './messages.js';

const REPO = 'https://github.com/whity-org/whity-core';
const TIMEOUT_MS = 30_000;

export const IMAGES = [
  'ghcr.io/whity-org/whity-core',
  'ghcr.io/whity-org/whity-core/web',
  'ghcr.io/whity-org/whity-core/render',
];

/** The oldest release with what the production compose file runs: events:relay in bin/whity-cli. */
export const OLDEST_SUPPORTED = 'v0.2.21';

export function normalizeVersion(input) {
  const match = /^v?(\d+\.\d+\.\d+)$/.exec(input ?? '');
  if (!match) throw new CliError(m.badVersion(input), 2);
  return `v${match[1]}`;
}

/** Negative when a is older than b. */
export function compareVersions(a, b) {
  const parts = (version) => version.replace(/^v/, '').split('.').map(Number);
  const [left, right] = [parts(a), parts(b)];
  for (let i = 0; i < 3; i += 1) {
    if (left[i] !== right[i]) return left[i] - right[i];
  }
  return 0;
}

export function assertSupported(version) {
  if (compareVersions(version, OLDEST_SUPPORTED) < 0) {
    throw new CliError(m.unsupportedVersion(OLDEST_SUPPORTED, version), 2);
  }
}

/** Reads the redirect of /releases/latest: unlike the API, it has no 60-an-hour limit per address. */
export async function latestVersion(deps) {
  const unknown = () => new CliError(m.latestUnknown(`${REPO}/releases`));
  let response;
  try {
    response = await deps.fetch(`${REPO}/releases/latest`, {
      redirect: 'manual',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  } catch {
    throw unknown();
  }
  const tag = /\/releases\/tag\/(v\d+\.\d+\.\d+)$/.exec(response.headers.get('location') ?? '');
  if (!tag) throw unknown();
  return tag[1];
}

/**
 * The compose file a release shipped. Releases before the asset existed use the
 * copy in this package. Only a 404 falls back: any other failure is reported, so
 * an outage can't quietly install a file from a different version.
 */
export async function composeFor(version, deps) {
  const url = `${REPO}/releases/download/${version}/whity-compose.yml`;
  let response;
  try {
    response = await deps.fetch(url, { signal: AbortSignal.timeout(TIMEOUT_MS) });
  } catch (error) {
    throw new CliError(m.downloadFailed(url, error.message));
  }
  if (response.status === 404) {
    return readFileSync(new URL('../templates/docker-compose.yml', import.meta.url), 'utf8');
  }
  if (!response.ok) {
    throw new CliError(m.downloadFailed(url, `HTTP ${response.status}`));
  }
  return response.text();
}
