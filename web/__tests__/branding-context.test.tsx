/**
 * WC-233: BrandingProvider seeds the branding context; useBranding() returns
 * the seeded value; the default context value is the safe fallback.
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';

// BrandingSync reads the signed-in identity; each test sets it.
let mockAuth: { user: { id: number; tenant_id: number } | null; isLoading: boolean } = {
  user: null,
  isLoading: false,
};
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => mockAuth,
}));

import {
  BrandingProvider,
  BrandingSync,
  parseBrandingResponse,
  useBranding,
  useRefreshBranding,
} from '@/lib/branding-context';
import type { Branding } from '@/lib/branding';

/** Probe component that renders the branding values as data-testid spans. */
function Probe() {
  const b = useBranding();
  return (
    <div>
      <span data-testid="siteName">{b.siteName}</span>
      <span data-testid="logoWideUrl">{b.logoWideUrl ?? 'null'}</span>
      <span data-testid="logoSquareUrl">{b.logoSquareUrl ?? 'null'}</span>
      <span data-testid="faviconUrl">{b.faviconUrl ?? 'null'}</span>
    </div>
  );
}

const CUSTOM_BRANDING: Branding = {
  siteName: 'Acme Corp',
  logoWideUrl: '/api/v1/branding/asset/1/logo_wide-abc.png',
  logoSquareUrl: '/api/v1/branding/asset/1/logo_square-def.png',
  faviconUrl: '/api/v1/branding/asset/1/favicon-ghi.ico',
};

describe('BrandingProvider + useBranding()', () => {
  it('returns the seeded branding values when wrapped in BrandingProvider', () => {
    render(
      <BrandingProvider initial={CUSTOM_BRANDING}>
        <Probe />
      </BrandingProvider>
    );

    expect(screen.getByTestId('siteName').textContent).toBe('Acme Corp');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe(
      '/api/v1/branding/asset/1/logo_wide-abc.png'
    );
    expect(screen.getByTestId('logoSquareUrl').textContent).toBe(
      '/api/v1/branding/asset/1/logo_square-def.png'
    );
    expect(screen.getByTestId('faviconUrl').textContent).toBe(
      '/api/v1/branding/asset/1/favicon-ghi.ico'
    );
  });

  it('returns the safe fallback default when no BrandingProvider is present', () => {
    render(<Probe />);

    expect(screen.getByTestId('siteName').textContent).toBe('Whity');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe('null');
    expect(screen.getByTestId('logoSquareUrl').textContent).toBe('null');
    expect(screen.getByTestId('faviconUrl').textContent).toBe('null');
  });

  it('nested providers inherit the innermost value', () => {
    const OUTER: Branding = {
      siteName: 'Outer',
      logoWideUrl: null,
      logoSquareUrl: null,
      faviconUrl: null,
    };
    const INNER: Branding = {
      siteName: 'Inner',
      logoWideUrl: '/api/v1/branding/asset/2/logo_wide-xyz.png',
      logoSquareUrl: null,
      faviconUrl: null,
    };

    render(
      <BrandingProvider initial={OUTER}>
        <BrandingProvider initial={INNER}>
          <Probe />
        </BrandingProvider>
      </BrandingProvider>
    );

    expect(screen.getByTestId('siteName').textContent).toBe('Inner');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe(
      '/api/v1/branding/asset/2/logo_wide-xyz.png'
    );
  });

  it('null logo/favicon URLs are exposed as null (not empty string)', () => {
    const branding: Branding = {
      siteName: 'No Assets',
      logoWideUrl: null,
      logoSquareUrl: null,
      faviconUrl: null,
    };

    render(
      <BrandingProvider initial={branding}>
        <Probe />
      </BrandingProvider>
    );

    expect(screen.getByTestId('logoWideUrl').textContent).toBe('null');
    expect(screen.getByTestId('faviconUrl').textContent).toBe('null');
  });
});

// ---------------------------------------------------------------------------
// #1716: the provider owns the main colour's <style> and re-reads it in the
// browser, because the server render cannot see the session (Path=/api).
// ---------------------------------------------------------------------------

const NONE: Branding = { siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null };

function themeBody(siteName: string, light: string, dark: string, logo: string | null = null) {
  return {
    data: {
      siteName,
      logoWideUrl: logo,
      logoSquareUrl: null,
      faviconUrl: null,
      mainColor: light,
      theme: {
        light: { primary: light, brand: light, 'plane-rest': `-3px 3px 0 0 ${light}` },
        dark: { primary: dark, brand: dark },
      },
    },
  };
}

function jsonResponse(body: unknown, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(body) } as Response);
}

function brandStyle(container: HTMLElement): string | null {
  return container.querySelector('style[data-brand-theme]')?.textContent ?? null;
}

/** A button that runs the provider's refresh, standing in for a save. */
function RefreshGrabber() {
  const refresh = useRefreshBranding();
  return (
    <button type="button" data-testid="refresh" onClick={() => void refresh()}>
      refresh
    </button>
  );
}

