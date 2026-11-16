/**
 * Design 3.5 editing on the grid (CV.8–CV.10): every change also works from
 * the keys; a customer's booking asks before it moves; a busy person offers
 * Book anyway; Undo puts it back.
 */

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { TimeGrid, type CalItem } from '@/components/calendar/time-grid';
import { QuickBooking, useGridEditing } from '@/components/calendar/calendar-editing';
import { CalendarRequestError, calendarApi } from '@/lib/calendar/api';

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/api/client', () => ({ api: { GET: jest.fn(async () => ({ data: { data: [] }, response: { ok: true } })) } }));
jest.mock('@/lib/calendar/api', () => {
  const actual = jest.requireActual('@/lib/calendar/api');
  return {
    ...actual,
    calendarApi: { moveBooking: jest.fn(), createBooking: jest.fn(), updateEvent: jest.fn(), createEvent: jest.fn(), addTimeOff: jest.fn() },
  };
});

const AMMAN = 'Asia/Amman';
const TUE = { year: 2026, month: 11, day: 17 };
const WED = { year: 2026, month: 11, day: 18 };

const booking = {
  id: 7,
  service_id: 1,
  profile_id: 4,
  customer: { name: 'Ahmad Khalil', email: 'ahmad@example.com', phone: '' },
  starts_at: '2026-11-17T07:00:00Z',
  ends_at: '2026-11-17T08:15:00Z',
  status: 'confirmed',
  source: 'staff',
} as never;

const item: CalItem = {
  key: 'b7',
  kind: 'booking',
  starts_at: '2026-11-17T07:00:00Z',
  ends_at: '2026-11-17T08:15:00Z',
  allDay: false,
  title: 'Cake tasting',
  who: 'Ahmad Khalil · Awss',
  where: '',
  colour: 'var(--chart-2)',
  booking,
};

const staff = [{ profile_id: 4, display_name: 'Awss Haddad', bookable: true, sort_order: 0, feed_enabled: false, unit_id: null }] as never;
const services = [{ id: 1, name: { en: 'Cake tasting' }, duration_minutes: 75, active: true, staff_profile_ids: [4] }] as never;

beforeEach(() => jest.clearAllMocks());

it('moves the selected booking with the arrow keys and saves on Enter (CV.10)', () => {
  const onChange = jest.fn();
  render(
    <TimeGrid
      days={[TUE, WED]}
      items={[item]}
      startHour={8}
      endHour={19}
      timeZone={AMMAN}
      weekendDays={[]}
      today={TUE}
      zone="GMT+3"
      selectedKey={null}
      onSelect={() => {}}
      canDrag={() => true}
      onChange={onChange}
    />
  );
  const button = screen.getByTestId('calendar-item');
  fireEvent.keyDown(button, { key: 'ArrowDown' });
  fireEvent.keyDown(button, { key: 'ArrowDown' });
  fireEvent.keyDown(button, { key: 'ArrowRight' });
  expect(screen.getByTestId('calendar-drag-label')).toHaveTextContent('10:30 – 11:45');
  fireEvent.keyDown(button, { key: 'Enter' });
  expect(onChange).toHaveBeenCalledWith(item, { date: WED, startMinute: 630, endMinute: 705 }, 'move');
});

function useEditing() {
  return useGridEditing({
    items: [item],
    days: [TUE, WED],
    services,
    staff,
    timeZone: AMMAN,
    canWrite: true,
    canChange: () => true,
    placeOf: () => ({ date: TUE, startMinute: 600, endMinute: 675 }),
    onChanged: () => {},
  });
}

function Dialogs({ children }: { children: ReactNode }) {
  return <>{children}</>;
}

