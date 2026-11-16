/**
 * Design 3.5's calendar on a computer (CV.2–CV.7): the views and their ranges,
 * Colour by from the chart series, the grid drawn from the earliest opening to
 * the latest closing with the name always written, overlapping bookings
 * cascading, details beside a booking closing on Esc, and the panel's ticks.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { TimeGrid, hoursToDraw, type CalItem } from '@/components/calendar/time-grid';
import { CalendarPanel, MiniMonth, zoneLabel } from '@/components/calendar/calendar-panel';
import { colourOf, palette, seriesColour } from '@/lib/calendar/colours';
import { stepAnchor, viewRange, weekDays } from '@/lib/calendar/layout';

const AMMAN = 'Asia/Amman';
const TUE = { year: 2026, month: 11, day: 17 };

function booking(key: string, startsAt: string, endsAt: string, extra: Partial<CalItem> = {}): CalItem {
  return {
    key,
    kind: 'booking',
    starts_at: startsAt,
    ends_at: endsAt,
    allDay: false,
    title: 'Cake tasting',
    who: 'Ahmad Khalil · Awss',
    where: 'Tasting room',
    colour: 'var(--chart-2)',
    ...extra,
  };
}

describe('views (CV.7)', () => {
  it('4 days starts from the day in view and steps by four', () => {
    const range = viewRange('four_days', TUE, 'sunday');
    expect(range.days.map((d) => d.day)).toEqual([17, 18, 19, 20]);
    expect(stepAnchor('four_days', TUE, 1)).toEqual({ year: 2026, month: 11, day: 21 });
    expect(viewRange('three_days', TUE, 'sunday').days).toHaveLength(3);
  });

  it('Week starts on the workspace first day', () => {
    expect(viewRange('week', TUE, 'sunday').days[0]).toEqual({ year: 2026, month: 11, day: 15 });
  });

  it('Year spans the calendar year', () => {
    const range = viewRange('year', TUE, 'sunday');
    expect(range.first).toEqual({ year: 2026, month: 1, day: 1 });
    expect(range.end).toEqual({ year: 2027, month: 1, day: 1 });
  });
});

describe('Colour by (CV.4)', () => {
  it('takes the chart series in list order, starting again after six', () => {
    const colours = palette([3, 4, 21], [1, 2], [7]);
    expect(colourOf('person', colours, { profileId: 4 })).toBe(seriesColour(1));
    expect(colourOf('service', colours, { serviceId: 2 })).toBe('var(--chart-2)');
    expect(colourOf('room', colours, { roomId: null })).toBe('var(--muted-foreground)');
    expect(seriesColour(6)).toBe('var(--chart-1)');
  });
});

describe('the time grid (CV.5, CV.6)', () => {
  const days = weekDays(TUE, 'sunday');

  it('draws from the earliest opening to the latest closing, widened for a booking outside it', () => {
    expect(hoursToDraw([{ start_time: '09:00', end_time: '17:30' }], [], days, AMMAN)).toEqual({ startHour: 9, endHour: 18 });
    // 07:00 in Amman (04:00Z).
    const early = booking('b', '2026-11-17T04:00:00Z', '2026-11-17T05:00:00Z');
    expect(hoursToDraw([{ start_time: '09:00', end_time: '17:00' }], [early], days, AMMAN).startHour).toBe(7);
    expect(hoursToDraw([], [], days, AMMAN)).toEqual({ startHour: 8, endHour: 19 });
  });

  it('writes the time and service, and the customer from 54 minutes; overlapping ones cascade', () => {
    const items = [
      booking('a', '2026-11-17T07:00:00Z', '2026-11-17T08:15:00Z'),
      booking('b', '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z', { title: 'Order pickup', who: 'Example Clinic · Emad' }),
    ];
    render(
      <TimeGrid
        days={days}
        items={items}
        startHour={8}
        endHour={19}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={TUE}
        zone="GMT+3"
        selectedKey={null}
        onSelect={() => {}}
      />
    );
    const [long, short] = screen.getAllByTestId('calendar-item');
    expect(long).toHaveTextContent('10:00 Cake tasting');
    expect(long).toHaveTextContent('Ahmad Khalil · Awss');
    expect(short).toHaveTextContent('10:00 Order pickup');
    expect(short).not.toHaveTextContent('Example Clinic');
    expect(long.style.width).toBe('calc(66% - 4px)');
    expect(short.style.insetInlineStart).toBe('calc(34% + 2px)');
    // Fri and Sat are shaded from the workspace's weekend.
    const headings = screen.getAllByTestId('calendar-day-heading');
    expect(headings.filter((h) => h.hasAttribute('data-weekend')).map((h) => h.dataset.date)).toEqual(['2026-11-20', '2026-11-21']);
  });

  it('opens the details beside the chosen booking and closes them on Esc', () => {
    const onSelect = jest.fn();
    const item = booking('a', '2026-11-17T07:00:00Z', '2026-11-17T08:15:00Z');
    render(
      <TimeGrid
        days={days}
        items={[item]}
        startHour={8}
        endHour={19}
        timeZone={AMMAN}
        weekendDays={[]}
        today={TUE}
        zone="GMT+3"
        selectedKey="a"
        onSelect={onSelect}
        renderDetails={() => <div data-testid="details">Details</div>}
      />
    );
    expect(screen.getByTestId('details')).toBeInTheDocument();
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onSelect).toHaveBeenCalledWith(null);
  });

  it('puts leave in the All day row as a bar across its days', () => {
    const leave: CalItem = {
      ...booking('o1', '2026-11-17T21:00:00Z', '2026-11-19T21:00:00Z'),
      kind: 'timeoff',
      allDay: true,
      title: 'Ibrahim Mansour · time off',
    };
    render(
      <TimeGrid
        days={days}
        items={[leave]}
        startHour={8}
        endHour={19}
        timeZone={AMMAN}
        weekendDays={[]}
        today={TUE}
        zone="GMT+3"
        selectedKey={null}
        onSelect={() => {}}
      />
    );
    const bar = within(screen.getByTestId('calendar-all-day')).getByTestId('calendar-bar-item');
    expect(bar).toHaveTextContent('Ibrahim Mansour · time off');
    // Wednesday and Thursday: columns 5 and 6 of the grid (after the hours).
    expect(bar.style.gridColumn).toBe('5 / span 2');
  });
});

describe('the panel (CV.2)', () => {
  it('bands the range in view on the mini month and jumps to a date', () => {
    const onPickDate = jest.fn();
    render(
      <MiniMonth timeZone={AMMAN} weekStart="sunday" today={TUE} shownFrom={{ ...TUE, day: 15 }} shownTo={{ ...TUE, day: 21 }} onPickDate={onPickDate} />
    );
    const shown = screen.getByTestId('calendar-mini-month').querySelectorAll('[data-shown]');
    expect(Array.from(shown).map((b) => (b as HTMLElement).dataset.date)).toEqual([
      '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21',
    ]);
    fireEvent.click(screen.getByText('25'));
    expect(onPickDate).toHaveBeenCalledWith({ year: 2026, month: 11, day: 25 });
  });

  it('shows people as coloured checkboxes and reports a tick', () => {
    const onToggle = jest.fn();
    render(
      <CalendarPanel
        timeZone={AMMAN}
        weekStart="sunday"
        today={TUE}
        shownFrom={TUE}
        shownTo={TUE}
        onPickDate={() => {}}
        search=""
        onSearch={() => {}}
        people={[{ id: 3, label: 'Amro Saleh', colour: 'var(--chart-1)', shown: true }]}
        rooms={[{ id: 1, label: 'Tasting room', colour: null, shown: false }]}
        services={null}
        onToggle={onToggle}
        alsoShow={['team_events']}
        onAlsoShow={() => {}}
        canBook
        onNewBooking={() => {}}
      />
    );
    fireEvent.click(screen.getByLabelText('Amro Saleh'));
    expect(onToggle).toHaveBeenCalledWith('people', 3, false);
    expect(screen.getByLabelText('Tasting room')).not.toBeChecked();
    expect(screen.getByLabelText('Cancelled bookings')).not.toBeChecked();
    expect(screen.getByTestId('calendar-zone')).toHaveTextContent('Amman');
  });

  it('names the zone by its city and offset', () => {
    expect(zoneLabel('Asia/Amman')).toMatch(/^Amman \(GMT\+3\)$/);
  });
});
