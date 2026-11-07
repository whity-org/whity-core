/**
 * WHIT-916: Settings → Sign-up for a workspace. Server refusals come back as
 * codes and are shown in the reader's words, a workspace with no slug is told
 * why its sign-up page can't be reached, rejected requests can be found and
 * approved later, and Reject asks first.
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (c: string) => c === 'signup:manage', loading: false }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

// Marks every translated string, so a test can tell the page's own words from
// the server's English fallback.
jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, values?: Record<string, string>) =>
      '[t] ' + fallback.replace(/\{(\w+)\}/g, (_m: string, name: string) => values?.[name] ?? ''),
}));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v }),
}));

jest.mock('@/components/admin/admin-header', () => ({
  AdminHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

import CustomerSignupSettingsPage from '@/app/(protected)/admin/settings/customer-signup/page';

function json(body: unknown, status = 200): Response {
  return { ok: status < 400, status, json: async () => body } as unknown as Response;
}

function settings(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      mode: 'off',
      role_id: 5,
      role_name: 'Customers',
      role_customer_safe: true,
      allowed_domains: [],
      eligible_roles: [{ id: 5, name: 'Customers', description: '', global: false }],
      pending_count: 0,
      workspace_slug: 'example-bakery',
      bot_check_ready: true,
      updated_at: null,
      ...overrides,
    },
  };
}

const REQUEST = {
  id: 9,
  email: 'layla@customer.example',
  display_name: 'Layla Haddad',
  status: 'pending',
  requested_at: '2026-11-06 10:00:00',
  verified_at: '2026-11-06 10:05:00',
  decided_at: null,
  decided_by_name: null,
};

/** Route the page's calls by URL and method. */
function routes(handlers: Record<string, (init?: RequestInit) => Response>) {
  mockApiClient.mockImplementation(async (url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url}`;
    const match = Object.keys(handlers).find((k) => key.startsWith(k));
    if (!match) throw new Error('unexpected call ' + key);
    return handlers[match](init);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

test('a bad domain is explained in the page’s own words, naming the line', async () => {
  routes({
    'GET /api/v1/signup/settings': () => json(settings()),
    'GET /api/v1/signup/requests': () => json({ data: [], pagination: {} }),
    'PUT /api/v1/signup/settings': () =>
      json(
        {
          error: 'Validation failed',
          details: { allowed_domains: 'Enter domain names such as example.com.', code: 'domain_invalid', value: 'not a domain' },
        },
        422
      ),
  });

  render(<CustomerSignupSettingsPage />);
  const domains = await screen.findByLabelText('[t] Allowed email domains');
  fireEvent.change(domains, { target: { value: 'not a domain' } });
  fireEvent.click(await screen.findByRole('button', { name: '[t] Save changes' }));

  expect(await screen.findByText(/^\[t\] “not a domain” isn’t a domain name/)).toBeInTheDocument();
  expect(screen.queryByText('Enter domain names such as example.com.')).not.toBeInTheDocument();
});

test('choosing Open with no bot check on the instance warns, with a link for the operator (WHIT-917)', async () => {
  routes({
    'GET /api/v1/signup/settings': () => json(settings({ email_signup_ready: false, join_providers: [] })),
    'GET /api/v1/signup/requests': () => json({ data: [], pagination: {} }),
  });

  render(<CustomerSignupSettingsPage />);
  await screen.findByLabelText('[t] Allowed email domains');
  expect(screen.queryByTestId('customer-signup-no-bot-check')).not.toBeInTheDocument();

  fireEvent.click(screen.getByLabelText(/\[t\] Open/));

  const warning = await screen.findByTestId('customer-signup-no-bot-check');
  expect(warning).toHaveTextContent('won’t work until the operator sets up a bot check');
  expect(screen.getByTestId('customer-signup-bot-check-link').getAttribute('href')).toBe('/admin/settings/public-forms');
});

test('with a sign-in provider available, the warning says people can still join with it', async () => {
  routes({
    'GET /api/v1/signup/settings': () =>
      json(settings({ mode: 'open', email_signup_ready: false, join_providers: [{ provider_key: 'whity_id', display_name: 'Whity ID' }] })),
    'GET /api/v1/signup/requests': () => json({ data: [], pagination: {} }),
  });

  render(<CustomerSignupSettingsPage />);
  expect(await screen.findByTestId('customer-signup-no-bot-check')).toHaveTextContent('People can still join with Whity ID');
});

test('a workspace with no slug is told its sign-up page can’t be reached', async () => {
  routes({
    'GET /api/v1/signup/settings': () => json(settings({ workspace_slug: null })),
    'GET /api/v1/signup/requests': () => json({ data: [], pagination: {} }),
  });

  render(<CustomerSignupSettingsPage />);
  expect(await screen.findByTestId('customer-signup-no-slug')).toHaveTextContent('no web address yet');
  expect(screen.queryByTestId('customer-signup-link')).not.toBeInTheDocument();
});

test('Reject asks first, and a turned-away request can be found and approved later', async () => {
  const calls: string[] = [];
  routes({
    'GET /api/v1/signup/settings': () => json(settings({ mode: 'approval', pending_count: 1 })),
    'GET /api/v1/signup/requests?status=pending': () => json({ data: [REQUEST], pagination: {} }),
    'GET /api/v1/signup/requests?status=rejected': () =>
      json({ data: [{ ...REQUEST, status: 'rejected', decided_at: '2026-11-06 11:00:00', decided_by_name: 'Sami' }], pagination: {} }),
    'POST /api/v1/signup/requests/9/reject': () => {
      calls.push('reject');
      return json({ data: { id: 9, status: 'rejected' } });
    },
    'POST /api/v1/signup/requests/9/approve': () => {
      calls.push('approve');
      return json({ data: { id: 9, status: 'approved' } });
    },
  });

  render(<CustomerSignupSettingsPage />);

  fireEvent.click(await screen.findByTestId('customer-signup-reject-9'));
  expect(calls).toEqual([]);
  expect(await screen.findByText('[t] Turn Layla Haddad away?')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: '[t] Reject' }));
  await waitFor(() => expect(calls).toEqual(['reject']));

  fireEvent.click(screen.getByTestId('customer-signup-filter-rejected'));
  expect(await screen.findByText(/Turned away by Sami/)).toBeInTheDocument();
  expect(screen.queryByTestId('customer-signup-reject-9')).not.toBeInTheDocument();

  fireEvent.click(screen.getByTestId('customer-signup-approve-9'));
  await waitFor(() => expect(calls).toEqual(['reject', 'approve']));
});
