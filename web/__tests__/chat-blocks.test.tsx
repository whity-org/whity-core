/**
 * Blocks inside chat messages (design 3.5, UC.5, WHIT-1096).
 *
 * What would go wrong unnoticed: a vote that adds instead of replacing in a
 * one-answer poll, a closed poll still taking votes, a record showing a stale
 * status or no way to open it, and a removed record breaking the message.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen } from '@testing-library/react';

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, time: (v: string | null) => v, date: (v: string | null) => v }),
}));
jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_k: string, f: string) => f,
}));

import { MessageBlock } from '@/components/chat/blocks';
import type { ChatPollBlock, ChatRecordBlock } from '@/lib/chat-wire';

const t = (_key: string, fallback: string, values?: Record<string, string | number>) =>
  Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback);

function poll(over: Partial<ChatPollBlock> = {}): ChatPollBlock {
  return {
    type: 'poll',
    question: 'Saturday tasting slot?',
    multiple: false,
    closes_at: null,
    closed: false,
    voters: 4,
    options: [
      { text: '10:00', votes: 3, mine: true, people: ['Amro Saleh', 'Awss Haddad', 'Emad Nasser'] },
      { text: '12:00', votes: 1, mine: false, people: ['Ibrahim Mansour'] },
    ],
    ...over,
  };
}

test('a one-answer poll replaces your vote, and tapping your answer takes it back', async () => {
  const onVote = jest.fn();
  render(<MessageBlock t={t} block={poll()} onVote={onVote} />);

  expect(screen.getByText('4 people voted')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: /12:00/ }));
  expect(onVote).toHaveBeenLastCalledWith([1]);
  await userEvent.click(screen.getByRole('button', { name: /10:00/ }));
  expect(onVote).toHaveBeenLastCalledWith([]);
});

test('a poll of several answers adds to your choice', async () => {
  const onVote = jest.fn();
  render(<MessageBlock t={t} block={poll({ multiple: true })} onVote={onVote} />);

  await userEvent.click(screen.getByRole('button', { name: /12:00/ }));
  expect(onVote).toHaveBeenLastCalledWith([0, 1]);
});

test('a closed poll shows its counts and takes no votes', () => {
  render(<MessageBlock t={t} block={poll({ closed: true })} onVote={jest.fn()} />);

  expect(screen.getByText('4 people voted · Closed')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /10:00/ })).toBeDisabled();
});

test('a booking shows its current status and opens it', () => {
  const booking: ChatRecordBlock = {
    type: 'record',
    record: 'booking',
    id: 7,
    title: 'Cake tasting · Ahmad Khalil',
    status: 'cancelled',
    href: '/calendar?date=2026-11-17',
    starts_at: '2026-11-17T10:00:00Z',
    with: 'Awss Haddad',
  };
  render(<MessageBlock t={t} block={booking} />);

  expect(screen.getByText('Cancelled')).toBeInTheDocument();
  expect(screen.getByText('Awss Haddad')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Open booking' })).toHaveAttribute('href', '/calendar?date=2026-11-17');
});

test('a record that is gone says so instead of breaking the message', () => {
  render(<MessageBlock t={t} block={{ type: 'record', record: 'document', id: 9, missing: true, title: '', status: '', href: '' }} />);

  expect(screen.getByText('This record is no longer here.')).toBeInTheDocument();
});
