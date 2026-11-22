/**
 * The blocking theme script is inlined in <head> before any bundle runs, so
 * whatever it embeds must be safe inside a <script> element (WHIT-1236).
 */

import { buildThemeInitScript, scriptSafeStringLiteral, THEME_STORAGE_KEY } from '@whity/ui/theme-mode';

const LS = String.fromCharCode(0x2028);
const PS = String.fromCharCode(0x2029);

describe('theme init script', () => {
  it('embeds the storage key as a plain string literal', () => {
    expect(buildThemeInitScript()).toContain(`var k=${JSON.stringify(THEME_STORAGE_KEY)};`);
  });

  it('escapes what could close the script element or end the line', () => {
    const literal = scriptSafeStringLiteral('</script><x>' + LS + PS + '"\\');
    for (const unsafe of ['<', '>', '/', LS, PS]) expect(literal).not.toContain(unsafe);
    // Still the same string once parsed as JavaScript.
    expect(JSON.parse(literal)).toBe('</script><x>' + LS + PS + '"\\');
  });
});
