/**
 * WHIT-1014: the public booking page and the customer's manage link show the
 * workspace's own brand, from the brand the booking API sends, even when the
 * app around them carries another (on a shared host, the instance's).
 *
 * The real PublicShell and BrandingProvider are used: the workspace's logo and
 * name replace the host's, and its theme `<style>` comes after the host's so
 * its `--primary` wins. Without a brand the host's branding stays.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: null, isLoading: false }),
}));

jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light' }),
}));

jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
  LanguageSwitcher: () => null,
}));

jest.mock('@/components/auth/turnstile-widget', () => ({
  TurnstileWidget: () => null,
}));

import { BrandingProvider } from '@/lib/branding-context';
import { PublicBookingScreen } from '@/components/booking/public-booking';
import { ManageBookingScreen } from '@/components/booking/manage-booking';

const TOKEN = 'a'.repeat(64);

const HOST = {
  siteName: 'Platform',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
  builtOnWhity: true,
};
const HOST_CSS = ':root{--primary:#c53829;}';

const BRAND = {
  name: 'Example Bakery',
  logo_wide_url: null,
  logo_square_url: '/api/v1/branding/asset/1/logo_square-abc.png',
  main_color: '#b74c05',
  theme: {
    light: { primary: '#b74c05', 'primary-foreground': '#ffffff' },
    dark: { primary: '#ff8a4c', 'primary-foreground': '#171717' },
  },
};

const page = (brand: unknown) => ({
  workspace: { name: 'Example Bakery', slug: 'example-bakery' },
  settings: { time_zone: 'Asia/Amman', min_notice_minutes: 0, max_advance_days: 60, week_start: 'saturday', weekend_days: ['friday', 'saturday'] },
  services: [],
  staff: [],
  bot_check: null,
  brand,
});

const booking = (brand: unknown) => ({
  id: 9,
  status: 'confirmed',
  starts_at: '2026-11-16T07:00:00Z',
  ends_at: '2026-11-16T07:30:00Z',
  service: { id: 1, name: { en: 'Cake tasting' }, duration_minutes: 30 },
  staff_name: 'Lina Saleh',
  price_minor: 12500,
  currency: 'JOD',
  customer: { name: 'Rana Haddad', email: 'rana@example-bakery.com' },
  workspace: { name: 'Example Bakery' },
  time_zone: 'Asia/Amman',
  brand,
});

function respond(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: async () => body });
}

function inHostBranding(ui: React.ReactElement) {
  return render(
    <BrandingProvider initial={HOST} initialThemeCss={HOST_CSS}>
      {ui}
    </BrandingProvider>
  );
}

/** The brand `<style>` tags in document order: the last one wins. */
function brandStyles(): string[] {
  return Array.from(document.querySelectorAll('style[data-brand-theme]')).map((s) => s.textContent ?? '');
}

const fetchMock = jest.fn();

beforeEach(() => {
  fetchMock.mockReset();
  global.fetch = fetchMock as unknown as typeof fetch;
});

describe('the booking pages in the workspace brand', () => {
  it('shows the booking page with the workspace logo, name and colour over the host brand', async () => {
    fetchMock.mockImplementation(() => respond({ data: page(BRAND) }));
    inHostBranding(<PublicBookingScreen workspace="example-bakery" />);

    await screen.findByTestId('book-no-services');
    const logo = document.querySelector('[data-slot="tenant-logo"]');
    expect(logo).toHaveTextContent('Example Bakery');
    expect(logo).not.toHaveTextContent('Platform');
    expect(logo?.querySelector('img')).toHaveAttribute('src', BRAND.logo_square_url);

    const styles = brandStyles();
    expect(styles[0]).toBe(HOST_CSS);
    expect(styles[styles.length - 1]).toBe(
      ':root{--primary:#b74c05;--primary-foreground:#ffffff;}.dark{--primary:#ff8a4c;--primary-foreground:#171717;}'
    );
  });

  it('keeps the host brand when the workspace sends none', async () => {
    fetchMock.mockImplementation(() => respond({ data: page(null) }));
    inHostBranding(<PublicBookingScreen workspace="example-bakery" />);

    await screen.findByTestId('book-no-services');
    expect(document.querySelector('[data-slot="tenant-logo"]')).toHaveTextContent('Platform');
    expect(brandStyles()).toEqual([HOST_CSS]);
  });

  it('keeps the default colour when the workspace set a logo but no colour', async () => {
    fetchMock.mockImplementation(() => respond({ data: page({ ...BRAND, main_color: null, theme: null }) }));
    inHostBranding(<PublicBookingScreen workspace="example-bakery" />);

    await screen.findByTestId('book-no-services');
    expect(document.querySelector('[data-slot="tenant-logo"]')).toHaveTextContent('Example Bakery');
    expect(brandStyles()).toEqual([HOST_CSS]);
  });

  it('shows the manage link in the workspace brand', async () => {
    fetchMock.mockImplementation(() => respond({ data: booking(BRAND) }));
    inHostBranding(<ManageBookingScreen token={TOKEN} />);

    await screen.findByTestId('manage-card');
    await waitFor(() => expect(document.querySelector('[data-slot="tenant-logo"]')).toHaveTextContent('Example Bakery'));
    const styles = brandStyles();
    expect(styles[styles.length - 1]).toContain(':root{--primary:#b74c05;');
  });
});
