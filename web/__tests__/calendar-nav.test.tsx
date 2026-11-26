/**
 * The calendar's sub-pages on a phone (PwCalendar): one button with the page's
 * name opens a sheet listing the pages, each with a line saying what it holds.
 */
import { render, screen, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { CalendarNav } from '@/components/calendar/calendar-nav';

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>{children}</a>
  ),
}));
jest.mock('@/components/calendar/use-calendar-basics', () => ({
  useCalendarAccess: () => ({ loading: false, canRead: true, canWrite: true, canManage: true }),
}));

it('opens the pages as a sheet from the page name', async () => {
  render(<CalendarNav active="services" />);
  const trigger = screen.getByTestId('calendar-nav-phone');
  expect(trigger).toHaveTextContent('Services');

  await userEvent.click(trigger);
  const sheet = await screen.findByTestId('calendar-nav-sheet');
  const links = within(sheet).getAllByRole('link');
  expect(links.map((a) => a.getAttribute('href'))).toEqual([
    '/calendar',
    '/calendar/waitlist',
    '/calendar/services',
    '/calendar/rooms',
    '/calendar/hours',
    '/calendar/reports',
    '/calendar/settings',
  ]);
  expect(links[2]).toHaveAttribute('aria-current', 'page');
  expect(within(sheet).getByText('Who works when')).toBeInTheDocument();
});
