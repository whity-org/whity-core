/**
 * WC-password-reset-2fa-recovery — password-reset landing page tests.
 *
 * Mirrors the token-consumption shape of verify-email/page.tsx, adapted for
 * this flow's form (new password + confirm), and the two possible success
 * outcomes (applied immediately vs staged for admin approval).
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

let mockToken: string | null = 'a-valid-token';
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'token' ? mockToken : null) }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { POST: (...a: unknown[]) => mockPost(...a) },
}));

import ResetPasswordPage from '@/app/reset-password/page';

beforeEach(() => {
  jest.clearAllMocks();
  mockToken = 'a-valid-token';
});

function fillAndSubmit(password: string, confirm = password) {
  fireEvent.change(screen.getByLabelText('New password'), { target: { value: password } });
  fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: confirm } });
  fireEvent.click(screen.getByRole('button', { name: /reset password/i }));
}

test('shows the no-token error state (with a link to request a new one) when there is no token', () => {
  mockToken = null;
  render(<ResetPasswordPage />);

  expect(screen.getByTestId('reset-password-error')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /request a new link/i })).toHaveAttribute(
    'href',
    '/forgot-password'
  );
});

test('applies the new password immediately and shows the "applied" success state', async () => {
  mockPost.mockResolvedValueOnce({
    data: { data: { status: 'applied', message: 'ok' } },
    error: undefined,
    response: new Response(null, { status: 200 }),
  });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() => expect(screen.getByTestId('reset-password-applied')).toBeInTheDocument());
  expect(mockPost).toHaveBeenCalledWith(
    '/api/v1/auth/password/reset',
    expect.objectContaining({
      body: { token: 'a-valid-token', password: 'a-strong-new-password' },
    })
  );
});

test('shows the awaiting-approval state when the backend stages the reset', async () => {
  mockPost.mockResolvedValueOnce({
    data: { data: { status: 'awaiting_approval', message: 'ok' } },
    error: undefined,
    response: new Response(null, { status: 200 }),
  });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() =>
    expect(screen.getByTestId('reset-password-awaiting-approval')).toBeInTheDocument()
  );
});

test('shows a generic error for an invalid/expired token (400)', async () => {
  mockPost.mockResolvedValueOnce({ data: undefined, error: {}, response: new Response(null, { status: 400 }) });

  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password');

  await waitFor(() => expect(screen.getByTestId('reset-password-error')).toBeInTheDocument());
});

test('rejects mismatched passwords client-side without calling the API', () => {
  render(<ResetPasswordPage />);
  fillAndSubmit('a-strong-new-password', 'a-different-password');

  expect(screen.getByText('Passwords do not match')).toBeInTheDocument();
  expect(mockPost).not.toHaveBeenCalled();
});

test('rejects a too-short password client-side without calling the API', () => {
  render(<ResetPasswordPage />);
  fillAndSubmit('short');

  expect(screen.getByText('Password must be at least 12 characters')).toBeInTheDocument();
  expect(mockPost).not.toHaveBeenCalled();
});
