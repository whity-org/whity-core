'use client';

/**
 * A customer conversation inside Chat (design 3.5, UC.1–UC.3; card
 * `TplChatHub`, its first frame). It replaces the 3.4 Messages page
 * (`TplMessagesInbox`), and keeps what that page had: assign, close, Reply or
 * Internal note (warm and dashed, "Only the team sees this"), the channel it
 * sends by, held replies an agent wrote with Cancel and Edit, and a reply that
 * didn't leave with Try again.
 *
 * The customer's messages sit on the start side, the team's on the end side in
 * the main colour's tint, each with the teammate who sent it (the customer sees
 * the workspace). The customer's details open in the right pane.
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { IconButton } from '@whity/ui/icon-button';
import { Textarea } from '@whity/ui/textarea';
import { AreaError, ReadOnlyNotice } from '@whity/ui/page-states';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { Checkbox } from '@whity/ui/checkbox';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { apiFetch } from '@/lib/api/client';
import type { ChatBlockedSender } from '@/lib/chat-wire';
import { chatApi, chatErrorText } from './chat-api';
import { CustomerBlockCard, CustomerBlockDialog, useCustomerBlockOptions } from './customer-blocks';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { api, type components } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { cn } from '@/lib/utils';
import {
  contactLabel,
  displayAddress,
  errorText,
  isPending,
  type ConversationDetail,
  type ConversationStatus,
  type Message,
} from '@/lib/messages-wire';

/** `errorText` reads a body; the typed client has already parsed it into `error`. */
function rebuilt(response: Response, error: unknown): Response {
  return new Response(JSON.stringify(error ?? null), { status: response.status });
}

const POLL_MS = 5000;

/** The channel as a short name: Email, Web chat, or the plugin channel's own name (WhatsApp, SMS). */
export function channelName(channel: string, t: TranslateFn): string {
  if (channel === 'email') return t('messages.channel.email', 'Email');
  if (channel === 'webchat') return t('messages.channel.webchat', 'Web chat');
  const name = channel.split(':').pop() ?? channel;
  if (name.toLowerCase() === 'whatsapp') return 'WhatsApp';
  if (name.toLowerCase() === 'sms') return 'SMS';
  return name.charAt(0).toUpperCase() + name.slice(1);
}

export function channelIcon(channel: string): IconName {
  const name = (channel.split(':').pop() ?? channel).toLowerCase();
  if (channel === 'email') return 'mail';
  if (channel === 'webchat') return 'world';
  if (name === 'whatsapp') return 'whatsapp';
  if (name === 'sms') return 'phone';
  return 'comment';
}

/** A customer's picture with their channel as a small badge on its corner (UC.1). */
export function CustomerAvatar({ name, channel, size, label }: { name: string; channel: string; size: number; label?: string }) {
  return (
    <span className="relative inline-flex shrink-0" style={{ width: size, height: size }}>
      <Avatar name={name} size={size} />
      <span
        className="absolute -bottom-0.5 -end-0.5 grid size-4 place-items-center rounded-full border-2 border-card bg-card text-success-text"
        title={label}
      >
        <Icon name={channelIcon(channel)} size={10} />
      </span>
    </span>
  );
}

