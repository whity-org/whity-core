'use client';

/**
 * The effective branding (site name, logos, favicon) and the workspace main
 * colour's theme CSS (decision 2.8), for every client component.
 *
 * Seeded by the root layout from its server-side fetch, then kept current IN
 * THE BROWSER, because the server-side fetch cannot see who is signed in: the
 * session cookies are scoped to `Path=/api`, so a page request never carries
 * them and the layout's `GET /api/v1/branding` resolves by host alone (on a
 * shared host, the global layer). The browser's own call to that endpoint does
 * carry the cookie and answers for the signed-in tenant. That gap is #1716: a
 * workspace saved its colour and the app kept the host's.
 *
 * So this provider owns the brand `<style>`: it starts from the server's CSS
 * (no flash for an anonymous reader, whose answer is the same) and replaces it
 * whenever `refreshBranding()` runs — on sign-in, on a tenant switch
 * (BrandingSync, inside AuthProvider) and after a branding save. The light set
 * is on `:root` and the dark set on `.dark`, so one refresh re-themes both
 * modes without a reload.
 */

import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import type { Branding } from '@/lib/branding';
import { brandThemeCss } from '@/lib/brand-theme-css';
import { useAuth } from '@/lib/auth-context';

const FALLBACK: Branding = {
  siteName: 'Whity',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
  builtOnWhity: true,
};

const BrandingContext = createContext<Branding>(FALLBACK);
const RefreshBrandingContext = createContext<() => Promise<void>>(async () => {});

/** A `GET /api/v1/branding` body as Branding plus its theme CSS, or null if malformed. */
export function parseBrandingResponse(body: unknown): { branding: Branding; themeCss: string } | null {
  const d = typeof body === 'object' && body !== null ? (body as { data?: unknown }).data : null;
  if (typeof d !== 'object' || d === null || Array.isArray(d)) return null;
  const r = d as Record<string, unknown>;
  if (typeof r.siteName !== 'string') return null;
  const url = (v: unknown): string | null => (typeof v === 'string' ? v : null);
  return {
    branding: {
      siteName: r.siteName,
      logoWideUrl: url(r.logoWideUrl),
      logoSquareUrl: url(r.logoSquareUrl),
      faviconUrl: url(r.faviconUrl),
      // Shown unless the server says the plan removes it (WHIT-932).
      builtOnWhity: r.builtOnWhity !== false,
    },
    themeCss: brandThemeCss(r.theme),
  };
}

export function BrandingProvider({
  initial,
  initialThemeCss = '',
  children,
}: {
  initial: Branding;
  /** The server's brand CSS (brandThemeCss), rendered until the first refresh. */
  initialThemeCss?: string;
  children: React.ReactNode;
}) {
  const [branding, setBranding] = useState<Branding>(initial);
  const [themeCss, setThemeCss] = useState(initialThemeCss);
  // Only the latest refresh may land: a slow answer for the previous tenant
  // must not overwrite the current one's.
  const latest = useRef(0);

  const refreshBranding = useCallback(async () => {
    const ticket = ++latest.current;
    try {
      const res = await fetch('/api/v1/branding', { credentials: 'include', cache: 'no-store' });
      if (!res.ok) return;
      const parsed = parseBrandingResponse(await res.json());
      if (parsed === null || ticket !== latest.current) return;
      setBranding(parsed.branding);
      setThemeCss(parsed.themeCss);
    } catch {
      // Keep what is showing: a failed refresh is no reason to drop the theme.
    }
  }, []);

  return (
    <BrandingContext.Provider value={branding}>
      <RefreshBrandingContext.Provider value={refreshBranding}>
        {/* Safe as text: brandThemeCss only passes token names and hex values. */}
        {themeCss !== '' && <style data-brand-theme="">{themeCss}</style>}
        {children}
      </RefreshBrandingContext.Provider>
    </BrandingContext.Provider>
  );
}

/**
 * A workspace's public brand as a public page's API sends it (WHIT-1014): the
 * booking page and the manage link carry the brand of the workspace they are
 * for, because on a shared host the layout's branding is the instance's.
 */
export interface WorkspaceBrand {
  name: string;
  logo_wide_url: string | null;
  logo_square_url: string | null;
  /** The token sets derived from the main colour (TenantTheme), or null for the default. */
  theme?: unknown;
}

/**
 * Shows `children` in a workspace's own brand (WHIT-1014): the same branding
 * context and theme `<style>` as the app-wide BrandingProvider, nested, so
 * TenantLogo and every `primary` token below read the workspace's. Its style
 * comes after the app's in the document, so it wins in both modes; the label
 * colours on the brand colour are the server's (TenantTheme, 4.5:1). Without
 * a brand, the app's branding stays as it is.
 */
export function WorkspaceBrandProvider({ brand, children }: { brand: WorkspaceBrand | null | undefined; children: React.ReactNode }) {
  const outer = useBranding();
  if (!brand) return <>{children}</>;
  const branding: Branding = {
    ...outer,
    siteName: brand.name || outer.siteName,
    logoWideUrl: brand.logo_wide_url,
    logoSquareUrl: brand.logo_square_url,
  };
  const themeCss = brandThemeCss(brand.theme);
  // Keyed: BrandingProvider takes `initial` once, so a different brand starts afresh.
  return (
    <BrandingProvider key={`${branding.siteName}|${branding.logoWideUrl}|${branding.logoSquareUrl}|${themeCss}`} initial={branding} initialThemeCss={themeCss}>
      {children}
    </BrandingProvider>
  );
}

/**
 * Re-reads branding whenever the signed-in identity changes (sign-in, sign-out,
 * tenant switch). Renders nothing; sits inside AuthProvider.
 */
export function BrandingSync() {
  const { user, isLoading } = useAuth();
  const refresh = useRefreshBranding();
  const userId = user?.id ?? null;
  const tenantId = user?.tenant_id ?? null;

  useEffect(() => {
    if (isLoading) return;
    void refresh();
  }, [isLoading, userId, tenantId, refresh]);

  return null;
}

export function useBranding(): Branding {
  return useContext(BrandingContext);
}

/** Re-reads branding and the main colour's theme; a no-op outside BrandingProvider. */
export function useRefreshBranding(): () => Promise<void> {
  return useContext(RefreshBrandingContext);
}
