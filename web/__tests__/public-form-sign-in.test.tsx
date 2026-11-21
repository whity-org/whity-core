/**
 * #2355 — a form's public link that asks people to sign in starts the sign-in
 * with the form, so a new person joins the form's workspace and comes back to
 * the form, and the page explains what came back when they could not.
 */

import React, { Suspense } from 'react';
import { act, render, screen } from '@testing-library/react';

jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/fetch-like-api').fetchLikeClient());
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));
jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
  LanguageSwitcher: () => null,
}));
jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Example Bakery', logoWideUrl: null, logoSquareUrl: null }),
}));

import PublicFormPage from '@/app/f/[slug]/page';

const SLUG = 'ab12'.repeat(16);

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

const SIGN_IN_FORM = {
  data: {
    slug: SLUG,
    name: 'Research application',
    description: null,
    fields: [],
    sections: [],
    accepts_submissions: true,
    opens_at: null,
    closes_at: null,
    sign_in_required: true,
    form_id: 5,
  },
};

async function renderForm() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <PublicFormPage params={Promise.resolve({ slug: SLUG })} />
      </Suspense>
    );
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = jest.fn().mockResolvedValue(json(200, SIGN_IN_FORM));
  window.history.replaceState(null, '', `/f/${SLUG}`);
});

test('signing in carries the form, and comes back to its signed-in page', async () => {
  await renderForm();

  const link = await screen.findByRole('link', { name: 'Sign in to fill it in' });
  const href = new URL(link.getAttribute('href') ?? '', 'https://app.test');
  expect(href.pathname).toBe('/login');
  expect(href.searchParams.get('form')).toBe(SLUG);
  expect(href.searchParams.get('next')).toBe('/forms/5');
  expect(screen.queryByTestId('form-sso-outcome')).toBeNull();
});

test('a sign-in the workspace could not take is explained on the form', async () => {
  window.history.replaceState(null, '', `/f/${SLUG}?sso=domain`);

  await renderForm();

  expect(await screen.findByTestId('form-sso-outcome')).toHaveTextContent(
    'only accepts sign-ups from some email domains'
  );
});

test('an unknown outcome in the address says nothing', async () => {
  window.history.replaceState(null, '', `/f/${SLUG}?sso=<b>hi</b>`);

  await renderForm();

  await screen.findByRole('link', { name: 'Sign in to fill it in' });
  expect(screen.queryByTestId('form-sso-outcome')).toBeNull();
});
