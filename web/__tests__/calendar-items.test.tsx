/**
 * One calendar on the grid (WHIT-1116): everything from /api/calendar/items
 * becomes one list the grid draws; kind is the type and a booking's state
 * comes from its status; Also show picks the kinds; the details of an event
 * show the guests and let an invited person answer.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { kindsFor, toCalItems, type ItemContext, type ServerItem } from '@/lib/calendar/items';
import { palette } from '@/lib/calendar/colours';
import { BookingDetails } from '@/components/calendar/booking-details';

const ctx: ItemContext = {
  colourBy: 'person',
  colours: palette([3, 4], [1], [7]),
  staffName: new Map([
    [3, 'Amro Saleh'],
    [4, 'Awss Haddad'],
  ]),
  services: new Map([[1, { id: 1, name: { en: 'Cake tasting' } } as never]]),
  rooms: new Map([[7, { id: 7, name: { en: 'Tasting room' } } as never]]),
  hiddenPeople: new Set(),
  hiddenRooms: new Set(),
  hiddenServices: new Set(),
  search: '',
  serviceName: (s) => (s as { name?: { en?: string } } | undefined)?.name?.en ?? '',
  label: (names) => names?.en ?? '',
  words: {
    noName: 'No name given',
    waitingPayment: 'waiting for payment',
    request: 'request',
    cancelled: 'cancelled',
    checkedIn: 'checked in',
    timeOff: (name) => `${name} · time off`,
    guests: (count) => `${count} guests`,
  },
};

function row(over: Partial<ServerItem>): ServerItem {
  return {
    id: 'event:1',
    kind: 'event',
    title: 'Team lunch',
    starts_at: '2026-11-17T09:00:00Z',
    ends_at: '2026-11-17T10:00:00Z',
    all_day: false,
    busy: true,
    status: 'confirmed',
    organiser_id: 3,
    people: [],
    room_id: null,
    location: '',
    booking: null,
    event: { id: 1, notes: '', meeting_id: null, external_ref: null },
    can: { open: true, move: true, resize: true, cancel: true },
    ...over,
  } as ServerItem;
}

it('asks for the kinds Also show ticks, tasks due only when ticked', () => {
  expect(kindsFor(['team_events', 'time_off', 'meetings'])).toEqual(['booking', 'event', 'external', 'meeting', 'time_off']);
  expect(kindsFor(['tasks_due'])).toEqual(['booking', 'task_due']);
});

it('maps every kind onto one list, keyed by the server id', () => {
  const items = toCalItems(
    [
      row({
        id: 'booking:7',
        kind: 'booking',
        title: 'Cake tasting',
        status: 'pending_payment',
        organiser_id: 4,
        room_id: 7,
        booking: { id: 7, service_id: 1, profile_id: 4, customer: { name: 'Ahmad Khalil' }, status: 'pending_payment' } as never,
        event: null,
      }),
      row({ id: 'meeting:3', kind: 'meeting', title: 'Board meeting', people: [{ profile_id: 4, role: 'required', response: 'invited' }], event: { id: 9, notes: '', meeting_id: 3, external_ref: null } }),
      row({ id: 'time_off:5', kind: 'time_off', title: 'Away', organiser_id: 4, all_day: true }),
    ],
    ctx
  );
  expect(items.map((i) => [i.key, i.kind])).toEqual([
    ['booking:7', 'wait'],
    ['meeting:3', 'meeting'],
    ['time_off:5', 'timeoff'],
  ]);
  expect(items[0]).toMatchObject({ title: 'Cake tasting', who: 'Ahmad Khalil · Awss', where: 'Tasting room · waiting for payment' });
  expect(items[1]).toMatchObject({ who: 'Amro Saleh · 1 guests', meetingId: 3 });
  expect(items[1].event).toMatchObject({ id: 9, profile_id: 3, kind: 'meeting' });
  expect(items[2]).toMatchObject({ title: 'Awss Haddad · time off', who: 'Away', allDay: true });
});

it('leaves out what the panel unticked', () => {
  const items = toCalItems([row({}), row({ id: 'event:2', organiser_id: 4 })], { ...ctx, hiddenPeople: new Set([4]) });
  expect(items.map((i) => i.key)).toEqual(['event:1']);
});

it('shows the guests and lets an invited person answer; a meeting opens its page', () => {
  const [item] = toCalItems(
    [
      row({
        id: 'meeting:3',
        kind: 'meeting',
        title: 'Board meeting',
        people: [
          { profile_id: 4, role: 'required', response: 'invited' },
          { profile_id: 3, role: 'required', response: 'accepted' },
        ],
        event: { id: 9, notes: 'Bring the figures', meeting_id: 3, external_ref: null },
      }),
    ],
    ctx
  );
  const onAnswer = jest.fn();
  render(
    <BookingDetails
      item={item}
      timeZone="Asia/Amman"
      withName=""
      canChange
      onClose={() => {}}
      onMove={() => {}}
      onCancel={() => {}}
      onOpen={() => {}}
      nameOf={(id) => ctx.staffName.get(id) ?? ''}
      me={4}
      onAnswer={onAnswer}
    />
  );
  expect(screen.getByText('Organised by Amro Saleh')).toBeInTheDocument();
  expect(screen.getByTestId('calendar-details-guests')).toHaveTextContent('Awss HaddadNot answered yet');
  expect(screen.getByTestId('calendar-details-guests')).toHaveTextContent('Amro SalehComing');
  expect(screen.getByText('Bring the figures')).toBeInTheDocument();
  fireEvent.click(screen.getByTestId('calendar-answer-tentative'));
  expect(onAnswer).toHaveBeenCalledWith('tentative');
  expect(screen.getByTestId('calendar-details-meeting')).toHaveAttribute('href', '/calendar/meetings/3');
  expect(screen.queryByTestId('calendar-details-edit')).toBeNull();
});
