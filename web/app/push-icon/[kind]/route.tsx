import { ImageResponse } from 'next/og';
import { WHITY_BLUE } from '@/lib/pwa/shared';
import { colourFrom, letterFrom } from '@/lib/pwa/push-icon';

/**
 * `GET /push-icon/{icon|badge}` — the images a push notification shows when a
 * workspace has no square logo (WHIT-1076).
 *
 *  - `icon?l=E&c=b74c05`: the workspace's initial in white on its main colour
 *    (Whity Blue without one). No `l`: the Whity icon.
 *  - `badge?l=E`: the initial alone, white on transparent. Android draws the
 *    badge from the image's transparency only, so it must be a silhouette.
 *    No `l`: the Whity mark.
 *
 * Drawn only from the query (one letter, one colour), never from a workspace's
 * data, so it is the same for everyone who asks and safe to cache.
 */
export const dynamic = 'force-dynamic';

/** The white Whity mark (docs/design/assets/Logos/whity-mark-main-white.svg). */
const WHITY_MARK =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32"><defs><mask id="k"><rect x="-100" y="-100" width="400" height="400" fill="#fff"/><path d="M15.5 2.0H24.5A5.5 5.5 0 0 1 30.0 7.5V16.5A5.5 5.5 0 0 1 24.5 22.0H15.5A5.5 5.5 0 0 1 10.0 16.5V7.5A5.5 5.5 0 0 1 15.5 2.0Z" fill="#000"/></mask></defs><rect x="3.5" y="10.5" width="18" height="18" rx="4.25" fill="#ffffff" stroke="#ffffff" stroke-width="2.5" mask="url(#k)"/><rect x="11.25" y="3.25" width="17.5" height="17.5" rx="4.25" fill="none" stroke="#ffffff" stroke-width="2.5"/></svg>';
const WHITY_MARK_URI = `data:image/svg+xml;base64,${Buffer.from(WHITY_MARK).toString('base64')}`;

const ICON_SIZE = 192;
const BADGE_SIZE = 96;

async function png(element: React.ReactElement, size: number): Promise<Response> {
  const bytes = await new ImageResponse(element, { width: size, height: size }).arrayBuffer();
  return new Response(bytes, {
    headers: { 'Content-Type': 'image/png', 'Cache-Control': 'public, max-age=604800, immutable' },
  });
}

export async function GET(request: Request, { params }: { params: Promise<{ kind: string }> }): Promise<Response> {
  const { kind } = await params;
  const url = new URL(request.url);
  const letter = letterFrom(url.searchParams.get('l'));

  if (kind === 'icon') {
    const background = letter ? colourFrom(url.searchParams.get('c')) : WHITY_BLUE;
    const mark = Math.round(ICON_SIZE * 0.61);
    return png(
      <div style={{ width: ICON_SIZE, height: ICON_SIZE, display: 'flex', alignItems: 'center', justifyContent: 'center', background }}>
        {letter ? (
          <div style={{ color: '#ffffff', fontSize: Math.round(ICON_SIZE * 0.5), fontWeight: 600, lineHeight: 1 }}>{letter}</div>
        ) : (
          // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
          <img src={WHITY_MARK_URI} width={mark} height={mark} />
        )}
      </div>,
      ICON_SIZE,
    );
  }

  if (kind === 'badge') {
    const mark = Math.round(BADGE_SIZE * 0.8);
    return png(
      <div style={{ width: BADGE_SIZE, height: BADGE_SIZE, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        {letter ? (
          <div style={{ color: '#ffffff', fontSize: Math.round(BADGE_SIZE * 0.78), fontWeight: 600, lineHeight: 1 }}>{letter}</div>
        ) : (
          // eslint-disable-next-line @next/next/no-img-element, jsx-a11y/alt-text
          <img src={WHITY_MARK_URI} width={mark} height={mark} />
        )}
      </div>,
      BADGE_SIZE,
    );
  }

  return new Response('Not found', { status: 404 });
}
