/**
 * Blocks in customer chats and Forward (design 3.5, UC.5, UC.9, UC.10,
 * WHIT-1106).
 *
 * What would go wrong unnoticed: a payment link sent without Whity Pay making
 * it first (a dead link to a customer), the wrong form or no times sent, the
 * team's card losing the amount or the link, and a forwarded message that
 * doesn't say it was forwarded.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';

const mockApiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({ apiFetch: (...a: unknown[]) => mockApiFetch(...a) }));
jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_k: string, f: string, v?: Record<string, string>) =>
      Object.entries(v ?? {}).reduce((s, [k, x]) => s.replace(`{${k}}`, x), f),
}));
jest.mock('@whity/features/datetime', () => ({
  ...jest.requireActual('@whity/features/datetime'),
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, time: (v: string | null) => v, date: (v: string | null) => v }),
}));

import { CustomerBlockCard, CustomerBlockDialog, wallTimeIn, wordsAbove } from '@/components/chat/customer-blocks';
import type { CustomerBlockOptions } from '@/lib/chat-wire';

const t = (_key: string, fallback: string, values?: Record<string, string | number>) =>
  Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback);

function respond(body: unknown, status = 200) {
  return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve({ data: body }) });
}

const options: CustomerBlockOptions = {
  booking_page: true,
  time_zone: 'Asia/Amman',
  services: [{ id: 1, name: 'Cake tasting', minutes: 75 }],
  forms: [
    { id: 1, title: 'Event order' },
    { id: 2, title: 'Allergy check' },
  ],
};

function bodyOf(call: unknown[]): Record<string, unknown> {
  return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

beforeEach(() => mockApiFetch.mockReset());

test('a payment link is made in Whity Pay first, then sent with its amount and words', async () => {
  mockApiFetch.mockImplementation((path: string) =>
    path.includes('whity-pay') ? respond({ reference: 'WP-77', url: 'https://pay.example.test/c/77' }, 201) : respond({ id: 9 }, 201)
  );
  const onSent = jest.fn();
  render(<CustomerBlockDialog t={t} kind="payment" conversationId={5} customerName="Ahmad" options={options} currency="USD" onClose={jest.fn()} onSent={onSent} />);

  const send = screen.getByRole('button', { name: 'Send' });
  expect(send).toBeDisabled();
  await userEvent.type(screen.getByLabelText('What it is for'), 'ORD-2291');
  await userEvent.type(screen.getByLabelText('Amount (USD)'), '18.50');
  await userEvent.type(screen.getByLabelText('Your words'), 'Here is the link');
  await userEvent.click(send);

  await waitFor(() => expect(onSent).toHaveBeenCalled());
  const [pay, sent] = mockApiFetch.mock.calls;
  expect(pay[0]).toBe('/api/v1/whity-pay/workspace/payments');
  expect(bodyOf(pay)).toMatchObject({ amount: '18.50', currency: 'USD', description: 'ORD-2291' });
  expect(sent[0]).toBe('/api/v1/chat/inbox/5/send-block');
  expect(bodyOf(sent)).toEqual({
    type: 'payment',
    text: 'Here is the link',
    url: 'https://pay.example.test/c/77',
    reference: 'WP-77',
    amount: '18.50',
    currency: 'USD',
    description: 'ORD-2291',
  });
});

test('a failed payment link sends nothing and says so', async () => {
  mockApiFetch.mockImplementation(() => Promise.resolve({ ok: false, status: 422, json: () => Promise.resolve({ error: 'Whity Pay is not set up.' }) }));
  const onSent = jest.fn();
  render(<CustomerBlockDialog t={t} kind="payment" conversationId={5} customerName="Ahmad" options={options} currency="USD" onClose={jest.fn()} onSent={onSent} />);

  await userEvent.type(screen.getByLabelText('What it is for'), 'ORD-2291');
  await userEvent.type(screen.getByLabelText('Amount (USD)'), '18');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));

  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(mockApiFetch).toHaveBeenCalledTimes(1);
  expect(onSent).not.toHaveBeenCalled();
});

test('asking for a form sends the form picked', async () => {
  mockApiFetch.mockImplementation(() => respond({ id: 9 }, 201));
  const onSent = jest.fn();
  render(<CustomerBlockDialog t={t} kind="form" conversationId={5} customerName="Ahmad" options={options} currency="USD" onClose={jest.fn()} onSent={onSent} />);

  await userEvent.selectOptions(screen.getByLabelText('Form'), '2');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));

  await waitFor(() => expect(onSent).toHaveBeenCalled());
  expect(bodyOf(mockApiFetch.mock.calls[0])).toEqual({ type: 'form', text: '', form_id: 2 });
});

test('booking times need at least one time', async () => {
  render(<CustomerBlockDialog t={t} kind="booking_times" conversationId={5} customerName="Ahmad" options={options} currency="USD" onClose={jest.fn()} onSent={jest.fn()} />);

  expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Add a time' })).toBeInTheDocument();
});

test("the team's card keeps the amount, the link and the customer's view", () => {
  render(
    <CustomerBlockCard
      t={t}
      block={{ type: 'payment', description: 'ORD-2291', amount: '18.00', currency: 'USD', url: 'https://pay.example.test/c/77', reference: 'WP-77' }}
    />
  );

  expect(screen.getByText('ORD-2291')).toBeInTheDocument();
  expect(screen.getByText('USD 18.00')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Open what they see' })).toHaveAttribute('href', 'https://pay.example.test/c/77');
});

test('booking times show the service and each time offered', () => {
  render(
    <CustomerBlockCard
      t={t}
      block={{ type: 'booking_times', service: { id: 1, name: 'Cake tasting', minutes: 75 }, times: ['2099-01-04T08:00:00Z', '2099-01-04T12:00:00Z'], url: 'https://app.whity.test/book/bakery' }}
    />
  );

  expect(screen.getByText('Cake tasting · 75 minutes')).toBeInTheDocument();
  expect(screen.getByText('2099-01-04T08:00:00Z')).toBeInTheDocument();
  expect(screen.getByText('2099-01-04T12:00:00Z')).toBeInTheDocument();
});

test("a time is picked on the booking page's clock, wherever the person is", () => {
  expect(wallTimeIn('2026-11-20T10:00', 'Asia/Amman')).toBe('2026-11-20T07:00:00.000Z');
  expect(wallTimeIn('2026-11-20T10:00', 'UTC')).toBe('2026-11-20T10:00:00.000Z');
});

test('the team sees only the words above a block; the card has the line and the link', () => {
  const block = { type: 'form' as const, form: { id: 1, title: 'Event order' }, url: 'https://app.whity.test/f/aaa' };
  expect(wordsAbove('Please fill this in\n\nEvent order\nhttps://app.whity.test/f/aaa', block)).toBe('Please fill this in');
  expect(wordsAbove('Event order\nhttps://app.whity.test/f/aaa', block)).toBe('');
  expect(wordsAbove('Something else', block)).toBe('Something else');
});
