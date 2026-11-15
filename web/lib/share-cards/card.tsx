import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { ImageResponse } from 'next/og';

/**
 * Link previews (design 3.4 `SiteShareCards`, WHIT-1041): the 1200 × 630
 * picture a chat app or social site shows for a link.
 *
 * - A workspace's public pages (booking, forms, joining) wear the workspace's
 *   own colour and logo, never Whity's pattern.
 * - A shared document never shows its content: the issuer, the reference and
 *   "private link".
 * - Text stays inside 1080 × 560 so it survives crops; the title is two lines
 *   at most. Arabic content gets the Arabic face and right-to-left.
 *
 * The fonts are the brand's own (docs/design/fonts), as TTF because the image
 * renderer reads no woff2.
 */

export const CARD_SIZE = { width: 1200, height: 630 };

const FONT_DIR = join(process.cwd(), 'lib', 'share-cards', 'fonts');

type Weight = 400 | 600;
interface FontSpec {
  name: string;
  file: string;
  weight: Weight;
}

const FONTS: FontSpec[] = [
  { name: 'Noto Sans', file: 'noto-sans-latin-400.ttf', weight: 400 },
  { name: 'Noto Sans', file: 'noto-sans-latin-600.ttf', weight: 600 },
  { name: 'Readex Pro', file: 'readex-pro-latin-600.ttf', weight: 600 },
  { name: 'Readex Pro Arabic', file: 'readex-pro-arabic-600.ttf', weight: 600 },
];

let lockup: Promise<string> | null = null;

/** Whity's horizontal lockup (handoff/logos), for Whity's own cards. */
function whityLockup(): Promise<string> {
  lockup ??= readFile(join(process.cwd(), 'lib', 'share-cards', 'whity-lockup.svg')).then(
    (svg) => `data:image/svg+xml;base64,${svg.toString('base64')}`
  );
  return lockup;
}

let loaded: Promise<{ name: string; data: ArrayBuffer; weight: Weight; style: 'normal' }[]> | null = null;

function fonts() {
  loaded ??= Promise.all(
    FONTS.map(async (f) => {
      const buf = await readFile(join(FONT_DIR, f.file));
      return { name: f.name, data: buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength) as ArrayBuffer, weight: f.weight, style: 'normal' as const };
    })
  );
  return loaded;
}

/** Whether the text is mostly Arabic, so the card reads right to left. */
export function isArabic(text: string): boolean {
  const arabic = (text.match(/[؀-ۿ]/g) ?? []).length;
  const latin = (text.match(/[A-Za-z]/g) ?? []).length;
  return arabic > latin;
}

/** Shorten to `max` characters on a word boundary, with an ellipsis. */
export function clip(text: string, max: number): string {
  const t = text.trim().replace(/\s+/g, ' ');
  if (t.length <= max) return t;
  const cut = t.slice(0, max - 1);
  // The cut already ends a word when the next character is a space.
  if (t.charAt(max - 1) === ' ') return `${cut.trimEnd()}…`;
  const space = cut.lastIndexOf(' ');
  return `${(space > max * 0.6 ? cut.slice(0, space) : cut).trimEnd()}…`;
}

/**
 * Arabic as the image renderer must be given it. It shapes each word but lays
 * words out left to right, so the words go in display order here; and lines
 * are kept short enough not to wrap, since a wrapped line would start with
 * the wrong words. Latin text is returned as it is.
 */
export function forDisplay(text: string, rtl: boolean, max: number): string {
  if (!rtl) return clip(text, max);
  return clip(text, max).split(' ').reverse().join(' ');
}

/** Relative luminance of #rrggbb, or null. */
function luminance(hex: string): number | null {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex);
  if (!m) return null;
  const n = parseInt(m[1], 16);
  const c = (v: number) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * c((n >> 16) & 255) + 0.7152 * c((n >> 8) & 255) + 0.0722 * c(n & 255);
}

/** White text on a dark colour, near-black on a light one. */
export function inkOn(background: string): string {
  const l = luminance(background);
  return l !== null && l > 0.4 ? '#141414' : '#ffffff';
}

/** Two letters for a workspace with no logo. */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('');
}

/** A logo as a data URL the renderer can draw, or null when it can't be fetched. */
export async function logoData(url: string | null | undefined, base: string): Promise<string | null> {
  if (!url) return null;
  try {
    const absolute = url.startsWith('http') ? url : `${base}${url.startsWith('/') ? '' : '/'}${url}`;
    const res = await fetch(absolute, { cache: 'no-store', signal: AbortSignal.timeout(3000) });
    if (!res.ok) return null;
    const type = res.headers.get('content-type') ?? '';
    if (!/^image\/(png|jpeg|svg\+xml|webp)/.test(type)) return null;
    const bytes = Buffer.from(await res.arrayBuffer());
    if (bytes.byteLength > 512 * 1024) return null;
    return `data:${type.split(';')[0]};base64,${bytes.toString('base64')}`;
  } catch {
    return null;
  }
}

export interface WorkspaceCard {
  kind: 'workspace';
  workspace: string;
  logo: string | null;
  /** #rrggbb; Whity Blue when the workspace has none. */
  color: string | null;
  title: string;
  subtitle?: string | null;
  address: string;
}

