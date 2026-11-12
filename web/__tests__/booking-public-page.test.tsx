/**
 * The public booking page (WHIT-945).
 *
 * - A time just taken (409): the page says so in plain words, lets the time
 *   go, fetches the free times again, and keeps what the customer typed.
 * - The spam fields: the hidden `website` field is sent empty, and
 *   `elapsed_ms` is the time since the page loaded.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { zonedDate, zonedInstant } from '@whity/features/datetime';

jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light' }),
}));

jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
}));

jest.mock('@/components/auth/turnstile-widget', () => ({
  TurnstileWidget: () => <div data-testid="book-bot-check" />,
}));

import { addDays } from '@/lib/calendar/layout';
import { PublicBookingScreen } from '@/components/booking/public-booking';

const AMMAN = 'Asia/Amman';

function respond(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });
}

// Free times tomorrow in Amman, so they are inside the first week the page shows.
const tomorrow = addDays(zonedDate(AMMAN), 1);
const at = (minutes: number) => zonedInstant(tomorrow, minutes, AMMAN).toISOString();
const slots = [
  { starts_at: at(9 * 60), ends_at: at(9 * 60 + 30), profile_ids: [3] },
  { starts_at: at(9 * 60 + 30), ends_at: at(10 * 60), profile_ids: [3] },
];

const page = {
  workspace: { name: 'Example Bakery', slug: 'example-bakery' },
  settings: { time_zone: AMMAN, min_notice_minutes: 0, max_advance_days: 60, week_start: 'saturday', weekend_days: ['friday', 'saturday'] },
  services: [
    { id: 1, name: { en: 'Cake tasting', ar: 'تذوق الكعك' }, description: [], duration_minutes: 30, price_minor: 12500, currency: 'JOD', staff_profile_ids: [3] },
  ],
  staff: [{ profile_id: 3, display_name: 'Lina Saleh' }],
  bot_check: null,
};

const booked = {
  id: 9,
  status: 'confirmed',
  starts_at: slots[1].starts_at,
  ends_at: slots[1].ends_at,
  service: { id: 1, name: { en: 'Cake tasting' }, duration_minutes: 30 },
  staff_name: 'Lina Saleh',
  price_minor: 12500,
  currency: 'JOD',
  customer: { name: 'Rana Haddad', email: 'rana@example-bakery.com' },
  manage_token: 'b'.repeat(64),
};

const fetchMock = jest.fn();
let clock = 0;

beforeEach(() => {
  fetchMock.mockReset();
  global.fetch = fetchMock as unknown as typeof fetch;
  clock = 1_000;
  jest.spyOn(Date, 'now').mockImplementation(() => clock);
});

afterEach(() => {
  jest.restoreAllMocks();
});

async function chooseTimeAndFill(time: string) {
  fireEvent.click(await screen.findByRole('radio', { name: /Cake tasting/ }));
  fireEvent.click(await screen.findByRole('button', { name: time }));
  fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Rana Haddad' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'rana@example-bakery.com' } });
}

describe('PublicBookingScreen', () => {
  it('says a time was just taken, fetches the free times again and keeps the details', async () => {
    let slotCalls = 0;
    const posted: unknown[] = [];
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/public/booking/example-bakery') return respond(200, { data: page });
      if (url.startsWith('/api/v1/public/booking/example-bakery/slots')) {
        slotCalls++;
        // After the conflict, 09:00 is gone.
        return respond(200, { data: slotCalls === 1 ? slots : slots.slice(1) });
      }
      if (url === '/api/v1/public/booking/example-bakery/bookings' && init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)));
        return respond(409, { error: 'That time was just taken' });
      }
      return respond(404, { error: 'unexpected' });
    });

    render(<PublicBookingScreen workspace="example-bakery" />);
    await chooseTimeAndFill('09:00');
    clock = 9_000;
    fireEvent.click(screen.getByTestId('book-submit'));

    await waitFor(() => expect(posted).toHaveLength(1));
    // The free times were asked for again, and 09:00 has gone.
    await waitFor(() => expect(slotCalls).toBe(2));
    await waitFor(() => expect(screen.queryByRole('button', { name: '09:00' })).toBeNull());
    const times = screen.getByRole('group', { name: 'Free times' });
    expect(within(times).getByRole('button', { name: '09:30' })).toHaveAttribute('aria-pressed', 'false');
    // The time was let go, so the details and the problem wait for a new one; what was typed is kept.
    fireEvent.click(within(times).getByRole('button', { name: '09:30' }));
    expect(screen.getByLabelText('Your name')).toHaveValue('Rana Haddad');
    expect(screen.getByLabelText('Email')).toHaveValue('rana@example-bakery.com');
  });

  it('shows the time-taken words while no time is chosen', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/public/booking/example-bakery') return respond(200, { data: page });
      if (url.startsWith('/api/v1/public/booking/example-bakery/slots')) return respond(200, { data: slots });
      if (init?.method === 'POST') return respond(409, { error: 'taken' });
      return respond(404, {});
    });
    render(<PublicBookingScreen workspace="example-bakery" />);
    await chooseTimeAndFill('09:00');
    clock = 9_000;
    fireEvent.click(screen.getByTestId('book-submit'));
    expect(await screen.findByTestId('book-problem')).toHaveTextContent('Someone has just booked that time');
  });

  it('sends the hidden field empty and how long the page was open, then confirms', async () => {
    const posted: Record<string, unknown>[] = [];
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/public/booking/example-bakery') return respond(200, { data: page });
      if (url.startsWith('/api/v1/public/booking/example-bakery/slots')) return respond(200, { data: slots });
      if (init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)));
        return respond(201, { data: booked, payment: null });
      }
      return respond(404, {});
    });

    render(<PublicBookingScreen workspace="example-bakery" />);
    await chooseTimeAndFill('09:30');

    // The hidden field is there for a bot, and out of reach for a person and a screen reader.
    const honeypot = screen.getByTestId('book-website');
    expect(honeypot).toHaveAttribute('tabindex', '-1');
    expect(honeypot.closest('[aria-hidden="true"]')).not.toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Website' })).toBeNull();

    clock = 8_500;
    fireEvent.click(screen.getByTestId('book-submit'));
    await screen.findByTestId('book-done');

    expect(posted).toHaveLength(1);
    expect(posted[0]).toEqual({
      service_id: 1,
      starts_at: expect.stringMatching(/T09:30:00\+03:00$/),
      name: 'Rana Haddad',
      email: 'rana@example-bakery.com',
      website: '',
      elapsed_ms: 7_500,
    });
    expect(screen.getByTestId('book-manage')).toHaveAttribute('href', `/booking/${'b'.repeat(64)}`);
    expect(screen.getByTestId('book-ics')).toHaveAttribute('href', `/api/v1/public/bookings/${'b'.repeat(64)}/ics`);
  });

  it('says plainly when the page is not available', async () => {
    fetchMock.mockImplementation(() => respond(404, { error: 'This booking page is not available' }));
    render(<PublicBookingScreen workspace="no-such-bakery" />);
    expect(await screen.findByTestId('book-closed')).toHaveTextContent('This booking page isn’t available');
  });

  it('offers the next week when nothing is free in this one', async () => {
    const asked: string[] = [];
    fetchMock.mockImplementation((url: string) => {
      if (url === '/api/v1/public/booking/example-bakery') return respond(200, { data: page });
      if (url.includes('/slots')) {
        asked.push(url);
        return respond(200, { data: [] });
      }
      return respond(404, {});
    });
    render(<PublicBookingScreen workspace="example-bakery" />);
    fireEvent.click(await screen.findByRole('radio', { name: /Cake tasting/ }));
    fireEvent.click(await screen.findByTestId('book-next-week'));
    await waitFor(() => expect(asked).toHaveLength(2));
    expect(new URL(asked[1], 'http://x').searchParams.get('from')).not.toBe(new URL(asked[0], 'http://x').searchParams.get('from'));
  });
  it('sends a request for a service that needs approval, and says the time is held (design 3.3)', async () => {
    const requestPage = { ...page, services: [{ ...page.services[0], sends_request: true }] };
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/public/booking/example-bakery') return respond(200, { data: requestPage });
      if (url.startsWith('/api/v1/public/booking/example-bakery/slots')) return respond(200, { data: slots });
      if (url === '/api/v1/public/booking/example-bakery/bookings' && init?.method === 'POST') {
        return respond(201, { data: { ...booked, status: 'requested' } });
      }
      return respond(404, {});
    });
    render(<PublicBookingScreen workspace="example-bakery" />);
    expect(await screen.findByTestId('book-service-request')).toBeInTheDocument();
    await chooseTimeAndFill('09:30');
    expect(screen.getByTestId('book-submit')).toHaveTextContent('Send request');
    clock = 9_000;
    fireEvent.click(screen.getByTestId('book-submit'));
    expect(await screen.findByTestId('book-done')).toHaveTextContent('Request sent');
  });
});
