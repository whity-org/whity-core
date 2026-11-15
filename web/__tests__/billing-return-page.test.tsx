/**
 * Where the billing service sends people after paying (TplPaymentReturn,
 * WHIT-1044): one card with one answer, and never "error".
 */

import React from 'react';
import { render, screen } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'checkout' ? 'cs_1' : null) }),
}));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ date: (value: string | null) => (value ? '14 Dec 2026' : null) }),
}));

const mockApiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  apiFetch: (...args: unknown[]) => mockApiFetch(...args),
}));

import BillingReturnPage from '@/app/(protected)/billing/return/page';

function answer(status: number, data?: Record<string, unknown>) {
  mockApiFetch.mockResolvedValue({ ok: status === 200, status, json: async () => ({ data }) });
}

const base = { status: null, plan: null, access_until: null, cancel_at_period_end: false, checkout_status: null };

beforeEach(() => mockApiFetch.mockReset());

test('an active subscription names the plan and its renewal, with Go to billing as the main button', async () => {
  answer(200, { ...base, has_access: true, status: 'active', plan: 'Team', access_until: '2026-12-14T00:00:00Z' });
  render(<BillingReturnPage />);

  const card = await screen.findByTestId('billing-return-active');
  expect(card).toHaveTextContent('Your subscription is active');
  expect(card).toHaveTextContent('Thanks. You’re on Team from today.');
  expect(card).toHaveTextContent('Renews on14 Dec 2026');
  expect(screen.getByRole('link', { name: 'Go to billing' })).toHaveAttribute('href', '/billing');
  expect(mockApiFetch).toHaveBeenCalledWith('/api/v1/billing/return?checkout=cs_1');
});

test('a checkout the billing service says ended unpaid says the plan did not change, and offers Try again', async () => {
  answer(200, { ...base, has_access: false, checkout_status: 'failed' });
  render(<BillingReturnPage />);

  const card = await screen.findByTestId('billing-return-failed');
  expect(card).toHaveTextContent('The payment didn’t go through');
  expect(card).toHaveTextContent('Nothing has changed on your plan.');
  expect(card).not.toHaveTextContent(/error/i);
});

test('no access yet, or no answer from the billing service, is "not confirmed yet", never a failure', async () => {
  answer(200, { ...base, has_access: false, checkout_status: 'open' });
  const { unmount } = render(<BillingReturnPage />);
  expect(await screen.findByTestId('billing-return-not-active')).toHaveTextContent('Not confirmed yet');
  unmount();

  answer(502);
  render(<BillingReturnPage />);
  expect(await screen.findByTestId('billing-return-unknown')).toHaveTextContent('Not confirmed yet');
});
