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
  .filter((file) => file.endsWith('.js') && file !== 'messages.js' && file !== 'messages-ar.js');

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

test('every Arabic message has an English one with the same name', async () => {
  const { ar } = await import('../src/messages-ar.js');
  const { MARK } = await import('../src/messages.js');
  const english = await import('../src/messages.js');
  const missing = Object.keys(ar(MARK)).filter((key) => !(key in english.m) && !(key in englishKeys(english)));
  assert.deepEqual(missing, []);
});

/** The English catalogue's names, whichever language this machine picked. */
function englishKeys() {
  const source = readFileSync(join(SRC, 'messages.js'), 'utf8');
  return Object.fromEntries([...source.matchAll(/^ {2}([A-Za-z0-9]+): /gm)].map((match) => [match[1], true]));
}

test('Arabic is chosen only where the console can show it', async () => {
  const { wantsArabic, unicodeConsole } = await import('../src/messages.js');
  assert.equal(wantsArabic({ LANG: 'ar_JO.UTF-8' }, 'linux'), true);
  assert.equal(wantsArabic({ LANG: 'en_GB.UTF-8' }, 'linux'), false);
  assert.equal(wantsArabic({ LANG: 'ar_JO.UTF-8' }, 'win32'), false, 'PowerShell 5.1 garbles Arabic');
  assert.equal(wantsArabic({ LANG: 'ar', WT_SESSION: 'x' }, 'win32'), true, 'Windows Terminal shows it');
  assert.equal(unicodeConsole({}, 'darwin'), true);
  assert.equal(unicodeConsole({}, 'win32'), false);
});
