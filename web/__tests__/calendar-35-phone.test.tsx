/**
 * Design 3.5 on a phone (CV.11): the computer's views map to Day, 3 days and
 * Month; a finger has to hold a booking half a second before it moves, and a
 * finger that moves first is scrolling.
 */

import { act, fireEvent, render, screen } from '@testing-library/react';
import { phoneView } from '@/components/calendar/calendar-phone';
import { TimeGrid, type CalItem } from '@/components/calendar/time-grid';

// jsdom has no PointerEvent; the grid reads only the pointer type, button and position.
class TestPointerEvent extends MouseEvent {
  pointerType: string;
  constructor(type: string, init: PointerEventInit = {}) {
    super(type, init);
    this.pointerType = init.pointerType ?? 'mouse';
  }
}
(window as unknown as { PointerEvent: unknown }).PointerEvent = TestPointerEvent;

const AMMAN = 'Asia/Amman';
const TUE = { year: 2026, month: 11, day: 17 };

const item: CalItem = {
  key: 'b1',
  kind: 'booking',
  starts_at: '2026-11-17T07:00:00Z',
  ends_at: '2026-11-17T08:00:00Z',
  allDay: false,
  title: 'Cake tasting',
  who: 'Ahmad Khalil · Awss',
  where: '',
  colour: 'var(--chart-2)',
};

it('maps the computer views to the phone ones', () => {
  expect(['day', 'four_days', 'week', 'month', 'year', undefined].map(phoneView)).toEqual([
    'day',
    'three_days',
    'three_days',
    'month',
    'month',
    'three_days',
  ]);
});

function grid(onChange: jest.Mock) {
  return render(
    <TimeGrid
      phone
      days={[TUE]}
      items={[item]}
      startHour={8}
      endHour={19}
      timeZone={AMMAN}
      weekendDays={[]}
      today={TUE}
      zone=""
      selectedKey={null}
      onSelect={() => {}}
      canDrag={() => true}
      onChange={onChange}
    />
  );
}

describe('press and hold (CV.10, CV.11)', () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it('picks a booking up after half a second and moves it by quarters of an hour', () => {
    const onChange = jest.fn();
    grid(onChange);
    const booking = screen.getByTestId('calendar-item');
    // 10:00 is two hours below 08:00: 112px at 56px an hour.
    fireEvent.pointerDown(booking, { pointerType: 'touch', button: 0, clientX: 50, clientY: 112 });
    act(() => {
      jest.advanceTimersByTime(500);
    });
    act(() => {
      window.dispatchEvent(new TestPointerEvent('pointermove', { clientX: 50, clientY: 140 }));
    });
    act(() => {
      window.dispatchEvent(new TestPointerEvent('pointerup', { clientX: 50, clientY: 140 }));
    });
    expect(onChange).toHaveBeenCalledWith(item, { date: TUE, startMinute: 630, endMinute: 690 }, 'move');
  });

  it('leaves a finger that moves before the hold to scroll', () => {
    const onChange = jest.fn();
    grid(onChange);
    fireEvent.pointerDown(screen.getByTestId('calendar-item'), { pointerType: 'touch', button: 0, clientX: 50, clientY: 112 });
    act(() => {
      window.dispatchEvent(new TestPointerEvent('pointermove', { clientX: 50, clientY: 160 }));
      jest.advanceTimersByTime(600);
      window.dispatchEvent(new TestPointerEvent('pointerup', { clientX: 50, clientY: 160 }));
    });
    expect(onChange).not.toHaveBeenCalled();
  });
});
