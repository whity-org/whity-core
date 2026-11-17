/**
 * The workspace theme derived from one main colour (decision 2.8), in the
 * browser, so the Branding preview re-themes as the colour is picked, before
 * anything is saved.
 *
 * A line-for-line port of src/Core/Branding/TenantTheme.php, which derives the
 * same tokens on the server once the colour is saved. The two must agree:
 * __tests__/tenant-theme.test.ts checks this file against the PHP output in
 * __tests__/fixtures/tenant-theme.json. Change both together.
 */

const NEAR_BLACK = '#171717';
const WHITE = '#ffffff';
const MIN_CONTRAST = 4.5;
const HEX_RE = /^#[0-9a-f]{6}$/i;

export type TenantThemeTokens = Record<string, string>;

export interface DerivedTenantTheme {
  /** The main colour, darkened if neither label could reach 4.5:1 on it. */
  color: string;
  adjusted: boolean;
  light: TenantThemeTokens;
  dark: TenantThemeTokens;
}

export function isHexColour(value: string): boolean {
  return HEX_RE.test(value);
}

export function deriveTenantTheme(hex: string): DerivedTenantTheme {
  if (!HEX_RE.test(hex)) throw new Error('The main colour must be a #rrggbb hex colour.');
  const [l0, c, h] = toOklch(hex);
  let l = l0;
  let main = hex.toLowerCase();
  let adjusted = false;
  if (contrast(WHITE, main) < MIN_CONTRAST && contrast(NEAR_BLACK, main) < MIN_CONTRAST) {
    while (l > 0.05 && contrast(WHITE, main) < MIN_CONTRAST) {
      l -= 0.01;
      main = oklchHex(l, c, h);
    }
    adjusted = true;
  }
  return { color: main, adjusted, light: mode(main, c, h, false), dark: mode(main, c, h, true) };
}

/** The tokens as inline CSS custom properties, for a themed preview frame. */
export function tenantThemeStyle(tokens: TenantThemeTokens): Record<string, string> {
  return Object.fromEntries(Object.entries(tokens).map(([name, value]) => [`--${name}`, value]));
}

function mode(main: string, c: number, h: number, dark: boolean): TenantThemeTokens {
  const color = dark ? oklchHex(0.7, Math.min(c, 0.15), h) : main;
  const label = dark ? NEAR_BLACK : contrast(WHITE, color) >= MIN_CONTRAST ? WHITE : NEAR_BLACK;
  const tint = dark ? oklchHex(0.3, Math.min(c, 0.06), h) : oklchHex(0.96, Math.min(c, 0.03), h);
  const plane = dark ? oklchHex(0.42, Math.min(c, 0.13), h) : oklchHex(0.3, Math.min(c, 0.1), h);
  return {
    primary: color,
    brand: color,
    ring: color,
    'chart-1': color,
    'primary-foreground': label,
    'brand-foreground': label,
    'primary-tint': tint,
    'primary-tint-foreground': color,
    'sidebar-primary': tint,
    'sidebar-primary-foreground': color,
    'plane-deep': plane,
    'plane-rest': `-3px 3px 0 0 ${color}`,
    'plane-hover': `-5px 5px 0 0 ${color}`,
    'plane-primary-rest': `-3px 3px 0 0 ${plane}`,
    'plane-primary-hover': `-5px 5px 0 0 ${plane}`,
  };
}

function contrast(a: string, b: string): number {
  const x = luminance(a);
  const y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

function toOklch(hex: string): [number, number, number] {
  const [r, g, b] = linearRgb(hex);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const lightness = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  const hue = (((Math.atan2(bb, a) * 180) / Math.PI + 360) % 360 + 360) % 360;
  return [lightness, Math.sqrt(a * a + bb * bb), hue];
}

function oklchHex(l: number, c0: number, h: number): string {
  let c = c0;
  let rgb = oklchToLinear(l, c, h);
  while (c > 0 && outOfGamut(rgb)) {
    c -= 0.002;
    rgb = oklchToLinear(l, Math.max(c, 0), h);
  }
  return (
    '#' +
    rgb
      .map((x0) => {
        const x = Math.min(1, Math.max(0, x0));
        const v = x <= 0.0031308 ? 12.92 * x : 1.055 * x ** (1 / 2.4) - 0.055;
        // PHP's round() rounds half away from zero; Math.round rounds half up. Same for positives.
        return Math.round(v * 255).toString(16).padStart(2, '0');
      })
      .join('')
  );
}

function oklchToLinear(l: number, c: number, h: number): [number, number, number] {
  const a = c * Math.cos((h * Math.PI) / 180);
  const b = c * Math.sin((h * Math.PI) / 180);
  const ll = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;
  return [
    4.0767416621 * ll - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * ll + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * ll - 0.7034186147 * m + 1.707614701 * s,
  ];
}

function outOfGamut(rgb: number[]): boolean {
  return rgb.some((x) => x < -0.0001 || x > 1.0001);
}

function linearRgb(hex: string): [number, number, number] {
  const out = [1, 3, 5].map((i) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255;
    return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  return [out[0], out[1], out[2]];
}

function luminance(hex: string): number {
  const [r, g, b] = linearRgb(hex);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
