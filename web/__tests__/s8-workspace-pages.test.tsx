/**
 * S8: what the workspace server added, as people see it. Tenants carry plan and
 * status, change plan asks first and says when it takes effect, and a maintenance
 * window shows without blocking anyone.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams('token=abc'),
}));

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient }) }));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

import TenantsPage from '@/app/(protected)/admin/tenants/page';
import { ChangePlan } from '@/app/(protected)/billing/change-plan';
import { MaintenanceNotice } from '@/components/shell/maintenance-notice';

const ok = (data: unknown) => ({ ok: true, status: 200, json: async () => ({ data }) });

const tenant = (over: object) => ({
  id: 7,
  name: 'Example Bakery',
  slug: 'example-bakery',
  userCount: 3,
  createdAt: '2026-01-01T00:00:00Z',
  plan: null,
  status: { state: 'active', daysLeft: null },
  ...over,
});

const plan = (plan_key: string, name: string, unit_amount: number, over: object = {}) => ({
  plan_key,
  name,
  description: null,
  is_addon: false,
  is_current: false,
  unit_amount,
  currency: 'JOD',
  billing_period: 'month',
  ...over,
});

const PLANS = [
  plan('small', 'Small', 10000, { is_current: true }),
  plan('large', 'Large', 49000),
  plan('tiny', 'Tiny', 5000),
  plan('extra', 'Extra', 1000, { is_addon: true }),
];

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
  Object.defineProperty(globalThis, 'crypto', {
    value: { randomUUID: () => 'key-12345678' },
    configurable: true,
  });
});

describe('tenants list', () => {
  it.each([
    [{ state: 'active', daysLeft: null }, 'Active'],
    [{ state: 'trial', daysLeft: 5 }, 'Trial, 5 days left'],
    [{ state: 'trial', daysLeft: null }, 'Trial'],
    [{ state: 'overdue', daysLeft: null }, 'Payment overdue'],
    [{ state: 'suspended', daysLeft: null }, 'Suspended'],
  ])('reads status %j as %s', async (status, words) => {
    apiClient.mockResolvedValue(ok([tenant({ status })]));
    render(<TenantsPage />);
    expect(await screen.findByText(words)).toBeInTheDocument();
  });

  it('names the plan, and says when there is none', async () => {
    apiClient.mockResolvedValue(
      ok([
        tenant({ plan: { key: 'large', name: 'Large' } }),
        tenant({ id: 8, name: 'Example Clinic', slug: 'example-clinic' }),
      ])
    );
    render(<TenantsPage />);
    expect(await screen.findByText('Large')).toBeInTheDocument();
    expect(screen.getByText('No plan')).toBeInTheDocument();
  });
});

describe('change plan', () => {
  const withPlans = (changeResponse: unknown = ok({ plan_key: 'large', takes_effect: 'now' })) =>
    apiClient.mockImplementation(async (url: string) =>
      url.includes('/change-plan') ? changeResponse : ok(PLANS)
    );

  it('offers the other tiers, not the current plan or an add-on', async () => {
    withPlans();
    render(<ChangePlan onChanged={jest.fn()} />);
    expect(await screen.findByTestId('change-plan-large')).toBeInTheDocument();
    expect(screen.getByTestId('change-plan-tiny')).toBeInTheDocument();
    expect(screen.queryByTestId('change-plan-small')).toBeNull();
    expect(screen.queryByTestId('change-plan-extra')).toBeNull();
  });

  it('says a dearer plan starts now, and changes nothing until confirmed', async () => {
    withPlans();
    const onChanged = jest.fn();
    render(<ChangePlan onChanged={onChanged} />);
    await userEvent.click(await screen.findByTestId('change-plan-large'));

    expect(await screen.findByText(/This starts now/)).toBeInTheDocument();
    expect(apiClient).not.toHaveBeenCalledWith('/api/v1/billing/change-plan', expect.anything());

    await userEvent.click(screen.getByRole('button', { name: 'Change plan' }));
    await waitFor(() => expect(onChanged).toHaveBeenCalled());

    const [, init] = apiClient.mock.calls.find(([url]: [string]) => url.includes('/change-plan'));
    expect(JSON.parse(init.body)).toEqual({
      plan_key: 'large',
      billing_period: 'month',
      idempotency_key: 'key-12345678',
    });
    expect(addToast).toHaveBeenCalledWith('You’re now on Large.', 'success');
  });

  it('says a cheaper plan waits for renewal', async () => {
    withPlans(ok({ plan_key: 'tiny', takes_effect: 'renewal' }));
    render(<ChangePlan onChanged={jest.fn()} />);
    await userEvent.click(await screen.findByTestId('change-plan-tiny'));
    expect(await screen.findByText(/This starts at your next renewal/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Change plan' }));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('You’ll move to Tiny at your next renewal.', 'success')
    );
  });

  it('says so when it fails, and does not report a change', async () => {
    withPlans({ ok: false, status: 500, json: async () => ({}) });
    const onChanged = jest.fn();
    render(<ChangePlan onChanged={onChanged} />);
    await userEvent.click(await screen.findByTestId('change-plan-large'));
    await userEvent.click(await screen.findByRole('button', { name: 'Change plan' }));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('The plan was not changed. Please try again.', 'error')
    );
    expect(onChanged).not.toHaveBeenCalled();
  });

  it('shows the plans without the action to someone who cannot pay', async () => {
    hasPermission.mockReturnValue(false);
    withPlans();
    render(<ChangePlan onChanged={jest.fn()} />);
    expect(await screen.findByText('Large')).toBeInTheDocument();
    expect(screen.queryByTestId('change-plan-large')).toBeNull();
    expect(screen.getByText(/Only someone who can pay/)).toBeInTheDocument();
  });

  it('falls back to the support line when there is nothing to move to', async () => {
    apiClient.mockResolvedValue(ok([PLANS[0]]));
    render(<ChangePlan onChanged={jest.fn()} />);
    expect(await screen.findByText(/contact support/)).toBeInTheDocument();
  });
});

describe('maintenance notice', () => {
  const realFetch = global.fetch;
  afterEach(() => {
    global.fetch = realFetch;
  });
  const status = (body: unknown, isOk = true) => {
    global.fetch = jest.fn(async () => ({ ok: isOk, json: async () => body })) as unknown as typeof fetch;
  };

  it('shows while an operator has announced it', async () => {
    status({ maintenance: { active: true, until: null } });
    render(<MaintenanceNotice />);
    expect(await screen.findByTestId('maintenance-notice')).toHaveTextContent(
      'We’re updating the service. Your data is safe.'
    );
    expect(screen.getByRole('link', { name: 'See status' })).toHaveAttribute('href', '/status');
  });

  it('shows nothing otherwise, and nothing when the status cannot be read', async () => {
    status({ maintenance: { active: false, until: null } });
    const { unmount } = render(<MaintenanceNotice />);
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByTestId('maintenance-notice')).toBeNull();
    unmount();

    status({}, false);
    render(<MaintenanceNotice />);
    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByTestId('maintenance-notice')).toBeNull();
  });
});
