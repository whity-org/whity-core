/**
 * Messages (ADR 0016, WHIT-939): the staff inbox.
 *
 * What would go wrong unnoticed: a reply posted as a note (or the reverse), a
 * held agent reply shown as if it had gone, a note shown like a customer
 * message, and a person without access seeing an empty inbox instead of
 * being told they cannot see it.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

let held: string[] = ['messages:read', 'messages:reply', 'messages:manage'];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (c: string) => held.includes(c), loading: false }),
}));

jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, values?: Record<string, string | number>) =>
      Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback),
}));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, relative: () => '5 min' }),
}));

jest.mock('@/components/admin/admin-header', () => ({
  AdminHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

import MessagesPage from '@/app/(protected)/admin/messages/page';

function json(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const conversation = {
  id: 42,
  channel: 'email',
  subject: 'Cake order',
  status: 'open',
  contact: { name: 'Sara', address: 'sara@example.test' },
  customer: null,
  assignee: null,
  last_message_at: '2026-11-11 08:00:00',
  last_inbound_at: '2026-11-11 08:00:00',
  preview: 'Two cakes please',
  created_at: '2026-11-11 08:00:00',
  closed_at: null,
};

function message(over: Record<string, unknown>) {
  return {
    conversation_id: 42,
    has_html: false,
    author: null,
    by_agent: false,
    source: null,
    status_detail: null,
    attachments_not_shown: 0,
    send_after: null,
    sent_at: null,
    created_at: '2026-11-11 08:00:00',
    ...over,
  };
}

function routes(url: string, init?: RequestInit) {
  if (url.startsWith('/api/v1/messages/conversations?')) {
    return json(200, { data: [conversation], next_cursor: null, total: 1, counts: { open: 1, pending: 0, closed: 3 } });
  }
  if (url === '/api/v1/messages/conversations/42' && !init?.method) {
    return json(200, {
      data: {
        ...conversation,
        messages: [
          message({ id: 1, kind: 'inbound', body: 'Two cakes please', status: 'received' }),
          message({ id: 2, kind: 'note', body: 'Check the oven', status: 'noted', author: { id: 7, name: 'Lina' } }),
          message({
            id: 3,
            kind: 'outbound',
            body: 'Sure, Thursday?',
            status: 'queued',
            by_agent: true,
            author: { id: 7, name: 'Lina' },
            send_after: '2026-11-11 08:05:00',
          }),
        ],
      },
    });
  }
  if (url.endsWith('/replies') || url.endsWith('/notes') || url.endsWith('/cancel')) {
    return json(201, { data: {} });
  }
  return json(404, {});
}

beforeEach(() => {
  held = ['messages:read', 'messages:reply', 'messages:manage'];
  mockApiClient.mockReset();
  mockApiClient.mockImplementation(routes);
  addToast.mockReset();
  window.history.replaceState(null, '', '/admin/messages');
});

test('lists conversations with status counts and opens one', async () => {
  render(<MessagesPage />);

  expect(await screen.findByText('Sara')).toBeInTheDocument();
  expect(screen.getByRole('tab', { name: /Closed\s*3/ })).toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: /Sara/ }));
  expect(await screen.findByText('Check the oven')).toBeInTheDocument();
  expect(window.location.search).toBe('?c=42');
});

test('a held agent reply says it has not gone, and can be cancelled', async () => {
  window.history.replaceState(null, '', '/admin/messages?c=42');
  render(<MessagesPage />);

  expect(await screen.findByText(/Not sent yet\. Sends at 2026-11-11 08:05:00/)).toBeInTheDocument();
  expect(screen.getByText('Agent for Lina')).toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: 'Cancel this message' }));
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/messages/3/cancel', { method: 'POST' }));
});

test('a reply goes to replies and a note goes to notes', async () => {
  window.history.replaceState(null, '', '/admin/messages?c=42');
  render(<MessagesPage />);
  await screen.findByText('Two cakes please', { selector: 'p' });

  const box = screen.getByRole('textbox', { name: 'Your reply' });
  await userEvent.type(box, 'Thursday works');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/messages/conversations/42/replies',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ body: 'Thursday works' }) })
    )
  );

  await userEvent.click(screen.getByRole('button', { name: 'Internal note' }));
  await userEvent.type(screen.getByRole('textbox', { name: 'Your note' }), 'Called her');
  await userEvent.click(screen.getByRole('button', { name: 'Add note' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/messages/conversations/42/notes', expect.objectContaining({ method: 'POST' }))
  );
});

test('without the reply permission there is no composer', async () => {
  held = ['messages:read'];
  window.history.replaceState(null, '', '/admin/messages?c=42');
  render(<MessagesPage />);

  expect(await screen.findByText(/Replying needs the Reply permission/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Assign to me' })).not.toBeInTheDocument();
});

test('a 403 says the person cannot see Messages', async () => {
  mockApiClient.mockImplementation(() => json(403, { error: 'Forbidden' }));
  render(<MessagesPage />);

  expect(await screen.findByText('You can’t see Messages')).toBeInTheDocument();
});

test('assigning to me sends my id', async () => {
  window.history.replaceState(null, '', '/admin/messages?c=42');
  mockApiClient.mockImplementation((url: string, init?: RequestInit) =>
    init?.method === 'PATCH' ? json(200, { data: conversation }) : routes(url, init)
  );
  render(<MessagesPage />);

  const header = await screen.findByRole('heading', { name: 'Sara', level: 2 });
  await userEvent.click(within(header.closest('header') as HTMLElement).getByRole('button', { name: 'Assign to me' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/messages/conversations/42',
      expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ assigned_profile_id: 7 }) })
    )
  );
});
