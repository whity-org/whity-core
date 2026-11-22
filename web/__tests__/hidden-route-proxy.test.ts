/**
 * @jest-environment node
 *
 * #2386: the route guard answers a hidden page with 404 and lets the rest through.
 */
import { NextRequest } from 'next/server';
import { proxy } from '@/proxy';
import { resetHiddenIdsCache } from '@/lib/routes/hidden-cache';

const realFetch = global.fetch;

function backendAnswers(body: unknown, ok = true) {
  global.fetch = jest.fn(async () => ({ ok, status: ok ? 200 : 500, json: async () => body })) as unknown as typeof fetch;
}

const request = (path: string) => new NextRequest(new URL(path, 'http://whity.test'));

describe('proxy (hidden pages)', () => {
  beforeEach(() => resetHiddenIdsCache());
  afterAll(() => {
    global.fetch = realFetch;
  });

  it('answers a hidden page with 404', async () => {
    backendAnswers({ data: { nav: ['calendar'], settings: ['sso'] } });

    expect((await proxy(request('/calendar/rooms'))).status).toBe(404);
    expect((await proxy(request('/admin/settings/sso'))).status).toBe(404);
  });

  it('lets every other page through', async () => {
    backendAnswers({ data: { nav: ['calendar'], settings: [] } });

    const response = await proxy(request('/contacts'));
    expect(response.status).toBe(200);
    expect(response.headers.get('x-middleware-next')).toBe('1');
  });

  it('asks the backend once per interval, not once per page', async () => {
    backendAnswers({ data: { nav: [], settings: [] } });

    await proxy(request('/contacts'));
    await proxy(request('/tasks'));
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect((global.fetch as jest.Mock).mock.calls[0][0]).toMatch(/\/api\/v1\/navigation\/hidden$/);
  });

  it('fails open when the backend cannot answer', async () => {
    global.fetch = jest.fn(async () => {
      throw new Error('down');
    }) as unknown as typeof fetch;

    expect((await proxy(request('/calendar'))).status).toBe(200);
  });
});
