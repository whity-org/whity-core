/**
 * WHIT-915 — a workspace's own customer sign-up pages: /join/[workspace] and
 * /join/confirm.
 */

import React, { Suspense } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

let mockToken: string | null = null;
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'token' ? mockToken : null) }),
}));

jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light' }),
}));

jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
}));

jest.mock('@/components/auth/turnstile-widget', () => ({
  TurnstileWidget: () => <div data-testid="signup-bot-check" />,
}));

import JoinWorkspacePage from '@/app/join/[workspace]/page';
import JoinConfirmPage from '@/app/join/confirm/page';

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

const OPEN_INFO = {
  data: {
    workspace_name: 'Example Bakery',
    workspace_slug: 'example-bakery',
    approval_required: true,
    allowed_domains: ['example-bakery.com'],
    bot_check: null,
  },
};

async function renderJoin(slug = 'example-bakery') {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <JoinWorkspacePage params={Promise.resolve({ workspace: slug })} />
      </Suspense>
    );
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockToken = null;
  global.fetch = jest.fn();
});

test('an unknown workspace, or one with sign-up off, shows that sign-up is not open', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce(json(404, { error: 'Sign-up is not open for this workspace' }));

  await renderJoin('no-such-place');

  expect(await screen.findByTestId('join-closed')).toBeInTheDocument();
  expect(global.fetch).toHaveBeenCalledWith('/api/v1/public/signup/workspaces/no-such-place', expect.anything());
});

test('the form names the workspace and its domains, and always ends at "check your email"', async () => {
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(json(200, OPEN_INFO))
    .mockResolvedValueOnce(json(202, { data: { status: 'check_email', message: '' } }));

  await renderJoin();

  expect(await screen.findByText('Join Example Bakery')).toBeInTheDocument();
  expect(screen.getByTestId('join-domains')).toHaveTextContent('@example-bakery.com');

  fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Layla Haddad' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'layla@example-bakery.com' } });
  fireEvent.click(screen.getByTestId('join-submit'));

  expect(await screen.findByTestId('join-sent')).toBeInTheDocument();
  const [, init] = (global.fetch as jest.Mock).mock.calls[1];
  expect(JSON.parse(init.body)).toEqual({
    email: 'layla@example-bakery.com',
    display_name: 'Layla Haddad',
    bot_token: '',
  });
});

test('a domain the workspace does not accept is explained on the form', async () => {
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(json(200, OPEN_INFO))
    .mockResolvedValueOnce(json(422, { error: 'x', details: { email: 'domain' } }));

  await renderJoin();
  await screen.findByText('Join Example Bakery');

  fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Outsider' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'someone@gmail.example' } });
  fireEvent.click(screen.getByTestId('join-submit'));

  expect(await screen.findByText(/only accepts sign-ups from the email domains/)).toBeInTheDocument();
});

test('the confirm page asks a new address for a password, then says it waits for approval', async () => {
  mockToken = 'a'.repeat(64);
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(
      json(200, { data: { workspace_name: 'Example Bakery', email: 'layla@example-bakery.com', requires_password: true } })
    )
    .mockResolvedValueOnce(json(200, { data: { status: 'pending_approval', message: '' } }));

  render(<JoinConfirmPage />);

  await screen.findByTestId('join-confirm-form');
  fireEvent.change(screen.getByLabelText('Choose a password'), { target: { value: 'bakery-pass-1' } });
  fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'bakery-pass-1' } });
  fireEvent.click(screen.getByTestId('join-confirm-submit'));

  expect(await screen.findByTestId('join-confirm-pending')).toBeInTheDocument();
});

test('an existing account is asked for nothing, and a refusal is shown as such', async () => {
  mockToken = 'b'.repeat(64);
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(
      json(200, { data: { workspace_name: 'Example Bakery', email: 'bob@example.com', requires_password: false } })
    )
    .mockResolvedValueOnce(json(409, { error: 'x', details: { reason: 'refused' } }));

  render(<JoinConfirmPage />);

  await screen.findByTestId('join-confirm-form');
  expect(screen.queryByLabelText('Choose a password')).not.toBeInTheDocument();
  fireEvent.click(screen.getByTestId('join-confirm-submit'));

  await waitFor(() => expect(screen.getByTestId('join-confirm-refused')).toBeInTheDocument());
});

test('a missing or bad link says so', async () => {
  render(<JoinConfirmPage />);
  expect(await screen.findByTestId('join-confirm-invalid')).toBeInTheDocument();
});
