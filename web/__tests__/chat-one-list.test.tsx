/**
 * One chat (design 3.5, UC.1, WHIT-1093): team chats and customer
 * conversations in one list.
 *
 * What would go wrong unnoticed: customer conversations missing for someone
 * who may read them (or shown to someone who may not), pinned chats not on
 * top, a tab that keeps the wrong kind, and a customer conversation opened as a
 * team chat.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@testing-library/react';

const replace = jest.fn();
let search = '';
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(search),
}));

const mockApiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  apiFetch: (...args: unknown[]) => mockApiFetch(...args),
  api: { GET: jest.fn(() => new Promise(() => undefined)) },
}));
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ user: { id: 7, profile_id: 7 } }) }));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/hooks/useCapabilities', () => ({ useCapabilities: () => ({ has: () => true, loading: false }) }));
jest.mock('@/lib/commands/new-intent', () => ({ useNewIntent: () => undefined }));
jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, values?: Record<string, string | number>) =>
      Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback),
  useLanguageDirection: () => 'ltr',
}));
jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, time: (v: string | null) => v, date: (v: string | null) => v }),
}));

import { ChatScreen } from '@/components/chat/chat-screen';

const team = (over: Record<string, unknown>) => ({
  kind: 'team',
  type: 'group',
  title: 'Abdali team',
  with: null,
  people_count: 3,
  agents_count: 0,
  link: null,
  unread: 0,
  pinned: false,
  last_message: null,
  last_message_at: '2026-11-16T09:00:00Z',
  created_at: '2026-11-01T09:00:00Z',
  ...over,
});

const customer = (over: Record<string, unknown>) => ({
  kind: 'customer',
  type: 'customer',
  title: 'Ahmad Khalil',
  channel: 'webchat',
  status: 'open',
  subject: null,
  contact: { name: 'Ahmad Khalil', address: 'visitor:1' },
  customer: null,
  assignee: null,
  waiting_since: null,
  unread: 0,
  pinned: false,
  last_message: { from: 'customer', body: 'Do you deliver to Khalda?', by_agent: false, author: null },
  last_message_at: '2026-11-16T08:00:00Z',
  created_at: '2026-11-16T08:00:00Z',
  ...over,
});

function respond(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
}

function inbox(data: unknown[], customers = true) {
  mockApiFetch.mockImplementation((path: string) => (path === '/api/v1/chat/inbox' ? respond({ data, customers }) : respond({ data: {} })));
}

beforeEach(() => {
  search = '';
  replace.mockReset();
  mockApiFetch.mockReset();
});

test('pinned chats sit on top, then everything else, team and customers together', async () => {
  inbox([team({ id: 4, pinned: true }), customer({ id: 1, unread: 2, waiting_since: new Date(Date.now() - 12 * 60000).toISOString() }), team({ id: 5, type: 'direct', title: 'Awss Haddad' })]);
  render(<ChatScreen />);

  const pinned = (await screen.findByRole('heading', { name: 'Pinned' })).closest('section') as HTMLElement;
  expect(within(pinned).getByText('Abdali team')).toBeInTheDocument();
  const all = screen.getByRole('heading', { name: 'All' }).closest('section') as HTMLElement;
  expect(within(all).getByText('Ahmad Khalil')).toBeInTheDocument();
  expect(within(all).getByText('Awss Haddad')).toBeInTheDocument();
  expect(within(all).getByText('Waiting 12 min')).toBeInTheDocument();
  expect(within(all).getByLabelText('2 unread')).toBeInTheDocument();
});

test('the tabs keep their kind: Customers, Team, Unread', async () => {
  inbox([team({ id: 4 }), customer({ id: 1, unread: 1 })]);
  render(<ChatScreen />);
  await screen.findByText('Abdali team');

  await userEvent.click(screen.getByRole('tab', { name: /Customers/ }));
  expect(screen.queryByText('Abdali team')).not.toBeInTheDocument();
  expect(screen.getByText('Ahmad Khalil')).toBeInTheDocument();

  await userEvent.click(screen.getByRole('tab', { name: 'Team' }));
  expect(screen.getByText('Abdali team')).toBeInTheDocument();
  expect(screen.queryByText('Ahmad Khalil')).not.toBeInTheDocument();

  await userEvent.click(screen.getByRole('tab', { name: /Unread/ }));
  expect(screen.queryByText('Abdali team')).not.toBeInTheDocument();
  expect(screen.getByText('Ahmad Khalil')).toBeInTheDocument();
});

test('no Customers tab for someone who may not read customer conversations', async () => {
  inbox([team({ id: 4 })], false);
  render(<ChatScreen />);
  await screen.findByText('Abdali team');

  expect(screen.queryByRole('tab', { name: /Customers/ })).not.toBeInTheDocument();
});

test('opening a row goes to /chat?c=<id>, whatever its kind', async () => {
  inbox([customer({ id: 1 })]);
  render(<ChatScreen />);

  await userEvent.click(await screen.findByRole('button', { name: /Ahmad Khalil/ }));
  expect(replace).toHaveBeenCalledWith('/chat?c=1', { scroll: false });
});

test('a customer conversation opens as one, not as a team chat', async () => {
  search = 'c=1';
  inbox([customer({ id: 1 })]);
  render(<ChatScreen />);
  await screen.findAllByText('Ahmad Khalil');

  // The team chat would ask the chat API for it; the customer conversation is read from Messages.
  expect(mockApiFetch).not.toHaveBeenCalledWith('/api/v1/chat/chats/1', undefined);
});
