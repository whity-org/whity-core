/**
 * Tests for the design-token generator (src/design/tokens/generate-tokens.js).
 *
 * Covers WC-28 hardening guarantees:
 *  - base.json loads and validates
 *  - OKLCH parsing + conversion is correct and sane
 *  - malformed input produces clear, typed errors
 *  - generated outputs are deterministic / idempotent
 *  - the brand accent + semantic + type-scale tokens are present
 */

import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';

interface Oklch {
  L: number;
  C: number;
  H: number;
  alpha: number;
}

interface Target {
  path: string;
  content: string | Buffer;
}

interface GeneratorModule {
  TokenValidationError: new (message?: string) => Error;
  parseOklch: (value: unknown, context: string) => Oklch;
  validateBase: (base: unknown) => Record<string, unknown>;
  loadBase: () => Record<string, unknown>;
  oklchToLinearSrgb: (L: number, C: number, H: number) => [number, number, number];
  oklchToArgbHex: (value: string) => string;
  oklchToCssHex: (value: string) => string;
  toCamelCase: (value: string) => string;
  remToPx: (value: string) => number;
  cssLengthToPx: (value: string) => number | null;
  generateTokensJson: (base: unknown) => string;
  generateThemeJson: (base: unknown) => string;
  generateDart: (base: unknown) => string;
  generateCssRegion: (base: unknown) => string;
  generateTokensPackageCss: (base: unknown) => string;
  syncGlobalsCss: (existing: string, base: unknown) => string;
  buildTargets: (base: unknown, opts?: Record<string, boolean>) => Target[];
  findStaleTargets: (targets: Target[]) => string[];
  findOrphanLogos: (targets: Target[]) => string[];
  generateSiteCss: (handoffCss: string, fontsDir?: string) => string;
  CSS_BEGIN: string;
  CSS_END: string;
}

// The generator is a CommonJS module living outside the web/ rootDir; load it
// at runtime so it is not pulled into the TS project's type graph.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const gen: GeneratorModule = require(
  path.join(__dirname, '..', '..', 'src', 'design', 'tokens', 'generate-tokens.js')
);

describe('parseOklch', () => {
  it('parses a plain OKLCH triple', () => {
    expect(gen.parseOklch('oklch(0.5 0.16 255)', 'test')).toEqual({
      L: 0.5,
      C: 0.16,
      H: 255,
      alpha: 1,
    });
  });

  it('parses an OKLCH value with a percentage alpha channel', () => {
    const parsed = gen.parseOklch('oklch(1 0 0 / 10%)', 'test');
    expect(parsed.alpha).toBeCloseTo(0.1, 5);
  });

  it('throws a typed error for non-OKLCH input', () => {
    expect(() => gen.parseOklch('#ff0000', 'test')).toThrow(gen.TokenValidationError);
  });

  it('throws for out-of-range lightness', () => {
    expect(() => gen.parseOklch('oklch(1.5 0 0)', 'test')).toThrow(/lightness/);
  });

  it('throws for non-string input', () => {
    expect(() => gen.parseOklch(42, 'test')).toThrow(gen.TokenValidationError);
  });
});

describe('oklch conversion', () => {
  it('converts the light brand blue to its expected sRGB hex', () => {
    // oklch(0.5 0.16 255) -> #0961BB (verified WCAG AA brand accent)
    expect(gen.oklchToArgbHex('oklch(0.5 0.16 255)')).toBe('0xFF0961BB');
  });

  it('produces an alpha byte for translucent colors', () => {
    expect(gen.oklchToArgbHex('oklch(1 0 0 / 10%)')).toBe('0x1AFFFFFF');
  });

  it('maps pure white and black correctly', () => {
    expect(gen.oklchToArgbHex('oklch(1 0 0)')).toBe('0xFFFFFFFF');
    expect(gen.oklchToArgbHex('oklch(0 0 0)')).toBe('0xFF000000');
  });

  it('converts the light brand blue to its expected CSS hex', () => {
    expect(gen.oklchToCssHex('oklch(0.5 0.16 255)')).toBe('#0961bb');
  });

  it('appends an alpha byte only for translucent colors', () => {
    expect(gen.oklchToCssHex('oklch(1 0 0)')).toBe('#ffffff');
    expect(gen.oklchToCssHex('oklch(1 0 0 / 10%)')).toBe('#ffffff1a');
  });
});

