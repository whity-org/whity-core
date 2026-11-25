/**
 * @jest-environment node
 *
 * WHIT-1416 (design section 41, CA.1): `/@handle` and everything under it is
 * served by app/w/[handle], and only `/@handle` paths are ever taken as a
 * place to come back to after signing in.
 */
import { NextRequest } from 'next/server';
import { customerAreaPath, proxy } from '@/proxy';
import { resetHiddenIdsCache } from '@/lib/routes/hidden-cache';
import { isCustomerAreaPath } from '@/lib/customer-area-return';

const realFetch = global.fetch;

describe('customer area addresses', () => {
  beforeEach(() => {
    resetHiddenIdsCache();
    global.fetch = jest.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: { nav: [], settings: [] } }) })) as unknown as typeof fetch;
  });
  afterAll(() => {
    global.fetch = realFetch;
  });

  it('maps the shared address to the area pages', () => {
    expect(customerAreaPath('/@example-bakery')).toBe('/w/example-bakery');
    expect(customerAreaPath('/@example-bakery/')).toBe('/w/example-bakery');
    expect(customerAreaPath('/@example-bakery/bills/quotes/7')).toBe('/w/example-bakery/bills/quotes/7');
  });

  it('leaves every other path alone', () => {
    expect(customerAreaPath('/contacts')).toBeNull();
    expect(customerAreaPath('/@Example')).toBeNull();
    expect(customerAreaPath('/@-bad')).toBeNull();
    expect(customerAreaPath('/w/example-bakery')).toBeNull();
  });

  it('rewrites the request and keeps the security policy header', async () => {
    const response = await proxy(new NextRequest(new URL('/@example-bakery/home', 'http://whity.test')));

    expect(response.headers.get('x-middleware-rewrite')).toBe('http://whity.test/w/example-bakery/home');
    expect(response.headers.get('Content-Security-Policy-Report-Only')).toContain('nonce-');
  });

  it('takes only area paths as a return after sign-in', () => {
    expect(isCustomerAreaPath('/@example-bakery/bills')).toBe(true);
    expect(isCustomerAreaPath('//evil.test/@x')).toBe(false);
    expect(isCustomerAreaPath('https://evil.test/@x')).toBe(false);
    expect(isCustomerAreaPath('/@example-bakery/../admin')).toBe(false);
    expect(isCustomerAreaPath('/dashboard')).toBe(false);
  });
});