export interface PaperCard {
  kind: 'paper';
  /** Who it is from: a workspace, or null for Whity's own pages. */
  issuer: string | null;
  logo?: string | null;
  color?: string | null;
  title: string;
  subtitle?: string | null;
  tag: string;
}

export type ShareCard = WorkspaceCard | PaperCard;

const WHITY_BLUE = '#0961bb';
const PAPER = '#f7f6f3';
const SHEET = '#ffffff';

/** The 1200 × 630 PNG for one card. */
export async function renderCard(card: ShareCard): Promise<ImageResponse> {
  const rtl = isArabic(`${card.title} ${card.subtitle ?? ''}`);
  // The renderer shapes Readex Pro's Arabic but not Noto Sans Arabic's, so
  // Arabic cards use Readex Pro throughout (the card's one-sentence face).
  const body = rtl ? 'Readex Pro Arabic' : 'Noto Sans';
  const display = rtl ? 'Readex Pro Arabic' : 'Readex Pro';
  const background = card.kind === 'workspace' ? (card.color ?? WHITY_BLUE) : PAPER;
  const ink = card.kind === 'workspace' ? inkOn(background) : '#141414';
  const muted = card.kind === 'workspace' ? ink : '#5c6066';
  const name = card.kind === 'workspace' ? card.workspace : card.issuer;
  const accent = card.color ?? WHITY_BLUE;
  const whity = name ? null : await whityLockup();

  const badge = (
    <div style={{ display: 'flex', alignItems: 'center', gap: 20, flexDirection: rtl ? 'row-reverse' : 'row' }}>
      {card.logo ? (
        <div style={{ display: 'flex', background: '#ffffff', borderRadius: 18, padding: 10 }}>
          {/* eslint-disable-next-line @next/next/no-img-element -- the image renderer draws plain img */}
          <img src={card.logo} width={68} height={68} style={{ objectFit: 'contain' }} alt="" />
        </div>
      ) : name ? (
        <div
          style={{
            display: 'flex',
            width: 88,
            height: 88,
            borderRadius: 18,
            alignItems: 'center',
            justifyContent: 'center',
            background: card.kind === 'workspace' ? '#ffffff' : accent,
            color: card.kind === 'workspace' ? accent : '#ffffff',
            fontSize: 36,
            fontWeight: 600,
            fontFamily: isArabic(name) ? 'Readex Pro Arabic' : 'Noto Sans',
          }}
        >
          {initials(name)}
        </div>
      ) : whity ? (
        // The lockup is 109.28 × 36.12; drawn 48px tall.
        // eslint-disable-next-line @next/next/no-img-element -- the image renderer draws plain img
        <img src={whity} width={145} height={48} alt="Whity" />
      ) : null}
      {name && (
        <div style={{ display: 'flex', fontSize: 34, fontWeight: 600, fontFamily: isArabic(name) ? 'Readex Pro Arabic' : 'Noto Sans', color: ink }}>
          {forDisplay(name, isArabic(name), 40)}
        </div>
      )}
    </div>
  );

  const content = (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        width: CARD_SIZE.width - 120,
        height: CARD_SIZE.height - 70,
        padding: '40px 40px',
        alignItems: rtl ? 'flex-end' : 'flex-start',
        textAlign: rtl ? 'right' : 'left',
        // The renderer draws `transparent` and `none` as black: set them only on paper.
        ...(card.kind === 'paper' ? { backgroundColor: SHEET, borderRadius: 24, boxShadow: '0 6px 24px rgba(0,0,0,0.10)' } : {}),
      }}
    >
      {badge}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14, alignItems: rtl ? 'flex-end' : 'flex-start' }}>
        <div
          style={{
            display: 'flex',
            fontFamily: display,
            fontWeight: 600,
            fontSize: 64,
            lineHeight: 1.15,
            color: card.kind === 'paper' ? accent : ink,
            maxWidth: 1000,
          }}
        >
          {forDisplay(card.title, rtl, rtl ? 28 : 70)}
        </div>
        {/* Arabic sentences can lose a glyph in this renderer; the
            description beside the picture carries them instead. */}
        {card.subtitle && !rtl && (
          <div style={{ display: 'flex', fontFamily: body, fontSize: 30, color: muted, opacity: card.kind === 'workspace' ? 0.85 : 1, maxWidth: 1000 }}>
            {forDisplay(card.subtitle, rtl, rtl ? 56 : 110)}
          </div>
        )}
      </div>
      <div style={{ display: 'flex', fontFamily: card.kind === 'workspace' ? 'Noto Sans' : body, fontSize: 24, color: muted, opacity: 0.8 }}>
        {card.kind === 'workspace' ? card.address : forDisplay(card.tag, isArabic(card.tag), 80)}
      </div>
    </div>
  );

  return new ImageResponse(
    (
      <div
        style={{
          display: 'flex',
          width: '100%',
          height: '100%',
          alignItems: 'center',
          justifyContent: 'center',
          background,
        }}
      >
        {content}
      </div>
    ),
    { ...CARD_SIZE, fonts: await fonts() }
  );
}
