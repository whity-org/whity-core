/**
 * New booking (WHIT-944): when the server answers 409 because the time was
 * just taken, the dialog says so in plain words, clears the chosen time,
 * fetches the free times again, and keeps what was typed about the customer.
 */

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { BookingDialog } from '@/components/calendar/booking-dialog';
import type { CalendarService, CalendarStaff } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const service: CalendarService = {
  id: 7,
  name: { en: 'Cake tasting', ar: 'تذوق الكعك' },
  duration_minutes: 30,
  buffer_before_minutes: 0,
  buffer_after_minutes: 0,
  price_minor: 12500,
  currency: 'JOD',
  bookable_online: true,
  active: true,
  sort_order: 0,
  staff_profile_ids: [11],
};

const staff: CalendarStaff[] = [{ profile_id: 11, display_name: 'Lina Saleh', bookable: true, sort_order: 0, feed_enabled: false }];

const slots = [
  { starts_at: '2026-11-14T06:00:00Z', ends_at: '2026-11-14T06:30:00Z', profile_ids: [11] },
  { starts_at: '2026-11-14T06:30:00Z', ends_at: '2026-11-14T07:00:00Z', profile_ids: [11] },
];

describe('BookingDialog', () => {
  beforeEach(() => mockApiClient.mockReset());

  it('says the time was taken, refreshes free times and keeps the customer', async () => {
    let availabilityCalls = 0;
    const posted: unknown[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url.startsWith('/api/v1/calendar/availability')) {
        availabilityCalls++;
        // After the conflict, 09:00 is gone.
        return respond(200, { data: availabilityCalls === 1 ? slots : slots.slice(1) });
      }
      if (url === '/api/v1/calendar/bookings' && init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)));
        return respond(409, { error: 'That time was just taken. Pick another time.' });
      }
      return respond(404, { error: 'unexpected' });
    });

    const onBooked = jest.fn();
    render(
      <BookingDialog
        open
        onOpenChange={() => {}}
        services={[service]}
        staff={staff}
        timeZone="Asia/Amman"
        initialDate={{ year: 2026, month: 11, day: 14 }}
        onBooked={onBooked}
      />
    );

    // Free times are shown on the workspace's clock: 06:00Z is 09:00 in Amman.
    const first = await screen.findByRole('button', { name: '09:00' });
    expect(availabilityCalls).toBe(1);
    expect(String(mockApiClient.mock.calls[0][0])).toContain('from=2026-11-14T00%3A00%3A00%2B03%3A00');
    fireEvent.click(first);
    expect(first).toHaveAttribute('aria-pressed', 'true');

    fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Rana Haddad' } });
    fireEvent.change(screen.getByLabelText(/Email/), { target: { value: 'rana@example-bakery.com' } });
    fireEvent.click(screen.getByTestId('booking-submit'));

    expect(await screen.findByTestId('booking-problem')).toHaveTextContent('That time was just taken. Pick another time.');
    // The request carried the workspace offset, and the free times were asked for again.
    expect(posted[0]).toMatchObject({ service_id: 7, starts_at: '2026-11-14T09:00:00+03:00', customer: { name: 'Rana Haddad' } });
    await waitFor(() => expect(availabilityCalls).toBe(2));
    await waitFor(() => expect(screen.queryByRole('button', { name: '09:00' })).toBeNull());
    const group = screen.getByRole('group', { name: 'Free times' });
    expect(within(group).getByRole('button', { name: '09:30' })).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByLabelText(/Name/)).toHaveValue('Rana Haddad');
    expect(onBooked).not.toHaveBeenCalled();
  });

  it('books and hands the booking back when the time is free', async () => {
    const booking = { id: 1, service_id: 7, profile_id: 11, customer: { name: 'Rana Haddad' }, starts_at: slots[0].starts_at, ends_at: slots[0].ends_at, status: 'confirmed', source: 'staff' };
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url.startsWith('/api/v1/calendar/availability')) return respond(200, { data: slots });
      if (url === '/api/v1/calendar/bookings' && init?.method === 'POST') return respond(201, { data: booking });
      return respond(404, {});
    });
    const onBooked = jest.fn();
    render(
      <BookingDialog
        open
        onOpenChange={() => {}}
        services={[service]}
        staff={staff}
        timeZone="Asia/Amman"
        initialDate={{ year: 2026, month: 11, day: 14 }}
        onBooked={onBooked}
      />
    );
    fireEvent.click(await screen.findByRole('button', { name: '09:00' }));
    fireEvent.change(screen.getByLabelText(/Name/), { target: { value: 'Rana Haddad' } });
    fireEvent.change(screen.getByLabelText(/Phone/), { target: { value: '+962790000000' } });
    fireEvent.click(screen.getByTestId('booking-submit'));
    await waitFor(() => expect(onBooked).toHaveBeenCalledWith(booking));
  });

  it('asks for a time and a way to reach the customer before posting', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url.startsWith('/api/v1/calendar/availability') ? respond(200, { data: slots }) : respond(404, {})
    );
    render(
      <BookingDialog
        open
        onOpenChange={() => {}}
        services={[service]}
        staff={staff}
        timeZone="Asia/Amman"
        initialDate={{ year: 2026, month: 11, day: 14 }}
        onBooked={() => {}}
      />
    );
    await screen.findByRole('button', { name: '09:00' });
    fireEvent.click(screen.getByTestId('booking-submit'));
    expect(await screen.findByText('Choose a free time.')).toBeInTheDocument();
    expect(screen.getByText('Enter the customer’s name.')).toBeInTheDocument();
    expect(screen.getByText('Enter an email or a phone number.')).toBeInTheDocument();
    expect(mockApiClient.mock.calls.every(([url]) => String(url).includes('availability'))).toBe(true);
  });
});
