/**
 * The parts of a chat message (design 3.5, UC.6–UC.12, WHIT-1094).
 *
 * What would go wrong unnoticed: a deleted message still showing its words,
 * someone else's message offering Edit or Delete, Delete going through without
 * the confirmation, reactions not toggling the one tapped, the quote or the
 * thread link not opening what it names, and Seen counting the writer.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, within } from '@testing-library/react';

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ dateTime: (v: string | null) => v, time: (v: string | null) => (v ? '09:15' : null), date: (v: string | null) => v }),
}));

import { MessageItem, TypingLine, type MessageActions } from '@/components/chat/message-parts';

// jsdom has no PointerEvent: a MouseEvent that carries pointerType, so touches have coordinates.
if (typeof window.PointerEvent === 'undefined') {
  class TestPointerEvent extends MouseEvent {
    pointerType: string;
    constructor(type: string, init: PointerEventInit = {}) {
      super(type, init);
      this.pointerType = init.pointerType ?? 'mouse';
    }
  }
  (window as unknown as { PointerEvent: unknown }).PointerEvent = TestPointerEvent;
}
import type { ChatMessage } from '@/lib/chat-wire';

const t = (_key: string, fallback: string, values?: Record<string, string | number>) =>
  Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback);

const AMRO = 3;
const AWSS = 4;

function message(over: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id: 20,
    chat_id: 4,
    kind: 'message',
    body: 'Oven 2 is fixed',
    author: { type: 'person', profile_id: AWSS, name: 'Awss Haddad' },
    mentions: [],
    status: 'posted',
    waiting_for: null,
    can_decide: false,
    proposal: null,
    event: null,
    created_at: '2026-11-16T09:15:00Z',
    reply_to: null,
    thread_root_id: null,
    in_chat: true,
    thread: null,
    reactions: [],
    attachments: [],
    block: null,
    pinned: false,
    edited: false,
    deleted: false,
    can_edit: false,
    can_delete: false,
    ...over,
  };
}

function actions(): jest.Mocked<MessageActions> {
  return {
    onReply: jest.fn(),
    onThread: jest.fn(),
    onReact: jest.fn(),
    onPin: jest.fn(),
    onCopy: jest.fn(),
    onEdit: jest.fn(),
    onDelete: jest.fn(),
    onQuote: jest.fn(),
  };
}

const marks = [
  { member_id: 1, profile_id: AMRO, name: 'Amro Saleh', last_read_message_id: 30 },
  { member_id: 2, profile_id: AWSS, name: 'Awss Haddad', last_read_message_id: 30 },
  { member_id: 3, profile_id: 5, name: 'Emad Nasser', last_read_message_id: 10 },
];

test('a deleted message says so and keeps none of its words or actions', () => {
  render(<MessageItem t={t} message={message({ deleted: true, body: '' })} layout="bubbles" myId={AMRO} readMarks={marks} actions={actions()} />);

  expect(screen.getByText('This message was deleted')).toBeInTheDocument();
  expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
});

test('someone else’s message offers no Edit or Delete', async () => {
  render(<MessageItem t={t} message={message()} layout="bubbles" myId={AMRO} readMarks={marks} actions={actions()} />);

  await userEvent.click(screen.getByRole('button', { name: 'More actions' }));
  expect(await screen.findByRole('menuitem', { name: /Copy text/ })).toBeInTheDocument();
  expect(screen.queryByRole('menuitem', { name: /Edit/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('menuitem', { name: /Delete/ })).not.toBeInTheDocument();
});

test('deleting your own message asks first, then deletes for everyone', async () => {
  const a = actions();
  const mine = message({ author: { type: 'person', profile_id: AMRO, name: 'Amro Saleh' }, can_edit: true, can_delete: true });
  render(<MessageItem t={t} message={mine} layout="bubbles" myId={AMRO} readMarks={marks} actions={a} />);

  await userEvent.click(screen.getByRole('button', { name: 'More actions' }));
  await userEvent.click(await screen.findByRole('menuitem', { name: /Delete/ }));
  expect(a.onDelete).not.toHaveBeenCalled();
  const dialog = await screen.findByRole('alertdialog');
  expect(within(dialog).getByText('Delete this message?')).toBeInTheDocument();
  await userEvent.click(within(dialog).getByRole('button', { name: 'Delete for everyone' }));
  expect(a.onDelete).toHaveBeenCalledWith(mine);
});

test('quick reactions, the reaction chips, Reply and Reply in thread call their action', async () => {
  const a = actions();
  const m = message({ reactions: [{ emoji: '🎉', count: 2, mine: true, people: ['Amro Saleh', 'Awss Haddad'] }], thread: { count: 4, last_at: null, people: [] } });
  render(<MessageItem t={t} message={m} layout="bubbles" myId={AMRO} readMarks={marks} actions={a} />);

  await userEvent.click(screen.getByRole('button', { name: 'React with 👍' }));
  expect(a.onReact).toHaveBeenLastCalledWith(m, '👍');
  await userEvent.click(screen.getByRole('button', { name: '🎉 from Amro Saleh, Awss Haddad' }));
  expect(a.onReact).toHaveBeenLastCalledWith(m, '🎉');
  await userEvent.click(screen.getByRole('button', { name: 'Reply' }));
  expect(a.onReply).toHaveBeenCalledWith(m);
  await userEvent.click(screen.getByRole('button', { name: /4 replies/ }));
  expect(a.onThread).toHaveBeenCalledWith(m);
});

test('a reply shows what it answers, and the quote goes to it', async () => {
  const a = actions();
  render(
    <MessageItem
      t={t}
      message={message({ reply_to: { id: 7, author_name: 'Amro Saleh', body: 'Can someone look at the mixer?', deleted: false } })}
      layout="rows"
      myId={AMRO}
      readMarks={marks}
      actions={a}
    />
  );

  await userEvent.click(screen.getByRole('button', { name: 'Go to the message from Amro Saleh' }));
  expect(a.onQuote).toHaveBeenCalledWith(7);
});

test('Seen counts the others, not the writer', () => {
  const mine = message({ id: 20, author: { type: 'person', profile_id: AMRO, name: 'Amro Saleh' } });
  render(<MessageItem t={t} message={mine} layout="bubbles" myId={AMRO} readMarks={marks} actions={actions()} />);

  expect(screen.getByRole('button', { name: 'Seen by 1 of 2' })).toBeInTheDocument();
});

test('edited and pinned show beside the time; rows put the name first', () => {
  render(<MessageItem t={t} message={message({ edited: true, pinned: true })} layout="rows" myId={AMRO} readMarks={marks} actions={actions()} />);

  expect(screen.getByText('edited')).toBeInTheDocument();
  expect(screen.getByLabelText('Pinned')).toBeInTheDocument();
  expect(screen.getByText('Awss Haddad')).toBeInTheDocument();
});

test('typing names the people typing', () => {
  const { rerender } = render(<TypingLine t={t} names={['Awss Haddad']} />);
  expect(screen.getByText('Awss is typing…')).toBeInTheDocument();
  rerender(<TypingLine t={t} names={['Awss Haddad', 'Emad Nasser']} />);
  expect(screen.getByText('Awss and Emad are typing…')).toBeInTheDocument();
});

test('on a phone, press and hold opens the actions, with six reactions', () => {
  jest.useFakeTimers();
  const a = actions();
  render(<MessageItem t={t} message={message()} layout="bubbles" myId={AMRO} readMarks={marks} actions={a} />);

  fireEvent.pointerDown(document.getElementById('chat-msg-20') as HTMLElement, { pointerType: 'touch', clientX: 50, clientY: 50 });
  act(() => {
    jest.advanceTimersByTime(600);
  });
  jest.useRealTimers();

  expect(screen.getAllByRole('button', { name: /^React with/ }).length).toBeGreaterThanOrEqual(6);
  fireEvent.click(screen.getAllByRole('button', { name: 'Reply in thread' }).pop() as HTMLElement);
  expect(a.onThread).toHaveBeenCalled();
});

test('on a phone, swiping a message sideways replies to it', () => {
  const a = actions();
  render(<MessageItem t={t} message={message()} layout="bubbles" myId={AMRO} readMarks={marks} actions={a} />);
  const el = document.getElementById('chat-msg-20') as HTMLElement;

  fireEvent.pointerDown(el, { pointerType: 'touch', clientX: 50, clientY: 50 });
  fireEvent.pointerMove(el, { pointerType: 'touch', clientX: 130, clientY: 52 });
  fireEvent.pointerUp(el, { pointerType: 'touch', clientX: 130, clientY: 52 });

  expect(a.onReply).toHaveBeenCalled();
});
