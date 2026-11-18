/**
 * Decision 2.8: the workspace main colour reaches the page as CSS custom
 * properties, light on :root and dark on .dark. Everything the server sends is
 * re-validated before it reaches a <style> tag.
 */

import { brandThemeCss } from '@/lib/brand-theme-css';

describe('brandThemeCss', () => {
  it('writes the light set on :root and the dark set on .dark, dark last so it wins', () => {
    const css = brandThemeCss({
      light: { primary: '#b74c05', 'plane-rest': '-3px 3px 0 0 #b74c05' },
      dark: { primary: '#e97c47' },
    });
    expect(css).toBe(':root{--primary:#b74c05;--plane-rest:-3px 3px 0 0 #b74c05;}.dark{--primary:#e97c47;}');
  });

  it('is empty when the workspace has no main colour', () => {
    expect(brandThemeCss(null)).toBe('');
    expect(brandThemeCss(undefined)).toBe('');
    expect(brandThemeCss({ light: {}, dark: {} })).toBe('');
  });

  it('drops any name or value that could break out of the style tag', () => {
    const css = brandThemeCss({
      light: {
        primary: '#b74c05',
        'primary;}body{': '#000000',
        ring: 'red;}body{display:none',
        brand: 'url(https://example.com)',
        'chart-1': '#B74C05',
      },
      dark: 'not an object',
    });
    expect(css).toBe(':root{--primary:#b74c05;}');
  });
});

describe('brandThemeCss with a theme (WHIT-1225)', () => {
  it('writes type, shape, density and motion on :root and Higher contrast under data-contrast', () => {
    const css = brandThemeCss(
      {
        light: { primary: '#0b6e74', 'plane-rest': '0 2px 6px 0 #0b6e7440' },
        dark: { primary: '#5baeb4' },
        vars: {
          'font-sans': 'var(--font-ibm-plex-sans), var(--font-ibm-plex-sans-arabic), ui-sans-serif, system-ui, sans-serif',
          'text-sm': '1rem',
          'control-h': '44px',
          'wy-radius-card': '16px',
          'dur-base': '260ms',
          'ease-out': 'linear(0, 0.5, 1.012, 1)',
        },
      },
      { light: { 'muted-foreground': '#484848' }, dark: { 'muted-foreground': '#c8c8c8' } },
    );
    expect(css).toBe(
      ':root{--primary:#0b6e74;--plane-rest:0 2px 6px 0 #0b6e7440;' +
        '--font-sans:var(--font-ibm-plex-sans), var(--font-ibm-plex-sans-arabic), ui-sans-serif, system-ui, sans-serif;' +
        '--text-sm:1rem;--control-h:44px;--wy-radius-card:16px;--dur-base:260ms;--ease-out:linear(0, 0.5, 1.012, 1);}' +
        '.dark{--primary:#5baeb4;}' +
        ':root[data-contrast="high"]{--muted-foreground:#484848;}' +
        '.dark[data-contrast="high"]{--muted-foreground:#c8c8c8;}',
    );
  });

  it('drops unknown variables and values outside their pattern', () => {
    const css = brandThemeCss({
      light: {},
      dark: {},
      vars: {
        'font-sans': 'Comic Sans; } body { display:none',
        'text-sm': '1rem;}',
        'control-h': '44px',
        'radius': '0',
        'ease-out': 'url(x)',
        'wy-radius-card': 'calc(100%)',
      },
    });
    expect(css).toBe(':root{--control-h:44px;}');
  });
});
