'use client';

/**
 * Messages (ADR 0016, WHIT-939): the workspace's conversations with its
 * customers, by email and web chat, in one inbox. Card: `TplMessagesInbox`.
 *
 * Desktop shows the list and the open conversation side by side; a phone shows
 * one at a time with a way back. The list filters by status (tabs with counts),
 * by who it is assigned to, and by search. The thread shows customer messages
 * at the start, the workspace's at the end, internal notes on a tint, and a
 * message that has not left yet (an agent's held reply) dashed, with Cancel.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Avatar } from '@whity/ui/avatar';
import type React from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { SearchField } from '@whity/ui/search-field';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { Textarea } from '@whity/ui/textarea';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { api, type components } from '@/lib/api/client';
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

/** `errorText` reads a body; the typed client has already parsed it into `error`. */
function rebuilt(response: Response, error: unknown): Response {
  return new Response(JSON.stringify(error ?? null), { status: response.status });
}

const STATUSES: ConversationStatus[] = ['open', 'pending', 'closed'];
type AssigneeFilter = 'all' | 'me' | 'none';
const POLL_MS = 15000;

export default function MessagesPage() {
  const t = useTranslation('admin');
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
    const { data, response } = await api.GET('/api/v1/messages/conversations', {
      params: {
        query: {
          status,
          limit: 50,
          ...(assignee !== 'all' ? { assignee } : {}),
          ...(search !== '' ? { q: search } : {}),
        },
      },
    });
    throwUnlessOk(response, t('messages.error.list', 'Your conversations could not be loaded.'));
    return data as unknown as ConversationList;
  }, [status, assignee, search]);

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
        description={t('messages.description', 'Customers’ email and web chat in one place. Customers never see internal notes.')}
        action={
          canManage ? (
            <Button asChild variant="outline">
              <Link href="/admin/settings/messages">
                <Icon name="settings" size={16} />
                {t('messages.settingsLink', 'Messages settings')}
              </Link>
            </Button>
          ) : undefined
        }
      />

      <Tabs value={status} onValueChange={(v) => setStatus(v as ConversationStatus)}>
        <TabsList variant="line">
          {STATUSES.map((s) => (
            <TabsTrigger key={s} value={s}>
              {statusLabel(s)}
              {s !== 'closed' && typeof counts[s] === 'number' && <span className="ms-1 text-muted-foreground">{counts[s]}</span>}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="grid min-h-[35rem] overflow-hidden rounded-card border border-border bg-card md:grid-cols-[minmax(17rem,21rem)_1fr]">
        <section
          aria-label={t('messages.list.label', 'Conversations')}
          className={cn('flex min-w-0 flex-col border-border md:border-e', selected !== null && 'hidden md:flex')}
        >
          <div className="space-y-3 border-b border-border p-3">
            <ToggleGroup
              value={assignee}
              required
              onChange={(v) => setAssignee((typeof v === 'string' ? v : 'all') as AssigneeFilter)}
              options={[
                { value: 'all', label: t('messages.filter.all', 'All') },
                { value: 'me', label: t('messages.filter.mine', 'Mine') },
                { value: 'none', label: t('messages.filter.unassigned', 'Unassigned') },
              ]}
              label={t('messages.filter.label', 'Assigned to')}
            />
            <SearchField
              value={query}
              onValueChange={(value) => {
                setQuery(value);
                if (value === '') setSearch('');
              }}
              onSearch={(value) => setSearch(value.trim())}
              label={t('messages.search.label', 'Search conversations')}
              clearLabel={t('messages.search.clear', 'Clear search')}
              placeholder={t('messages.search.placeholder', 'Search messages')}
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
                  className="m-3"
                  title={t('messages.empty.filtered', 'Nothing matches')}
                  description={
                    assignee === 'me' && search === ''
                      ? t('messages.empty.filteredMine', 'None of these conversations are assigned to you.')
                      : undefined
                  }
                  clearLabel={t('messages.empty.clear', 'Show all')}
                  onClear={() => {
                    setQuery('');
                    setSearch('');
                    setAssignee('all');
                  }}
                />
              ) : (
                <EmptyFirstTime
                  className="m-3"
                  icon="comment"
                  title={t('messages.empty.title', 'No messages yet')}
                  description={t(
                    'messages.empty.description',
                    'Turn on web chat or connect an email address, and customers’ messages land here.'
                  )}
                  action={
                    canManage ? (
                      <Button asChild variant="outline" size="sm">
                        <Link href="/admin/settings/messages">{t('messages.empty.setup', 'Set up messages')}</Link>
                      </Button>
                    ) : undefined
                  }
                />
              )
            ) : (
              <ul>
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

/** Today's messages by their time, older ones by their date (the card's time column). */
function useListTime(withTime = false): (value: string) => string | null {
  const { time, date, dateTime } = useDateDisplay();
  return (value: string) => {
    const at = new Date(value.replace(' ', 'T'));
    const now = new Date();
    const sameDay =
      !Number.isNaN(at.getTime()) &&
      at.getFullYear() === now.getFullYear() &&
      at.getMonth() === now.getMonth() &&
      at.getDate() === now.getDate();
    return sameDay ? time(value) : withTime ? dateTime(value) : date(value);
  };
}

function ConversationRow({ conversation, active, onOpen }: { conversation: Conversation; active: boolean; onOpen: () => void }) {
  const t = useTranslation('admin');
  const when = useListTime()(conversation.last_message_at);
  const name = contactLabel(conversation) || t('messages.visitor', 'Website visitor');
  const line = conversation.preview ?? conversation.subject;

  return (
    <li className="border-b border-border">
      <button
        type="button"
        onClick={onOpen}
        aria-current={active ? 'true' : undefined}
        className={cn(
          'grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-2.5 p-3 text-start hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring',
          active && 'bg-primary-tint hover:bg-primary-tint'
        )}
      >
        <Avatar name={name} size={36} />
        <span className="min-w-0">
          <span className="block truncate font-semibold text-foreground">{name}</span>
          {line && <span dir="auto" className="mt-0.5 block truncate text-start text-sm text-muted-foreground">{line}</span>}
          <span className="mt-1 flex flex-wrap items-center gap-1.5">
            <ChannelBadge channel={conversation.channel} />
            {conversation.assignee ? (
              <span title={conversation.assignee.name} className="inline-flex">
                <Avatar name={conversation.assignee.name} size={20} />
                <span className="sr-only">{conversation.assignee.name}</span>
              </span>
            ) : (
              <Badge variant="secondary">{t('messages.filter.unassigned', 'Unassigned')}</Badge>
            )}
          </span>
        </span>
        {when && <span className="shrink-0 text-xs text-muted-foreground">{when}</span>}
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
    <Badge variant="outline" icon={channel === 'email' ? 'mail' : 'comment'} className="shrink-0">
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
  const { user } = useAuth();
  const { addToast } = useToast();
  const { dateTime, time } = useDateDisplay();
  const [mode, setMode] = useState<'reply' | 'note'>('reply');
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);

  const detail = useFetch<ConversationDetail>(async () => {
    const { data, response } = await api.GET('/api/v1/messages/conversations/{id}', { params: { path: { id } } });
    if (!response.ok) throw new Error(t('messages.error.thread', 'This conversation could not be loaded.'));
    return (data as unknown as { data: ConversationDetail }).data;
  }, [id]);

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

  // Edit: the held reply stops and its words move into the composer, for a person to send.
  const edit = async (message: Message): Promise<void> => {
    if (!(await cancel(message, true))) return;
    setMode('reply');
    setDraft(message.body);
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

  const channelName =
    conversation.channel === 'email'
      ? t('messages.channel.email', 'Email')
      : conversation.channel === 'webchat'
        ? t('messages.channel.webchat', 'Web chat')
        : conversation.channel.split(':').pop() ?? conversation.channel;
  const firstName = name.split(' ')[0];
  const mine = myId !== null && conversation.assignee?.id === myId;

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <header className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3">
        <Button type="button" variant="ghost" size="icon" className="md:hidden" onClick={onBack} aria-label={t('messages.back', 'Back to conversations')} title={t('messages.back', 'Back to conversations')}>
          <Icon name="chevron-left" size={16} className="rtl:-scale-x-100" />
        </Button>
        <Avatar name={name} size={40} />
        <div className="min-w-0 flex-1">
          <h2 className="truncate text-base font-semibold text-foreground">{name}</h2>
          <p dir="auto" className="truncate text-start text-sm text-muted-foreground">
            {[channelName, address, conversation.subject].filter(Boolean).join(' · ')}
          </p>
        </div>
        {canManage ? (
          <div className="flex flex-wrap items-center gap-2 max-sm:w-full">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                  <Icon name="user" size={16} />
                  {conversation.assignee
                    ? t('messages.assignedTo', 'Assigned to {name}', { name: conversation.assignee.name })
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
                {conversation.status !== 'pending' && (
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
              <Button type="button" variant="outline" size="sm" onClick={() => void patch({ status: 'open' }, statusLabel('open'))}>
                <Icon name="undo" size={16} />
                {t('messages.action.reopen', 'Reopen')}
              </Button>
            ) : (
              <Button type="button" variant="outline" size="sm" onClick={() => void patch({ status: 'closed' }, statusLabel('closed'))}>
                <Icon name="check" size={16} />
                {t('messages.action.close', 'Close conversation')}
              </Button>
            )}
          </div>
        ) : (
          <Badge variant={conversation.status === 'open' ? 'info' : conversation.status === 'pending' ? 'warning' : 'outline'}>
            {statusLabel(conversation.status)}
          </Badge>
        )}
      </header>

      <ol className="min-h-0 flex-1 space-y-3.5 overflow-y-auto bg-background p-4" aria-live="polite">
        {conversation.messages.map((message) => (
          <MessageBubble
            key={message.id}
            message={message}
            customerName={firstName}
            time={time}
            dateTime={dateTime}
            onCancel={canReply ? () => void cancel(message) : undefined}
            onEdit={canReply ? () => void edit(message) : undefined}
            onRetry={canReply ? () => void retry(message) : undefined}
            onCopy={() => copy(message)}
          />
        ))}
        <div ref={bottom} />
      </ol>

      {canReply ? (
        <form
          className="space-y-2.5 border-t border-border bg-card px-4 py-3"
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
              { value: 'reply', label: t('messages.mode.reply', 'Reply') },
              { value: 'note', label: t('messages.mode.note', 'Internal note') },
            ]}
            label={t('messages.mode.label', 'What you are writing')}
          />
          <Textarea
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            rows={2}
            aria-label={mode === 'reply' ? t('messages.composer.reply', 'Your reply') : t('messages.composer.note', 'Your note')}
            placeholder={
              mode === 'reply'
                ? t('messages.composer.replyTo', 'Write to {name}…', { name: firstName })
                : t('messages.composer.noteHint', 'Write a note. Only your team sees it.')
            }
            className={cn(mode === 'note' && 'border-warning bg-warning-tint')}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                void send();
              }
            }}
          />
          <div className="flex items-center gap-2.5">
            <span className="text-sm text-muted-foreground">
              {mode === 'reply'
                ? t('messages.composer.via', 'Sends by {channel}', { channel: channelName.toLowerCase() })
                : t('messages.composer.noteOnly', 'Only your team sees this')}
            </span>
            <span className="flex-1" />
            <Button type="submit" disabled={sending || draft.trim() === ''}>
              {mode === 'reply' && <Icon name="send" size={16} className="rtl:-scale-x-100" />}
              {mode === 'reply' ? t('messages.send', 'Send') : t('messages.addNote', 'Add note')}
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
    </div>
  );
}

