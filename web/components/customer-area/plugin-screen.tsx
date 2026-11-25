'use client';

/**
 * A plugin's screen in the customer area (SDK 1.173, WHIT-1424; design
 * section 41 §9, CA.17): its blocks, drawn by the same renderer as staff
 * screens, or its embed page in a sandboxed frame. Either way the plugin is
 * called only with the screen's customer token (lib/customer-screen.ts).
 *
 * An embed's page is fetched with that token and shown through `srcdoc`, so
 * the frame never carries the person's session. A `fullScreen` embed (a
 * lesson player, a test) fills the window under a slim bar with Close.
 */

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { apiFetch } from '@/lib/api/client';
import { useBranding } from '@/lib/branding-context';
import { areaHref } from '@/lib/customer-area';
import { createFrameBridge, navigationTarget, type FrameToken } from '@/lib/plugin-frame-bridge';
import { screenAuthorization, useScreenAuthorization } from '@/lib/customer-screen';
import { useToast } from '@/lib/toast-context';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import { EMBED_SANDBOX } from '@/components/plugin/embed-screen';
import { Button } from '@whity/ui/button';
import { useLanguageCode, useLanguageDirection, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import type { Block } from '@/lib/plugin-features';
import { LoadState } from './area-parts';

export type CustomerScreen = components['schemas']['CustomerAreaScreen'];

/** The blocks of a screen, once its token is in place. */
export function ScreenBlocks({ handle, screenId, apiPaths, blocks }: { handle: string; screenId: string; apiPaths: string[]; blocks: unknown[] }) {
  const ready = useScreenAuthorization(handle, screenId, apiPaths);
  if (!ready) return <div className="h-32 animate-pulse rounded-lg bg-muted" aria-busy="true" />;

  return <BlockRenderer blocks={blocks as Block[]} />;
}

export function ScreenEmbed({ handle, screen, onClose }: { handle: string; screen: CustomerScreen; onClose: () => void }) {
  const t = useTranslation('customer');
  const language = useLanguageCode();
  const dir = useLanguageDirection();
  const router = useRouter();
  const branding = useBranding();
  const { addToast } = useToast();
  const frameRef = useRef<HTMLIFrameElement>(null);
  const [height, setHeight] = useState<number | null>(null);
  const [page, setPage] = useState<{ kind: 'loading' } | { kind: 'failed' } | { kind: 'ready'; html: string }>({ kind: 'loading' });
  const [retry, setRetry] = useState(0);
  const apiPaths = screen.api_paths ?? [];
  const ready = useScreenAuthorization(handle, screen.id, apiPaths);
  const path = screen.embed?.path ?? null;
  const fullScreen = screen.embed?.fullScreen === true;

  const live = useRef({ language, dir, router, addToast, branding });
  useEffect(() => {
    live.current = { language, dir, router, addToast, branding };
  }, [language, dir, router, addToast, branding]);

  useEffect(() => {
    if (!ready || path === null) return undefined;
    let alive = true;
    apiFetch(path)
      .then(async (response) => {
        if (!alive) return;
        setPage(response.ok ? { kind: 'ready', html: await response.text() } : { kind: 'failed' });
      })
      .catch(() => alive && setPage({ kind: 'failed' }));
    return () => {
      alive = false;
    };
  }, [ready, path, retry]);

  useEffect(() => {
    if (path === null) return undefined;
    const auth = screenAuthorization(handle, screen.id, apiPaths);
    const listener = createFrameBridge({
      frameWindow: () => frameRef.current?.contentWindow ?? null,
      embedPath: path,
      fetchToken: async (): Promise<FrameToken> => {
        const token = await auth.token(false);
        if (token === null) throw new Error('screen token refused');
        return { token, expiresIn: 300 };
      },
      send: (url, init) => fetch(url, { ...init, credentials: 'omit' }),
      context: () => ({
        locale: live.current.language ?? 'en',
        dir: live.current.dir,
        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
        audience: 'customer',
        brand: { name: live.current.branding.siteName, color: null, logo: live.current.branding.logoSquareUrl ?? live.current.branding.logoWideUrl ?? null },
      }),
      onResize: setHeight,
      // Inside the area only: `/x/...` and the area's own pages.
      onNavigate: (to) => {
        const target = navigationTarget(to);
        if (target === null) return;
        live.current.router.push(target.startsWith(`/@${handle}`) ? target : areaHref(handle, target));
      },
      onToast: (message, tone) => live.current.addToast(message, tone),
    });
    window.addEventListener('message', listener);
    return () => window.removeEventListener('message', listener);
    // The api paths travel as a joined key in useScreenAuthorization; here the screen id is enough.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [handle, screen.id, path]);

  if (page.kind === 'failed') {
    return <LoadState state={{ kind: 'failed' }} onRetry={() => setRetry((n) => n + 1)} />;
  }

  const frame = page.kind === 'ready' && (
    <iframe
      ref={frameRef}
      srcDoc={page.html}
      title={typeof screen.label === 'string' ? screen.label : screen.id}
      className={fullScreen ? 'h-full w-full border-0' : height === null ? 'h-[70vh] w-full rounded-lg border border-border' : 'w-full rounded-lg border border-border'}
      style={!fullScreen && height !== null ? { height } : undefined}
      sandbox={EMBED_SANDBOX}
      referrerPolicy="no-referrer"
      data-testid="customer-screen-frame"
    />
  );

  if (fullScreen) {
    return (
      <div className="fixed inset-0 z-50 flex flex-col bg-background pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]">
        <div className="flex h-12 items-center justify-end border-b border-border px-3">
          <Button variant="outline" onClick={onClose} data-testid="customer-screen-close">
            {t('screen.close', 'Close')}
          </Button>
        </div>
        <div className="min-h-0 flex-1">{frame || <div className="h-full animate-pulse bg-muted" aria-busy="true" />}</div>
      </div>
    );
  }

  return frame || <div className="h-64 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
}
