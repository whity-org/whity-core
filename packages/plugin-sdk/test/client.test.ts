import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPluginClient, fetchAllPages, versionedPath } from '../src/client.ts';
import { createMockRequester, type RecordedRequest } from '../src/testing.ts';

test('paths are versioned the way the host serves them', () => {
  assert.equal(versionedPath('/api/cake-orders/today'), '/api/v1/cake-orders/today');
  assert.equal(versionedPath('/api/v1/cake-orders/today'), '/api/v1/cake-orders/today');
  assert.equal(versionedPath('/api/v2/x', 'v1'), '/api/v2/x');
  assert.equal(versionedPath('/users'), '/v1/users');
  assert.equal(versionedPath('/api/x', ''), '/api/x');
});

test('fetchAllPages walks every page and says the set is complete', async () => {
  const request = createMockRequester({
    '/api/v1/orders?page=1&per_page=2': { data: [1, 2], pagination: { page: 1, perPage: 2, total: 3, totalPages: 2 } },
    '/api/v1/orders?page=2&per_page=2': { data: [3], pagination: { page: 2, perPage: 2, total: 3, totalPages: 2 } },
  });

  const result = await fetchAllPages<number>(request, '/api/v1/orders', 2);

  assert.deepEqual(result, { complete: true, items: [1, 2, 3], total: 3 });
  assert.equal(request.calls.length, 2);
});

test('a failed page makes the set incomplete, never silently short', async () => {
  const request = createMockRequester({
    '/api/v1/orders?page=1&per_page=2': { data: [1, 2], pagination: { page: 1, perPage: 2, total: 4, totalPages: 2 } },
    '/api/v1/orders?page=2&per_page=2': { status: 500, body: { error: { code: 'boom' } } },
  });

  assert.deepEqual(await fetchAllPages<number>(request, '/api/v1/orders', 2), { complete: false, items: [1, 2], total: 4 });
});

test('a list without a pagination envelope is whole as it is', async () => {
  const request = createMockRequester({ '/api/v1/flavours': { data: ['vanilla', 'lemon'] } });

  assert.deepEqual(await fetchAllPages<string>(request, '/api/v1/flavours'), { complete: true, items: ['vanilla', 'lemon'], total: 2 });
});

test('a row count that grows mid-walk is reported incomplete', async () => {
  const request = createMockRequester({
    '/api/v1/orders?page=1&per_page=2': { data: [1, 2], pagination: { page: 1, perPage: 2, total: 2, totalPages: 1 } },
  });
  const growing = createMockRequester({
    '/api/v1/orders?page=1&per_page=2': { data: [1, 2], pagination: { page: 1, perPage: 2, total: 3, totalPages: 1 } },
  });

  assert.equal((await fetchAllPages(request, '/api/v1/orders', 2)).complete, true);
  assert.equal((await fetchAllPages(growing, '/api/v1/orders', 2)).complete, false);
});

test('the route client unwraps data, sends JSON and never throws on an error', async () => {
  const request = createMockRequester({
    'GET /api/v1/cake-orders/today': { data: [{ id: 7, cake: 'Lemon' }] },
    'POST /api/v1/cake-orders': (r: RecordedRequest) => ({ status: 201, body: { data: { id: 8, ...(r.body as object) } } }),
    'DELETE /api/v1/cake-orders/9': { status: 404, body: { error: { code: 'not_found', message: 'No such order' } } },
  });
  const routes = createPluginClient({ request });

  const today = await routes.get<{ id: number; cake: string }[]>('/api/cake-orders/today');
  assert.deepEqual(today.ok && today.data, [{ id: 7, cake: 'Lemon' }]);

  const created = await routes.post<{ id: number; cake: string }>('/api/cake-orders', { cake: 'Chocolate' });
  assert.equal(created.status, 201);
  assert.deepEqual(created.ok && created.data, { id: 8, cake: 'Chocolate' });

  const missing = await routes.delete('/api/cake-orders/9');
  assert.equal(missing.ok, false);
  assert.equal(!missing.ok && missing.error?.code, 'not_found');

  assert.deepEqual(request.calls.map((c) => `${c.method} ${c.url}`), [
    'GET /api/v1/cake-orders/today',
    'POST /api/v1/cake-orders',
    'DELETE /api/v1/cake-orders/9',
  ]);
});

test('the route client adds a query and walks a list route', async () => {
  const request = createMockRequester({
    '/api/v1/orders?status=open': { data: ['a'] },
    '/api/v1/orders?page=1&per_page=100': { data: ['a', 'b'], pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 } },
  });
  const routes = createPluginClient({ request });

  assert.deepEqual((await routes.get<string[]>('/api/orders', { status: 'open' })).ok, true);
  assert.deepEqual(await routes.all<string>('/api/orders'), { complete: true, items: ['a', 'b'], total: 2 });
});
