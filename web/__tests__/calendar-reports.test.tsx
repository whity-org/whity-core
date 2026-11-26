/**
 * Reports › Appointments and the home's Appointments card (WHIT-1437, design
 * section 39 §4.2–§4.3): the totals with their trend, the breakdown table and
 * its switch, the "Not all bookings are marked" note, the CSV link, and the
 * card's three customers who missed most.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import CalendarReportsPage from '@/app/(protected)/calendar/reports/page';
import { AppointmentsReportCard } from '@/components/calendar/appointments-report-card';
import type { CalendarAttendanceReport } from '@/lib/calendar/api';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () =>
  jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient)
);
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>{children}</a>
  ),
}));
jest.mock('@/components/calendar/calendar-nav', () => ({ CalendarNav: () => null }));
const mockAccess = { loading: false, canRead: true, canWrite: true, canManage: true, canReports: true, me: 11, canChange: () => true };
jest.mock('@/components/calendar/use-calendar-basics', () => ({
  useCalendarAccess: () => mockAccess,
  useCalendarBasics: () => ({
    data: { timeZone: 'Asia/Amman', staff: [{ profile_id: 11, display_name: 'Amro Saleh', bookable: true, sort_order: 0, feed_enabled: false }], services: [], rooms: [], units: [], settings: {} },
    error: null,
  }),
}));

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

const zero = { came: 0, no_show: 0, late_cancels: 0, early_cancels: 0, unmarked: 0, due: 0, no_show_rate: null, late_cancel_rate: null, missed_rate: null, unmarked_share: null };

function report(by: string): CalendarAttendanceReport {
  return {
    from: '2026-10-31T21:00:00Z',
    to: '2026-11-30T21:00:00Z',
    previous_from: '2026-09-30T21:00:00Z',
    by,
    totals: { came: 6, no_show: 2, late_cancels: 2, early_cancels: 1, unmarked: 2, due: 10, no_show_rate: 0.2, late_cancel_rate: 0.2, missed_rate: 0.4, unmarked_share: 0.1667 },
    previous: { ...zero, came: 9, no_show: 1, due: 10, no_show_rate: 0.1, late_cancel_rate: 0, missed_rate: 0.1, unmarked_share: 0 },
    rows:
      by === 'customer'
        ? [
            { key: 'c:3', label: 'Rana Haddad', ...zero, came: 1, no_show: 2, late_cancels: 1, due: 4, no_show_rate: 0.5, late_cancel_rate: 0.25, missed_rate: 0.75, unmarked_share: 0 },
            { key: 'c:4', label: 'Ibrahim Mansour', ...zero, came: 5, due: 5, no_show_rate: 0, late_cancel_rate: 0, missed_rate: 0, unmarked_share: 0 },
          ]
        : [{ key: '11', label: 'Amro Saleh', ...zero, came: 6, no_show: 2, late_cancels: 2, due: 10, no_show_rate: 0.2, late_cancel_rate: 0.2, missed_rate: 0.4, unmarked_share: 0.1667 }],
    truncated: false,
    own: false,
    time_zone: 'Asia/Amman',
  } as unknown as CalendarAttendanceReport;
}

describe('Reports › Appointments', () => {
  beforeEach(() => mockApiClient.mockReset());

  it('shows the rates with their trend, the note, the table and the CSV link', async () => {
    const asked: string[] = [];
    mockApiClient.mockImplementation((url: string) => {
      asked.push(url);
      const by = new URL(url, 'http://x').searchParams.get('by') ?? 'staff';
      return respond(200, { data: report(by) });
    });
    render(<CalendarReportsPage />);

    expect(await screen.findByTestId('reports-tile-no-show')).toHaveTextContent('20.0%');
    expect(screen.getByTestId('reports-tile-no-show')).toHaveTextContent('Up 10.0 points on the period before');
    expect(screen.getByTestId('reports-tile-due')).toHaveTextContent('10');
    expect(screen.getByTestId('reports-not-all-marked')).toHaveTextContent('Not all bookings are marked: 2');
    expect(screen.getByTestId('reports-row-11')).toHaveTextContent('Amro Saleh');
    expect(asked[0]).toContain('by=staff');
    expect(asked[0]).toContain('period=this_month');
    expect(screen.getByTestId('reports-csv').getAttribute('href')).toBe('/api/v1/calendar/attendance/report?by=staff&period=this_month&format=csv&lang=en');

    fireEvent.click(screen.getByRole('button', { name: 'Customer' }));
    expect(await screen.findByTestId('reports-row-c:3')).toHaveTextContent('Rana Haddad');
    await waitFor(() => expect(asked.some((u) => u.includes('by=customer'))).toBe(true));
  });
});

describe('The home Appointments card', () => {
  beforeEach(() => mockApiClient.mockReset());

  it('shows this month and the customers who missed most', async () => {
    mockApiClient.mockImplementation(() => respond(200, { data: report('customer') }));
    render(<AppointmentsReportCard />);
    expect(await screen.findByTestId('home-appointments')).toHaveTextContent('20.0%');
    const missed = screen.getByTestId('home-appointments-missed');
    expect(missed).toHaveTextContent('Rana Haddad');
    expect(missed).toHaveTextContent('Missed 3');
    expect(missed).not.toHaveTextContent('Ibrahim Mansour');
  });
});
