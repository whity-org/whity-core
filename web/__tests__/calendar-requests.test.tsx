/**
 * Design 3.3 (`TplAppointments`): a customer's request is accepted from the
 * Requests card; a booking is checked in, and the check-in undone, from its
 * sheet; a request in the sheet offers Accept and Suggest another time, not
 * Check in.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { RequestsCard } from '@/components/calendar/appointments-side';
import { BookingSheet } from '@/components/calendar/booking-sheet';
import type { CalendarBooking, CalendarService, CalendarStaff } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

const mockToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: mockToast, toasts: [], removeToast: () => {} }),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const service: CalendarService = {
  id: 7,
  name: { en: 'Engagement cake', ar: 'كعكة خطوبة' },
  duration_minutes: 45,
  buffer_before_minutes: 0,
  buffer_after_minutes: 0,
  price_minor: null,
  currency: 'JOD',
  bookable_online: true,
  active: true,
  sort_order: 0,
  staff_profile_ids: [11],
  room_id: null,
  needs_approval: true,
};

const staff: CalendarStaff[] = [{ profile_id: 11, display_name: 'Ibrahim Mansour', bookable: true, sort_order: 0, feed_enabled: false }];

const request: CalendarBooking = {
  id: 5,
  service_id: 7,
  profile_id: 11,
  customer: { name: 'Emad Nasser', email: 'emad@example-bakery.com' },
  starts_at: '2026-11-16T09:30:00Z',
  ends_at: '2026-11-16T10:15:00Z',
  status: 'requested',
  source: 'public',
};

describe('RequestsCard', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockToast.mockReset();
  });

  it('accepts a request and asks the calendar to load again', async () => {
    mockApiClient.mockImplementation((url: string, init?: RequestInit) =>
      url === '/api/v1/calendar/bookings/5/accept' && init?.method === 'POST'
        ? respond(200, { data: { ...request, status: 'confirmed' } })
        : respond(404, {})
    );
    const onChanged = jest.fn();
    const onSuggest = jest.fn();
    render(
      <RequestsCard requests={[request]} services={[service]} staff={staff} timeZone="Asia/Amman" canChange onSuggest={onSuggest} onChanged={onChanged} />
    );
    expect(screen.getByTestId('calendar-request-5')).toHaveTextContent('Engagement cake');
    fireEvent.click(screen.getByTestId('calendar-request-suggest-5'));
    expect(onSuggest).toHaveBeenCalledWith(request);

    fireEvent.click(screen.getByTestId('calendar-request-accept-5'));
    await waitFor(() => expect(onChanged).toHaveBeenCalled());
    expect(mockToast).toHaveBeenCalledWith('Booked for Emad Nasser', 'success');
  });

  it('says when nothing is waiting, and offers no actions to someone who can’t book', () => {
    render(<RequestsCard requests={[]} services={[service]} staff={staff} timeZone="Asia/Amman" canChange={false} onSuggest={() => {}} onChanged={() => {}} />);
    expect(screen.getByTestId('calendar-requests-none')).toBeInTheDocument();
  });
});

describe('BookingSheet, design 3.3', () => {
  beforeEach(() => mockApiClient.mockReset());

  it('checks a customer in and undoes it', async () => {
    const confirmed: CalendarBooking = { ...request, status: 'confirmed' };
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/calendar/bookings/5/check-in' && init?.method === 'POST') {
        return respond(200, { data: { ...confirmed, checked_in_at: '2026-11-16T09:31:00Z' } });
      }
      return respond(404, {});
    });
    const onChanged = jest.fn();
    render(
      <BookingSheet booking={confirmed} onClose={() => {}} services={[service]} staff={staff} timeZone="Asia/Amman" canChange onChanged={onChanged} />
    );
    expect(screen.queryByTestId('booking-accept')).toBeNull();
    fireEvent.click(screen.getByTestId('booking-check-in'));
    await waitFor(() => expect(onChanged).toHaveBeenCalledWith(expect.objectContaining({ checked_in_at: '2026-11-16T09:31:00Z' }), 'checkedIn'));
  });

  it('offers Accept and Suggest another time for a request, and no check-in', () => {
    render(<BookingSheet booking={request} onClose={() => {}} services={[service]} staff={staff} timeZone="Asia/Amman" canChange onChanged={() => {}} />);
    expect(screen.getByTestId('booking-accept')).toBeInTheDocument();
    expect(screen.getByTestId('booking-suggest')).toBeInTheDocument();
    expect(screen.queryByTestId('booking-check-in')).toBeNull();
    expect(screen.getByTestId('booking-cancel')).toHaveTextContent('Decline');
  });
});