describe('helpers', () => {
  it('camel-cases hyphenated token keys', () => {
    expect(gen.toCamelCase('sidebar-primary-foreground')).toBe('sidebarPrimaryForeground');
    expect(gen.toCamelCase('chart-1')).toBe('chart1');
  });

  it('converts rem to logical pixels', () => {
    expect(gen.remToPx('0.625rem')).toBeCloseTo(10, 5);
    expect(gen.remToPx('1rem')).toBeCloseTo(16, 5);
  });
});

describe('validateBase', () => {
  function makeValidBase(): Record<string, unknown> {
    // Round-trip the real base.json through load to get a known-valid object.
    return gen.loadBase();
  }

  it('accepts the real base.json', () => {
    expect(() => gen.validateBase(makeValidBase())).not.toThrow();
  });

  it('rejects light/dark parity mismatches', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string>; dark: Record<string, string> } };
    delete base.colors.dark.primary;
    expect(() => gen.validateBase(base)).toThrow(/parity mismatch/);
  });

  it('rejects a missing *-foreground pair', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string>; dark: Record<string, string> } };
    delete base.colors.light['brand-foreground'];
    delete base.colors.dark['brand-foreground'];
    expect(() => gen.validateBase(base)).toThrow(/brand-foreground/);
  });

  it('rejects malformed OKLCH in the source', () => {
    const base = makeValidBase() as { colors: { light: Record<string, string> } };
    base.colors.light.primary = 'rgb(0,0,0)';
    expect(() => gen.validateBase(base)).toThrow(gen.TokenValidationError);
  });
});

describe('document type scales (E.33, 6.5)', () => {
  it('converts rem, px and pt to CSS pixels', () => {
    expect(gen.cssLengthToPx('1rem')).toBe(16);
    expect(gen.cssLengthToPx('11px')).toBe(11);
    expect(gen.cssLengthToPx('9.5pt')).toBeCloseTo(12.667, 3);
    expect(gen.cssLengthToPx('large')).toBeNull();
  });

  it('emits the sheet and print scales in both CSS outputs', () => {
    const base = gen.loadBase();
    for (const css of [gen.generateCssRegion(base), gen.generateTokensPackageCss(base)]) {
      expect(css).toContain('--doc-text-sheet-body: 1rem;');
      expect(css).toContain('--doc-text-print-body: 9.5pt;');
      expect(css).toContain('--doc-text-print-title: 22pt;');
      expect(css).toContain('--doc-leading-body-arabic: 1.7;');
    }
  });

  it('rejects a document size below the 11px floor (3.5a)', () => {
    const base = gen.loadBase() as { document: { fontSize: Record<string, string> } };
    base.document.fontSize['print-label'] = '8pt';
    expect(() => gen.validateBase(base)).toThrow(/below 11px/);
  });

  it('rejects a document size that is not a length', () => {
    const base = gen.loadBase() as { document: { fontSize: Record<string, string> } };
    base.document.fontSize['print-body'] = 'small';
    expect(() => gen.validateBase(base)).toThrow(gen.TokenValidationError);
  });
});

