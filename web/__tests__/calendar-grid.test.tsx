/**
 * The calendar grid (WHIT-944): in Arabic the layout mirrors through `dir` and
 * logical properties alone. The DOM keeps reading order in both directions,
 * items are offset with `inset-inline-start`, never `left`, and the weekend
 * comes from the workspace's setting.
 */

import { render, screen } from '@testing-library/react';
import { TimeGrid, MonthGrid, type GridItem } from '@/components/calendar/calendar-grid';
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
