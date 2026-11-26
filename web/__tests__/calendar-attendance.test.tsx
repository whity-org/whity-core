/**
 * Did they come (WHIT-1427, design section 39 §4): the card lists bookings
 * nobody has marked and marks them with one press; the sheet offers Didn't
 * come once a booking has started and shows the customer's flag in words;
 * cancelling asks whose cancel it is and sends it.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { DidTheyComeCard } from '@/components/calendar/appointments-side';
import { BookingSheet } from '@/components/calendar/booking-sheet';
import type { CalendarBooking, CalendarService, CalendarStaff } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () =>
  jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient)
);

const mockToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockToast, toasts: [], removeToast: () => {} }),
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

const staff: CalendarStaff[] = [{ profile_id: 11, display_name: 'Amro Saleh', bookable: true, sort_order: 0, feed_enabled: false }];

const started: CalendarBooking = {
  id: 9,
  service_id: 7,
  profile_id: 11,
  customer: { name: 'Rana Haddad', email: 'rana@example.com' },
  starts_at: '2026-01-01T07:00:00Z',
  ends_at: '2026-01-01T07:30:00Z',
  status: 'confirmed',
  source: 'staff',
  attendance: null,
  customer_flag: { misses: 2, of: 5 },
} as CalendarBooking;

describe('Did they come', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockToast.mockReset();
  });

  it('lists the unmarked bookings with the flag and marks one with a press', async () => {
    const posted: unknown[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url.startsWith('/api/v1/calendar/attendance/unmarked')) return respond(200, { data: [started] });
      if (url === '/api/v1/calendar/bookings/9/attendance' && init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)));
        return respond(200, { data: { ...started, attendance: 'no_show' } });
      }
      return respond(404, { error: 'unexpected' });
    });
    const onChanged = jest.fn();
    render(<DidTheyComeCard unitId={null} reloadKey={0} timeZone="UTC" canChange onChanged={onChanged} />);

    expect(await screen.findByTestId('calendar-did-they-come')).toHaveTextContent('Rana Haddad');
    expect(screen.getByTestId('calendar-unmarked-9')).toHaveTextContent('Missed 2 of the last 5');
    fireEvent.click(screen.getByTestId('calendar-unmarked-no-show-9'));
    await waitFor(() => expect(posted).toEqual([{ attendance: 'no_show' }]));
    expect(onChanged).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByTestId('calendar-did-they-come')).toBeNull());
  });

  it('shows nothing when everyone is marked', async () => {
    mockApiClient.mockImplementation(() => respond(200, { data: [] }));
    const { container } = render(<DidTheyComeCard unitId={null} reloadKey={0} timeZone="UTC" canChange onChanged={() => {}} />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('offers Didn’t come on a started booking, shows the flag, and asks whose cancel it is', async () => {
    const calls: { url: string; body: unknown }[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined });
      if (url === '/api/v1/calendar/bookings/9/attendance') return respond(200, { data: { ...started, attendance: 'no_show', attendance_at: '2026-01-01T07:20:00Z' } });
      if (url === '/api/v1/calendar/bookings/9/cancel') return respond(200, { data: { ...started, status: 'cancelled', cancel_kind: 'business' } });
      return respond(200, { data: started });
    });
    const onChanged = jest.fn();
    render(<BookingSheet booking={started} onClose={() => {}} services={[service]} staff={staff} timeZone="UTC" canChange onChanged={onChanged} />);

    expect(await screen.findByTestId('booking-customer-flag')).toHaveTextContent('Missed 2 of the last 5');
    fireEvent.click(screen.getByTestId('booking-no-show'));
    await waitFor(() => expect(onChanged).toHaveBeenCalledWith(expect.objectContaining({ attendance: 'no_show' }), 'noShowMarked'));

    fireEvent.click(screen.getByTestId('booking-cancel'));
    expect(await screen.findByTestId('booking-cancel-whose')).toHaveTextContent('The customer asked');
    fireEvent.click(screen.getByTestId('booking-cancel-business'));
    expect(screen.getByTestId('booking-cancel-whose')).toHaveTextContent('Never counted against the customer.');
    fireEvent.click(screen.getByTestId('booking-cancel-confirm'));
    await waitFor(() => expect(calls.find((c) => c.url === '/api/v1/calendar/bookings/9/cancel')?.body).toEqual({ cancelled_for: 'business' }));
  });

  it('never offers Didn’t come before the start', async () => {
    mockApiClient.mockImplementation(() => respond(200, { data: started }));
    const later = { ...started, starts_at: '2099-01-01T07:00:00Z', ends_at: '2099-01-01T07:30:00Z' } as CalendarBooking;
    render(<BookingSheet booking={later} onClose={() => {}} services={[service]} staff={staff} timeZone="UTC" canChange onChanged={() => {}} />);
    expect(await screen.findByTestId('booking-check-in')).toBeInTheDocument();
    expect(screen.queryByTestId('booking-no-show')).toBeNull();
  });
});
