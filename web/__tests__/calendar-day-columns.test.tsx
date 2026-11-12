/**
 * Design 3.3 (`TplAppointments`): Day for everyone has a column for each
 * person and each room. A booking whose service needs a room shows in both
 * its person's and its room's column; a request is drawn as a request; the
 * branch filter keeps only that branch's people and rooms.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { DayColumns } from '@/components/calendar/day-columns';
import type { GridItem } from '@/components/calendar/calendar-grid';
import type { CalendarBooking, CalendarRoom, CalendarStaff } from '@/lib/calendar/api';

const AMMAN = 'Asia/Amman';
const day = { year: 2026, month: 11, day: 16 };

const staff: CalendarStaff[] = [
  { profile_id: 11, display_name: 'Ibrahim Mansour', bookable: true, sort_order: 0, feed_enabled: false, unit_id: 1 },
  { profile_id: 12, display_name: 'Emad Nasser', bookable: true, sort_order: 1, feed_enabled: false, unit_id: 2 },
];
const rooms: CalendarRoom[] = [{ id: 4, name: { en: 'Tasting room', ar: 'غرفة التذوق' }, unit_id: 1, active: true, sort_order: 0 }];

const booking: CalendarBooking = {
  id: 9,
  service_id: 7,
  profile_id: 11,
  room_id: 4,
  customer: { name: 'Awss Haddad' },
  // 10:00 to 10:45 in Amman.
  starts_at: '2026-11-16T07:00:00Z',
  ends_at: '2026-11-16T07:45:00Z',
  status: 'confirmed',
  source: 'staff',
};

const items: GridItem[] = [
  { key: 'b9', starts_at: booking.starts_at, ends_at: booking.ends_at, allDay: false, title: 'Awss Haddad', subtitle: 'Wedding cake consultation', person: '', tone: 'booking', booking },
  {
    key: 'b10',
    starts_at: '2026-11-16T09:30:00Z',
    ends_at: '2026-11-16T10:15:00Z',
    allDay: false,
    title: 'Request: Emad Shami',
    subtitle: 'Engagement cake',
    person: '',
    tone: 'request',
    booking: { ...booking, id: 10, profile_id: 12, room_id: null, status: 'requested' },
  },
];

describe('DayColumns', () => {
  it('draws people and rooms as columns, a room booking in both, a request as a request', () => {
    const onOpen = jest.fn();
    render(
      <DayColumns day={day} today={{ year: 2026, month: 11, day: 15 }} timeZone={AMMAN} items={items} staff={staff} rooms={rooms} unitId={null} label="Monday" onOpen={onOpen} />
    );
    const grid = screen.getByRole('group', { name: 'Monday' });
    expect(grid).toHaveTextContent('Ibrahim Mansour');
    expect(grid).toHaveTextContent('Emad Nasser');
    expect(grid).toHaveTextContent('Tasting room');

    const blocks = within(grid).getAllByRole('listitem');
    // The room booking twice (person and room), the request once.
    expect(blocks.filter((b) => b.textContent?.includes('Awss Haddad'))).toHaveLength(2);
    const request = blocks.find((b) => b.textContent?.includes('Emad Shami'));
    expect(request).toHaveAttribute('data-state', 'request');
    expect(blocks[0]).toHaveTextContent('10:00–10:45');

    fireEvent.click(request!);
    expect(onOpen).toHaveBeenCalledWith(items[1]);
  });

  it('keeps only the chosen branch', () => {
    render(<DayColumns day={day} today={day} timeZone={AMMAN} items={items} staff={staff} rooms={rooms} unitId={2} label="Monday" onOpen={() => {}} />);
    const grid = screen.getByRole('group', { name: 'Monday' });
    expect(grid).toHaveTextContent('Emad Nasser');
    expect(grid).not.toHaveTextContent('Ibrahim Mansour');
    expect(grid).not.toHaveTextContent('Tasting room');
  });
});
