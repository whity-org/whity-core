/**
 * A customer conversation inside Chat (design 3.5, UC.3, WHIT-1093; it was the
 * Messages page, ADR 0016, WHIT-939).
 *
 * What would go wrong unnoticed: a reply posted as a note (or the reverse), a
 * held agent reply shown as if it had gone, a note shown like a customer
 * message, and someone without the reply permission given a box to write in.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, values?: Record<string, string | number>) =>
      Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback),
}));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, time: (v: string | null) => v, date: (v: string | null) => v }),
}));

import { CustomerChat } from '@/components/chat/customer-chat';

let canReply = true;
let canManage = true;
const onRead = jest.fn();

function Chat({ unread = 0, details = false }: { unread?: number; details?: boolean }) {
  return (
    <CustomerChat
      id={42}
      canReply={canReply}
      canManage={canManage}
      unreadAtOpen={unread}
      detailsOpen={details}
      pinned={false}
      onPin={() => undefined}
      onToggleDetails={() => undefined}
      onBack={() => undefined}
      onChanged={() => undefined}
      onRead={onRead}
    />
  );
}

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
  canReply = true;
  canManage = true;
  onRead.mockReset();
  mockApiClient.mockReset();
  mockApiClient.mockImplementation(routes);
  addToast.mockReset();
});

test('shows the conversation, marks it read, and puts the "n new" line above the unread', async () => {
  render(<Chat unread={1} />);

  expect(await screen.findByText('Check the oven')).toBeInTheDocument();
  expect(screen.getByText('Only the team sees this')).toBeInTheDocument();
  expect(screen.getByRole('separator')).toHaveTextContent('1 new');
  await waitFor(() => expect(onRead).toHaveBeenCalledWith(3));
});

test('the details pane says how to reach the customer', async () => {
  render(<Chat details />);

  const pane = await screen.findByRole('complementary', { name: 'Sara' });
  expect(within(pane).getByText('sara@example.test')).toBeInTheDocument();
  expect(within(pane).getByText('Unassigned')).toBeInTheDocument();
});

test('a held agent reply says it has not gone, and can be cancelled', async () => {
  render(<Chat />);

  expect(await screen.findByText(/Not sent yet\. Sends at 2026-11-11 08:05:00/)).toBeInTheDocument();
  expect(screen.getByText(/Agent for Lina/)).toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/messages/3/cancel', { method: 'POST' }));
});

test('a reply goes to replies and a note goes to notes', async () => {
  render(<Chat />);
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

  await userEvent.click(screen.getByRole('radio', { name: 'Internal note' }));
  await userEvent.type(screen.getByRole('textbox', { name: 'Your note' }), 'Called her');
  await userEvent.click(screen.getByRole('button', { name: 'Add note' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/messages/conversations/42/notes', expect.objectContaining({ method: 'POST' }))
  );
});

test('without the reply permission there is no composer', async () => {
  canReply = false;
  canManage = false;
  render(<Chat />);

  expect(await screen.findByText(/Replying needs the “Reply to customers” permission/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Assign/ })).not.toBeInTheDocument();
});

test('assigning to me sends my id', async () => {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) =>
    init?.method === 'PATCH' ? json(200, { data: conversation }) : routes(url, init)
  );
  render(<Chat />);

  const header = await screen.findByRole('heading', { name: 'Sara', level: 2 });
  await userEvent.click(within(header.closest('header') as HTMLElement).getByRole('button', { name: 'Assign' }));
  await userEvent.click(await screen.findByRole('menuitem', { name: 'Assign to me' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/messages/conversations/42',
      expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ assigned_profile_id: 7 }) })
    )
  );
});

test('Edit stops a held reply and puts its words in the composer', async () => {
  render(<Chat />);

  await userEvent.click(await screen.findByRole('button', { name: 'Edit' }));
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/messages/3/cancel', { method: 'POST' }));
  expect(await screen.findByRole('textbox', { name: 'Your reply' })).toHaveValue('Sure, Thursday?');
});

test('a failed reply says why and can be sent again', async () => {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) =>
    url === '/api/v1/messages/conversations/42' && !init?.method
      ? json(200, {
          data: {
            ...conversation,
            messages: [
              message({ id: 9, kind: 'outbound', body: 'Confirmed', status: 'failed', status_detail: 'The address bounced.', author: { id: 7, name: 'Lina' } }),
            ],
          },
        })
      : routes(url, init)
  );
  render(<Chat />);

  expect(await screen.findByText(/Not sent: The address bounced\./)).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
  await waitFor(() =>
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/messages/conversations/42/replies',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ body: 'Confirmed' }) })
    )
  );
});
