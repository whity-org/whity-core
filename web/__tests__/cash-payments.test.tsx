/**
 * WHIT-1153: the operator records a cash payment for a workspace's paid
 * plugin plan, and voids one only with a reason.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CashPayments } from '@/app/(protected)/admin/billing/cash-payments';
import { api } from '@/lib/api/client';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn() },
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

const mockGet = api.GET as jest.Mock;
const mockPost = api.POST as jest.Mock;

const PAYMENT = {
  id: 9, tenant_id: 5, plan_key: 'plugin.salons.monthly', quantity: 3, amount_minor: 45000, currency: 'JOD',
  receipt_number: 'R-001', note: null, paid_on: '2026-11-16', period_start: '2026-11-16 00:00:00',
  period_end: '2026-12-16 00:00:00', recorded_by: 'Amro Saleh', recorded_at: '2026-11-16 10:00:00', voided_at: null, void_reason: null,
};

describe('CashPayments', () => {
  beforeEach(() => {
    mockGet.mockResolvedValue({ data: { data: [PAYMENT] } });
    mockPost.mockResolvedValue({ data: { data: PAYMENT } });
  });

  it('records a payment for the workspace and plan chosen', async () => {
    const user = userEvent.setup();
    render(<CashPayments addonPlanKeys={['team', 'plugin.salons.monthly']} />);

    await user.type(screen.getByRole('textbox', { name: 'Workspace number' }), '5');
    await user.clear(screen.getByRole('textbox', { name: 'Chairs or seats' }));
    await user.type(screen.getByRole('textbox', { name: 'Chairs or seats' }), '3');
    await user.type(screen.getByRole('textbox', { name: 'Amount paid' }), '45.000');
    await user.type(screen.getByRole('textbox', { name: 'Receipt number' }), 'R-002');
    await user.click(screen.getByRole('button', { name: 'Record cash payment' }));

    await waitFor(() =>
      expect(mockPost).toHaveBeenCalledWith('/api/v1/billing/cash-payments', {
        body: expect.objectContaining({ workspace: 5, planKey: 'plugin.salons.monthly', quantity: 3, amount: '45.000', currency: 'JOD', receiptNumber: 'R-002' }),
      }),
    );
    expect(await screen.findByText(/Receipt R-001/)).toBeInTheDocument();
  });

  it('voids only with a reason', async () => {
    const user = userEvent.setup();
    render(<CashPayments addonPlanKeys={['plugin.salons.monthly']} />);
    await user.type(screen.getByRole('textbox', { name: 'Workspace number' }), '5');
    await user.click(await screen.findByRole('button', { name: 'Void…' }));

    expect(screen.getByRole('button', { name: 'Void' })).toBeDisabled();
    await user.type(screen.getByRole('textbox', { name: 'Why is it voided?' }), 'Wrong workspace');
    await user.click(screen.getByRole('button', { name: 'Void' }));
    await waitFor(() =>
      expect(mockPost).toHaveBeenCalledWith('/api/v1/billing/cash-payments/{id}/void', { params: { path: { id: 9 } }, body: { reason: 'Wrong workspace' } }),
    );
  });

  it('shows nothing when no paid plugin plan exists', () => {
    const { container } = render(<CashPayments addonPlanKeys={['team']} />);
    expect(container).toBeEmptyDOMElement();
  });
});
