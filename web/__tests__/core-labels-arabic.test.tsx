import React, { useState } from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import { LanguageProvider, useCurrentLanguage, useTranslation } from '@whity/features/i18n';

/**
 * WHIT-1205: core labels stay English in Arabic while plugin text turns Arabic.
 *
 * The provider fetched the requested domains one after another and threw the
 * whole batch away when a component registered another domain mid-load, after
 * marking every pair loaded. The dashboard's app widgets (WHIT-1178) mount their
 * blocks, which ask for 'plugin', only once each widget's route has answered, so
 * on an Arabic dashboard 'common' and 'admin' were dropped and never asked for
 * again: the sidebar, the buttons and "From your apps" stayed English.
 */

const mockApiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  apiFetch: (...a: unknown[]) => mockApiFetch(...a),
}));

// The real BlockRenderer's first line is `useTranslation('plugin')`; that
// registration is what matters here, not the blocks it draws.
jest.mock('@/components/plugin/blocks/block-renderer', () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { useTranslation: useT } = require('@whity/features/i18n');
  return {
    BlockRenderer: () => {
      const t = useT('plugin');
      return <span data-testid="plugin-text">{t('blocks.empty', 'Nothing yet')}</span>;
    },
  };
});

import { AppWidgetBody, CalmEmpty } from '@/app/(protected)/dashboard/app-widget';

const LANGUAGES = [
  { code: 'en', name: 'English', direction: 'ltr' },
  { code: 'ar', name: 'العربية', direction: 'rtl' },
];

const ARABIC: Record<string, Record<string, string>> = {
  common: { 'dashboard.tryAgain': 'حاول مجددًا', 'nav.home': 'الرئيسية' },
  admin: { 'nav.settings': 'الإعدادات' },
  plugin: { 'blocks.empty': 'لا شيء بعد' },
};

/** Translation bundles answer only when the test releases them. */
let held: Array<() => void> = [];
const releaseBundles = async () => {
  for (let round = 0; round < 5; round++) {
    await act(async () => {
      const now = held;
      held = [];
      now.forEach((answer) => answer());
    });
  }
};

function mockLanguageApi(profile: string) {
  global.fetch = jest.fn((url: string, init?: RequestInit) => {
    if (url === '/api/v1/languages') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
    }
    if (url === '/api/v1/settings/language') {
      if (init?.method === 'PATCH') profile = JSON.parse(String(init.body)).language_code;
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ language_code: profile }) });
    }
    const bundle = /^\/api\/v1\/translations\/([^/]+)\/(.+)$/.exec(url);
    if (bundle) {
      const translations = bundle[1] === 'ar' ? ARABIC[bundle[2]] ?? {} : {};
      return new Promise((resolve) => {
        held.push(() => resolve({ ok: true, status: 200, json: async () => ({ translations }) }));
      });
    }
    return Promise.resolve({ ok: false, status: 404, json: async () => ({}) });
  }) as unknown as typeof fetch;
}

const requested = (domain: string) =>
  (global.fetch as jest.Mock).mock.calls.some(([url]) => url === `/api/v1/translations/ar/${domain}`);

describe('core labels in Arabic (WHIT-1205)', () => {
  beforeEach(() => {
    localStorage.clear();
    held = [];
    mockApiFetch.mockReset();
  });

  it("keep Arabic on a dashboard whose app widget draws after the core bundles were asked for", async () => {
    mockLanguageApi('ar');
    let answerRoute: (r: { ok: boolean; status: number }) => void = () => {};
    mockApiFetch.mockReturnValue(new Promise((resolve) => (answerRoute = resolve)));

    render(
      <LanguageProvider>
        <CalmEmpty onRetry={() => {}} />
        <AppWidgetBody
          widget={{ source: '/api/v1/inventory/low', blocks: [] } as never}
          onDenied={() => {}}
        />
      </LanguageProvider>
    );

    // The core bundle is on its way when the widget's route answers and its
    // blocks register 'plugin'.
    await waitFor(() => expect(requested('common')).toBe(true));
    await act(async () => answerRoute({ ok: true, status: 200 }));
    await waitFor(() => expect(screen.getByTestId('plugin-text')).toBeInTheDocument());
    await releaseBundles();

    await waitFor(() => expect(screen.getByTestId('plugin-text')).toHaveTextContent('لا شيء بعد'));
    expect(screen.getByRole('button')).toHaveTextContent('حاول مجددًا');
  });

  it('keep Arabic after switching when a domain registers while the bundles load', async () => {
    mockLanguageApi('en');

    function CoreLabels() {
      const common = useTranslation('common');
      const admin = useTranslation('admin');
      return (
        <>
          <span data-testid="home">{common('nav.home', 'Home')}</span>
          <span data-testid="settings">{admin('nav.settings', 'Settings')}</span>
        </>
      );
    }
    function PluginText() {
      const t = useTranslation('plugin');
      return <span data-testid="plugin-text">{t('blocks.empty', 'Nothing yet')}</span>;
    }
    function Screen() {
      const { setLanguage } = useCurrentLanguage();
      const [late, setLate] = useState(false);
      return (
        <>
          <CoreLabels />
          {late && <PluginText />}
          <button onClick={() => void setLanguage('ar')}>Arabic</button>
          <button onClick={() => setLate(true)}>Show app</button>
        </>
      );
    }

    render(
      <LanguageProvider>
        <Screen />
      </LanguageProvider>
    );
    await waitFor(() => expect(held.length).toBeGreaterThan(0));
    await releaseBundles();

    await act(async () => screen.getByText('Arabic').click());
    await waitFor(() => expect(requested('common')).toBe(true));
    await act(async () => screen.getByText('Show app').click());
    await releaseBundles();

    await waitFor(() => expect(screen.getByTestId('home')).toHaveTextContent('الرئيسية'));
    expect(screen.getByTestId('settings')).toHaveTextContent('الإعدادات');
    expect(screen.getByTestId('plugin-text')).toHaveTextContent('لا شيء بعد');
  });

  it('drops a bundle that arrives for the language the person just left', async () => {
    mockLanguageApi('en');
    function Labels() {
      const { setLanguage } = useCurrentLanguage();
      const t = useTranslation('common');
      return (
        <>
          <span data-testid="home">{t('nav.home', 'Home')}</span>
          <button onClick={() => void setLanguage('ar')}>Arabic</button>
          <button onClick={() => void setLanguage('en')}>English</button>
        </>
      );
    }

    render(
      <LanguageProvider>
        <Labels />
      </LanguageProvider>
    );
    await waitFor(() => expect(held.length).toBeGreaterThan(0));
    await releaseBundles();

    // Arabic asked for, then English chosen again before it answers.
    await act(async () => screen.getByText('Arabic').click());
    await waitFor(() => expect(requested('common')).toBe(true));
    await act(async () => screen.getByText('English').click());
    await releaseBundles();

    expect(screen.getByTestId('home')).toHaveTextContent('Home');
  });
});
