const TOKEN_NAME_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const HEX_COLOR_RE = /^#[0-9a-f]{6}$/;
const LENGTH = String.raw`-?\d+(?:px)?`;
const SHADOW = `${LENGTH} ${LENGTH} ${LENGTH} ${LENGTH} #[0-9a-f]{6}(?:[0-9a-f]{2})?`;
const SHADOW_RE = new RegExp(`^${SHADOW}(?:, ${SHADOW})?$`);
const PX_RE = /^\d+px$/;
const REM_RE = /^\d+(?:\.\d+)?rem$/;
const MS_RE = /^\d+ms$/;
const NUMBER = String.raw`-?\d+(?:\.\d+)?`;
const EASE_RE = new RegExp(`^(?:linear\\(${NUMBER}(?:, ${NUMBER})*\\)|cubic-bezier\\(${NUMBER}, ${NUMBER}, ${NUMBER}, ${NUMBER}\\))$`);
const RADII = ['control-sm', 'control', 'control-lg', 'sm', 'md', 'lg', 'xl', '2xl', 'card', 'dialog', 'control-pressed', 'control-selected'];
/** TH.21 (30-themes 14.2): ThemeValidator::COMPONENT_FAMILIES. */
const SHAPE_FAMILIES = ['buttons', 'fields', 'chips', 'cards', 'dialogs', 'tables', 'navigation'];
const FONT_STACK_RE = /^var\(--font-[a-z0-9-]+\)(?:, var\(--font-[a-z0-9-]+\))*, ui-sans-serif, system-ui, sans-serif$/;

/**
 * The theme's mode-independent values (WHIT-1225: type, shape, density and
 * motion) and the one pattern each must match. Nothing else is written.
 */
const VAR_PATTERNS: Record<string, RegExp> = {
  'font-sans': FONT_STACK_RE,
  'font-heading': FONT_STACK_RE,
  'control-h-sm': PX_RE,
  'control-h': PX_RE,
  'control-h-lg': PX_RE,
  'row-h': PX_RE,
  'dur-fast': MS_RE,
  'dur-base': MS_RE,
  'dur-slow': MS_RE,
  'ease-out': EASE_RE,
  ...Object.fromEntries(['2xs', 'xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl'].map((s) => [`text-${s}`, REM_RE])),
  ...Object.fromEntries(
    RADII.map((s) => [`wy-radius-${s}`, PX_RE])
  ),
  // TH.17: the Cut preset's bevelled corners.
  'wy-corner-shape': /^(?:round|bevel)$/,
  // TH.21: a component family's own preset, the same set under its name.
  ...Object.fromEntries(
    SHAPE_FAMILIES.flatMap((family) => [
      ...RADII.map((s) => [`wy-${family}-radius-${s}`, PX_RE] as const),
      [`wy-${family}-corner-shape`, /^(?:round|bevel)$/] as const,
    ])
  ),
};

function isObject(raw: unknown): raw is Record<string, unknown> {
  return typeof raw === 'object' && raw !== null && !Array.isArray(raw);
}

/** One mode's colour and shadow declarations, keeping only well-formed names and values. */
function declarations(raw: unknown): string {
  if (!isObject(raw)) return '';
  return Object.entries(raw)
    .filter(
      ([key, value]) =>
        TOKEN_NAME_RE.test(key) && typeof value === 'string' && (HEX_COLOR_RE.test(value) || SHADOW_RE.test(value))
    )
    .map(([key, value]) => `--${key}:${value as string};`)
    .join('');
}

/** The type, shape, density and motion declarations: known names only, each with its own pattern. */
function varDeclarations(raw: unknown): string {
  if (!isObject(raw)) return '';
  return Object.entries(raw)
    .filter(([key, value]) => typeof value === 'string' && VAR_PATTERNS[key]?.test(value) === true)
    .map(([key, value]) => `--${key}:${value as string};`)
    .join('');
}

/**
 * The workspace's theme as CSS (decision 2.8, WHIT-1225): the light colours
 * and the type, shape, density and motion values on `:root`, the dark colours
 * on `.dark`, which comes after so it wins in dark mode, and a person's Higher
 * contrast (TH.12) under `[data-contrast="high"]`. Empty for Whity's own look.
 *
 * The server compiles these (ThemeCompiler); this re-validates every name and
 * value before it reaches a `<style>` tag, so plain concatenation is safe:
 * nothing that passes the patterns can hold a quote, brace or semicolon.
 */
export function brandThemeCss(theme: unknown, high?: unknown): string {
  let css = '';
  if (isObject(theme)) {
    const root = declarations(theme.light) + varDeclarations(theme.vars);
    const dark = declarations(theme.dark);
    if (root !== '') css += `:root{${root}}`;
    if (dark !== '') css += `.dark{${dark}}`;
  }
  if (isObject(high)) {
    const highLight = declarations(high.light);
    const highDark = declarations(high.dark);
    if (highLight !== '') css += `:root[data-contrast="high"]{${highLight}}`;
    if (highDark !== '') css += `.dark[data-contrast="high"]{${highDark}}`;
  }
  return css;
}

/**
 * The theme editor's preview (TH.25): a draft's colours and values on two
 * elements, `[data-theme-preview="light"]` and `[data-theme-preview="dark"]`,
 * so light and dark sit side by side whatever mode the page is in. The same
 * name and value checks as the workspace's theme; nothing reaches `:root`.
 */
export function previewThemeCss(preview: unknown): string {
  if (!isObject(preview)) return '';
  const vars = varDeclarations(preview.vars);
  let css = '';
  for (const mode of ['light', 'dark'] as const) {
    const body = declarations(preview[mode]) + vars;
    if (body !== '') css += `[data-theme-preview="${mode}"]{${body}}`;
  }
  return css;
}
