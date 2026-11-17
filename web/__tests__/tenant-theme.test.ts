/**
 * The browser's copy of the main-colour theme (web/lib/tenant-theme.ts) must
 * derive exactly what the server does (src/Core/Branding/TenantTheme.php), or
 * the Branding preview would show one colour and the saved workspace another.
 * The fixture is TenantTheme::derive() output for the six swatches plus two
 * extremes; regenerate it whenever the PHP changes.
 */
import { deriveTenantTheme, isHexColour, tenantThemeStyle } from '@/lib/tenant-theme';
import reference from './fixtures/tenant-theme.json';

describe('deriveTenantTheme matches TenantTheme.php', () => {
  it.each(Object.keys(reference))('%s', (hex) => {
    expect(deriveTenantTheme(hex)).toEqual((reference as Record<string, unknown>)[hex]);
  });

  it('refuses anything but #rrggbb', () => {
    expect(() => deriveTenantTheme('blue')).toThrow();
    expect(isHexColour('#0961BB')).toBe(true);
    expect(isHexColour('#096')).toBe(false);
  });

  it('turns tokens into CSS custom properties', () => {
    expect(tenantThemeStyle({ primary: '#0961bb' })).toEqual({ '--primary': '#0961bb' });
  });
});