/** Today's messages by their time, older ones by their date. */
export function useListTime(withTime = false): (value: string | null | undefined) => string | null {
  const { time, date, dateTime } = useDateDisplay();
  return (value) => {
    if (!value) return null;
    // The server writes UTC; a value without a zone is UTC, not the browser's time.
    const iso = value.replace(' ', 'T');
    const at = new Date(/[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : `${iso}Z`);
    const now = new Date();
    const sameDay =
      !Number.isNaN(at.getTime()) &&
      at.getFullYear() === now.getFullYear() &&
      at.getMonth() === now.getMonth() &&
      at.getDate() === now.getDate();
    return sameDay ? time(value) : withTime ? dateTime(value) : date(value);
  };
}

export function CustomerChat({
  id,
  canReply,
  canManage,
  unreadAtOpen,
  detailsOpen,
  pinned,
  onPin,
  muted = false,
  onMute,
  onToggleDetails,
  onBack,
  onChanged,
  onRead,
}: {
  id: number;
  canReply: boolean;
  canManage: boolean;
  /** How many of the customer's messages were unread when it opened: the "n new" line goes above them. */
  unreadAtOpen: number;
  detailsOpen: boolean;
  pinned: boolean;
  onPin: (pinned: boolean) => void;
  muted?: boolean;
  onMute?: (muted: boolean) => void;
  onToggleDetails: () => void;
  onBack: () => void;
  onChanged: () => void;
  onRead: (messageId: number) => void;
}) {
  const t = useTranslation('admin');
  const tc = useTranslation('chat');
  const { user } = useAuth();
  const { addToast } = useToast();
  const { date: dateOnly } = useDateDisplay();
  const [mode, setMode] = useState<'reply' | 'note'>('reply');
  const [draft, setDraft] = useState('');
  // UC.5: booking times, a form or a payment link, from the + beside the reply box.
  const currency = useWorkspaceCurrency();
  const blockOptions = useCustomerBlockOptions(id, canReply);
  const [blockKind, setBlockKind] = useState<'booking_times' | 'form' | 'payment' | null>(null);
  const [sending, setSending] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);
  const box = useRef<HTMLTextAreaElement>(null);
  // The unread line stays where it was when the chat opened, as the card draws it.
  const [newFrom, setNewFrom] = useState<number | null>(null);
  // UC.12: whether this contact is blocked, and since when.
  const chat = useMemo(() => chatApi(apiFetch), []);
  const [blocked, setBlocked] = useState<ChatBlockedSender | null>(null);
  useEffect(() => {
    let live = true;
    chat.blockStatus(id).then((b) => live && setBlocked(b), () => undefined);
    return () => {
      live = false;
    };
  }, [chat, id]);

  const detail = useFetch<ConversationDetail>(async () => {
    const { data, response } = await api.GET('/api/v1/messages/conversations/{id}', { params: { path: { id } } });
    if (!response.ok) throw new Error(t('messages.error.thread', 'This conversation could not be loaded.'));
    return (data as unknown as { data: ConversationDetail }).data;
  }, [id]);

  const refetch = detail.refetch;
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') refetch();
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [refetch]);

  const conversation = detail.data;
  const messages = useMemo(() => conversation?.messages ?? [], [conversation]);
  const lastId = messages.length > 0 ? messages[messages.length - 1].id : 0;

  if (newFrom === null && messages.length > 0) {
    const inbound = messages.filter((m) => m.kind === 'inbound');
    const first = unreadAtOpen > 0 ? inbound[Math.max(0, inbound.length - unreadAtOpen)] : undefined;
    setNewFrom(first ? first.id : 0);
  }

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' });
  }, [lastId]);

  // What is on screen is read, for this person.
  const readMark = useRef(0);
  useEffect(() => {
    if (lastId > readMark.current) {
      readMark.current = lastId;
      onRead(lastId);
    }
  }, [lastId, onRead]);

  const myId = typeof user?.id === 'number' ? user.id : null;

  const patch = async (body: components['schemas']['MessagingUpdateRequest'], done: string): Promise<void> => {
    const { response, error } = await api.PATCH('/api/v1/messages/conversations/{id}', {
      params: { path: { id } },
      body,
    });
    if (!response.ok) {
      addToast(await errorText(rebuilt(response, error), t('messages.error.update', 'That change was not saved.')), 'error');
      return;
    }
    addToast(done, 'success');
    detail.refetch();
    onChanged();
  };

  const send = async (): Promise<void> => {
    const text = draft.trim();
    if (text === '' || sending) return;
    setSending(true);
    try {
      const options = { params: { path: { id } }, body: { body: text } };
      const { response, error } =
        mode === 'reply'
          ? await api.POST('/api/v1/messages/conversations/{id}/replies', options)
          : await api.POST('/api/v1/messages/conversations/{id}/notes', options);
      if (!response.ok) {
        // Nothing is lost: the words stay in the box to try again.
        addToast(await errorText(rebuilt(response, error), t('messages.error.send', 'Your message was not sent. Please try again.')), 'error');
        return;
      }
      setDraft('');
      detail.refetch();
      onChanged();
    } finally {
      setSending(false);
    }
  };

  const cancel = async (message: Message, quiet = false): Promise<boolean> => {
    const { response } = await api.POST('/api/v1/messages/{id}/cancel', { params: { path: { id: message.id } } });
    if (!response.ok) {
      addToast(t('messages.error.cancel', 'It has already been sent.'), 'error');
    } else if (!quiet) {
      addToast(t('messages.cancelled', 'Message cancelled. It will not be sent.'), 'success');
    }
    detail.refetch();
    return response.ok;
  };

  // Edit: the held reply stops and its words move into the box, for a person to send.
  const edit = async (message: Message): Promise<void> => {
    if (!(await cancel(message, true))) return;
    setMode('reply');
    setDraft(message.body);
    box.current?.focus();
  };

  const retry = async (message: Message): Promise<void> => {
    const { response, error } = await api.POST('/api/v1/messages/conversations/{id}/replies', {
      params: { path: { id } },
      body: { body: message.body },
    });
    if (!response.ok) {
      addToast(await errorText(rebuilt(response, error), t('messages.error.send', 'Your message was not sent. Please try again.')), 'error');
      return;
    }
    detail.refetch();
    onChanged();
  };

  const copy = (message: Message): void => {
    void navigator.clipboard?.writeText(message.body).then(
      () => addToast(t('messages.copied', 'Text copied'), 'success'),
      () => undefined
    );
  };

  const statusLabel = (s: ConversationStatus): string =>
    s === 'open'
      ? t('messages.status.open', 'Open')
      : s === 'pending'
        ? t('messages.status.pending', 'Waiting for customer')
        : t('messages.status.closed', 'Closed');

  if (detail.error) {
    return (
      <section className="grid flex-1 place-items-center p-8">
        <AreaError
          message={t('messages.error.thread', 'This conversation could not be loaded.')}
          retryLabel={t('messages.retry', 'Try again')}
          onRetry={() => detail.refetch()}
        />
      </section>
    );
  }
  if (!conversation) {
    return (
      <section className="flex-1 p-6" aria-busy="true">
        <div className="h-40 animate-pulse rounded-card bg-muted/40" />
      </section>
    );
  }

  const name = contactLabel(conversation) || t('messages.visitor', 'Website visitor');
  const address = displayAddress(conversation.contact.address);
  const channel = channelName(conversation.channel, t);
  const firstName = name.split(' ')[0];
  const mine = myId !== null && conversation.assignee?.id === myId;
  const unreadCount = newFrom ? messages.filter((m) => m.id >= newFrom && m.kind === 'inbound').length : 0;

  return (
    <>
      <section aria-label={name} className="flex min-h-0 min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-3 border-b border-border px-4 py-2.5">
          <IconButton icon="chevron-left" label={tc('action.back', 'Back to chats')} className="md:hidden rtl:rotate-180" onClick={onBack} />
          <CustomerAvatar name={name} channel={conversation.channel} size={40} label={channel} />
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-base font-semibold text-foreground">{name}</h2>
            <p dir="auto" className="truncate text-start text-sm text-muted-foreground">
              {[tc('customer.kind', 'Customer'), channel, address, conversation.subject].filter(Boolean).join(' · ')}
            </p>
          </div>
          {canManage ? (
            <div className="flex shrink-0 items-center gap-2 max-sm:hidden">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button type="button" variant="outline" size="sm">
                    {conversation.assignee
                      ? tc('customer.assigned', 'Assigned {name}', { name: conversation.assignee.name.split(' ')[0] })
                      : t('messages.assign', 'Assign')}
                    <Icon name="chevron-down" size={12} />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {myId !== null && !mine && (
                    <DropdownMenuItem onSelect={() => void patch({ assigned_profile_id: myId }, t('messages.assigned', 'Assigned to you'))}>
                      {t('messages.assignMe', 'Assign to me')}
                    </DropdownMenuItem>
                  )}
                  {conversation.assignee !== null && (
                    <DropdownMenuItem onSelect={() => void patch({ assigned_profile_id: null }, t('messages.unassigned', 'No one is assigned now'))}>
                      {t('messages.unassign', 'Unassign')}
                    </DropdownMenuItem>
                  )}
                  {conversation.status !== 'pending' && conversation.status !== 'closed' && (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem onSelect={() => void patch({ status: 'pending' }, statusLabel('pending'))}>
                        {t('messages.action.pending', 'Mark waiting for customer')}
                      </DropdownMenuItem>
                    </>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
              {conversation.status === 'closed' ? (
                <Button type="button" variant="outline" size="sm" icon="undo" onClick={() => void patch({ status: 'open' }, statusLabel('open'))}>
                  {t('messages.action.reopen', 'Reopen')}
                </Button>
              ) : (
                <Button type="button" variant="outline" size="sm" icon="check" onClick={() => void patch({ status: 'closed' }, statusLabel('closed'))}>
                  {tc('customer.close', 'Close')}
                </Button>
              )}
            </div>
          ) : null}
          {canManage && (
            // On a phone the header has room for one button: the same actions behind ⋯.
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="icon" className="sm:hidden" aria-label={tc('customer.actions', 'Assign or close')}>
                  <Icon name="more" size={16} />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {myId !== null && !mine && (
                  <DropdownMenuItem onSelect={() => void patch({ assigned_profile_id: myId }, t('messages.assigned', 'Assigned to you'))}>
                    {t('messages.assignMe', 'Assign to me')}
                  </DropdownMenuItem>
                )}
                {conversation.assignee !== null && (
                  <DropdownMenuItem onSelect={() => void patch({ assigned_profile_id: null }, t('messages.unassigned', 'No one is assigned now'))}>
                    {t('messages.unassign', 'Unassign')}
                  </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                {conversation.status === 'closed' ? (
                  <DropdownMenuItem onSelect={() => void patch({ status: 'open' }, statusLabel('open'))}>{t('messages.action.reopen', 'Reopen')}</DropdownMenuItem>
                ) : (
                  <DropdownMenuItem onSelect={() => void patch({ status: 'closed' }, statusLabel('closed'))}>{tc('customer.close', 'Close')}</DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
          {!canManage && (
            <Badge variant={conversation.status === 'open' ? 'info' : conversation.status === 'pending' ? 'warning' : 'outline'}>
              {statusLabel(conversation.status)}
            </Badge>
          )}
          <IconButton
            icon="info"
            label={detailsOpen ? tc('details.hide', 'Hide details') : tc('details.show', 'Show details')}
            pressed={detailsOpen}
            onClick={onToggleDetails}
          />
        </header>

        <ol className="min-h-0 flex-1 space-y-3 overflow-y-auto bg-muted/40 px-4 py-4" aria-live="polite">
          {messages.map((message) => (
            <CustomerMessageItem
              key={message.id}
              message={message}
              customerName={firstName}
              newLine={newFrom !== null && newFrom > 0 && message.id === newFrom ? unreadCount : 0}
              onCancel={canReply ? () => void cancel(message) : undefined}
              onEdit={canReply ? () => void edit(message) : undefined}
              onRetry={canReply ? () => void retry(message) : undefined}
              onCopy={() => copy(message)}
              blockText={tc}
            />
          ))}
          <div ref={bottom} />
        </ol>

        {blocked && (
          <div className="flex flex-wrap items-center gap-3 border-t border-border bg-muted px-4 py-2.5 text-sm">
            <Icon name="ban" size={16} />
            <span className="flex-1">
              {tc('block.banner', 'You blocked this contact on {date}. Nothing they send reaches the team.', { date: dateOnly(blocked.created_at) ?? '' })}
            </span>
            {canManage && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={async () => {
                  try {
                    await chat.unblock(blocked.id);
                    setBlocked(null);
                  } catch (e) {
                    addToast(chatErrorText(e, ''), 'error');
                  }
                }}
              >
                {tc('blocked.unblock', 'Unblock')}
              </Button>
            )}
          </div>
        )}
        {canReply ? (
          <form
            className="grid gap-2 border-t border-border bg-card px-4 py-3"
            onSubmit={(event) => {
              event.preventDefault();
              void send();
            }}
          >
            <div className="flex flex-wrap items-center gap-2">
              <div role="radiogroup" aria-label={t('messages.mode.label', 'What you are writing')} className="inline-flex rounded-control border border-border p-0.5">
                {(['reply', 'note'] as const).map((m) => (
                  <button
                    key={m}
                    type="button"
                    role="radio"
                    aria-checked={mode === m}
                    onClick={() => setMode(m)}
                    className={cn(
                      'h-8 rounded-control-sm px-2.5 text-sm',
                      mode === m ? (m === 'note' ? 'bg-warning-tint font-medium text-foreground' : 'bg-primary-tint font-medium text-primary-tint-foreground') : 'text-muted-foreground hover:bg-muted'
                    )}
                  >
                    {m === 'reply' ? t('messages.mode.reply', 'Reply') : t('messages.mode.note', 'Internal note')}
                  </button>
                ))}
              </div>
              <span className="ms-auto text-sm text-muted-foreground">
                {mode === 'reply'
                  ? tc('customer.sendBy', 'Send by {channel}', { channel })
                  : t('messages.composer.noteOnly', 'Only your team sees this')}
              </span>
            </div>
            <div
              className={cn(
                'flex items-end gap-2 rounded-card border px-3 py-1.5 focus-within:outline-2 focus-within:outline-ring',
                mode === 'note' ? 'border-[1.5px] border-dashed border-warning bg-warning-tint' : 'border-input bg-card'
              )}
            >
              {mode === 'reply' && blockOptions && (blockOptions.payments || (blockOptions.booking_page && blockOptions.services.length > 0) || blockOptions.forms.length > 0) && (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button type="button" variant="ghost" size="icon-sm" className="mb-1" aria-label={tc('cblock.add', 'Send a booking, form or payment')}>
                      <Icon name="plus" size={18} />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="start" side="top">
                    {blockOptions.payments && (
                      <DropdownMenuItem onSelect={() => setBlockKind('payment')}>
                        <Icon name="card" size={16} />
                        {tc('cblock.menuPay', 'Payment link')}
                      </DropdownMenuItem>
                    )}
                    {blockOptions.booking_page && blockOptions.services.length > 0 && (
                      <DropdownMenuItem onSelect={() => setBlockKind('booking_times')}>
                        <Icon name="calendar" size={16} />
                        {tc('cblock.menuTimes', 'Booking times')}
                      </DropdownMenuItem>
                    )}
                    {blockOptions.forms.length > 0 && (
                      <DropdownMenuItem onSelect={() => setBlockKind('form')}>
                        <Icon name="form" size={16} />
                        {tc('cblock.menuForm', 'Ask for a form')}
                      </DropdownMenuItem>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
              <Textarea
                ref={box}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                rows={1}
                maxLength={4000}
                aria-label={mode === 'reply' ? t('messages.composer.reply', 'Your reply') : t('messages.composer.note', 'Your note')}
                placeholder={
                  mode === 'reply'
                    ? tc('customer.replyTo', 'Reply to {name} by {channel}', { name: firstName, channel })
                    : t('messages.composer.noteHint', 'Write a note. Only your team sees it.')
                }
                className="max-h-36 min-h-10 flex-1 resize-none overflow-y-auto border-0 bg-transparent p-0 py-2 text-base shadow-none [field-sizing:content] focus-visible:border-0 focus-visible:ring-0 focus-visible:outline-none max-sm:text-lg md:text-base"
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    void send();
                  }
                }}
              />
              <Button
                type="submit"
                size="icon-sm"
                className="mb-1 rtl:[&_svg]:-scale-x-100"
                disabled={sending || draft.trim() === ''}
                aria-label={mode === 'reply' ? t('messages.send', 'Send') : t('messages.addNote', 'Add note')}
                title={mode === 'reply' ? t('messages.send', 'Send') : t('messages.addNote', 'Add note')}
              >
                <Icon name={mode === 'reply' ? 'send' : 'check'} size={16} />
              </Button>
            </div>
          </form>
        ) : (
          <div className="border-t border-border bg-card px-4 py-3">
            <ReadOnlyNotice
              title={t('messages.readOnlyTitle', 'You can read this conversation')}
              description={t('messages.readOnly', 'Replying needs the “Reply to customers” permission. Ask an owner.')}
            />
          </div>
        )}
      </section>
      {blockKind !== null && blockOptions && (
        <CustomerBlockDialog
          t={tc}
          kind={blockKind}
          conversationId={id}
          customerName={firstName}
          options={blockOptions}
          currency={currency}
          onClose={() => setBlockKind(null)}
          onSent={() => {
            setBlockKind(null);
            detail.refetch();
            onChanged();
          }}
        />
      )}
      {detailsOpen && (
        <CustomerDetails
          conversation={conversation}
          channel={channel}
          pinned={pinned}
          onPin={onPin}
          muted={muted}
          onMute={onMute}
          canBlock={canManage && blocked === null}
          onBlock={async (reportSpam) => {
            try {
              setBlocked(await chat.block(id, reportSpam));
              addToast(tc('block.done', 'Blocked. Nothing they send reaches the team.'), 'success');
            } catch (e) {
              addToast(chatErrorText(e, ''), 'error');
            }
          }}
          onClose={onToggleDetails}
        />
      )}
    </>
  );
}

/** Two ticks for a reply that left: in the main colour once the customer has read it (UC.11). */
function Ticks({ status, t }: { status: Message['status']; t: TranslateFn }) {
  if (status !== 'sent' && status !== 'delivered' && status !== 'read') return null;
  const read = status === 'read';
  const label =
    status === 'read' ? t('messages.state.read', 'Read') : status === 'delivered' ? t('messages.state.delivered', 'Delivered') : t('messages.state.sent', 'Sent');
  return (
    <span className={cn('inline-flex', read ? 'text-primary' : 'text-muted-foreground')} title={label} aria-label={label}>
      <Icon name="check" size={12} />
      {status !== 'sent' && <Icon name="check" size={12} className="-ms-2" />}
    </span>
  );
}

function CustomerMessageItem({
  message,
  customerName,
  newLine,
  onCancel,
  onEdit,
  onRetry,
  onCopy,
  blockText,
}: {
  blockText: TranslateFn;
  message: Message;
  customerName: string;
  /** When above zero, the "n new" line goes above this message. */
  newLine: number;
  onCancel?: () => void;
  onEdit?: () => void;
  onRetry?: () => void;
  onCopy: () => void;
}) {
  const t = useTranslation('admin');
  const tc = useTranslation('chat');
  const { time, dateTime } = useDateDisplay();
  const inbound = message.kind === 'inbound';
  const note = message.kind === 'note';
  const pending = isPending(message);
  const failed = message.status === 'failed';
  const bot = (message.source ?? '').startsWith('bot.');
  // A bot is named by its rule; the Bot tag beside it says what it is.
  const author = bot
    ? message.source === 'bot.away'
      ? tc('bots.away', 'Away reply')
      : message.source === 'bot.welcome'
        ? tc('bots.welcome', 'Welcome')
        : tc('bot.name', 'Bot')
    : message.by_agent
      ? t('messages.byAgent', 'Agent for {name}', { name: message.author?.name ?? '' })
      : message.author?.name ?? t('messages.workspace', 'Your team');
  const at = useListTime(true)(message.created_at);

  return (
    <>
      {newLine > 0 && (
        <li className="flex items-center gap-3 py-1 text-sm font-medium text-primary" role="separator">
          <span className="h-px flex-1 bg-primary" />
          {tc('thread.new', '{count} new', { count: String(newLine) })}
          <span className="h-px flex-1 bg-primary" />
        </li>
      )}
      <li className={cn('grid max-w-[78%] gap-1', !inbound && 'ms-auto justify-items-end')}>
        <div
          className={cn(
            'grid gap-1 rounded-[14px] border px-3.5 py-2 text-start',
            inbound && 'border-border bg-card',
            !inbound && !note && !bot && 'border-transparent bg-primary-tint',
            bot && 'border-border bg-muted',
            note && 'border-[1.5px] border-dashed border-warning bg-warning-tint',
            pending && 'border-[1.5px] border-dashed border-input bg-card',
            failed && 'border-[1.5px] border-destructive',
            message.status === 'cancelled' && 'opacity-60'
          )}
        >
          {!inbound && (
            <p className="flex flex-wrap items-center gap-2 text-xs">
              {message.by_agent && <Icon name="robot" size={12} />}
              {bot && <Icon name="trigger" size={12} />}
              <span className={cn('font-semibold', bot ? 'text-muted-foreground' : 'text-warning-text')}>{author}</span>
              {bot && <span className="rounded-control-sm bg-card px-1.5 text-xs font-semibold text-muted-foreground">{tc('bot.tag', 'Bot')}</span>}
              {note && <span className="text-muted-foreground">{tc('customer.noteOnly', 'Only the team sees this')}</span>}
            </p>
          )}
          <p dir="auto" className="whitespace-pre-wrap break-words text-start text-foreground max-sm:text-lg">{message.body}</p>
          {message.block && !inbound && <CustomerBlockCard t={blockText} block={message.block} />}
          {message.attachments_not_shown > 0 && (
            <p className="text-xs text-muted-foreground">{t('messages.attachments', '{n} attachment(s) not shown', { n: message.attachments_not_shown })}</p>
          )}
          <p className="flex items-center justify-end gap-1 text-xs text-muted-foreground">
            {at}
            {!inbound && !note && <Ticks status={message.status} t={t} />}
          </p>
        </div>
        {pending && (
          <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            {message.send_after
              ? t('messages.state.held', 'Not sent yet. Sends at {time}', { time: time(message.send_after) ?? dateTime(message.send_after) ?? '' })
              : t('messages.state.queued', 'Sending…')}
            {message.send_after && onCancel && (
              <>
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                  {t('messages.cancel', 'Cancel')}
                </Button>
                {onEdit && (
                  <Button type="button" variant="outline" size="sm" icon="edit" onClick={onEdit}>
                    {t('messages.edit', 'Edit')}
                  </Button>
                )}
              </>
            )}
          </p>
        )}
        {message.status === 'cancelled' && <p className="text-xs text-muted-foreground">{t('messages.state.cancelled', 'Cancelled, not sent')}</p>}
        {failed && !inbound && !note && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-error-text">
            <Icon name="error" size={14} />
            {message.status_detail
              ? t('messages.state.failedWhy', 'Not sent: {reason}', { reason: message.status_detail })
              : t('messages.state.failed', 'Not sent')}
            {onRetry && (
              <Button type="button" variant="outline" size="sm" onClick={onRetry}>
                {t('messages.retry', 'Try again')}
              </Button>
            )}
            <Button type="button" variant="ghost" size="sm" onClick={onCopy}>
              {t('messages.copy', 'Copy text')}
            </Button>
          </div>
        )}
        {inbound && <span className="sr-only">{customerName}</span>}
      </li>
    </>
  );
}

/** The customer's details on the right (UC.3): how to reach them, who has it, and their contact record. */
function CustomerDetails({
  conversation,
  channel,
  pinned,
  onPin,
  muted,
  onMute,
  canBlock,
  onBlock,
  onClose,
}: {
  conversation: ConversationDetail;
  channel: string;
  pinned: boolean;
  onPin: (pinned: boolean) => void;
  muted: boolean;
  onMute?: (muted: boolean) => void;
  canBlock: boolean;
  onBlock: (reportSpam: boolean) => Promise<void>;
  onClose: () => void;
}) {
  const [confirmBlock, setConfirmBlock] = useState(false);
  const [reportSpam, setReportSpam] = useState(false);
  const whatsapp = channel === 'WhatsApp';
  const t = useTranslation('admin');
  const tc = useTranslation('chat');
  const { date } = useDateDisplay();
  const name = contactLabel(conversation) || t('messages.visitor', 'Website visitor');
  const address = displayAddress(conversation.contact.address);

  return (
    <ChatSidePane title={name} subtitle={tc('customer.kind', 'Customer')} onClose={onClose} closeLabel={tc('details.close', 'Close details')}>
      <div className="flex items-center gap-3">
        <CustomerAvatar name={name} channel={conversation.channel} size={48} label={channel} />
        <div className="min-w-0">
          <p className="truncate text-lg font-semibold">{name}</p>
          <p className="text-sm text-muted-foreground">{tc('customer.since', 'Customer since {date}', { date: date(conversation.created_at) ?? '' })}</p>
        </div>
      </div>
      <dl className="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
        <dt className="text-muted-foreground">{channel}</dt>
        <dd dir="ltr" className="truncate text-start">{address}</dd>
        <dt className="text-muted-foreground">{tc('customer.assignedLabel', 'Assigned')}</dt>
        <dd>{conversation.assignee?.name ?? t('messages.filter.unassigned', 'Unassigned')}</dd>
        {conversation.subject && (
          <>
            <dt className="text-muted-foreground">{tc('customer.subject', 'Subject')}</dt>
            <dd dir="auto" className="text-start">{conversation.subject}</dd>
          </>
        )}
      </dl>
      <div className="flex flex-wrap gap-2">
        {conversation.customer && (
          <Button asChild variant="outline" size="sm">
            <Link href="/contacts">{tc('customer.openContact', 'Open contact')}</Link>
          </Button>
        )}
        <Button variant="ghost" size="sm" icon="star" onClick={() => onPin(!pinned)} aria-pressed={pinned}>
          {pinned ? tc('pin.offShort', 'Unpin') : tc('pin.onShort', 'Pin')}
        </Button>
        {onMute && (
          <Button variant="ghost" size="sm" icon="notification" onClick={() => onMute(!muted)} aria-pressed={muted}>
            {muted ? tc('mute.off', 'Unmute') : tc('mute.on', 'Mute')}
          </Button>
        )}
        {canBlock && (
          <Button variant="ghost" size="sm" icon="ban" onClick={() => setConfirmBlock(true)}>
            {tc('block.action', 'Block')}
          </Button>
        )}
      </div>
      <ConfirmAction
        open={confirmBlock}
        onOpenChange={setConfirmBlock}
        title={tc('block.title', 'Block {who}?', { who: displayAddress(conversation.contact.address) || name })}
        description={
          <span className="grid gap-3">
            <span>
              {tc(
                'block.body',
                'They won’t be able to message you on {channel} any more. Past messages stay. You can unblock in Settings › Chat.',
                { channel }
              )}
            </span>
            {whatsapp && (
              <label className="flex items-center gap-2 text-foreground">
                <Checkbox checked={reportSpam} onCheckedChange={(v) => setReportSpam(v === true)} />
                {tc('block.spam', 'Also report as spam to WhatsApp')}
              </label>
            )}
          </span>
        }
        confirmLabel={tc('block.confirm', 'Block')}
        cancelLabel={tc('action.cancel', 'Cancel')}
        variant="destructive"
        onConfirm={async () => {
          setConfirmBlock(false);
          await onBlock(reportSpam);
        }}
      />
    </ChatSidePane>
  );
}

/**
 * The right pane (UC.2): 360px beside the conversation on a wide screen; under
 * 1280px it opens over the conversation, and on a phone it is its own screen.
 */
export function ChatSidePane({
  title,
  subtitle,
  onClose,
  closeLabel,
  children,
}: {
  title: string;
  subtitle?: string;
  onClose: () => void;
  closeLabel: string;
  children: React.ReactNode;
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && window.matchMedia('(max-width: 1279px)').matches) onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <aside
      aria-label={title}
      className="absolute inset-0 z-20 flex min-h-0 flex-col bg-card md:inset-y-0 md:start-auto md:end-0 md:w-[22.5rem] md:border-s md:border-border md:shadow-lg xl:static xl:z-auto xl:shrink-0 xl:shadow-none"
    >
      <header className="flex items-start gap-2 border-b border-border px-4 py-3">
        <IconButton icon="chevron-left" label={closeLabel} className="md:hidden rtl:rotate-180" onClick={onClose} />
        <div className="min-w-0 flex-1">
          <h2 className="truncate text-base font-semibold">{title}</h2>
          {subtitle && <p className="truncate text-sm text-muted-foreground">{subtitle}</p>}
        </div>
        <IconButton icon="close" label={closeLabel} className="max-md:hidden" onClick={onClose} />
      </header>
      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">{children}</div>
    </aside>
  );
}
