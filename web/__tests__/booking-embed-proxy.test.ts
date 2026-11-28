/**
 * @jest-environment node
 *
 * WHIT-1445 (design section 39 §9.1, AP.24): `/book/{workspace}/embed` is the
 * one page another site may frame, and only the sites the workspace listed
 * under Your website. The list comes from the backend; anything that isn't a
 * list of https origins, or a backend that can't answer, frames nowhere.
 */
import { NextRequest } from 'next/server';
import { proxy } from '@/proxy';
import { cleanAncestors, embedWorkspace, resetEmbedPolicyCache } from '@/lib/booking/embed-policy';
import { resetHiddenIdsCache } from '@/lib/routes/hidden-cache';
import nextConfig from '@/next.config';

const realFetch = global.fetch;

function backend(frameAncestors: unknown, ok = true) {
  global.fetch = jest.fn(async (url: string | URL) => {
    if (String(url).includes('/api/v1/public/booking/')) {
      return { ok, status: ok ? 200 : 500, json: async () => ({ data: { frame_ancestors: frameAncestors } }) };
    }
    return { ok: true, status: 200, json: async () => ({ data: { nav: [], settings: [] } }) };
  }) as unknown as typeof fetch;
}

describe('the booking widget page', () => {
  beforeEach(() => {
    resetHiddenIdsCache();
    resetEmbedPolicyCache();
  });
  afterAll(() => {
    global.fetch = realFetch;
  });

  it('knows its address and nothing else', () => {
    expect(embedWorkspace('/book/example-bakery/embed')).toBe('example-bakery');
    expect(embedWorkspace('/book/example-bakery')).toBeNull();
    expect(embedWorkspace('/book/Example/embed')).toBeNull();
    expect(embedWorkspace('/book/example-bakery/embed/x')).toBeNull();
  });

  it('keeps only https origins', () => {
    expect(cleanAncestors("'none'")).toBe("'none'");
    expect(cleanAncestors('https://example-bakery.com https://shop.example-bakery.com:8443')).toBe('https://example-bakery.com https://shop.example-bakery.com:8443');
    for (const bad of ['*', 'https://*.example.com', 'http://example.com', "https://example.com 'unsafe-inline'", 'https://example.com/x', '', null, 42]) {
      expect(cleanAncestors(bad)).toBe("'none'");
    }
  });

  it('is framed only by the listed sites', async () => {
    backend('https://example-bakery.com');
    const response = await proxy(new NextRequest(new URL('/book/example-bakery/embed', 'http://whity.test')));
    expect(response.headers.get('Content-Security-Policy')).toBe('frame-ancestors https://example-bakery.com');
  });

  it('is framed by nobody when the backend fails or sends something odd', async () => {
    backend('https://example-bakery.com', false);
    expect((await proxy(new NextRequest(new URL('/book/a-bakery/embed', 'http://whity.test')))).headers.get('Content-Security-Policy')).toBe("frame-ancestors 'none'");
    backend('*');
    expect((await proxy(new NextRequest(new URL('/book/b-bakery/embed', 'http://whity.test')))).headers.get('Content-Security-Policy')).toBe("frame-ancestors 'none'");
  });

  it('leaves every other page to the static headers', async () => {
    backend('https://example-bakery.com');
    const response = await proxy(new NextRequest(new URL('/book/example-bakery', 'http://whity.test')));
    expect(response.headers.get('Content-Security-Policy')).toBeNull();
    const rules = await nextConfig.headers!();
    const main = rules.find((r) => r.headers.some((h) => h.key === 'X-Frame-Options'));
    const embed = rules.find((r) => r.source === '/book/:workspace/embed');
    expect(main?.source).toContain('book/[^/]+/embed');
    expect(embed?.headers.some((h) => h.key === 'X-Frame-Options' || h.key === 'Content-Security-Policy')).toBe(false);
  });
});
