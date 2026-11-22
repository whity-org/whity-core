/**
 * The public quote page (WHIT-1342, design section 34 §3.3, QT.5): with no
 * sign-in the customer sees the quote and accepts it with their name or
 * declines it; an answered, expired, withdrawn or replaced quote says so in
 * plain words, and a link that opens nothing never says "error".
 */

import React, { Suspense } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';

// The shell (logo, language switcher) is the booking page's, tested there.
jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...a: unknown[]) => GET(...a), POST: (...a: unknown[]) => POST(...a) },
}));

import PublicQuotePage from '@/app/quote/[token]/page';

const TOKEN = 'ab'.repeat(32);
const ok = (data: unknown, status = 200) => ({ data, error: undefined, response: new Response(null, { status }) });

const quote = (over: Record<string, unknown> = {}) => ({
  number: 'QUO-0001',
  revision: 1,
  status: 'sent',
  customer: 'Example Café',
  issue_date: '2026-11-22',
  valid_until: '2026-12-22',
  currency: 'USD',
  prices_include_tax: false,
  subtotal_minor: 8100,
  tax_minor: 1296,
  total_minor: 9396,
  terms: 'Half on order, half on delivery.',
  decided_by_name: null,
  decided_at: null,
  replaced: false,
  lines: [{ description: 'Birthday cake, large', quantity: '2.000', unit: null, price_minor: 4500, discount_bp: 1000, tax_bp: 1600, line_total_minor: 8100 }],
  workspace: { name: 'Example Bakery', brand: null },
  ...over,
});

async function open(token = TOKEN) {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <PublicQuotePage params={Promise.resolve({ token })} />
      </Suspense>
    );
  });
}

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
});

it('shows the quote and accepts it with the customer’s name', async () => {
  GET.mockResolvedValue(ok({ data: quote() }));
  POST.mockResolvedValue(ok({ data: quote({ status: 'accepted', decided_by_name: 'Awss Haddad', decided_at: '2026-11-22T10:00:00Z' }) }));
  await open();

  expect(await screen.findByText('QUO-0001')).toBeInTheDocument();
  expect(screen.getByText('Birthday cake, large')).toBeInTheDocument();
  expect(screen.getByTestId('quote-public-total')).toHaveTextContent('$93.96');
  expect(screen.getByText('10%')).toBeInTheDocument();
  expect(screen.queryByText(/error/i)).not.toBeInTheDocument();

  fireEvent.click(screen.getByTestId('quote-public-accept'));
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-public-send'));
  });
  expect(screen.getByText('Type your name to accept the quote.')).toBeInTheDocument();
  expect(POST).not.toHaveBeenCalled();

  fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Awss Haddad' } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-public-send'));
  });
  expect(POST).toHaveBeenCalledWith('/api/v1/public/quotes/{token}/accept', { params: { path: { token: TOKEN } }, body: { name: 'Awss Haddad' } });
  expect(await screen.findByTestId('quote-accepted')).toHaveTextContent('Accepted by Awss Haddad');
  expect(screen.queryByTestId('quote-public-answer')).not.toBeInTheDocument();
});

it('declines with an optional reason', async () => {
  GET.mockResolvedValue(ok({ data: quote() }));
  POST.mockResolvedValue(ok({ data: quote({ status: 'declined', decided_at: '2026-11-22T10:00:00Z' }) }));
  await open();

  fireEvent.click(await screen.findByTestId('quote-public-decline'));
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-public-send'));
  });

  expect(POST).toHaveBeenCalledWith('/api/v1/public/quotes/{token}/decline', { params: { path: { token: TOKEN } }, body: { reason: '' } });
  expect(await screen.findByTestId('quote-declined')).toHaveTextContent('Thank you for letting Example Bakery know');
});

it.each([
  [{ status: 'expired' }, 'quote-expired', 'Ask Example Bakery for a new one'],
  [{ status: 'cancelled' }, 'quote-withdrawn', 'withdrawn this quote'],
  [{ replaced: true }, 'quote-replaced', 'Open the newest link'],
])('says what to do when the quote can no longer be answered (%o)', async (over, testId, words) => {
  GET.mockResolvedValue(ok({ data: quote(over) }));
  await open();

  expect(await screen.findByTestId(testId)).toHaveTextContent(words);
  expect(screen.queryByTestId('quote-public-accept')).not.toBeInTheDocument();
});

it('says a link that opens nothing in plain words, without asking the server for a malformed one', async () => {
  await open('not-a-token');
  expect(await screen.findByTestId('quote-outcome')).toHaveTextContent('This link doesn’t open a quote');
  expect(GET).not.toHaveBeenCalled();

  GET.mockResolvedValue({ data: undefined, error: { error: 'gone' }, response: new Response(null, { status: 404 }) });
  await open();
  expect((await screen.findAllByTestId('quote-outcome'))[1]).toHaveTextContent('Ask the business that sent it for a new one');
});

it('asks to wait after too many tries, and offers to try again when the server doesn’t answer', async () => {
  GET.mockResolvedValueOnce({ data: undefined, error: {}, response: new Response(null, { status: 429 }) });
  await open();
  expect(await screen.findByTestId('quote-outcome')).toHaveTextContent('Wait a minute');

  GET.mockRejectedValueOnce(new Error('offline'));
  GET.mockResolvedValueOnce(ok({ data: quote() }));
  await open();
  const retry = await screen.findByRole('button', { name: 'Try again' });
  await act(async () => {
    fireEvent.click(retry);
  });
  expect(await screen.findByText('Birthday cake, large')).toBeInTheDocument();
});