/** Click refresh and let the fetch and state updates settle. */
async function refreshFromTest(): Promise<void> {
  await act(async () => {
    screen.getByTestId('refresh').click();
    await new Promise((r) => setTimeout(r, 0));
  });
}

describe('BrandingProvider — main colour theme (#1716)', () => {
  const fetchMock = jest.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    global.fetch = fetchMock as unknown as typeof fetch;
    mockAuth = { user: null, isLoading: false };
  });

  it('renders the server CSS until a refresh, then the signed-in tenant light and dark sets', async () => {
    fetchMock.mockReturnValue(jsonResponse(themeBody('Example Bakery', '#00792f', '#56b669', '/logo.png')));
    const { container } = render(
      <BrandingProvider initial={NONE} initialThemeCss=":root{--primary:#b74c05;}">
        <RefreshGrabber />
        <Probe />
      </BrandingProvider>
    );
    expect(brandStyle(container)).toBe(':root{--primary:#b74c05;}');

    await refreshFromTest();

    expect(fetchMock).toHaveBeenCalledWith('/api/v1/branding', { credentials: 'include', cache: 'no-store' });
    const css = brandStyle(container);
    expect(css).toContain(':root{--primary:#00792f;--brand:#00792f;--plane-rest:-3px 3px 0 0 #00792f;}');
    expect(css).toContain('.dark{--primary:#56b669;--brand:#56b669;}');
    expect(css).not.toContain('#b74c05');
    expect(screen.getByTestId('siteName').textContent).toBe('Example Bakery');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe('/logo.png');
  });

  it('drops the brand style when the refreshed answer has no main colour (reset)', async () => {
    fetchMock.mockReturnValue(jsonResponse({ data: { ...NONE, mainColor: null, theme: null } }));
    const { container } = render(
      <BrandingProvider initial={NONE} initialThemeCss=":root{--primary:#b74c05;}">
        <RefreshGrabber />
      </BrandingProvider>
    );

    await refreshFromTest();

    expect(brandStyle(container)).toBeNull();
  });

  it('keeps the current theme when the refresh fails', async () => {
    fetchMock.mockReturnValue(jsonResponse({ error: 'nope' }, false));
    const { container } = render(
      <BrandingProvider initial={NONE} initialThemeCss=":root{--primary:#b74c05;}">
        <RefreshGrabber />
      </BrandingProvider>
    );

    await refreshFromTest();

    expect(brandStyle(container)).toBe(':root{--primary:#b74c05;}');
  });

  it('lets only the latest of two overlapping refreshes land (saving twice quickly)', async () => {
    let releaseFirst: (v: Response) => void = () => {};
    fetchMock
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseFirst = resolve; }))
      .mockReturnValueOnce(jsonResponse(themeBody('Whity', '#643b9a', '#ae86ec')));
    const { container } = render(
      <BrandingProvider initial={NONE}>
        <RefreshGrabber />
      </BrandingProvider>
    );

    await act(async () => {
      screen.getByTestId('refresh').click();
      screen.getByTestId('refresh').click();
      await new Promise((r) => setTimeout(r, 0));
      releaseFirst({ ok: true, json: () => Promise.resolve(themeBody('Whity', '#00792f', '#56b669')) } as Response);
      await new Promise((r) => setTimeout(r, 0));
    });

    expect(brandStyle(container)).toContain('--primary:#643b9a');
    expect(brandStyle(container)).not.toContain('#00792f');
  });

  it('ignores unsafe token names and values', () => {
    const parsed = parseBrandingResponse({
      data: { ...NONE, theme: { light: { primary: 'red;}body{display:none', 'x}y': '#000000', ok: '#123abc' }, dark: {} } },
    });
    expect(parsed?.themeCss).toBe(':root{--ok:#123abc;}');
    expect(parseBrandingResponse({ data: null })).toBeNull();
  });

  it('BrandingSync re-reads branding once auth settles and again on a tenant switch', async () => {
    fetchMock.mockImplementation(() => jsonResponse(themeBody('Whity', '#00792f', '#56b669')));
    mockAuth = { user: null, isLoading: true };
    const tree = () => (
      <BrandingProvider initial={NONE}>
        <BrandingSync />
      </BrandingProvider>
    );
    const { rerender, container } = render(tree());
    expect(fetchMock).not.toHaveBeenCalled();

    mockAuth = { user: { id: 3, tenant_id: 1 }, isLoading: false };
    rerender(tree());
    await waitFor(() => expect(brandStyle(container)).toContain('#00792f'));
    expect(fetchMock).toHaveBeenCalledTimes(1);

    fetchMock.mockImplementation(() => jsonResponse(themeBody('Whity', '#643b9a', '#ae86ec')));
    mockAuth = { user: { id: 3, tenant_id: 4 }, isLoading: false };
    rerender(tree());
    await waitFor(() => expect(brandStyle(container)).toContain('#643b9a'));
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});