it('asks before moving a customer booking, emails by default, and offers Undo (CV.9)', async () => {
  (calendarApi.moveBooking as jest.Mock).mockResolvedValue(booking);
  const { result, rerender } = renderHook(() => useEditing());
  act(() => result.current.onChange(item, { date: WED, startMinute: 750, endMinute: 825 }, 'move'));
  rerender();
  render(<Dialogs>{result.current.dialogs}</Dialogs>);
  expect(screen.getByText('Move Ahmad Khalil’s cake tasting?')).toBeInTheDocument();
  expect(screen.getByText(/From Tuesday 17 November, 10:00 to Wednesday 18 November, 12:30/)).toBeInTheDocument();
  fireEvent.click(screen.getByTestId('calendar-ask-confirm'));
  await waitFor(() => expect(calendarApi.moveBooking).toHaveBeenCalled());
  expect(calendarApi.moveBooking).toHaveBeenCalledWith(7, expect.objectContaining({ starts_at: '2026-11-18T12:30:00+03:00', notify: true, within_hours: true }));
  await waitFor(() => expect(addToast).toHaveBeenCalled());
  const [message, , duration, action] = addToast.mock.calls[0];
  expect(message).toBe('Moved to Wednesday 18 November, 12:30. Ahmad was emailed.');
  expect(duration).toBe(10_000);
  action.onClick();
  const undo = (calendarApi.moveBooking as jest.Mock).mock.calls.at(-1);
  expect(undo[1]).toEqual(expect.objectContaining({ starts_at: '2026-11-17T10:00:00+03:00', book_anyway: true }));
  // A booking's length is its service's (Q35.7): neither the move nor the Undo sends one.
  expect(undo[1]).not.toHaveProperty('duration_minutes');
  expect((calendarApi.moveBooking as jest.Mock).mock.calls[0][1]).not.toHaveProperty('duration_minutes');
});

it('says the customer’s calendar won’t update when the email is unticked (Q35.8)', () => {
  const { result, rerender } = renderHook(() => useEditing());
  act(() => result.current.onChange(item, { date: WED, startMinute: 750, endMinute: 825 }, 'move'));
  rerender();
  const { rerender: again } = render(<Dialogs>{result.current.dialogs}</Dialogs>);
  expect(screen.queryByTestId('calendar-ask-no-email')).toBeNull();
  fireEvent.click(screen.getByTestId('calendar-ask-notify'));
  rerender();
  again(<Dialogs>{result.current.dialogs}</Dialogs>);
  expect(screen.getByTestId('calendar-ask-no-email')).toHaveTextContent('Ahmad’s calendar won’t update');
});

it('pulls only team events to a new length, never a booking (Q35.7)', () => {
  const event: CalItem = { ...item, key: 'event:3', kind: 'event', title: 'Team lunch', booking: undefined, event: { id: 3, profile_id: 4, title: 'Team lunch', starts_at: item.starts_at, ends_at: item.ends_at, all_day: false, busy: true, status: 'confirmed' } as never };
  const { result } = renderHook(() => useEditing());
  expect(result.current.canResize(item)).toBe(false);
  expect(result.current.canResize(event)).toBe(true);
  render(
    <TimeGrid
      days={[TUE]}
      items={[{ ...item, starts_at: '2026-11-17T05:00:00Z', ends_at: '2026-11-17T06:00:00Z' }, event]}
      startHour={8}
      endHour={19}
      timeZone={AMMAN}
      weekendDays={[]}
      today={TUE}
      zone="GMT+3"
      selectedKey={null}
      onSelect={() => {}}
      canDrag={() => true}
      canResize={result.current.canResize}
      onChange={() => {}}
    />
  );
  expect(screen.getAllByTestId('calendar-resize')).toHaveLength(1);
});

it('says a busy person is busy and books anyway on request', async () => {
  (calendarApi.createBooking as jest.Mock)
    .mockRejectedValueOnce(new CalendarRequestError('busy', 409, 'That person is busy then.', 'person_busy'))
    .mockResolvedValueOnce({ customer: { name: 'Example Clinic' } });
  const onSaved = jest.fn();
  render(
    <QuickBooking
      draft={{ date: WED, startMinute: 810, endMinute: 885, fixed: false }}
      services={services}
      staff={staff}
      timeZone={AMMAN}
      canEvent
      me={null}
      onServiceLength={() => {}}
      onClose={() => {}}
      onSaved={onSaved}
      onMore={() => {}}
    />
  );
  fireEvent.change(screen.getByLabelText('Customer'), { target: { value: 'Example Clinic' } });
  fireEvent.change(screen.getByLabelText('Email or phone'), { target: { value: 'clinic@example.com' } });
  fireEvent.click(screen.getByTestId('calendar-quick-save'));
  expect(await screen.findByTestId('calendar-quick-busy')).toHaveTextContent('Awss Haddad is busy then');
  fireEvent.click(screen.getByTestId('calendar-quick-anyway'));
  await waitFor(() => expect(onSaved).toHaveBeenCalledWith('Booked for Example Clinic'));
  expect(calendarApi.createBooking).toHaveBeenLastCalledWith(
    expect.objectContaining({ starts_at: '2026-11-18T13:30:00+03:00', book_anyway: true, notify: true, within_hours: true })
  );
});
