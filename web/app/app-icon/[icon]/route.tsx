import { ImageResponse } from 'next/og';
import { backendUrl } from '@/lib/backend-url';
import { getAppInstall } from '@/lib/pwa/app-install';
import { APP_ICONS, isAppIconName, LAUNCH_BACKGROUND, WHITY_BLUE } from '@/lib/pwa/shared';

/**
 * `GET /app-icon/{192|512|180|maskable-192|maskable-512}` — the installed
 * app's icons, drawn per host (#1733).
 *
 *  - A workspace with a square logo: the logo on white, inside the safe zone.
 *  - A renamed workspace without one: its initial on its main colour.
 *  - Whity itself: the white mark on Whity Blue (the brand's app icon).
 *
 * Maskable icons keep everything inside the central 80% circle a platform may
 * crop to, so the drawing is smaller there than on the `any` icons.
 */
export const dynamic = 'force-dynamic';

/** The white Whity mark (docs/design/assets/Logos/whity-mark-main-white.svg). */
const WHITY_MARK =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32"><defs><mask id="k"><rect x="-100" y="-100" width="400" height="400" fill="#fff"/><path d="M15.5 2.0H24.5A5.5 5.5 0 0 1 30.0 7.5V16.5A5.5 5.5 0 0 1 24.5 22.0H15.5A5.5 5.5 0 0 1 10.0 16.5V7.5A5.5 5.5 0 0 1 15.5 2.0Z" fill="#000"/></mask></defs><rect x="3.5" y="10.5" width="18" height="18" rx="4.25" fill="#ffffff" stroke="#ffffff" stroke-width="2.5" mask="url(#k)"/><rect x="11.25" y="3.25" width="17.5" height="17.5" rx="4.25" fill="none" stroke="#ffffff" stroke-width="2.5"/></svg>';

const WHITY_MARK_URI = `data:image/svg+xml;base64,${Buffer.from(WHITY_MARK).toString('base64')}`;

/** Formats the image renderer can draw. */
const DRAWABLE = new Set(['image/png', 'image/jpeg', 'image/svg+xml']);
const MAX_LOGO_BYTES = 2 * 1024 * 1024;

async function logoDataUri(url: string): Promise<string | null> {
  try {
    const res = await fetch(new URL(url, backendUrl()), { cache: 'no-store' });
    if (!res.ok) return null;
    const type = (res.headers.get('content-type') ?? '').split(';')[0].trim().toLowerCase();
    if (!DRAWABLE.has(type)) return null;
    const bytes = Buffer.from(await res.arrayBuffer());
    if (bytes.length === 0 || bytes.length > MAX_LOGO_BYTES) return null;
    return `data:${type};base64,${bytes.toString('base64')}`;
  } catch {
    return null;
  }
}

/** The first letter, when the bundled font can draw it. */
function initialOf(name: string): string | null {
  const first = name.trim().charAt(0).toUpperCase();
  return /^[A-Z0-9]$/.test(first) ? first : null;
}

async function draw(element: React.ReactElement, size: number): Promise<Response> {
  // Rendered to bytes here, so a logo the renderer rejects is caught below
  // instead of failing half-way through a streamed response.
  const bytes = await new ImageResponse(element, { width: size, height: size }).arrayBuffer();
  return new Response(bytes, {
    headers: {
      'Content-Type': 'image/png',
      // Icon URLs carry the branding version, so a long cache is safe.
      'Cache-Control': 'public, max-age=86400',
      Vary: 'Host',
    },
  });
}

export async function GET(_request: Request, { params }: { params: Promise<{ icon: string }> }): Promise<Response> {
  const { icon } = await params;
  if (!isAppIconName(icon)) {
    return new Response('Not found', { status: 404 });
  }
  const install = await getAppInstall();
  if (install.state !== 'on') {
    return new Response('Not found', { status: 404, headers: { 'Cache-Control': 'no-store' } });
  }

  const { size, maskable } = APP_ICONS[icon];
  const tile = (background: string, child: React.ReactNode) => (
    <div style={{ width: size, height: size, display: 'flex', alignItems: 'center', justifyContent: 'center', background }}>
      {child}
    </div>
  );

  if (install.logoSquareUrl) {
    const logo = await logoDataUri(install.logoSquareUrl);
    if (logo) {
      const side = Math.round(size * (maskable ? 0.56 : 0.72));
      try {
        // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
        return await draw(tile(LAUNCH_BACKGROUND, <img src={logo} width={side} height={side} style={{ objectFit: 'contain' }} />), size);
      } catch {
        // An image the renderer cannot read: fall through to the drawn icon.
      }
    }
  }

  const isWhity = install.siteName.trim() === 'Whity';
  const initial = initialOf(install.siteName);
  if (!isWhity && initial) {
    return draw(
      tile(
        install.themeColor,
        <div style={{ color: '#ffffff', fontSize: Math.round(size * (maskable ? 0.4 : 0.5)), fontWeight: 600, lineHeight: 1 }}>{initial}</div>,
      ),
      size,
    );
  }

  if (!isWhity) {
    // A white-label workspace whose name the font cannot draw: its colour
    // alone, rather than Whity's mark on someone else's app.
    return draw(tile(install.themeColor, null), size);
  }

  const mark = Math.round(size * (maskable ? 0.5 : 0.61));
  return draw(
    // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
    tile(WHITY_BLUE, <img src={WHITY_MARK_URI} width={mark} height={mark} />),
    size,
  );
}
