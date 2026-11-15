/**
 * WC-password-reset-2fa-recovery — "I lost my 2FA device" account-recovery
 * page tests. Mirrors verify-email/page.tsx's two-mode shape (no-token
 * request form / token auto-confirm) adapted for this flow.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

let mockToken: string | null = null;
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'token' ? mockToken : null) }),
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { POST: (...a: unknown[]) => mockPost(...a) },
}));

import AccountRecoveryPage from '@/app/account-recovery/page';

beforeEach(() => {
  jest.clearAllMocks();
  mockToken = null;
});

// ---------------------------------------------------------------------------
// Request form (no token)
// ---------------------------------------------------------------------------

function submitRequest(email: string) {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.click(screen.getByRole('button', { name: /request recovery/i }));
}

test('shows the request form when there is no token, and a generic confirmation on submit', async () => {
  mockPost.mockResolvedValueOnce({ data: {}, error: undefined, response: new Response(null, { status: 202 }) });

  render(<AccountRecoveryPage />);
  expect(screen.getByTestId('account-recovery-form')).toBeInTheDocument();

  submitRequest('someone@example.com');

  await waitFor(() => expect(screen.getByTestId('account-recovery-request-sent')).toBeInTheDocument());
  expect(mockPost).toHaveBeenCalledWith(
    '/api/v1/auth/2fa-recovery/request',
    expect.objectContaining({ body: { email: 'someone@example.com' } })
  );
});

test('the request form never distinguishes account existence in its response handling (202 either way)', async () => {
  mockPost.mockResolvedValueOnce({ data: {}, error: undefined, response: new Response(null, { status: 202 }) });
  render(<AccountRecoveryPage />);
  submitRequest('known@example.com');
  await waitFor(() => expect(screen.getByTestId('account-recovery-request-sent')).toBeInTheDocument());
  expect(screen.getByText(/if that address has an account/i)).toBeInTheDocument();
});

test('surfaces a 429 as a rate-limit message on the request form', async () => {
  mockPost.mockResolvedValueOnce({ data: undefined, error: {}, response: new Response(null, { status: 429 }) });

  render(<AccountRecoveryPage />);
  submitRequest('flood@example.com');

  await waitFor(() => expect(screen.getByText(/too many requests/i)).toBeInTheDocument());
});

// ---------------------------------------------------------------------------
// Token confirmation
// ---------------------------------------------------------------------------

test('sends nothing when the link opens, so a mail scanner cannot file a request', async () => {
  mockToken = 'a-valid-token';

  render(<AccountRecoveryPage />);

  expect(screen.getByTestId('account-recovery-pending')).toBeInTheDocument();
  expect(mockPost).not.toHaveBeenCalled();
});

test('sends the reason the person gave when they confirm', async () => {
  mockToken = 'a-valid-token';
  mockPost.mockResolvedValueOnce({ data: {}, error: undefined, response: new Response(null, { status: 200 }) });

  render(<AccountRecoveryPage />);
  fireEvent.change(screen.getByLabelText(/why do you need this/i), { target: { value: 'Lost my phone' } });
  fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

  await waitFor(() => expect(screen.getByTestId('account-recovery-submitted')).toBeInTheDocument());
  expect(mockPost).toHaveBeenCalledWith(
    '/api/v1/auth/2fa-recovery/confirm',
    expect.objectContaining({ body: { token: 'a-valid-token', reason: 'Lost my phone' } })
  );
});

test('confirms the token when the person presses the button, and shows the submitted state', async () => {
  mockToken = 'a-valid-token';
  mockPost.mockResolvedValueOnce({ data: {}, error: undefined, response: new Response(null, { status: 200 }) });

  render(<AccountRecoveryPage />);
  fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

  await waitFor(() => expect(screen.getByTestId('account-recovery-submitted')).toBeInTheDocument());
  expect(mockPost).toHaveBeenCalledTimes(1);
  expect(mockPost).toHaveBeenCalledWith(
    '/api/v1/auth/2fa-recovery/confirm',
    expect.objectContaining({ body: { token: 'a-valid-token' } })
  );
});

test('confirming a token never submits a password field (no such input exists on this page)', async () => {
  mockToken = 'a-valid-token';
  mockPost.mockResolvedValueOnce({ data: {}, error: undefined, response: new Response(null, { status: 200 }) });

  render(<AccountRecoveryPage />);
  fireEvent.click(screen.getByRole('button', { name: 'Send request' }));
  await waitFor(() => expect(screen.getByTestId('account-recovery-submitted')).toBeInTheDocument());

  expect(screen.queryByLabelText(/password/i)).not.toBeInTheDocument();
});

test('shows a generic error and falls back to the request form for an invalid/expired token', async () => {
  mockToken = 'expired-token';
  mockPost.mockResolvedValueOnce({ data: undefined, error: {}, response: new Response(null, { status: 400 }) });

  render(<AccountRecoveryPage />);
  fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

  await waitFor(() => expect(screen.getByTestId('account-recovery-form')).toBeInTheDocument());
});
