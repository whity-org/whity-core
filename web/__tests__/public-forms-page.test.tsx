/**
 * WHIT-906: the Public forms settings page. Issuing shows the key once, revoking
 * asks first, the closed state says so, and only an operator sees the bot check.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

let mockCaps: string[] = ['public_form_keys:manage'];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (c: string) => mockCaps.includes(c), loading: false }),
}));

let mockTenantId = 7;
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { tenant_id: mockTenantId } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string) => fallback,
}));

jest.mock('@/components/admin/admin-header', () => ({
  AdminHeader: ({ title, action }: { title: string; action?: React.ReactNode }) => (
    <div>
      <h1>{title}</h1>
      {action}
    </div>
  ),
}));

jest.mock('@/components/field-select', () => ({
  FieldSelect: ({
    id,
    value,
    options,
    onChange,
  }: {
    id: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (v: string) => void;
  }) => (
    <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="" />
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  ),
}));

import PublicFormsPage from '@/app/(protected)/admin/settings/public-forms/page';

const KEY = 'a'.repeat(64);

function json(body: unknown, status = 200): Response {
  return { ok: status < 400, status, json: async () => body } as unknown as Response;
}

const liveKey = {
  id: 3,
  plugin: 'arcline',
  form: 'enquiry',
  label: 'Example Bakery website',
  key_hint: 'abcd1234',
  allowed_origins: ['https://www.example-bakery.com'],
  created_at: '2026-11-05 10:00:00',
  revoked_at: null,
};

function route(keys: unknown[], ready = true, botSettings: unknown = null) {
  mockApiClient.mockImplementation(async (url: string, init?: RequestInit) => {
    if (url === '/api/v1/public-form-keys' && !init?.method) {
      return json({ data: keys, meta: { forms: [{ plugin: 'arcline', form: 'enquiry' }], bot_check_ready: ready } });
    }
    if (url === '/api/v1/public-form-keys' && init?.method === 'POST') {
      return json({ data: { ...liveKey, key: KEY } }, 201);
    }
    if (url === '/api/v1/public-form-keys/3/revoke') {
      return json({}, 204);
    }
    if (url === '/api/v1/settings/public-forms/bot-check') {
      return json({ data: botSettings });
    }
    throw new Error(`Unexpected call: ${url}`);
  });
}

describe('PublicFormsPage', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    addToast.mockReset();
    mockCaps = ['public_form_keys:manage'];
    mockTenantId = 7;
  });

  it('denies access without the permission', () => {
    mockCaps = [];
    route([]);
    render(<PublicFormsPage />);
    expect(screen.getByText(/You need permission to manage public form keys/)).toBeTruthy();
  });

  it('shows an empty workspace and lists a live key', async () => {
    route([]);
    const { unmount } = render(<PublicFormsPage />);
    await screen.findByTestId('public-forms-empty');
    unmount();

    route([liveKey]);
    render(<PublicFormsPage />);
    const card = await screen.findByTestId('public-form-key-3');
    expect(within(card).getByText('Example Bakery website')).toBeTruthy();
    expect(within(card).getByText('https://www.example-bakery.com')).toBeTruthy();
  });

  it('says forms are closed when the bot check is not ready', async () => {
    route([liveKey], false);
    render(<PublicFormsPage />);
    const alert = await screen.findByTestId('public-forms-closed');
    expect(alert.textContent).toMatch(/operator/);
  });

  it('shows the key once after issuing, then not again', async () => {
    route([]);
    render(<PublicFormsPage />);
    fireEvent.click(await screen.findByTestId('public-forms-issue'));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Main website' } });
    fireEvent.change(screen.getByLabelText('Sites that may send this form'), {
      target: { value: 'https://a.example.com\nhttps://b.example.com' },
    });
    fireEvent.click(screen.getByTestId('public-forms-issue-submit'));

    const issued = await screen.findByTestId('public-forms-issued');
    expect(within(issued).getByText(KEY)).toBeTruthy();
    expect(within(issued).getByText(`/api/v1/arcline/forms/enquiry/${KEY}`)).toBeTruthy();

    const post = mockApiClient.mock.calls.find(([, init]) => init?.method === 'POST');
    expect(JSON.parse(post?.[1].body)).toEqual({
      plugin: 'arcline',
      form: 'enquiry',
      label: 'Main website',
      allowed_origins: ['https://a.example.com', 'https://b.example.com'],
    });

    fireEvent.click(screen.getByTestId('public-forms-issued-done'));
    await waitFor(() => expect(screen.queryByText(KEY)).toBeNull());
  });

  it('asks before revoking, then revokes', async () => {
    route([liveKey]);
    render(<PublicFormsPage />);
    fireEvent.click(await screen.findByTestId('public-form-key-revoke-3'));
    expect(mockApiClient).not.toHaveBeenCalledWith('/api/v1/public-form-keys/3/revoke', expect.anything());

    const dialog = await screen.findByRole('alertdialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke key' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/public-form-keys/3/revoke', { method: 'POST' })
    );
  });

  it('keeps the bot check from anyone but an operator', async () => {
    route([liveKey]);
    render(<PublicFormsPage />);
    await screen.findByTestId('public-form-key-3');
    expect(screen.queryByTestId('public-forms-bot-check')).toBeNull();
    expect(mockApiClient).not.toHaveBeenCalledWith('/api/v1/settings/public-forms/bot-check');
  });

  it('never fills in the secret for an operator, and leaves it out of a save unless typed', async () => {
    mockCaps = ['public_form_keys:manage', 'settings:manage'];
    mockTenantId = 0;
    route([liveKey], true, {
      provider: 'turnstile',
      providers: ['turnstile'],
      site_key: 'site-key',
      has_secret: true,
      allow_without_bot_check: false,
      ready: true,
    });
    render(<PublicFormsPage />);
    const card = await screen.findByTestId('public-forms-bot-check');
    await within(card).findByDisplayValue('site-key');
    expect((within(card).getByLabelText('Secret key') as HTMLInputElement).value).toBe('');

    fireEvent.click(within(card).getByTestId('public-forms-bot-save'));

    await waitFor(() => {
      const put = mockApiClient.mock.calls.find(([, init]) => init?.method === 'PUT');
      expect(put).toBeTruthy();
      expect(JSON.parse(put?.[1].body)).toEqual({
        provider: 'turnstile',
        site_key: 'site-key',
        allow_without_bot_check: false,
      });
    });
  });
});
