import { parseVerifyInput } from '@/lib/verify-scan';

const ORIGIN = 'https://app.whity.dev';
const TOKEN = 'a3f1'.repeat(16);

describe('parseVerifyInput (#1734)', () => {
  it('accepts this instance’s own verify link', () => {
    expect(parseVerifyInput(`${ORIGIN}/verify/${TOKEN}`, ORIGIN)).toEqual({
      ok: true,
      token: TOKEN,
      path: `/verify/${TOKEN}`,
    });
  });

  it('accepts the same host on http and a trailing slash', () => {
    expect(parseVerifyInput(`http://app.whity.dev/verify/${TOKEN}/`, ORIGIN)).toMatchObject({ ok: true, token: TOKEN });
  });

  it('accepts a hand-typed link without a scheme, and surrounding spaces', () => {
    expect(parseVerifyInput(`  app.whity.dev/verify/${TOKEN}  `, ORIGIN)).toMatchObject({ ok: true, token: TOKEN });
  });

  it('accepts a bare 64-character code, lower-casing it', () => {
    expect(parseVerifyInput(TOKEN.toUpperCase(), ORIGIN)).toMatchObject({ ok: true, token: TOKEN });
  });

  it('rebuilds the path from the token, dropping any query or fragment', () => {
    const result = parseVerifyInput(`${ORIGIN}/verify/${TOKEN}?next=https://evil.example#x`, ORIGIN);
    expect(result).toEqual({ ok: true, token: TOKEN, path: `/verify/${TOKEN}` });
  });

  it.each([
    `https://evil.example/verify/${TOKEN}`,
    `https://app.whity.dev.evil.example/verify/${TOKEN}`,
    `https://user@app.whity.dev/verify/${TOKEN}`,
    `${ORIGIN}/login`,
    `${ORIGIN}/verify/${TOKEN}/extra`,
    `${ORIGIN}//evil.example/verify/${TOKEN}`,
  ])('refuses %s as foreign', (value) => {
    expect(parseVerifyInput(value, ORIGIN)).toEqual({ ok: false, reason: 'foreign' });
  });

  it.each(['javascript:alert(1)', 'data:text/html,hi', `${ORIGIN}/verify/%2F%2Fevil`, `${ORIGIN}/verify/short`, 'not a link'])(
    'refuses %s as invalid',
    (value) => {
      expect(parseVerifyInput(value, ORIGIN)).toEqual({ ok: false, reason: 'invalid' });
    }
  );

  it('reports an empty value', () => {
    expect(parseVerifyInput('   ', ORIGIN)).toEqual({ ok: false, reason: 'empty' });
  });
});
