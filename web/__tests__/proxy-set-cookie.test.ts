/**
 * @jest-environment node
 *
 * Regression guard (#2234): each upstream Set-Cookie must reach the browser
 * exactly once, never duplicated and never comma-joined.
 */
jest.mock('@/lib/backend-url', () => ({ backendUrl: () => 'http://backend.test' }));
jest.mock('next/headers', () => ({
  cookies: jest.fn(async () => ({ getAll: () => [] as { name: string; value: string }[] })),
}));

import { POST } from '@/app/api/[...path]/route';

describe('proxy Set-Cookie forwarding', () => {
  const realFetch = global.fetch;
  afterEach(() => {
    global.fetch = realFetch;
  });

  it('forwards two upstream cookies exactly once each', async () => {
    global.fetch = jest.fn(async (): Promise<Response> =>
      new Response('{}', {
        status: 200,
        headers: [
          ['Content-Type', 'application/json'],
          ['Set-Cookie', 'a=1; HttpOnly'],
          ['Set-Cookie', 'b=2; Secure'],
        ],
      })
    ) as unknown as typeof fetch;

    const result = await POST(
      new Request('http://localhost:3000/api/auth/login', { method: 'POST', body: '{}' })
    );

    expect(result.headers.getSetCookie()).toEqual(['a=1; HttpOnly', 'b=2; Secure']);
  });
});
