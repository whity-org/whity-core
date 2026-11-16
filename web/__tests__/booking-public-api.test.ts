/**
 * The public booking page's pure helpers (WHIT-945): free times grouped by
 * the day they fall on IN THE WORKSPACE'S ZONE, and the body of a booking
 * request with its spam fields.
 *
 * These run in jsdom's default zone (UTC on CI), which is the point of the
 * grouping test: 21:30Z on Saturday is 00:30 on Sunday in Amman, and a shop in
 * Amman means Sunday.
 */

import { bookingRequestBody, daysOfWeek, descriptionOf, isManageToken, weekQuery } from '@/lib/booking/public-api';

const AMMAN = 'Asia/Amman';

describe('daysOfWeek', () => {
  const first = { year: 2026, month: 11, day: 14 }; // Saturday

  it('puts each free time on its day in the workspace zone, not the device zone', () => {
    const slots = [
      { starts_at: '2026-11-15T06:00:00Z', ends_at: '2026-11-15T06:30:00Z' }, // Sun 09:00 Amman
      { starts_at: '2026-11-14T21:30:00Z', ends_at: '2026-11-14T22:00:00Z' }, // Sat 21:30Z = Sun 00:30 Amman
      { starts_at: '2026-11-14T20:30:00Z', ends_at: '2026-11-14T21:00:00Z' }, // Sat 23:30 Amman
    ];
    const week = daysOfWeek(first, slots, AMMAN);

    expect(week).toHaveLength(7);
    expect(week.map((d) => d.date.day)).toEqual([14, 15, 16, 17, 18, 19, 20]);
    // Saturday keeps only its 23:30.
    expect(week[0].items.map((s) => s.starts_at)).toEqual(['2026-11-14T20:30:00Z']);
    // Sunday has 00:30 then 09:00, in time order.
    expect(week[1].items.map((s) => s.starts_at)).toEqual(['2026-11-14T21:30:00Z', '2026-11-15T06:00:00Z']);
    // The rest of the week is there with nothing free, so the page can say so.
    expect(week.slice(2).every((d) => d.items.length === 0)).toBe(true);
  });

  it('reads the same slots onto other days for a workspace in another zone', () => {
    const slots = [{ starts_at: '2026-11-14T21:30:00Z', ends_at: '2026-11-14T22:00:00Z' }];
    expect(daysOfWeek(first, slots, 'America/New_York')[0].items).toHaveLength(1); // 16:30 Saturday
    expect(daysOfWeek(first, slots, AMMAN)[1].items).toHaveLength(1); // 00:30 Sunday
  });

  it('asks for the week from the workspace midnight, with its offset', () => {
    expect(weekQuery(first, AMMAN)).toEqual({
      from: '2026-11-14T00:00:00+03:00',
      to: '2026-11-21T00:00:00+03:00',
    });
  });
});

describe('bookingRequestBody', () => {
  const draft = {
    serviceId: 1,
    profileId: null,
    startsAt: '2026-11-15T09:00:00+03:00',
    name: '  Rana Haddad ',
    email: ' rana@example-bakery.com ',
    phone: '',
    note: '   ',
    website: '',
    elapsedMs: 4210.6,
    botToken: null,
  };

  it('always sends the hidden field and the time on the page, and leaves empty extras out', () => {
    const body = bookingRequestBody(draft);
    expect(body).toEqual({
      service_id: 1,
      starts_at: '2026-11-15T09:00:00+03:00',
      name: 'Rana Haddad',
      email: 'rana@example-bakery.com',
      website: '',
      elapsed_ms: 4211,
    });
    expect('profile_id' in body).toBe(false);
    expect('bot_token' in body).toBe(false);
  });

  it('sends what a bot typed into the hidden field as it is, for the server to refuse', () => {
    expect(bookingRequestBody({ ...draft, website: 'http://spam.example' }).website).toBe('http://spam.example');
  });

  it('sends the person, phone, note and bot token when there are any', () => {
    expect(
      bookingRequestBody({ ...draft, profileId: 3, phone: ' +962 79 555 0142 ', note: 'Birthday', botToken: 'tok' })
    ).toMatchObject({ profile_id: 3, phone: '+962 79 555 0142', note: 'Birthday', bot_token: 'tok' });
  });
});

describe('small readers', () => {
  it('treats an empty description sent as [] as none', () => {
    const service = { id: 1, name: { en: 'Cake tasting' }, duration_minutes: 30, price_minor: 12500, currency: 'JOD', staff_profile_ids: [3] };
    expect(descriptionOf({ ...service, description: [] as unknown as Record<string, string> })).toBeNull();
    expect(descriptionOf({ ...service, description: { en: ' ', ar: '' } })).toBeNull();
    expect(descriptionOf({ ...service, description: { en: 'Six small cakes' } })).toEqual({ en: 'Six small cakes' });
  });

  it('knows a manage token when it sees one', () => {
    expect(isManageToken('a'.repeat(64))).toBe(true);
    expect(isManageToken('A'.repeat(64))).toBe(false);
    expect(isManageToken('not-a-link')).toBe(false);
  });
});
