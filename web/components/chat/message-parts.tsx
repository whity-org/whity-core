'use client';

/**
 * The parts of a chat message (design 3.5, UC.6–UC.12; card `TplChatParts`).
 *
 * - Bubbles (the default) or compact rows, each person's choice (UC.7): in
 *   bubbles theirs sit on the start side with their picture, mine on the end
 *   side in the main colour's tint; rows put picture, name and time on the
 *   start side for everyone.
 * - Above a message, the one it replies to; under it, its reactions and "n
 *   replies" for its thread.
 * - Hover or focus shows three quick reactions, more, Reply, Reply in thread
 *   and ⋯ (Pin, Copy, Edit and Delete for your own messages). Every action is
 *   a labelled button.
 * - Two ticks on your own messages, in the main colour once everyone has seen
 *   it; they open Seen by.
 */

import { useMemo, useState, type ReactNode } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@whity/ui/popover';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
import type { ChatLayout, ChatMention, ChatMessage, ChatReadMark } from '@/lib/chat-wire';

/** The words here are Chat's: the `t` passed in is `useTranslation('chat')`, as this hook gives. */
export function useChatText(): TranslateFn {
  const t = useTranslation('chat');
  return t;
}

export const QUICK_REACTIONS = ['👍', '❤️', '😂'];
export const MORE_REACTIONS = ['👍', '❤️', '😂', '😮', '🙏', '🎉', '👏', '✅'];

/** A stable hue per name, so a person's name has the same colour in every chat. */
function nameTone(name: string): string {
  const tones = ['text-primary', 'text-warning-text', 'text-success-text', 'text-info-text', 'text-error-text'];
  let h = 0;
  for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return tones[h % tones.length];
}

export interface MessageActions {
  onReply: (m: ChatMessage) => void;
  onThread: (m: ChatMessage) => void;
  onReact: (m: ChatMessage, emoji: string) => void;
  onPin: (m: ChatMessage, pinned: boolean) => void;
  onCopy: (m: ChatMessage) => void;
  onEdit: (m: ChatMessage) => void;
  onDelete: (m: ChatMessage) => void;
  onQuote: (messageId: number) => void;
}

/** The text, with the people and agents it mentions in the main colour and searched words marked. */
export function MessageBody({ text, mentions, highlight }: { text: string; mentions: ChatMention[]; highlight?: string }) {
  const parts = useMemo(() => {
    const names = mentions.map((m) => `@${m.name}`);
    const terms = [...names, ...(highlight && highlight.trim() !== '' ? [highlight.trim()] : [])].sort((a, b) => b.length - a.length);
    if (terms.length === 0) return [text];
    const escaped = terms.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return text.split(new RegExp(`(${escaped.join('|')})`, 'gi'));
  }, [text, mentions, highlight]);
  const names = new Set(mentions.map((m) => `@${m.name}`.toLowerCase()));
  const term = highlight?.trim().toLowerCase() ?? '';
  return (
    <p dir="auto" className="text-start text-base whitespace-pre-wrap break-words">
      {parts.map((p, i) =>
        names.has(p.toLowerCase()) ? (
          <span key={i} className="font-medium text-primary">
            {p}
          </span>
        ) : term !== '' && p.toLowerCase() === term ? (
          <mark key={i} className="rounded-control-sm bg-warning-tint text-foreground">
            {p}
          </mark>
        ) : (
          <span key={i}>{p}</span>
        )
      )}
    </p>
  );
}

function Quote({ t, message, onOpen }: { t: TranslateFn; message: ChatMessage; onOpen: (id: number) => void }) {
  const q = message.reply_to;
  if (!q) return null;
  return (
    <button
      type="button"
      onClick={() => onOpen(q.id)}
      className="mb-1 grid w-full rounded-control-sm border-s-[3px] border-primary bg-card/60 px-2.5 py-1 text-start text-sm hover:bg-card"
      aria-label={t('msg.quoteOpen', 'Go to the message from {name}', { name: q.author_name })}
    >
      <span className="truncate font-medium text-primary">{q.author_name}</span>
      <span dir="auto" className="truncate text-start text-muted-foreground">
        {q.deleted ? t('msg.deleted', 'This message was deleted') : q.body}
      </span>
    </button>
  );
}

