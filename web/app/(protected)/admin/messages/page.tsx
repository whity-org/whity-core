'use client';

/**
 * Messages (ADR 0016, WHIT-939): the workspace's conversations with its
 * customers, by email and web chat, in one inbox.
 *
 * Desktop shows the list and the open conversation side by side; a phone shows
 * one at a time with a way back. The list filters by status (tabs with counts),
 * by who it is assigned to, and by search. The thread shows customer messages
 * at the start, the workspace's at the end, internal notes on a tint, and a
 * message that has not left yet (an agent's held reply) dashed, with Cancel.
 *
 * No card exists for this screen yet; it is built from the existing pieces and
 * the brand team has been asked for a design (brand-ask-messages-inbox).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { SearchField } from '@whity/ui/search-field';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { Textarea } from '@whity/ui/textarea';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { throwUnlessOk } from '@/lib/forbidden';
import { cn } from '@/lib/utils';
import {
  contactLabel,
  displayAddress,
  errorText,
  isPending,
  type Conversation,
  type ConversationDetail,
  type ConversationList,
  type ConversationStatus,
  type Message,
} from '@/lib/messages-wire';

const STATUSES: ConversationStatus[] = ['open', 'pending', 'closed'];
type AssigneeFilter = 'all' | 'me' | 'none';
const POLL_MS = 15000;

export default function MessagesPage() {
  const t = useTranslation('admin');
  const { apiClient } = useAuth();
  const { has } = useCapabilities();
  const canReply = has('messages:reply');
  const canManage = has('messages:manage');

  const [status, setStatus] = useState<ConversationStatus>('open');
  const [assignee, setAssignee] = useState<AssigneeFilter>('all');
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  // Opens the conversation named in the address (?c=42), as links from the inbox do.
  const [selected, setSelected] = useState<number | null>(() => {
    if (typeof window === 'undefined') return null;
    const id = Number(new URLSearchParams(window.location.search).get('c'));
    return Number.isInteger(id) && id > 0 ? id : null;
  });

  const selectConversation = useCallback((id: number | null) => {
    setSelected(id);
    const url = new URL(window.location.href);
    if (id === null) url.searchParams.delete('c');
    else url.searchParams.set('c', String(id));
    window.history.replaceState(null, '', url.toString());
  }, []);

  const list = useFetch<ConversationList>(async () => {
    const params = new URLSearchParams({ status, limit: '50' });
    if (assignee !== 'all') params.set('assignee', assignee);
    if (search !== '') params.set('q', search);
    const response = await apiClient(`/api/v1/messages/conversations?${params.toString()}`);
    throwUnlessOk(response, t('messages.error.list', 'Your conversations could not be loaded.'));
    return (await response.json()) as ConversationList;
  }, [apiClient, status, assignee, search]);

  const refetchList = list.refetch;
  useEffect(() => {
    const timer = window.setInterval(() => refetchList(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [refetchList]);

  const rows = list.data?.data ?? [];
  const counts = list.data?.counts ?? {};
  const filtered = search !== '' || assignee !== 'all';

  const statusLabel = (s: ConversationStatus): string =>
    s === 'open'
      ? t('messages.status.open', 'Open')
      : s === 'pending'
        ? t('messages.status.pending', 'Waiting for customer')
        : t('messages.status.closed', 'Closed');

  if (list.forbidden) {
    return (
      <div className="space-y-6">
        <AdminHeader title={t('messages.title', 'Messages')} />
        <AccessDeniedPanel
          title={t('messages.denied.title', 'You can’t see Messages')}
          description={t('messages.denied.description', 'Ask someone who runs this workspace to give your role access to Messages.')}
        />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('messages.title', 'Messages')}
        description={t('messages.description', 'Conversations with your customers by email and web chat.')}
      />

      <div className="grid min-h-[32rem] overflow-hidden rounded-card border border-border bg-card md:grid-cols-[minmax(18rem,24rem)_1fr]">
        <section
          aria-label={t('messages.list.label', 'Conversations')}
          className={cn('flex min-w-0 flex-col border-border md:border-e', selected !== null && 'hidden md:flex')}
        >
          <div className="space-y-3 border-b border-border p-3">
            <Tabs value={status} onValueChange={(v) => setStatus(v as ConversationStatus)}>
              <TabsList className="w-full">
                {STATUSES.map((s) => (
                  <TabsTrigger key={s} value={s} className="flex-1">
                    {s === 'pending' ? t('messages.tab.pending', 'Waiting') : statusLabel(s)}
                    {typeof counts[s] === 'number' && <span className="ms-1 text-muted-foreground">{counts[s]}</span>}
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>
            <SearchField
              value={query}
              onValueChange={(value) => {
                setQuery(value);
                if (value === '') setSearch('');
              }}
              onSearch={(value) => setSearch(value.trim())}
              label={t('messages.search.label', 'Search conversations')}
              clearLabel={t('messages.search.clear', 'Clear search')}
              placeholder={t('messages.search.placeholder', 'Search names, addresses and messages')}
            />
            <ToggleGroup
              value={assignee}
              required
              onChange={(v) => setAssignee((typeof v === 'string' ? v : 'all') as AssigneeFilter)}
              options={[
                { value: 'all', label: t('messages.filter.all', 'Everyone') },
                { value: 'me', label: t('messages.filter.mine', 'Mine') },
                { value: 'none', label: t('messages.filter.unassigned', 'Unassigned') },
              ]}
              label={t('messages.filter.label', 'Assigned to')}
            />
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto">
            {list.loading && rows.length === 0 ? (
              <ul aria-busy="true" className="divide-y divide-border">
                {[0, 1, 2, 3].map((i) => (
                  <li key={i} className="h-16 animate-pulse bg-muted/40" />
                ))}
              </ul>
            ) : list.error ? (
              <AreaError
                message={t('messages.error.list', 'Your conversations could not be loaded.')}
                retryLabel={t('messages.retry', 'Try again')}
                onRetry={() => list.refetch()}
              />
            ) : rows.length === 0 ? (
              filtered ? (
                <EmptyFiltered
                  title={t('messages.empty.filtered', 'No conversations match')}
                  clearLabel={t('messages.empty.clear', 'Clear filters')}
                  onClear={() => {
                    setQuery('');
                    setSearch('');
                    setAssignee('all');
                  }}
                />
              ) : (
                <EmptyFirstTime
                  icon="comment"
                  title={t('messages.empty.title', 'No conversations here')}
                  description={t(
                    'messages.empty.description',
                    'When customers email you or use the chat on your pages, their messages arrive here.'
                  )}
                />
              )
            ) : (
              <ul className="divide-y divide-border">
                {rows.map((row) => (
                  <ConversationRow
                    key={row.id}
                    conversation={row}
                    active={row.id === selected}
                    onOpen={() => selectConversation(row.id)}
                  />
                ))}
              </ul>
            )}
          </div>
        </section>

        <section
          aria-label={t('messages.thread.label', 'Conversation')}
          className={cn('min-w-0 flex-col', selected === null ? 'hidden md:flex' : 'flex')}
        >
          {selected === null ? (
            <EmptyFirstTime
              icon="comment"
              title={t('messages.pick.title', 'Pick a conversation')}
              description={t('messages.pick.description', 'Choose one from the list to read it and reply.')}
              className="m-auto"
            />
          ) : (
            <Thread
              key={selected}
              id={selected}
              canReply={canReply}
              canManage={canManage}
              statusLabel={statusLabel}
              onBack={() => selectConversation(null)}
              onChanged={() => list.refetch()}
            />
          )}
        </section>
      </div>
    </div>
  );
}

function ConversationRow({ conversation, active, onOpen }: { conversation: Conversation; active: boolean; onOpen: () => void }) {
  const t = useTranslation('admin');
  const { relative } = useDateDisplay();
  const name = contactLabel(conversation) || t('messages.visitor', 'Website visitor');
  const age = relative(conversation.last_message_at, {
    seconds: () => t('messages.age.now', 'now'),
    minutes: (n) => t('messages.age.minutes', '{n} min', { n }),
    hours: (n) => t('messages.age.hours', '{n} h', { n }),
    days: (n) => t('messages.age.days', '{n} d', { n }),
  });

  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        aria-current={active ? 'true' : undefined}
        className={cn(
          'flex w-full items-start gap-3 px-3 py-3 text-start hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring',
          active && 'bg-muted'
        )}
      >
        <Avatar name={name} size={32} />
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span className="truncate font-medium text-foreground">{name}</span>
            <ChannelBadge channel={conversation.channel} />
            {age && <span className="ms-auto shrink-0 text-xs text-muted-foreground">{age}</span>}
          </span>
          {conversation.subject && <span dir="auto" className="block truncate text-start text-sm text-foreground">{conversation.subject}</span>}
          {conversation.preview && <span dir="auto" className="block truncate text-start text-sm text-muted-foreground">{conversation.preview}</span>}
          {conversation.assignee && (
            <span className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Avatar name={conversation.assignee.name} size={16} />
              {conversation.assignee.name}
            </span>
          )}
        </span>
      </button>
    </li>
  );
}

function ChannelBadge({ channel }: { channel: string }) {
  const t = useTranslation('admin');
  const label =
    channel === 'email'
      ? t('messages.channel.email', 'Email')
      : channel === 'webchat'
        ? t('messages.channel.webchat', 'Web chat')
        : channel.split(':').pop() ?? channel;

  return (
    <Badge variant="outline" className="shrink-0">
      {label}
    </Badge>
  );
}

function Thread({
  id,
  canReply,
  canManage,
  statusLabel,
  onBack,
  onChanged,
}: {
  id: number;
  canReply: boolean;
  canManage: boolean;
  statusLabel: (s: ConversationStatus) => string;
  onBack: () => void;
  onChanged: () => void;
}) {
  const t = useTranslation('admin');
  const { apiClient, user } = useAuth();
  const { addToast } = useToast();
  const { dateTime } = useDateDisplay();
  const [mode, setMode] = useState<'reply' | 'note'>('reply');
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);

  const detail = useFetch<ConversationDetail>(async () => {
    const response = await apiClient(`/api/v1/messages/conversations/${id}`);
    if (!response.ok) throw new Error(t('messages.error.thread', 'This conversation could not be loaded.'));
    return ((await response.json()) as { data: ConversationDetail }).data;
  }, [apiClient, id]);

  const refetch = detail.refetch;
  useEffect(() => {
    const timer = window.setInterval(() => refetch(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [refetch]);

  const messageCount = detail.data?.messages.length ?? 0;
  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' });
  }, [messageCount]);

  const conversation = detail.data;
  const myId = typeof user?.id === 'number' ? user.id : null;

  const patch = async (body: Record<string, unknown>, done: string): Promise<void> => {
    const response = await apiClient(`/api/v1/messages/conversations/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    if (!response.ok) {
      addToast(await errorText(response, t('messages.error.update', 'That change was not saved.')), 'error');
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
      const response = await apiClient(`/api/v1/messages/conversations/${id}/${mode === 'reply' ? 'replies' : 'notes'}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ body: text }),
      });
      if (!response.ok) {
        addToast(await errorText(response, t('messages.error.send', 'Your message was not sent. Please try again.')), 'error');
        return;
      }
      setDraft('');
      detail.refetch();
      onChanged();
    } finally {
      setSending(false);
    }
  };

  const cancel = async (message: Message): Promise<void> => {
    const response = await apiClient(`/api/v1/messages/${message.id}/cancel`, { method: 'POST' });
    if (!response.ok) {
      addToast(t('messages.error.cancel', 'It has already been sent.'), 'error');
    } else {
      addToast(t('messages.cancelled', 'Message cancelled. It will not be sent.'), 'success');
    }
    detail.refetch();
  };

  const statusActions = useMemo(() => (conversation ? STATUSES.filter((s) => s !== conversation.status) : []), [conversation]);

  if (detail.error) {
    return (
      <AreaError
        message={t('messages.error.thread', 'This conversation could not be loaded.')}
        retryLabel={t('messages.retry', 'Try again')}
        onRetry={() => detail.refetch()}
      />
    );
  }
  if (!conversation) {
    return <div aria-busy="true" className="m-6 h-40 animate-pulse rounded-card bg-muted/40" />;
  }

  const name = contactLabel(conversation) || t('messages.visitor', 'Website visitor');
  const address = displayAddress(conversation.contact.address);

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-border p-3">
        <Button type="button" variant="ghost" size="icon" className="md:hidden" onClick={onBack} aria-label={t('messages.back', 'Back to conversations')} title={t('messages.back', 'Back to conversations')}>
          <Icon name="chevron-left" size={16} />
        </Button>
        <Avatar name={name} size={40} />
        <div className="min-w-0 flex-1">
          <h2 className="truncate text-base font-semibold text-foreground">{name}</h2>
          <p dir="auto" className="truncate text-start text-sm text-muted-foreground">
            {[address, conversation.subject].filter(Boolean).join(' · ')}
          </p>
        </div>
        <div className="flex items-center gap-2 max-sm:order-last max-sm:w-full">
          <ChannelBadge channel={conversation.channel} />
          <Badge variant={conversation.status === 'open' ? 'info' : conversation.status === 'pending' ? 'warning' : 'outline'}>
            {statusLabel(conversation.status)}
          </Badge>
        </div>
        {canManage && (
          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            {myId !== null && conversation.assignee?.id !== myId && (
              <Button type="button" variant="outline" size="sm" onClick={() => void patch({ assigned_profile_id: myId }, t('messages.assigned', 'Assigned to you'))}>
                {t('messages.assignMe', 'Assign to me')}
              </Button>
            )}
            {conversation.assignee !== null && (
              <Button type="button" variant="outline" size="sm" onClick={() => void patch({ assigned_profile_id: null }, t('messages.unassigned', 'No one is assigned now'))}>
                {t('messages.unassign', 'Unassign')}
              </Button>
            )}
            {statusActions.map((s) => (
              <Button key={s} type="button" variant="outline" size="sm" onClick={() => void patch({ status: s }, statusLabel(s))}>
                {s === 'closed'
                  ? t('messages.action.close', 'Close')
                  : s === 'open'
                    ? t('messages.action.reopen', 'Reopen')
                    : t('messages.action.pending', 'Mark waiting for customer')}
              </Button>
            ))}
          </div>
        )}
      </header>

      <ol className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
        {conversation.messages.map((message) => (
          <MessageBubble key={message.id} message={message} customerName={name} dateTime={dateTime} onCancel={canReply ? () => void cancel(message) : undefined} />
        ))}
        <div ref={bottom} />
      </ol>

      {canReply ? (
        <form
          className="space-y-2 border-t border-border p-3"
          onSubmit={(event) => {
            event.preventDefault();
            void send();
          }}
        >
          <ToggleGroup
            value={mode}
            required
            onChange={(v) => setMode(v === 'note' ? 'note' : 'reply')}
            options={[
              { value: 'reply', label: t('messages.mode.reply', 'Reply to customer') },
              { value: 'note', label: t('messages.mode.note', 'Internal note') },
            ]}
            label={t('messages.mode.label', 'What you are writing')}
          />
          <Textarea
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            rows={3}
            aria-label={mode === 'reply' ? t('messages.composer.reply', 'Your reply') : t('messages.composer.note', 'Your note')}
            placeholder={
              mode === 'reply'
                ? t('messages.composer.replyHint', 'Write a reply. It goes to the customer.')
                : t('messages.composer.noteHint', 'Write a note. Only your team sees it.')
            }
            className={cn(mode === 'note' && 'bg-warning-tint')}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                void send();
              }
            }}
          />
          <div className="flex justify-end">
            <Button type="submit" disabled={sending || draft.trim() === ''}>
              {mode === 'reply' ? t('messages.send', 'Send') : t('messages.addNote', 'Add note')}
            </Button>
          </div>
        </form>
      ) : (
        <p className="border-t border-border p-3 text-sm text-muted-foreground">
          {t('messages.readOnly', 'You can read this conversation. Replying needs the Reply permission.')}
        </p>
      )}
    </div>
  );
}

function MessageBubble({
  message,
  customerName,
  dateTime,
  onCancel,
}: {
  message: Message;
  customerName: string;
  dateTime: (value: string | null | undefined) => string | null;
  onCancel?: () => void;
}) {
  const t = useTranslation('admin');
  const inbound = message.kind === 'inbound';
  const note = message.kind === 'note';
  const pending = isPending(message);
  const who = inbound
    ? customerName
    : message.by_agent
      ? t('messages.byAgent', 'Agent for {name}', { name: message.author?.name ?? '' })
      : message.author?.name ?? t('messages.workspace', 'Your team');

  const stateText = (): string | null => {
    switch (message.status) {
      case 'queued':
        return message.send_after
          ? t('messages.state.held', 'Not sent yet. Sends at {time}', { time: dateTime(message.send_after) ?? '' })
          : t('messages.state.queued', 'Sending…');
      case 'failed':
        return message.status_detail
          ? t('messages.state.failedWhy', 'Not sent: {reason}', { reason: message.status_detail })
          : t('messages.state.failed', 'Not sent');
      case 'cancelled':
        return t('messages.state.cancelled', 'Cancelled, not sent');
      case 'delivered':
        return t('messages.state.delivered', 'Delivered');
      case 'read':
        return t('messages.state.read', 'Read');
      default:
        return null;
    }
  };
  const state = stateText();

  return (
    <li className={cn('flex', inbound ? 'justify-start' : 'justify-end')}>
      <div
        className={cn(
          'max-w-[85%] rounded-card border px-3 py-2',
          inbound && 'border-border bg-muted',
          !inbound && !note && 'border-border bg-card',
          note && 'border-warning-text/30 bg-warning-tint',
          pending && 'border-dashed',
          message.status === 'cancelled' && 'opacity-60'
        )}
      >
        <p className="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <Avatar name={who} kind={message.by_agent ? 'agent' : 'person'} size={16} />
          <span className="font-medium text-foreground">{who}</span>
          {note && <Badge variant="warning">{t('messages.note', 'Note')}</Badge>}
          <span>{dateTime(message.created_at)}</span>
        </p>
        <p dir="auto" className="whitespace-pre-wrap break-words text-start text-foreground">{message.body}</p>
        {message.attachments_not_shown > 0 && (
          <p className="mt-1 text-xs text-muted-foreground">
            {t('messages.attachments', '{n} attachment(s) not shown', { n: message.attachments_not_shown })}
          </p>
        )}
        {state && (
          <p className={cn('mt-1 text-xs', message.status === 'failed' ? 'text-error-text' : 'text-muted-foreground')}>{state}</p>
        )}
        {pending && message.send_after && onCancel && (
          <Button type="button" variant="outline" size="sm" className="mt-2" onClick={onCancel}>
            {t('messages.cancel', 'Cancel this message')}
          </Button>
        )}
      </div>
    </li>
  );
}
