/**
 * WHIT-901: the OAuth side of the Email settings page. Secrets stay write-only, a
 * blank field keeps what is stored, and every connection state reads in plain words.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

let mockGlobal: Record<string, string> = {};
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: async () => ({ data: { data: { global: mockGlobal, registry: [] } } }),
    PATCH: async () => ({ data: {} }),
  },
}));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: () => true, loading: false }),
}));

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { tenant_id: 0, email: 'owner@example-bakery.test' } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import EmailSettingsPage from '@/app/(protected)/admin/settings/email/page';

type Oauth = { has_client_secret: boolean; has_refresh_token: boolean; state: string };

function respond(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as unknown as Response;
}

function stubStatus(oauth: Oauth) {
  mockApiClient.mockImplementation(async (url: string) => {
    if (url === '/api/v1/settings/mail/status') {
      return respond({ data: { has_smtp_password: false, auth_mode: 'oauth2', oauth } });
    }
    return respond({}, 204);
  });
}

function credentialCalls() {
  return mockApiClient.mock.calls.filter(([url]) => url === '/api/v1/settings/mail/oauth-credentials');
}

describe('Email settings: OAuth', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    addToast.mockReset();
    mockGlobal = { 'mail.transport': 'smtp', 'mail.smtp.auth_mode': 'oauth2', 'mail.oauth.provider': 'google' };
  });

  it('keeps the password field for an install on password sign-in', async () => {
    mockGlobal = { 'mail.transport': 'smtp', 'mail.smtp.auth_mode': 'password' };
    stubStatus({ has_client_secret: false, has_refresh_token: false, state: 'not_connected' });

    render(<EmailSettingsPage />);

    expect(await screen.findByTestId('email-password-field')).toBeInTheDocument();
    expect(screen.queryByTestId('email-oauth-fields')).not.toBeInTheDocument();
  });

  it('replaces the password with the OAuth fields', async () => {
    stubStatus({ has_client_secret: false, has_refresh_token: false, state: 'not_connected' });

    render(<EmailSettingsPage />);

    expect(await screen.findByTestId('email-oauth-fields')).toBeInTheDocument();
    expect(screen.queryByTestId('email-password-field')).not.toBeInTheDocument();
    expect(screen.queryByTestId('email-oauth-disconnect')).not.toBeInTheDocument();
  });

  it('says in plain words when the account needs reconnecting', async () => {
    stubStatus({ has_client_secret: true, has_refresh_token: true, state: 'needs_reconnect' });

    render(<EmailSettingsPage />);

    const state = await screen.findByTestId('email-oauth-state');
    await waitFor(() => expect(state).toHaveAttribute('data-state', 'needs_reconnect'));
    expect(state).toHaveTextContent('revoked or has expired');
    expect(state).toHaveAttribute('data-variant', 'destructive');
  });

  it.each([
    ['connected', 'success'],
    ['not_yet_used', 'info'],
    ['token_service_unavailable', 'warning'],
    ['client_rejected', 'destructive'],
    ['smtp_rejected', 'destructive'],
    ['credentials_unreadable', 'destructive'],
    ['smtp_host_not_allowed', 'destructive'],
    ['insecure_connection', 'destructive'],
  ])('shows %s as %s', async (oauthState, variant) => {
    stubStatus({ has_client_secret: true, has_refresh_token: true, state: oauthState });

    render(<EmailSettingsPage />);

    const state = await screen.findByTestId('email-oauth-state');
    await waitFor(() => expect(state).toHaveAttribute('data-state', oauthState));
    expect(state).toHaveAttribute('data-variant', variant);
  });

  it('sends only the secret that was typed and clears the box afterwards', async () => {
    stubStatus({ has_client_secret: true, has_refresh_token: true, state: 'connected' });
    render(<EmailSettingsPage />);
    await screen.findByTestId('email-oauth-fields');

    const refresh = screen.getByLabelText('Refresh token') as HTMLInputElement;
    expect(refresh.type).toBe('password');
    fireEvent.change(refresh, { target: { value: 'a-new-refresh-token' } });
    fireEvent.click(screen.getByTestId('email-oauth-save'));

    await waitFor(() => expect(credentialCalls()).toHaveLength(1));
    const init = credentialCalls()[0][1] as RequestInit;
    expect(init.method).toBe('PUT');
    expect(JSON.parse(init.body as string)).toEqual({ refresh_token: 'a-new-refresh-token' });
    await waitFor(() => expect(refresh.value).toBe(''));
  });

  it('never sends a blank save', async () => {
    stubStatus({ has_client_secret: true, has_refresh_token: true, state: 'connected' });
    render(<EmailSettingsPage />);
    await screen.findByTestId('email-oauth-fields');

    expect(screen.getByTestId('email-oauth-save')).toBeDisabled();
    expect(credentialCalls()).toHaveLength(0);
  });

  it('asks before disconnecting, naming the consequence', async () => {
    stubStatus({ has_client_secret: true, has_refresh_token: true, state: 'connected' });
    render(<EmailSettingsPage />);
    fireEvent.click(await screen.findByTestId('email-oauth-disconnect'));

    expect(await screen.findByText('Disconnect this mail account?')).toBeInTheDocument();
    expect(credentialCalls()).toHaveLength(0);
    expect(screen.getByText(/Email stops going out/)).toBeInTheDocument();
  });
});