function Reactions({ t, message, onReact }: { t: TranslateFn; message: ChatMessage; onReact: (emoji: string) => void }) {
  if (message.reactions.length === 0) return null;
  return (
    <div className="mt-1 flex flex-wrap gap-1">
      {message.reactions.map((r) => (
        <button
          key={r.emoji}
          type="button"
          onClick={() => onReact(r.emoji)}
          aria-pressed={r.mine}
          title={r.people.join(', ')}
          aria-label={t('msg.reactionLabel', '{emoji} from {people}', { emoji: r.emoji, people: r.people.join(', ') })}
          className={cn(
            'inline-flex h-7 items-center gap-1 rounded-full border px-2 text-sm',
            r.mine ? 'border-primary bg-primary-tint text-primary-tint-foreground' : 'border-border bg-card hover:bg-muted'
          )}
        >
          <span aria-hidden="true">{r.emoji}</span>
          <span>{r.count}</span>
        </button>
      ))}
    </div>
  );
}

/** Two ticks on your own message: grey once sent, in the main colour once everyone else has seen it (UC.11). */
function SeenTicks({ t, message, readMarks, myId }: { t: TranslateFn; message: ChatMessage; readMarks: ChatReadMark[]; myId: number }) {
  const { time } = useDateDisplay();
  const others = readMarks.filter((r) => r.profile_id !== myId);
  const seen = others.filter((r) => r.last_read_message_id >= message.id);
  const all = others.length > 0 && seen.length === others.length;
  const label =
    others.length === 0
      ? t('seen.sent', 'Sent')
      : t('seen.count', 'Seen by {seen} of {all}', { seen: String(seen.length), all: String(others.length) });
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button type="button" aria-label={label} title={label} className={cn('inline-flex rounded-control-sm', all ? 'text-primary' : 'text-muted-foreground')}>
          <Icon name="check" size={12} />
          <Icon name="check" size={12} className="-ms-2" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-64 p-3">
        <p className="mb-2 text-sm font-semibold">{label}</p>
        <ul className="grid gap-2">
          {others.map((r) => {
            const yes = r.last_read_message_id >= message.id;
            return (
              <li key={r.member_id} className="grid grid-cols-[1.75rem_minmax(0,1fr)] items-center gap-2 text-sm">
                <Avatar name={r.name} size={28} />
                <span className="min-w-0">
                  <span className="block truncate">{r.name}</span>
                  <span className="block text-xs text-muted-foreground">
                    {yes ? t('seen.yes', 'Seen') : t('seen.no', 'Delivered, not seen yet')}
                  </span>
                </span>
              </li>
            );
          })}
        </ul>
        {message.created_at && <p className="mt-2 text-xs text-muted-foreground">{t('seen.sentAt', 'Sent {time}', { time: time(message.created_at) ?? '' })}</p>}
      </PopoverContent>
    </Popover>
  );
}

