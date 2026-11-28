/**
 * Treatment plans (WHIT-1444, design section 39 §7.4, AP.22): the customer
 * record shows each plan's visits and progress; Book the next visit finds the
 * first free time and books it only when staff say so; Book all says which
 * visits could not be placed; a booking shows "Visit 2 of 3".
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { BookingPlanSection, TreatmentPlansCard } from '@/components/calendar/treatment-plans';
import type { CalendarBooking } from '@/lib/calendar/api';

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

const plan = {
  id: 5,
  title: 'Laser, 3 sessions',
  status: 'active',
  customer: { contact_id: 40, name: 'Lina Haddad', email: 'lina@example.com', phone: '' },
  staff: { profile_id: 11, name: 'Amro Saleh' },
  steps: [
    { id: 1, position: 0, service_id: 7, service_name: { en: 'Laser session' }, gap_min_days: 0, gap_max_days: 7, state: 'done', booking: { id: 90, starts_at: '2026-11-16T06:00:00Z', status: 'confirmed' } },
    { id: 2, position: 1, service_id: 7, service_name: { en: 'Laser session' }, gap_min_days: 14, gap_max_days: 21, state: 'to_book', booking: null },
    { id: 3, position: 2, service_id: 7, service_name: { en: 'Laser session' }, gap_min_days: 14, gap_max_days: 21, state: 'to_book', booking: null },
  ],
  progress: { done: 1, missed: 0, total: 3 },
  created_at: '2026-11-16T05:00:00Z',
};

describe('Treatment plans', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockToast.mockReset();
  });

  it('shows nothing to someone who may not see the calendar', async () => {
    mockApiClient.mockImplementation(() => respond(403, { error: 'No access' }));
    const { container } = render(<TreatmentPlansCard contactId={40} canChange />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container.textContent).toBe(''));
  });

  it('books the next visit only after staff confirm the time', async () => {
    const booked: unknown[] = [];
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url.startsWith('/api/v1/calendar/plans/5/next')) {
        return respond(200, { data: { step_id: 2, starts_at: '2026-11-30T07:00:00Z', window: { from: '2026-11-29T21:00:00Z', to: '2026-12-07T21:00:00Z' } } });
      }
      if (url === '/api/v1/calendar/plans/5/book-next' && init?.method === 'POST') {
        booked.push(JSON.parse(String(init.body)));
        return respond(201, { data: plan });
      }
      return respond(200, { data: [plan] });
    });
    render(<TreatmentPlansCard contactId={40} canChange />);
    expect(await screen.findByTestId('plan-progress-5')).toHaveTextContent('1 of 3 done');
    expect(screen.getByText('Done')).toBeInTheDocument();
    expect(screen.getByText('With Amro Saleh')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('plan-next-5'));
    await screen.findByTestId('plan-next-found-5');
    expect(booked).toEqual([]);
    fireEvent.click(screen.getByTestId('plan-next-book-5'));
    await waitFor(() => expect(booked).toEqual([{ starts_at: '2026-11-30T07:00:00Z' }]));
  });

  it('Book all says which visits could not be placed', async () => {
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/calendar/plans/5/book-all' && init?.method === 'POST') {
        return respond(200, { data: { plan, steps: [{ step_id: 2, booked: true, starts_at: '2026-11-30T07:00:00Z' }, { step_id: 3, booked: false, starts_at: null }] } });
      }
      return respond(200, { data: [plan] });
    });
    render(<TreatmentPlansCard contactId={40} canChange />);
    fireEvent.click(await screen.findByTestId('plan-book-all-5'));
    await waitFor(() => expect(mockToast).toHaveBeenCalledWith('1 visits booked. 1 could not be placed: book them from the calendar.', 'warning'));
  });

  it('a booking shows which visit of the plan it is', async () => {
    mockApiClient.mockImplementation(() =>
      respond(200, { data: { plan_id: 5, title: 'Laser, 3 sessions', step: 2, total: 3, done: 1, status: 'active', next_to_book: true } })
    );
    const booking = { id: 91, service_id: 7, profile_id: 11, contact_id: 40, staff_name: 'Amro Saleh', customer: { name: 'Lina Haddad' }, starts_at: '2026-11-30T07:00:00Z', ends_at: '2026-11-30T08:00:00Z', status: 'confirmed', source: 'staff' } as unknown as CalendarBooking;
    render(<BookingPlanSection booking={booking} canChange />);
    expect(await screen.findByTestId('booking-plan-step')).toHaveTextContent('Visit 2 of 3');
    expect(screen.getByTestId('plan-next-5')).toBeInTheDocument();
  });

  it('a booking outside any plan shows nothing', async () => {
    mockApiClient.mockImplementation(() => respond(200, { data: null }));
    const booking = { id: 92, customer: { name: 'Lina Haddad' }, status: 'confirmed' } as unknown as CalendarBooking;
    const { container } = render(<BookingPlanSection booking={booking} canChange />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(container.textContent).toBe('');
  });
});
