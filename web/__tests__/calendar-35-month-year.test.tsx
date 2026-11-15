/**
 * Design 3.5 Month and Year (CV.7, `TplCalendarViews`): up to three bookings a
 * day then "n more", which opens the day; leave as a bar across its days; Year
 * shades each day in four steps and opens a day on click.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { MonthView, YearView, yearStep } from '@/components/calendar/month-year';
import type { CalItem } from '@/components/calendar/time-grid';

const AMMAN = 'Asia/Amman';
const TUE = { year: 2026, month: 11, day: 17 };

function at(hourUtc: number, extra: Partial<CalItem> = {}): CalItem {
  const h = String(hourUtc).padStart(2, '0');
  return {
    key: `b${hourUtc}${extra.title ?? ''}`,
    kind: 'booking',
    starts_at: `2026-11-17T${h}:00:00Z`,
    ends_at: `2026-11-17T${h}:30:00Z`,
    allDay: false,
    title: 'Order pickup',
    who: '',
    where: '',
    colour: 'var(--chart-5)',
    ...extra,
  };
}

describe('Month', () => {
  it('shows three bookings a day, then a "more" that opens the day', () => {
    const onOpenDay = jest.fn();
    render(
      <MonthView
        anchor={TUE}
        weekStart="sunday"
        weekendDays={['friday', 'saturday']}
        today={TUE}
        timeZone={AMMAN}
        items={[at(6), at(7), at(8, { title: 'Delivery' }), at(9, { title: 'Cake tasting' })]}
        onOpenItem={() => {}}
        onOpenDay={onOpenDay}
      />
    );
    const chips = screen.getAllByTestId('calendar-month-chip');
    expect(chips).toHaveLength(3);
    expect(chips[0]).toHaveTextContent('09:00Order pickup');
    fireEvent.click(screen.getByTestId('calendar-month-more'));
    expect(onOpenDay).toHaveBeenCalledWith(TUE);
    expect(screen.getByTestId('calendar-month-more')).toHaveTextContent('1 more');
  });

  it('draws leave as a bar across the days it covers', () => {
    const leave: CalItem = {
      ...at(0),
      key: 'off',
      kind: 'timeoff',
      allDay: true,
      title: 'Ibrahim Mansour · time off',
      starts_at: '2026-11-17T21:00:00Z',
      ends_at: '2026-11-19T21:00:00Z',
    };
    render(
      <MonthView anchor={TUE} weekStart="sunday" weekendDays={[]} today={TUE} timeZone={AMMAN} items={[leave]} onOpenItem={() => {}} onOpenDay={() => {}} />
    );
    const bar = screen.getByTestId('calendar-month-bar');
    expect(bar).toHaveTextContent('Ibrahim Mansour · time off');
    // Wednesday 18 and Thursday 19: the 4th and 5th day of a Sunday week.
    expect(bar.style.gridColumn).toBe('4 / span 2');
  });
});

describe('Year', () => {
  it('shades in four steps', () => {
    expect([0, 1, 3, 4, 6, 7, 30].map(yearStep)).toEqual([0, 1, 1, 2, 2, 3, 3]);
  });

  it('shades each day by its count and opens a day', () => {
    const onOpenDay = jest.fn();
    render(
      <YearView
        year={2026}
        weekStart="sunday"
        today={TUE}
        timeZone={AMMAN}
        counts={new Map([['2026-11-17', 5], ['2026-01-04', 9]])}
        onOpenDay={onOpenDay}
      />
    );
    const days = screen.getAllByTestId('calendar-year-day');
    expect(days).toHaveLength(365);
    const nov17 = days.find((d) => d.dataset.date === '2026-11-17')!;
    expect(nov17).toHaveAttribute('data-step', '2');
    expect(nov17).toHaveAttribute('aria-current', 'date');
    expect(days.find((d) => d.dataset.date === '2026-01-04')).toHaveAttribute('data-step', '3');
    fireEvent.click(within(screen.getByTestId('calendar-year')).getAllByText('4')[0]);
    expect(onOpenDay).toHaveBeenCalledWith({ year: 2026, month: 1, day: 4 });
  });
});