/** The hover bar (UC.9): three quick reactions, more, Reply, Reply in thread and ⋯. */
function ActionBar({
  t,
  message,
  actions,
  inThread,
  className,
}: {
  t: TranslateFn;
  message: ChatMessage;
  actions: MessageActions;
  inThread: boolean;
  className?: string;
}) {
  const [confirmDelete, setConfirmDelete] = useState(false);
  const btn = 'grid size-8 place-items-center rounded-control-sm text-base hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring';
  return (
    <div
      role="toolbar"
      aria-label={t('msg.actions', 'Message actions')}
      className={cn('flex items-center gap-0.5 rounded-control border border-border bg-popover p-0.5 shadow-md', className)}
    >
      {QUICK_REACTIONS.map((e) => (
        <button key={e} type="button" className={btn} aria-label={t('msg.reactWith', 'React with {emoji}', { emoji: e })} onClick={() => actions.onReact(message, e)}>
          <span aria-hidden="true">{e}</span>
        </button>
      ))}
      <Popover>
        <PopoverTrigger asChild>
          <button type="button" className={btn} aria-label={t('msg.moreReactions', 'More reactions')}>
            <Icon name="plus" size={16} />
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-auto p-2">
          <div className="grid grid-cols-4 gap-1">
            {MORE_REACTIONS.map((e) => (
              <button key={e} type="button" className={cn(btn, 'size-10 text-xl')} aria-label={t('msg.reactWith', 'React with {emoji}', { emoji: e })} onClick={() => actions.onReact(message, e)}>
                <span aria-hidden="true">{e}</span>
              </button>
            ))}
          </div>
          <p className="mt-2 max-w-52 text-xs text-muted-foreground">{t('msg.reactHint', 'Tap a reaction again to take it back.')}</p>
        </PopoverContent>
      </Popover>
      <button type="button" className={btn} aria-label={t('msg.reply', 'Reply')} title={t('msg.reply', 'Reply')} onClick={() => actions.onReply(message)}>
        <Icon name="undo" size={16} />
      </button>
      {!inThread && (
        <button type="button" className={btn} aria-label={t('msg.replyThread', 'Reply in thread')} title={t('msg.replyThread', 'Reply in thread')} onClick={() => actions.onThread(message)}>
          <Icon name="comment" size={16} />
        </button>
      )}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button type="button" className={btn} aria-label={t('msg.more', 'More actions')}>
            <Icon name="more" size={16} />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={() => actions.onReply(message)}>
            <Icon name="undo" size={16} />
            {t('msg.reply', 'Reply')}
          </DropdownMenuItem>
          {!inThread && (
            <DropdownMenuItem onSelect={() => actions.onThread(message)}>
              <Icon name="comment" size={16} />
              {t('msg.replyThread', 'Reply in thread')}
            </DropdownMenuItem>
          )}
          <DropdownMenuItem onSelect={() => actions.onPin(message, !message.pinned)}>
            <Icon name="star" size={16} />
            {message.pinned ? t('msg.unpin', 'Unpin') : t('msg.pin', 'Pin to the top')}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => actions.onCopy(message)}>
            <Icon name="copy" size={16} />
            {t('msg.copy', 'Copy text')}
          </DropdownMenuItem>
          {(message.can_edit || message.can_delete) && <DropdownMenuSeparator />}
          {message.can_edit && (
            <DropdownMenuItem onSelect={() => actions.onEdit(message)}>
              <Icon name="edit" size={16} />
              {t('msg.edit', 'Edit')}
            </DropdownMenuItem>
          )}
          {message.can_delete && (
            <DropdownMenuItem onSelect={() => setConfirmDelete(true)} className="text-destructive">
              <Icon name="delete" size={16} />
              {t('msg.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
      <ConfirmAction
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t('delete.title', 'Delete this message?')}
        description={t(
          'delete.body',
          'Everyone in this chat will see “This message was deleted”. You can delete for everyone up to 2 days after sending.'
        )}
        confirmLabel={t('delete.confirm', 'Delete for everyone')}
        cancelLabel={t('delete.keep', 'Keep it')}
        variant="destructive"
        onConfirm={() => actions.onDelete(message)}
      />
    </div>
  );
}

/**
 * One message, as a bubble or a compact row. `children` carries what only
 * some messages have (an agent's held line, a proposal card).
 */
export function MessageItem({
  t,
  message,
  layout,
  myId,
  readMarks,
  actions,
  inThread = false,
  highlight,
  agentTag,
  forLine,
  children,
}: {
  t: TranslateFn;
  message: ChatMessage;
  layout: ChatLayout;
  myId: number;
  readMarks: ChatReadMark[];
  actions: MessageActions;
  inThread?: boolean;
  highlight?: string;
  /** The Agent tag, for an agent's message. */
  agentTag?: ReactNode;
  /** "for Amro", for an agent's message. */
  forLine?: string | null;
  children?: ReactNode;
}) {
  const { time } = useDateDisplay();
  const author = message.author;
  const isAgent = author?.type === 'agent';
  const mine = !isAgent && author?.type === 'person' && author.profile_id === myId;
  const sentAt = time(message.created_at);
  const bubbles = layout === 'bubbles';
  const deleted = message.deleted;

  const body = deleted ? (
    <p className="flex items-center gap-1.5 text-muted-foreground italic">
      <Icon name="ban" size={14} />
      {t('msg.deleted', 'This message was deleted')}
    </p>
  ) : (
    <>
      <Quote t={t} message={message} onOpen={actions.onQuote} />
      {children ?? <MessageBody text={message.body} mentions={message.mentions} highlight={highlight} />}
    </>
  );

  const meta = (
    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
      {message.pinned && (
        <span role="img" aria-label={t('msg.pinned', 'Pinned')} className="inline-flex">
          <Icon name="star" size={12} />
        </span>
      )}
      {message.edited && !deleted && <span>{t('msg.edited', 'edited')}</span>}
      {message.edited && !deleted && sentAt !== null && <span aria-hidden="true">·</span>}
      {sentAt !== null && <time>{sentAt}</time>}
      {mine && !deleted && <SeenTicks t={t} message={message} readMarks={readMarks} myId={myId} />}
    </span>
  );

  const thread =
    !inThread && message.thread && message.thread.count > 0 ? (
      <button
        type="button"
        onClick={() => actions.onThread(message)}
        className="mt-1 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
      >
        <Icon name="comment" size={14} />
        {message.thread.count === 1 ? t('msg.oneReply', '1 reply') : t('msg.replies', '{count} replies', { count: String(message.thread.count) })}
      </button>
    ) : null;

  const bar = !deleted && (
    <ActionBar
      t={t}
      message={message}
      actions={actions}
      inThread={inThread}
      className={cn(
        // Hover, or keyboard focus inside the message; a click leaves no bar behind.
        'absolute -top-9 z-10 hidden group-hover:flex group-has-[:focus-visible]:flex has-[[aria-expanded=true]]:flex',
        bubbles && mine ? 'end-0' : 'start-10'
      )}
    />
  );

  if (bubbles) {
    return (
      <div id={`chat-msg-${message.id}`} className={cn('group relative flex gap-2', mine ? 'justify-end' : 'justify-start')}>
        {!mine && <Avatar name={author?.name ?? ''} kind={isAgent ? 'agent' : 'person'} size={32} className="mt-auto" />}
        <div className={cn('grid max-w-[min(32.5rem,85%)] justify-items-start', mine && 'justify-items-end')}>
          <div
            className={cn(
              'relative grid gap-0.5 rounded-[14px] border px-3 py-2',
              mine ? 'border-transparent bg-primary-tint' : 'border-border bg-card',
              isAgent && 'border-dashed border-primary',
              deleted && 'bg-muted'
            )}
          >
            {!mine && (
              <p className="flex flex-wrap items-center gap-1.5 text-sm">
                <span className={cn('font-semibold', nameTone(author?.name ?? ''))}>{author?.name}</span>
                {agentTag}
                {forLine && <span className="text-xs text-muted-foreground">{forLine}</span>}
              </p>
            )}
            {body}
            <span className="justify-self-end">{meta}</span>
            {bar}
          </div>
          <Reactions t={t} message={message} onReact={(e) => actions.onReact(message, e)} />
          {thread}
        </div>
      </div>
    );
  }

  return (
    <div id={`chat-msg-${message.id}`} className="group relative grid grid-cols-[2rem_minmax(0,1fr)] gap-2.5 rounded-control px-1 py-1 hover:bg-muted/50">
      <Avatar name={author?.name ?? ''} kind={isAgent ? 'agent' : 'person'} size={32} />
      <div className="min-w-0">
        <div className="mb-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <b className={cn('text-sm font-semibold', nameTone(author?.name ?? ''))}>{author?.name}</b>
          {agentTag}
          {forLine && <span>{forLine}</span>}
          {meta}
        </div>
        <div className={cn(isAgent && 'border-s-2 border-primary-tint ps-2.5')}>{body}</div>
        <Reactions t={t} message={message} onReact={(e) => actions.onReact(message, e)} />
        {thread}
      </div>
      {bar}
    </div>
  );
}

/** "Ahmad is typing…" under the last message (UC.11). */
export function TypingLine({ t, names }: { t: TranslateFn; names: string[] }) {
  if (names.length === 0) return null;
  const first = names.map((n) => n.split(' ')[0]);
  const text =
    first.length === 1
      ? t('typing.one', '{name} is typing…', { name: first[0] })
      : first.length === 2
        ? t('typing.two', '{a} and {b} are typing…', { a: first[0], b: first[1] })
        : t('typing.many', 'Several people are typing…');
  return (
    <p className="flex items-center gap-2 text-sm text-muted-foreground" aria-live="polite">
      <span className="inline-flex gap-1 rounded-full bg-muted px-2.5 py-1.5" aria-hidden="true">
        <span className="size-1.5 animate-pulse rounded-full bg-muted-foreground" />
        <span className="size-1.5 animate-pulse rounded-full bg-muted-foreground [animation-delay:150ms]" />
        <span className="size-1.5 animate-pulse rounded-full bg-muted-foreground [animation-delay:300ms]" />
      </span>
      {text}
    </p>
  );
}

/** The line above the first unread message, with how many are new. */
export function NewLine({ t, count }: { t: TranslateFn; count: number }) {
  return (
    <div className="flex items-center gap-3 py-1 text-sm font-medium text-primary" role="separator">
      <span className="h-px flex-1 bg-primary" />
      {t('thread.new', '{count} new', { count: String(count) })}
      <span className="h-px flex-1 bg-primary" />
    </div>
  );
}

/** The pinned bar under the header: the newest pin, and how many more (UC.8). */
export function PinnedBar({ t, pinned, onOpen }: { t: TranslateFn; pinned: ChatMessage[]; onOpen: (id: number) => void }) {
  if (pinned.length === 0) return null;
  const top = pinned[0];
  return (
    <button
      type="button"
      onClick={() => onOpen(top.id)}
      className="flex w-full items-center gap-2 border-b border-border bg-card px-4 py-2 text-start text-sm hover:bg-muted"
    >
      <Icon name="star" size={14} />
      <b className="font-semibold">{t('pinned.label', 'Pinned')}</b>
      <span dir="auto" className="min-w-0 flex-1 truncate text-start">
        {top.body}
      </span>
      {pinned.length > 1 && <span className="shrink-0 text-muted-foreground">{t('pinned.more', '{count} more', { count: String(pinned.length - 1) })}</span>}
    </button>
  );
}

/** Above the message box while replying or editing, with a way out (UC.9, UC.12). */
export function ComposerBanner({ title, text, onClose, closeLabel, tone }: { title: string; text: string; onClose: () => void; closeLabel: string; tone: 'reply' | 'edit' }) {
  return (
    <div className={cn('flex items-start gap-2 rounded-control border-s-[3px] bg-muted px-3 py-1.5', tone === 'edit' ? 'border-warning' : 'border-primary')}>
      <div className="min-w-0 flex-1">
        <p className={cn('text-sm font-medium', tone === 'edit' ? 'text-warning-text' : 'text-primary')}>{title}</p>
        <p dir="auto" className="truncate text-start text-sm text-muted-foreground">
          {text}
        </p>
      </div>
      <Button type="button" variant="ghost" size="icon-sm" aria-label={closeLabel} onClick={onClose}>
        <Icon name="close" size={14} />
      </Button>
    </div>
  );
}
