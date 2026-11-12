/**
 * The customer's manage page (WHIT-945): moving a booking gives it a new
 * link, so the address is replaced with the new token and later actions use
 * it; the old one stops working on the server.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { zonedDate, zonedInstant } from '@whity/features/datetime';

jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

import { addDays } from '@/lib/calendar/layout';
import { ManageBookingScreen } from '@/components/booking/manage-booking';

const AMMAN = 'Asia/Amman';
const OLD = 'a'.repeat(64);
const NEW = 'c'.repeat(64);

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const tomorrow = addDays(zonedDate(AMMAN), 1);
const at = (minutes: number) => zonedInstant(tomorrow, minutes, AMMAN).toISOString();

const booking = {
  id: 9,
  status: 'confirmed',
  starts_at: at(9 * 60),
  ends_at: at(9 * 60 + 30),
  service: { id: 1, name: { en: 'Cake tasting', ar: 'تذوق الكعك' }, duration_minutes: 30 },
  staff_name: 'Lina Saleh',
  price_minor: 12500,
  currency: 'JOD',
  customer: { name: 'Rana Haddad', email: 'rana@example-bakery.com' },
  workspace: { name: 'Example Bakery' },
  time_zone: AMMAN,
};

const fetchMock = jest.fn();

beforeEach(() => {
  fetchMock.mockReset();
  global.fetch = fetchMock as unknown as typeof fetch;
});

afterEach(() => {
  jest.restoreAllMocks();
});

describe('ManageBookingScreen', () => {
  it('moves the booking, replaces the address with the new link and uses it from then on', async () => {
    const calls: { url: string; method: string; body?: unknown }[] = [];
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      calls.push({ url, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : undefined });
      if (url === `/api/v1/public/bookings/${OLD}`) return respond(200, { data: booking });
      if (url.startsWith(`/api/v1/public/bookings/${OLD}/slots`)) {
        return respond(200, { data: [{ starts_at: at(9 * 60), ends_at: at(9 * 60 + 30) }, { starts_at: at(11 * 60), ends_at: at(11 * 60 + 30) }] });
      }
      if (url === `/api/v1/public/bookings/${OLD}/move`) {
        return respond(200, { data: { ...booking, starts_at: at(11 * 60), ends_at: at(11 * 60 + 30), workspace: undefined, time_zone: undefined, manage_token: NEW } });
      }
      if (url === `/api/v1/public/bookings/${NEW}/cancel`) return respond(200, { data: { ...booking, status: 'cancelled' } });
      return respond(404, { error: 'This link is invalid or has expired' });
    });
    const replaceState = jest.spyOn(window.history, 'replaceState');

    render(<ManageBookingScreen token={OLD} />);
    expect(await screen.findByTestId('manage-card')).toHaveTextContent('Your booking with Example Bakery');

    fireEvent.click(screen.getByTestId('manage-move'));
    // The booking's own time is not offered as a move.
    expect(await screen.findByRole('button', { name: '11:00' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: '09:00' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: '11:00' }));
    fireEvent.click(screen.getByTestId('move-submit'));

    expect(await screen.findByTestId('manage-notice')).toHaveTextContent('the old link no longer works');
    const move = calls.find((c) => c.url.endsWith('/move'));
    expect(move).toMatchObject({ method: 'POST', body: { starts_at: expect.stringMatching(/T11:00:00\+03:00$/) } });
    expect(replaceState).toHaveBeenCalledWith(null, '', `/booking/${NEW}`);
    expect(window.location.pathname).toBe(`/booking/${NEW}`);
    // The workspace's name and zone are kept though the move's answer leaves them out.
    expect(screen.getByTestId('manage-card')).toHaveTextContent('Your booking with Example Bakery');
    expect(screen.getByTestId('manage-ics')).toHaveAttribute('href', `/api/v1/public/bookings/${NEW}/ics`);

    // Cancel now goes to the new link.
    fireEvent.click(screen.getByTestId('manage-cancel'));
    fireEvent.click(await screen.findByTestId('manage-cancel-confirm'));
    await waitFor(() => expect(calls.some((c) => c.url === `/api/v1/public/bookings/${NEW}/cancel`)).toBe(true));
    expect(await screen.findByTestId('booking-status')).toHaveTextContent('Cancelled');
    expect(screen.queryByTestId('manage-move')).toBeNull();
  });

  it('says a link that is not one of ours is invalid, without asking the server', async () => {
    render(<ManageBookingScreen token="not-a-link" />);
    expect(await screen.findByTestId('manage-invalid')).toHaveTextContent('This link is invalid or has expired');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('says an expired link is invalid', async () => {
    fetchMock.mockImplementation(() => respond(404, { error: 'This link is invalid or has expired' }));
    render(<ManageBookingScreen token={OLD} />);
    expect(await screen.findByTestId('manage-invalid')).toBeInTheDocument();
  });
  it('takes the time the business suggested for a request and switches to the new link (design 3.3)', async () => {
    const suggested = { ...booking, status: 'requested', suggested: true };
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === `/api/v1/public/bookings/${OLD}`) return respond(200, { data: suggested });
      if (url === `/api/v1/public/bookings/${OLD}/accept` && init?.method === 'POST') {
        return respond(200, { data: { ...booking, workspace: undefined, time_zone: undefined, manage_token: NEW } });
      }
      return respond(404, { error: 'This link is invalid or has expired' });
    });
    const replaceState = jest.spyOn(window.history, 'replaceState');

    render(<ManageBookingScreen token={OLD} />);
    expect(await screen.findByTestId('manage-card')).toHaveTextContent('suggests this one');
    fireEvent.click(screen.getByTestId('manage-take'));

    expect(await screen.findByTestId('manage-notice')).toHaveTextContent('Booked.');
    expect(replaceState).toHaveBeenCalledWith(null, '', `/booking/${NEW}`);
    expect(screen.queryByTestId('manage-take')).toBeNull();
  });

  it('shows a request that is still waiting without Take this time', async () => {
    fetchMock.mockImplementation((url: string) =>
      url === `/api/v1/public/bookings/${OLD}` ? respond(200, { data: { ...booking, status: 'requested', suggested: false } }) : respond(404, {})
    );
    render(<ManageBookingScreen token={OLD} />);
    expect(await screen.findByTestId('manage-card')).toHaveTextContent('waiting for an answer');
    expect(screen.queryByTestId('manage-take')).toBeNull();
    expect(screen.getByTestId('manage-cancel')).toBeInTheDocument();
  });
});
