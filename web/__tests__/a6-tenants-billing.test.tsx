/**
 * A6: the tenants list searches on the server and deleting a workspace asks for
 * its name (tier 3); plan prices are typed in normal units and sent as minor
 * units; a promotion's fixed amount is the same.
 */

import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient }) }));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

import TenantsPage from '@/app/(protected)/admin/tenants/page';
import BillingPage from '@/app/(protected)/admin/billing/page';

const ok = (data: unknown) => ({ ok: true, status: 200, json: async () => ({ data }) });

const TENANTS = [
  { id: 7, name: 'Example Bakery', slug: 'example-bakery', userCount: 3, createdAt: '2026-01-01T00:00:00Z', plan: null, status: { state: 'active', daysLeft: null } },
];

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe('tenants list', () => {
  it('sends what is typed to the server as q, after a short pause', async () => {
    apiClient.mockResolvedValue(ok(TENANTS));
    render(<TenantsPage />);
    await screen.findByText('Example Bakery');
    expect(apiClient).toHaveBeenLastCalledWith('/api/v1/tenants?per_page=100');

    await userEvent.type(screen.getByRole('searchbox', { name: 'Search tenants' }), 'bak');
    await waitFor(() => expect(apiClient).toHaveBeenLastCalledWith('/api/v1/tenants?per_page=100&q=bak'));
  });

  it('says so when nothing matches, and offers to clear', async () => {
    apiClient.mockResolvedValueOnce(ok(TENANTS)).mockResolvedValue(ok([]));
    render(<TenantsPage />);
    await screen.findByText('Example Bakery');
    await userEvent.type(screen.getByRole('searchbox', { name: 'Search tenants' }), 'zzz');
    expect(await screen.findByText(/No workspaces match/)).toBeInTheDocument();
  });

  it('keeps Delete disabled until the workspace name is typed, then deletes', async () => {
    apiClient.mockImplementation(async (url: string, init?: { method?: string }) =>
      init?.method === 'DELETE' ? { ok: true, json: async () => ({}) } : ok(TENANTS)
    );
    render(<TenantsPage />);
    await screen.findByText('Example Bakery');
    await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }));

    const confirm = await screen.findByRole('button', { name: 'Delete Tenant' });
    expect(confirm).toBeDisabled();
    await userEvent.type(screen.getByLabelText('Type Example Bakery to confirm'), 'Example Bakery');
    expect(confirm).toBeEnabled();
    await act(async () => {
      await userEvent.click(confirm);
    });
    expect(apiClient).toHaveBeenCalledWith('/api/v1/tenants/7', { method: 'DELETE' });
  });
});

describe('plan prices', () => {
  it('shows an amount in normal units and sends minor units', async () => {
    apiClient.mockImplementation(async (url: string, init?: { method?: string }) => {
      if (url === '/api/v1/plans') return ok([{ id: 1, plan_key: 'plus', name: 'Plus', description: null, is_active: true }]);
      if (url === '/api/v1/plans/1/prices' && init?.method === 'POST') return ok({});
      if (url === '/api/v1/plans/1/prices')
        return ok([{ id: 9, plan_id: 1, currency: 'JOD', unit_amount: 49000, billing_period: 'month', is_per_seat: false, is_active: true }]);
      return ok([]);
    });
    render(<BillingPage />);
    expect(await screen.findByTestId('price-9')).toHaveTextContent('JOD 49.000');

    await userEvent.click(screen.getByTestId('add-price-1'));
    await userEvent.type(screen.getByTestId('price-amount'), '12.5');
    await userEvent.click(screen.getByTestId('price-save'));
    await waitFor(() =>
      expect(apiClient).toHaveBeenCalledWith(
        '/api/v1/plans/1/prices',
        expect.objectContaining({ method: 'POST', body: expect.stringContaining('"unit_amount":12500') })
      )
    );
    expect(JSON.parse((apiClient.mock.calls.find((c) => c[1]?.method === 'POST') as [string, { body: string }])[1].body).currency).toBe('JOD');
  });
});
