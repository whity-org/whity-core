import { readFileSync } from 'node:fs';

/**
 * A file's text, or null when it isn't there. Reading is the check: asking first
 * and reading later leaves a gap in which the file can change.
 */
export function readIfPresent(path) {
  try {
    return readFileSync(path, 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT' || error.code === 'ENOTDIR') return null;
    throw error;
  }
}