function MessageBubble({
  message,
  customerName,
  time,
  dateTime,
  onCancel,
  onEdit,
  onRetry,
  onCopy,
}: {
  message: Message;
  customerName: string;
  time: (value: string | null | undefined) => string | null;
  dateTime: (value: string | null | undefined) => string | null;
  onCancel?: () => void;
  onEdit?: () => void;
  onRetry?: () => void;
  onCopy: () => void;
}) {
  const t = useTranslation('admin');
  const inbound = message.kind === 'inbound';
  const note = message.kind === 'note';
  const pending = isPending(message);
  const failed = message.status === 'failed';
  const who = inbound
    ? customerName
    : message.by_agent
      ? t('messages.byAgent', 'Agent for {name}', { name: message.author?.name ?? '' })
      : message.author?.name ?? t('messages.workspace', 'Your team');
  const at = useListTime(true)(message.created_at);

  const meta = (): React.ReactNode => {
    if (note) {
      return (
        <>
          <Icon name="lock" size={12} />
          {[t('messages.noteLabel', 'Internal note'), who, at, t('messages.noteHidden', 'customers never see notes')].filter(Boolean).join(' · ')}
        </>
      );
    }
    if (pending) {
      const state = message.send_after
        ? t('messages.state.held', 'Not sent yet. Sends at {time}', { time: time(message.send_after) ?? dateTime(message.send_after) ?? '' })
        : t('messages.state.queued', 'Sending…');
      return (
        <>
          {message.by_agent && <Icon name="robot" size={14} />}
          {[who, state].join(' · ')}
        </>
      );
    }
    if (failed) {
      return (
        <>
          <Icon name="alert" size={14} />
          {message.status_detail
            ? t('messages.state.failedWhy', 'Not sent: {reason}', { reason: message.status_detail })
            : t('messages.state.failed', 'Not sent')}
        </>
      );
    }
    const state =
      message.status === 'cancelled'
        ? t('messages.state.cancelled', 'Cancelled, not sent')
        : message.status === 'delivered'
          ? t('messages.state.delivered', 'Delivered')
          : message.status === 'read'
            ? t('messages.state.read', 'Read')
            : null;
    return [at, who, state].filter(Boolean).join(' · ');
  };

  return (
    <li className={cn('grid gap-1', note ? 'w-full' : 'max-w-[76%]', !inbound && !note && 'ms-auto justify-items-end')}>
      <div
        className={cn(
          'rounded-[14px] border px-3.5 py-2.5',
          inbound && 'border-border bg-card',
          !inbound && !note && 'border-transparent bg-primary-tint',
          note && 'border-warning bg-warning-tint',
          pending && 'border-[1.5px] border-dashed border-input bg-card',
          failed && 'border-[1.5px] border-destructive',
          message.status === 'cancelled' && 'opacity-60'
        )}
      >
        <p dir="auto" className="whitespace-pre-wrap break-words text-start text-foreground">{message.body}</p>
        {message.attachments_not_shown > 0 && (
          <p className="mt-1 text-xs text-muted-foreground">
            {t('messages.attachments', '{n} attachment(s) not shown', { n: message.attachments_not_shown })}
          </p>
        )}
      </div>
      <p className={cn('flex flex-wrap items-center gap-1.5 text-xs', !inbound && !note && 'justify-end', failed ? 'text-error-text' : 'text-muted-foreground')}>
        {meta()}
        {pending && message.send_after && onCancel && (
          <>
            <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
              {t('messages.cancel', 'Cancel')}
            </Button>
            {onEdit && (
              <Button type="button" variant="outline" size="sm" onClick={onEdit}>
                <Icon name="edit" size={14} />
                {t('messages.edit', 'Edit')}
              </Button>
            )}
          </>
        )}
      </p>
      {failed && !inbound && !note && (
        <div className="flex flex-wrap gap-2">
          {onRetry && (
            <Button type="button" variant="outline" size="sm" onClick={onRetry}>
              <Icon name="sync" size={14} />
              {t('messages.retry', 'Try again')}
            </Button>
          )}
          <Button type="button" variant="ghost" size="sm" onClick={onCopy}>
            {t('messages.copy', 'Copy text')}
          </Button>
        </div>
      )}
    </li>
  );
}
