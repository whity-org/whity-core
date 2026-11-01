import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relative) => readFileSync(new URL(relative, import.meta.url), 'utf8').replace(/\r\n/g, '\n');

const compose = read('../../../docker/production/docker-compose.yml');

test('the bundled compose file is the one the repository ships', () => {
  assert.equal(read('../templates/docker-compose.yml'), compose);
});

test('all three images run at the installed version, with no moving tag', () => {
  for (const image of ['whity-core:', 'whity-core/web:', 'whity-core/render:']) {
    assert.ok(compose.includes(`ghcr.io/whity-org/${image}\${WHITY_VERSION`), image);
  }
  assert.doesNotMatch(compose, /:latest/);
});

test('uploads survive the container being recreated', () => {
  assert.match(compose, /STORAGE_ROOT: \/app\/storage/);
  assert.match(compose, /- storage:\/app\/storage/);
  assert.match(compose, /^volumes:\n {2}postgres_data:\n {2}storage:/m);
});

test('the database is not published to the host', () => {
  const postgres = /^ {2}postgres:\n([\s\S]*?)^ {2}frankenphp:/m.exec(compose)[1];
  assert.doesNotMatch(postgres, /ports:/);
});