describe('generated outputs', () => {
  const base = gen.loadBase();

  it('emits the brand accent and semantic tokens in CSS', () => {
    const css = gen.generateCssRegion(base);
    expect(css).toContain('--brand: oklch(0.5 0.16 255)');
    expect(css).toContain('--brand-foreground:');
    for (const sem of ['success', 'warning', 'error', 'info']) {
      expect(css).toContain(`--${sem}:`);
      expect(css).toContain(`--${sem}-foreground:`);
    }
  });

  it('emits the full type scale in CSS', () => {
    const css = gen.generateCssRegion(base);
    for (const size of ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl']) {
      expect(css).toContain(`--text-${size}:`);
    }
    // Decision 3.6: no 700 in the product.
    expect(css).not.toContain('--font-weight-bold');
    expect(css).not.toMatch(/font-weight[^;]*: 700/);
    expect(css).toContain('--leading-normal: 1.5');
    expect(css).toContain('--tracking-tight: -0.025em');
  });

  it('emits valid Dart with no template-literal leakage', () => {
    const dart = gen.generateDart(base);
    expect(dart).not.toContain('$camelKey');
    expect(dart).not.toContain('oklch(');
    expect(dart).toContain("'brand': Color(0xFF0961BB)");
    expect(dart).toContain("static const String fontMono = 'Geist Mono'");
  });

  it('produces parseable, versioned JSON', () => {
    const json = JSON.parse(gen.generateTokensJson(base)) as {
      version: string;
      light: Record<string, string>;
      dark: Record<string, string>;
      typography: { fontSize: Record<string, unknown> };
    };
    expect(typeof json.version).toBe('string');
    expect(json.light.brand).toBe('oklch(0.5 0.16 255)');
    expect(json.dark.brand).toBeDefined();
    expect(Object.keys(json.typography.fontSize).length).toBeGreaterThan(0);
  });

  it('emits a colors-only master theme.json with light/dark oklch + resolved hex', () => {
    const theme = JSON.parse(gen.generateThemeJson(base)) as {
      version: string;
      colors: Record<string, { light: string; dark: string; lightHex: string; darkHex: string }>;
    };
    expect(typeof theme.version).toBe('string');
    expect(theme.colors.brand).toEqual({
      light: 'oklch(0.5 0.16 255)',
      dark: 'oklch(0.7 0.15 255)',
      lightHex: '#0961bb',
      darkHex: expect.stringMatching(/^#[0-9a-f]{6}$/),
    });
    // Colors-only: no typography/spacing/radius leak into this file.
    expect(theme).not.toHaveProperty('typography');
    expect(theme).not.toHaveProperty('spacing');
    // Every color in base.json's light palette appears, alphabetically.
    const expectedNames = Object.keys(
      (base as { colors: { light: Record<string, string> } }).colors.light
    ).sort();
    expect(Object.keys(theme.colors)).toEqual(expectedNames);
  });

  it('is idempotent: the generated CSS region round-trips through syncGlobalsCss', () => {
    const region = gen.generateCssRegion(base);
    const wrapped = `header\n\n${region}\nfooter\n`;
    const once = gen.syncGlobalsCss(wrapped, base);
    const twice = gen.syncGlobalsCss(once, base);
    expect(twice).toBe(once);
    expect(once).toContain('header');
    expect(once).toContain('footer');
  });

  it('reports no stale targets immediately after a fresh build', () => {
    // buildTargets reads current on-disk files for the diff comparison.
    const targets = gen.buildTargets(base);
    // After `npm run tokens:generate` the checked-in files should match;
    // this guards the committed outputs against drift.
    const stale = gen.findStaleTargets(targets);
    expect(stale).toEqual([]);
  });
});

// WHIT-1055: @whity/tokens carries the websites' token CSS and the logo set, so
// other repos install them instead of keeping copies.
describe('site tokens and logos in @whity/tokens', () => {
  const root = path.join(__dirname, '..', '..');
  const handoffCss = fs.readFileSync(path.join(root, 'docs', 'design', 'handoff', 'web', 'tokens.css'), 'utf8');
  const fontsDir = path.join(root, 'packages', 'tokens', 'fonts');

  it('keeps the handoff CSS and reads fonts from the package', () => {
    const css = gen.generateSiteCss(handoffCss);
    expect(css).not.toMatch(/url\(['"]?\/fonts\//);
    const fonts = [...css.matchAll(/url\('\.\.\/fonts\/([^']+)'\)/g)].map((m) => m[1]);
    // The handoff's own count of @font-face sources, independent of the generator.
    expect(fonts).toHaveLength((handoffCss.match(/url\('\/fonts\//g) ?? []).length);
    for (const f of fonts) expect(fs.existsSync(path.join(fontsDir, f))).toBe(true);
    // Everything else is the handoff unchanged.
    const strip = (s: string) => s.replace(/\r\n/g, '\n').replace(/url\('(\.\.)?\/fonts\//g, 'url(FONTS/');
    expect(strip(css).endsWith(strip(handoffCss))).toBe(true);
  });

  it('refuses a handoff that uses a font the package does not ship', () => {
    const extra = handoffCss + "\n@font-face { font-family: 'X'; src: url('/fonts/not-shipped-700.woff2'); }\n";
    expect(() => gen.generateSiteCss(extra)).toThrow(/not-shipped-700\.woff2/);
  });

  it('refuses a handoff whose fonts no longer sit under /fonts/', () => {
    expect(() => gen.generateSiteCss(handoffCss.replace(/\/fonts\//g, '/type/'))).toThrow(/names no \/fonts\//);
  });

  it('carries every handoff logo byte for byte', () => {
    const targets = gen.buildTargets(base(), { json: false, dart: false, web: false, theme: false, tokensPackage: true });
    const logos = targets.filter((t) => t.path.includes(`${path.sep}packages${path.sep}tokens${path.sep}logos${path.sep}`));
    const handoffLogos = path.join(root, 'docs', 'design', 'handoff', 'logos');
    const expected: string[] = [];
    const walk = (d: string, pre: string) => {
      for (const e of fs.readdirSync(d, { withFileTypes: true })) {
        if (e.isDirectory()) walk(path.join(d, e.name), `${pre}${e.name}/`);
        else expected.push(`${pre}${e.name}`);
      }
    };
    walk(handoffLogos, '');
    expect(logos).toHaveLength(expected.length);
    expect(expected).toContain('whity-lockup-horizontal-colour.svg');
    expect(expected).toContain('png/app-icon-512.png');
    for (const t of logos) expect(Buffer.isBuffer(t.content)).toBe(true);
  });

  it('reports a logo the handoff no longer has as stale', () => {
    const targets = gen.buildTargets(base(), { json: false, dart: false, web: false, theme: false, tokensPackage: true });
    const orphan = path.join(root, 'packages', 'tokens', 'logos', 'zz-removed-by-brand-team.svg');
    fs.writeFileSync(orphan, '<svg/>');
    try {
      expect(gen.findOrphanLogos(targets)).toEqual([orphan]);
      expect(gen.findStaleTargets(targets)).toContain(orphan);
    } finally {
      fs.unlinkSync(orphan);
    }
    expect(gen.findOrphanLogos(targets)).toEqual([]);
  });

  it('copies tokens.css, fonts and logos into a site folder with working font paths', () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const bin = require(path.join(root, 'packages', 'tokens', 'bin', 'whity-tokens.js')) as {
      main: (argv: string[]) => number;
    };
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'whity-tokens-'));
    const log = jest.spyOn(console, 'log').mockImplementation(() => undefined);
    try {
      expect(bin.main(['copy', dir])).toBe(0);
      const css = fs.readFileSync(path.join(dir, 'tokens.css'), 'utf8');
      const fonts = [...css.matchAll(/url\('([^']+\.woff2)'\)/g)].map((m) => m[1]);
      expect(fonts.length).toBeGreaterThan(0);
      for (const f of fonts) {
        expect(f.startsWith('fonts/')).toBe(true);
        expect(fs.existsSync(path.join(dir, f))).toBe(true);
      }
      expect(fs.existsSync(path.join(dir, 'logos', 'whity-mark-colour.svg'))).toBe(true);
      expect(fs.existsSync(path.join(dir, 'logos', 'png', 'app-icon-512.png'))).toBe(true);
    } finally {
      log.mockRestore();
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });

  it('copy leaves out fonts and logos when asked, and rejects bad arguments', () => {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const bin = require(path.join(root, 'packages', 'tokens', 'bin', 'whity-tokens.js')) as {
      main: (argv: string[]) => number;
    };
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'whity-tokens-'));
    const log = jest.spyOn(console, 'log').mockImplementation(() => undefined);
    const err = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    try {
      expect(bin.main(['copy', dir, '--no-fonts', '--no-logos'])).toBe(0);
      expect(fs.readdirSync(dir)).toEqual(['tokens.css']);
      expect(bin.main(['copy'])).toBe(2);
      expect(bin.main(['copy', dir, '--logos-only'])).toBe(2);
      expect(bin.main(['paste', dir])).toBe(2);
    } finally {
      log.mockRestore();
      err.mockRestore();
      fs.rmSync(dir, { recursive: true, force: true });
    }
  });

  function base() {
    return gen.loadBase();
  }
});
