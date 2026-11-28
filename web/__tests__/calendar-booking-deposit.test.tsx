/**
 * A booking's deposit (WHIT-1445, AP.26): shown in the booking's currency
 * with its decimals; Refund asks first and only then calls the server; when
 * the provider can't refund from Whity, it offers Mark as refunded; someone
 * without calendar:manage sees no Refund.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { DepositSection } from '@/components/calendar/booking-deposit';
import type { CalendarBooking } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () =>
  jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient)
);
const mockToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockToast, toasts: [], removeToast: () => {} }),
}));
let mockCanManage = true;
jest.mock('@/components/calendar/use-calendar-basics', () => ({
  useCalendarAccess: () => ({ canManage: mockCanManage }),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const booking = {
  id: 9,
  customer: { name: 'Lina Haddad' },
  status: 'cancelled',
  currency: 'JOD',
  deposit: { amount_minor: 3125, currency: 'JOD', state: 'to_refund', paid_at: '2026-11-15T04:00:00Z', refunded_at: null, refunded_by: null, refund_ref: null },
} as unknown as CalendarBooking;

describe('Deposit on a booking', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockToast.mockReset();
    mockCanManage = true;
  });

  it('shows the amount in the currency’s own decimals, and refunds only after asking', async () => {
    const sent: unknown[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      sent.push([url, JSON.parse(String(init?.body ?? 'null'))]);
      return respond(200, { data: { ...booking, deposit: { ...booking.deposit, state: 'refunded' } } });
    });
    const changed = jest.fn();
    render(<DepositSection booking={booking} timeZone="UTC" onChanged={changed} />);
    expect(screen.getByTestId('booking-deposit')).toHaveTextContent('JOD');
    expect(screen.getByTestId('booking-deposit')).toHaveTextContent('3.125');
    expect(screen.getByTestId('booking-deposit-state')).toHaveTextContent('To refund');

    fireEvent.click(screen.getByTestId('deposit-refund'));
    expect(sent).toEqual([]);
    fireEvent.click(await screen.findByRole('button', { name: 'Refund' }));
    await waitFor(() => expect(sent).toEqual([['/api/v1/calendar/bookings/9/deposit/refund', { outside: false }]]));
    await waitFor(() => expect(changed).toHaveBeenCalled());
  });

  it('offers Mark as refunded when the provider can’t refund from here', async () => {
    const sent: unknown[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      const body = JSON.parse(String(init?.body ?? 'null'));
      sent.push(body);
      return body?.outside ? respond(200, { data: booking }) : respond(409, { error: 'Refund it in its own dashboard.', code: 'refund_outside' });
    });
    render(<DepositSection booking={booking} timeZone="UTC" onChanged={jest.fn()} />);
    fireEvent.click(screen.getByTestId('deposit-refund'));
    fireEvent.click(await screen.findByRole('button', { name: 'Refund' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Mark as refunded' }));
    await waitFor(() => expect(sent).toEqual([{ outside: false }, { outside: true }]));
  });

  it('has no Refund without calendar:manage, and nothing without a deposit', () => {
    mockCanManage = false;
    const { rerender, container } = render(<DepositSection booking={booking} timeZone="UTC" onChanged={jest.fn()} />);
    expect(screen.queryByTestId('deposit-refund')).toBeNull();
    rerender(<DepositSection booking={{ ...booking, deposit: null } as unknown as CalendarBooking} timeZone="UTC" onChanged={jest.fn()} />);
    expect(container.textContent).toBe('');
  });
});
