import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const SRC = fileURLToPath(new URL('../src', import.meta.url));

/** `toolVersion()` prints a version number, which has no words to translate. */
const ALLOWED = new Set(["''", 'toolVersion(']);

/** The argument of every CliError and deps.log call that doesn't come from the catalogue. */
function textOutsideCatalogue(source) {
  const found = [];
  let calls = 0;
  for (const match of source.matchAll(/(new CliError|deps\.log)\(\s*([^\s)]*)/g)) {
    calls += 1;
    const argument = match[2];
    if (!argument.startsWith('m.') && !ALLOWED.has(argument)) found.push(`${match[1]}(${argument}`);
  }
  return { calls, found };
}

const files = readdirSync(SRC, { recursive: true })
  .map(String)
  .filter((file) => file.endsWith('.js') && file !== 'messages.js');

test('every message comes from messages.js', () => {
  let total = 0;
  for (const file of files) {
    const { calls, found } = textOutsideCatalogue(readFileSync(join(SRC, file), 'utf8'));
    total += calls;
    assert.deepEqual(found, [], file);
  }
  assert.ok(total > 40, `The scan found only ${total} calls, so it no longer matches the code.`);
});

test('the scan catches text written in place', () => {
  assert.equal(textOutsideCatalogue("deps.log('Hello');").found.length, 1);
  assert.equal(textOutsideCatalogue('new CliError(`No ${x}`, 2)').found.length, 1);
  assert.equal(textOutsideCatalogue("new CliError(\n  'wrapped'\n)").found.length, 1);
  assert.deepEqual(textOutsideCatalogue("deps.log(m.help()); deps.log('');").found, []);
});

test('messages stay plain ASCII, which PowerShell 5.1 prints correctly', () => {
  const lines = readFileSync(join(SRC, 'messages.js'), 'utf8');
  assert.match(lines, /^[\x00-\x7f]*$/);
});
