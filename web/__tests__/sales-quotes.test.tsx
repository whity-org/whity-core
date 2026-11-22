/**
 * Quotes on the web (WHIT-1341, design section 34 §3.8): Sales lists quotes
 * with their number, customer, total and state; a draft's one main button is
 * Send, which shows the customer's link once; a sent quote records the
 * customer's answer; and someone who only reads sees no button that changes
 * anything.
 */

import React, { Suspense } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

const push = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ push }),
  useSearchParams: () => new URLSearchParams(),
}));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/lib/branding-context', () => ({ useWorkspaceCurrency: () => 'USD' }));

let permissions: string[] = ['quotes:read', 'quotes:write'];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (p: string) => permissions.includes(p), hasPermission: (p: string) => permissions.includes(p), loading: false }),
}));

const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...a: unknown[]) => GET(...a),
    POST: (...a: unknown[]) => POST(...a),
    PATCH: jest.fn(),
  },
}));

import SalesPage from '@/app/(protected)/sales/page';
import QuotePage from '@/app/(protected)/sales/quotes/[id]/page';

const ok = (data: unknown, status = 200) => ({ data, error: undefined, response: new Response(null, { status }) });

const quote = (over: Record<string, unknown> = {}) => ({
  id: 7,
  number: null,
  revision: 1,
  replaces_id: null,
  status: 'draft',
  contact_id: 41,
  contact_name: 'Example Café',
  contact_email: 'orders@example.com',
  issue_date: null,
  valid_until: '2026-12-21',
  currency: 'USD',
  prices_include_tax: false,
  subtotal_minor: 12000,
  tax_minor: 1920,
  total_minor: 13920,
  notes: '',
  terms: 'Half on order.',
  decided_by_name: '',
  decided_online: false,
  decline_reason: '',
  created_by_name: 'Amro Saleh',
  created_at: '2026-11-21T09:00:00Z',
  updated_at: '2026-11-21T09:00:00Z',
  lines: [
    { id: 1, variant_id: 3, sku: 'CAKE-B', unit: 'pc', description: 'Birthday cake', quantity: '2', price_minor: 4500, discount_bp: 0, tax_bp: 1600, line_total_minor: 9000 },
    { id: 2, variant_id: null, sku: null, unit: null, description: 'Decorating, per hour', quantity: '1.500', price_minor: 2000, discount_bp: 0, tax_bp: 1600, line_total_minor: 3000 },
  ],
  conversions: [],
  revisions: [{ id: 7, revision: 1, status: 'draft' }],
  ...over,
});

async function openQuote() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <QuotePage params={Promise.resolve({ id: '7' })} />
      </Suspense>
    );
  });
}

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
  push.mockReset();
  permissions = ['quotes:read', 'quotes:write'];
});

it('lists quotes with their number, customer, total and state, and offers a new one', async () => {
  GET.mockResolvedValue(ok({ data: [quote({ id: 8, number: 'QUO-0001', revision: 2, status: 'sent' }), quote()] }));

  render(<SalesPage />);

  expect(await screen.findByText('QUO-0001')).toBeInTheDocument();
  expect(screen.getByText('r2')).toBeInTheDocument();
  expect(screen.getByText('Not sent yet')).toBeInTheDocument();
  expect(screen.getAllByText('Example Café')).toHaveLength(2);
  expect(screen.getAllByText('$139.20').length).toBeGreaterThan(0);
  expect(screen.getAllByTestId('quote-status').map((b) => b.textContent)).toEqual(['Sent', 'Draft']);
  expect(screen.getByTestId('quote-new')).toHaveAttribute('href', '/sales/quotes/new');
});

it('sends a draft and shows the customer’s link once', async () => {
  GET.mockResolvedValue(ok({ data: quote() }));
  POST.mockResolvedValue(
    ok({ data: quote({ status: 'sent', number: 'QUO-0001' }), link: 'https://app.example.com/quote/abc', emailed: true, document_id: 55 })
  );
  await openQuote();

  expect(await screen.findByText('Decorating, per hour')).toBeInTheDocument();
  expect(screen.getByTestId('quote-edit')).toBeInTheDocument();
  fireEvent.click(screen.getByTestId('quote-send'));
  expect(screen.getByTestId('quote-send-dialog')).toHaveTextContent('Email it to orders@example.com');
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-send-confirm'));
  });

  expect(POST).toHaveBeenCalledWith('/api/v1/sales/quotes/{id}/send', { params: { path: { id: 7 } }, body: { email: true } });
  const dialog = await screen.findByTestId('quote-link-dialog');
  expect(dialog).toHaveTextContent('Sent by email');
  expect(screen.getByDisplayValue('https://app.example.com/quote/abc')).toBeInTheDocument();
});

it('asks for approval instead when the workspace approves quotes first', async () => {
  GET.mockResolvedValue(ok({ data: quote() }));
  POST.mockImplementation(async (path: string) =>
    path.endsWith('/send')
      ? { data: undefined, error: { error: 'Quotes are approved before they go out here.', details: { code: 'route_needed' } }, response: new Response(null, { status: 409 }) }
      : ok({ data: quote({ status: 'waiting_approval' }) })
  );
  await openQuote();

  fireEvent.click(await screen.findByTestId('quote-send'));
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-send-confirm'));
  });

  await waitFor(() => expect(POST).toHaveBeenCalledWith('/api/v1/sales/quotes/{id}/submit', expect.anything()));
});

it('records the customer’s answer on a sent quote, asking who accepted', async () => {
  GET.mockResolvedValue(ok({ data: quote({ status: 'sent', number: 'QUO-0001', sent_at: '2026-11-21T10:00:00Z' }) }));
  POST.mockResolvedValue(ok({ data: quote({ status: 'accepted', number: 'QUO-0001' }) }));
  await openQuote();

  fireEvent.click(await screen.findByTestId('quote-answer'));
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-answer-save'));
  });
  expect(screen.getByText('Write the name of who accepted.')).toBeInTheDocument();
  expect(POST).not.toHaveBeenCalled();

  fireEvent.change(screen.getByLabelText('Who accepted'), { target: { value: 'Awss Haddad' } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('quote-answer-save'));
  });
  expect(POST).toHaveBeenCalledWith('/api/v1/sales/quotes/{id}/decision', { params: { path: { id: 7 } }, body: { accepted: true, name: 'Awss Haddad' } });
});

it('shows a reader the quote and its state, with nothing to change', async () => {
  permissions = ['quotes:read'];
  GET.mockResolvedValue(ok({ data: quote({ status: 'sent', number: 'QUO-0001' }) }));
  await openQuote();

  expect(await screen.findByText('Decorating, per hour')).toBeInTheDocument();
  expect(screen.queryByTestId('quote-send')).not.toBeInTheDocument();
  expect(screen.queryByTestId('quote-answer')).not.toBeInTheDocument();
  expect(screen.queryByTestId('quote-withdraw')).not.toBeInTheDocument();
  expect(screen.getByTestId('quote-print')).toBeInTheDocument();
});
