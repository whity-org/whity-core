/**
 * A6 browser pass: a refused request (403), a failed one and an empty list are
 * three different things, and an operator with no plans can make one.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient }) }));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: () => true, hasPermission: () => true, loading: false }),
}));

import PromotionsPage from '@/app/(protected)/admin/promotions/page';
import BillingPage from '@/app/(protected)/admin/billing/page';
import ErrorsPage from '@/app/(protected)/admin/errors/page';

const ok = (data: unknown) => ({ ok: true, status: 200, json: async () => ({ data }) });
const refused = { ok: false, status: 403, json: async () => ({ error: 'Forbidden' }) };

beforeEach(() => jest.clearAllMocks());

describe('promotions', () => {
  it('says access is missing on a 403, with no failure text and no empty list', async () => {
    apiClient.mockResolvedValue(refused);
    render(<PromotionsPage />);
    expect(await screen.findByText('You don’t have access to promotions')).toBeInTheDocument();
    expect(screen.queryByText('No promotions yet')).toBeNull();
    expect(screen.queryByText('Failed to load promotions')).toBeNull();
  });

  it('offers Try again, not an empty list, when the load fails', async () => {
    apiClient.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });
    render(<PromotionsPage />);
    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
    expect(screen.queryByText('No promotions yet')).toBeNull();
  });
});

describe('errors inbox', () => {
  it('says access is missing on a 403 instead of "No unresolved errors"', async () => {
    global.fetch = jest.fn().mockResolvedValue(refused) as unknown as typeof fetch;
    render(<ErrorsPage />);
    expect(await screen.findByText('You don’t have access to the error inbox')).toBeInTheDocument();
    expect(screen.queryByText('No unresolved errors')).toBeNull();
  });
});

describe('plans and pricing', () => {
  it('says access is missing on a 403', async () => {
    apiClient.mockResolvedValue(refused);
    render(<BillingPage />);
    expect(await screen.findByText('You don’t have access to plans and pricing')).toBeInTheDocument();
    expect(screen.queryByTestId('new-plan')).toBeNull();
  });

  it('with no plans, New plan creates one (the key is made from the name)', async () => {
    apiClient.mockImplementation(async (url: string, init?: { method?: string }) => {
      if (url === '/api/v1/plans' && init?.method === 'POST') return { ok: true, status: 201, json: async () => ({ data: { id: 1 } }) };
      return ok([]);
    });
    render(<BillingPage />);
    await userEvent.click(await screen.findByTestId('new-plan'));
    await userEvent.type(await screen.findByTestId('plan-name'), 'Plus plan');
    await userEvent.click(screen.getByTestId('plan-save'));
    await waitFor(() =>
      expect(apiClient).toHaveBeenCalledWith(
        '/api/v1/plans',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ plan_key: 'plus_plan', name: 'Plus plan', description: null }),
        })
      )
    );
  });
});
