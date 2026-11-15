/**
 * The calendar grid (WHIT-944): in Arabic the layout mirrors through `dir` and
 * logical properties alone. The DOM keeps reading order in both directions,
 * items are offset with `inset-inline-start`, never `left`, and the weekend
 * comes from the workspace's setting.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { AgendaList, TimeGrid, MonthGrid, type GridItem } from '@/components/calendar/calendar-grid';
import { monthWeeks, weekDays } from '@/lib/calendar/layout';

const AMMAN = 'Asia/Amman';
const WED = { year: 2026, month: 11, day: 11 };

const items: GridItem[] = [
  {
    key: 'b1',
    starts_at: '2026-11-07T06:00:00Z', // Saturday 09:00 in Amman
    ends_at: '2026-11-07T07:00:00Z',
    allDay: false,
    title: 'Rana Haddad',
    subtitle: 'Cake tasting',
    person: 'Lina Saleh',
    tone: 'booking',
  },
];

function renderGrid(dir: 'ltr' | 'rtl') {
  return render(
    <div dir={dir}>
      <TimeGrid
        days={weekDays(WED, 'saturday')}
        items={items}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        showPerson={false}
        onOpen={() => {}}
      />
    </div>
  );
}

describe('TimeGrid', () => {
  it.each(['ltr', 'rtl'] as const)('keeps reading order in the DOM (%s)', (dir) => {
    renderGrid(dir);
    const headings = screen.getAllByTestId('calendar-day-heading').map((el) => el.getAttribute('data-date'));
    expect(headings).toEqual(['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
  });

  it('shades the workspace’s weekend', () => {
    renderGrid('rtl');
    const weekend = screen
      .getAllByTestId('calendar-day-heading')
      .filter((el) => el.hasAttribute('data-weekend'))
      .map((el) => el.getAttribute('data-date'));
    expect(weekend).toEqual(['2026-11-07', '2026-11-13']);
  });

  it('places an item on the workspace’s clock with a logical offset', () => {
    renderGrid('rtl');
    const item = screen.getByTestId('calendar-item');
    expect(item.closest('[data-testid="calendar-day-column"]')).toHaveAttribute('data-date', '2026-11-07');
    // 09:00 at 48px an hour, plus the 1px gap.
    expect(item.style.top).toBe(`${9 * 48 + 1}px`);
    expect(item.style.insetInlineStart).not.toBe('');
    expect(item.style.left).toBe('');
  });
});

describe('TimeGrid week (TplCalendarStaff, WHIT-1044)', () => {
  const booked: GridItem[] = [{ ...items[0], booking: { id: 1 } as unknown as GridItem['booking'] }];

  it('heads each day with its date and how many bookings it has', () => {
    render(
      <TimeGrid
        days={weekDays(WED, 'saturday')}
        items={booked}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        showPerson={false}
        onOpen={() => {}}
      />
    );
    const counts = screen.getAllByTestId('calendar-day-count').map((el) => el.textContent);
    expect(counts[0]).toBe('1 booking');
    expect(counts.slice(1).every((text) => text === '0 bookings')).toBe(true);
  });

  it('reads a booking as time and service, over the customer and the person', () => {
    render(
      <TimeGrid
        days={weekDays(WED, 'saturday')}
        items={booked}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        showPerson
        onOpen={() => {}}
      />
    );
    const [first, second] = Array.from(screen.getByTestId('calendar-item').children).map((el) => el.textContent);
    expect(first).toBe('09:00 Cake tasting');
    expect(second).toBe('Rana Haddad · Lina Saleh');
  });

  it('marks now with a line on today’s column only', () => {
    render(
      <TimeGrid
        days={weekDays(WED, 'saturday')}
        items={[]}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        showPerson={false}
        onOpen={() => {}}
      />
    );
    const line = screen.getByTestId('calendar-now');
    expect(line.closest('[data-testid="calendar-day-column"]')).toHaveAttribute('data-date', '2026-11-11');
  });
});

describe('MonthGrid', () => {
  it('heads the month with the workspace’s first day', () => {
    render(
      <MonthGrid
        weeks={monthWeeks(WED, 'sunday')}
        month={11}
        items={items}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        onOpen={() => {}}
      />
    );
    expect(screen.getAllByTestId('calendar-month-weekday').map((el) => el.textContent)).toEqual([
      'Sun',
      'Mon',
      'Tue',
      'Wed',
      'Thu',
      'Fri',
      'Sat',
    ]);
  });
});

describe('on a phone', () => {
  it('lists a week as one card per day with a count, and says when nothing is booked', () => {
    render(
      <AgendaList
        days={weekDays(WED, 'saturday')}
        items={items}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        showPerson={false}
        onOpen={() => {}}
      />
    );
    const days = screen.getAllByTestId('calendar-agenda-day');
    expect(days).toHaveLength(7);
    const counts = screen.getAllByTestId('calendar-agenda-count').map((el) => el.textContent);
    expect(counts[0]).toBe('1 bookings');
    expect(counts.slice(1)).toEqual(Array(6).fill('Nothing booked'));
  });

  it('chooses a day in the month by its count and marks it', () => {
    const onSelectDay = jest.fn();
    const onPickDay = jest.fn();
    window.matchMedia = ((query: string) => ({ matches: true, media: query, addEventListener() {}, removeEventListener() {} })) as unknown as typeof window.matchMedia;
    render(
      <MonthGrid
        weeks={monthWeeks(WED, 'sunday')}
        month={11}
        items={items}
        timeZone={AMMAN}
        weekendDays={['friday', 'saturday']}
        today={WED}
        onOpen={() => {}}
        onPickDay={onPickDay}
        selected={{ year: 2026, month: 11, day: 7 }}
        onSelectDay={onSelectDay}
      />
    );
    const selected = screen.getAllByTestId('calendar-month-day').filter((el) => el.hasAttribute('data-selected'));
    expect(selected.map((el) => el.getAttribute('data-date'))).toEqual(['2026-11-07']);
    fireEvent.click(within(selected[0]).getByText('1', { selector: 'button.block' }));
    expect(onSelectDay).toHaveBeenCalledWith(expect.objectContaining({ day: 7 }));
    expect(onPickDay).not.toHaveBeenCalled();
  });
});
