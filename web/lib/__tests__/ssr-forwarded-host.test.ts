/**
 * WHIT-1295 (#2195): the server-side fetches for branding, theme and UI
 * preferences tell the backend the host the browser asked for, never an
 * X-Forwarded-Host the browser made up.
 */
jest.mock('server-only', () => ({}));

let incoming: Record<string, string> = {};
jest.mock('next/headers', () => ({
  headers: async () => ({ get: (name: string) => incoming[name.toLowerCase()] ?? null }),
  cookies: async () => ({ getAll: () => [] }),
}));

import { fetchBranding } from '@/lib/branding';
import { getThemeOverrides } from '@/lib/theme';
import { getUiPreferences } from '@/lib/ui-preferences';

const sent: Array<Record<string, string>> = [];

beforeEach(() => {
  sent.length = 0;
  delete process.env.TRUSTED_PROXY_HOPS;
  global.fetch = jest.fn(async (_url: unknown, init?: { headers?: Record<string, string> }) => {
    sent.push(init?.headers ?? {});
    return { ok: true, json: async () => ({ data: {} }) } as unknown as Response;
  }) as unknown as typeof fetch;
});

const calls: Array<[string, () => Promise<unknown>]> = [
  ['branding', () => fetchBranding()],
  ['theme', () => getThemeOverrides()],
  ['ui preferences', () => getUiPreferences()],
];

describe.each(calls)('%s', (_name, call) => {
  it("sends Host, not the browser's X-Forwarded-Host", async () => {
    incoming = { host: 'bakery.example.com', 'x-forwarded-host': 'other.example.com' };
    await call();
    expect(sent[0]?.['X-Forwarded-Host']).toBe('bakery.example.com');
  });

  it('sends no host at all when Host is not a plain name', async () => {
    incoming = { host: 'a b', 'x-forwarded-host': 'other.example.com' };
    await call();
    expect(sent[0]).not.toHaveProperty('X-Forwarded-Host');
  });
});
