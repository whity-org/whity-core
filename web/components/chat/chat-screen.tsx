'use client';

/**
 * Team chat (CH.1, CH.2; cards TplChat and TplChatAddAgent): one chat for
 * people and agents. The list of chats, the open chat, and beside it who is in
 * it and what each agent may do there.
 *
 * Agents are always marked: the robot face, the Agent tag, who they work for
 * here, and a blue edge on their messages. An agent's held line or proposal is
 * a dashed card ("not happened yet") that only the deciding person can approve.
 *
 * The chat polls rather than holding a connection: the open chat every 5
 * seconds and the list every 15, and nothing while the tab is hidden.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { EmptyState } from '@whity/ui/empty-state';
import { Icon, type IconName } from '@whity/ui/icon';
import { IconButton } from '@whity/ui/icon-button';
import { Input } from '@whity/ui/input';
import { Skeleton } from '@whity/ui/skeleton';
import { Textarea } from '@whity/ui/textarea';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { AreaError } from '@whity/ui/page-states';
import { useLanguageDirection, useTranslation, type TranslateFn } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { cn } from '@/lib/utils';
import type { ChatAgentMember, ChatDetail, ChatMember, ChatMention, ChatMessage, ChatMode, ChatSummary } from '@/lib/chat-wire';
import { chatApi, chatErrorText, type ChatApi } from './chat-api';
import { AddMemberDialog } from './add-member-dialog';
import { NewChatDialog } from './new-chat-dialog';

const THREAD_MS = 5000;
const LIST_MS = 15000;

type Filter = 'all' | 'people' | 'agents';

/** Polls `fn` every `ms` while the tab is visible. */
function usePoll(fn: () => void, ms: number, active: boolean) {
  const saved = useRef(fn);
  useEffect(() => {
    saved.current = fn;
  }, [fn]);
  useEffect(() => {
    if (!active) return undefined;
    const id = window.setInterval(() => {
      if (document.visibilityState === 'visible') saved.current();
    }, ms);
    return () => window.clearInterval(id);
  }, [ms, active]);
}

export function ChatScreen() {
  const t = useTranslation('chat');
  const { apiClient, user } = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const selected = Number(params.get('c') ?? '') || null;
  const myId = Number((user as { profile_id?: number; id?: number } | null)?.profile_id ?? (user as { id?: number } | null)?.id ?? 0);
  const api = useMemo(() => chatApi(apiClient), [apiClient]);

  const [chats, setChats] = useState<ChatSummary[] | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
  const [query, setQuery] = useState('');
  const [newOpen, setNewOpen] = useState(false);

  const loadList = useCallback(async () => {
    try {
      setChats(await api.list());
      setListError(null);
    } catch (e) {
      setListError(chatErrorText(e, ''));
    }
  }, [api]);

  useEffect(() => {
    let live = true;
    api.list().then(
      (data) => live && setChats(data),
      (e: unknown) => live && setListError(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [api]);
  usePoll(loadList, LIST_MS, true);

  const open = useCallback(
    (id: number | null) => {
      router.replace(id === null ? '/chat' : `/chat?c=${id}`, {
        scroll: false,
      });
    },
    [router]
  );

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return (chats ?? []).filter((c) => {
      if (filter === 'people' && c.agents_count > 0) return false;
      if (filter === 'agents' && c.agents_count === 0) return false;
      return q === '' || c.title.toLowerCase().includes(q);
    });
  }, [chats, filter, query]);

  if (listError !== null && chats === null) {
    return <AreaError message={listError || t('error.generic', 'Something went wrong with Chat. Please try again.')} retryLabel={t('action.retry', 'Try again')} onRetry={() => void loadList()} />;
  }

  return (
    <div className="grid h-[calc(100dvh-8.5rem)] min-h-[32rem] grid-cols-1 overflow-hidden rounded-card border border-border bg-card md:grid-cols-[18.75rem_minmax(0,1fr)] xl:grid-cols-[18.75rem_minmax(0,1fr)_20rem]">
      <ChatList
        t={t}
        chats={chats}
        visible={visible}
        selected={selected}
        filter={filter}
        query={query}
        onFilter={setFilter}
        onQuery={setQuery}
        onOpen={open}
        onNew={() => setNewOpen(true)}
        hidden={selected !== null}
      />
      {selected !== null ? (
        <OpenChat key={selected} t={t} api={api} chatId={selected} myId={myId} onBack={() => open(null)} onChanged={() => void loadList()} />
      ) : (
        <div className="hidden place-items-center p-8 md:grid xl:col-span-2">
          <EmptyState
            icon={<Icon name="comment" size={28} />}
            title={t('empty.title', 'Choose a chat')}
            description={t('empty.description', 'Chats with people and agents appear on the left. Start one to talk to your team.')}
            action={
              <Button variant="outline" icon="plus" onClick={() => setNewOpen(true)}>
                {t('new.action', 'New chat')}
              </Button>
            }
          />
        </div>
      )}
      {newOpen && (
        <NewChatDialog
          open={newOpen}
          onOpenChange={setNewOpen}
          api={api}
          myId={myId}
          onStarted={(id) => {
            setNewOpen(false);
            void loadList();
            open(id);
          }}
        />
      )}
    </div>
  );
}

// ── The list ─────────────────────────────────────────────────────────────────

function ChatList({
  t,
  chats,
  visible,
  selected,
  filter,
  query,
  onFilter,
  onQuery,
  onOpen,
  onNew,
  hidden,
}: {
  t: TranslateFn;
  chats: ChatSummary[] | null;
  visible: ChatSummary[];
  selected: number | null;
  filter: Filter;
  query: string;
  onFilter: (f: Filter) => void;
  onQuery: (q: string) => void;
  onOpen: (id: number) => void;
  onNew: () => void;
  hidden: boolean;
}) {
  const groups = visible.filter((c) => c.type === 'group');
  const direct = visible.filter((c) => c.type === 'direct');

  return (
    <nav aria-label={t('list.label', 'Chats')} className={cn('flex min-h-0 flex-col border-e border-border', hidden && 'hidden md:flex')}>
      <header className="grid gap-2 border-b border-border p-3">
        <div className="flex items-center gap-2">
          <Input
            className="flex-1"
            value={query}
            onChange={(e) => onQuery(e.target.value)}
            placeholder={t('list.search', 'Search chats')}
            aria-label={t('list.search', 'Search chats')}
          />
          <IconButton icon="plus" variant="outline" label={t('new.action', 'New chat')} onClick={onNew} />
        </div>
        <ToggleGroup
          label={t('list.show', 'Show')}
          required
          value={filter}
          onChange={(v) => onFilter((v as Filter) ?? 'all')}
          options={[
            { value: 'all', label: t('list.all', 'All') },
            { value: 'people', label: t('list.people', 'People') },
            { value: 'agents', label: t('list.agents', 'Agents') },
          ]}
        />
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto pb-3">
        {chats === null ? (
          <div className="grid gap-2 p-3" aria-busy="true">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : visible.length === 0 ? (
          <p className="p-4 text-sm text-muted-foreground">
            {chats.length === 0 ? t('list.none', 'No chats yet. Start one with New chat.') : t('list.noMatch', 'No chats match.')}
          </p>
        ) : (
          <>
            {groups.length > 0 && <ListSection title={t('list.groups', 'Groups')} chats={groups} selected={selected} onOpen={onOpen} t={t} />}
            {direct.length > 0 && <ListSection title={t('list.direct', 'Direct')} chats={direct} selected={selected} onOpen={onOpen} t={t} />}
          </>
        )}
      </div>
    </nav>
  );
}

function ListSection({
  title,
  chats,
  selected,
  onOpen,
  t,
}: {
  title: string;
  chats: ChatSummary[];
  selected: number | null;
  onOpen: (id: number) => void;
  t: TranslateFn;
}) {
  return (
    <section>
      <h2 className="px-3.5 pt-3 pb-1 text-xs font-semibold text-muted-foreground">{title}</h2>
      <ul>
        {chats.map((c) => (
          <li key={c.id}>
            <button
              type="button"
              onClick={() => onOpen(c.id)}
              aria-current={c.id === selected ? 'true' : undefined}
              className={cn(
                'mx-1.5 grid w-[calc(100%-0.75rem)] grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-2.5 rounded-control px-3 py-2 text-start text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring',
                c.id === selected && 'bg-primary-tint hover:bg-primary-tint'
              )}
            >
              <ChatIcon chat={c} size={36} />
              <span className="min-w-0">
                <span className="block truncate font-medium">{c.title}</span>
                <span dir="auto" className="block truncate text-start text-xs text-muted-foreground">
                  {preview(c, t)}
                </span>
              </span>
              {c.unread > 0 ? (
                <span
                  className="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-xs text-primary-foreground"
                  aria-label={t('list.unread', '{count} unread', {
                    count: String(c.unread),
                  })}
                >
                  {c.unread}
                </span>
              ) : (
                <span />
              )}
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}

function preview(c: ChatSummary, t: TranslateFn): string {
  const m = c.last_message;
  if (!m) return '';
  if (c.type === 'direct' && c.with?.type === 'agent') return t('list.agentPreview', 'Agent · {text}', { text: m.body });
  if (m.kind === 'line') return `${m.author?.name ?? ''} ${m.body}`.trim();
  if (m.kind === 'system') return systemText(m, t);
  if (!m.author || c.type === 'direct') return m.body;
  return `${m.author.name.split(' ')[0]}: ${m.body}`;
}

function linkIcon(type: string | undefined): IconName {
  return type === 'board' ? 'kanban' : type === 'meeting' ? 'meeting' : type === 'record' ? 'document' : 'users';
}

function ChatIcon({ chat, size }: { chat: Pick<ChatSummary, 'type' | 'with' | 'link' | 'title'>; size: number }) {
  if (chat.type === 'direct' && chat.with) {
    return <Avatar name={chat.with.name} kind={chat.with.type === 'agent' ? 'agent' : 'person'} size={size} />;
  }
  return (
    <span aria-hidden="true" className="grid place-items-center rounded-control bg-muted text-foreground" style={{ width: size, height: size }}>
      <Icon name={linkIcon(chat.link?.type)} size={18} />
    </span>
  );
}

// ── The open chat ────────────────────────────────────────────────────────────

function OpenChat({
  t,
  api,
  chatId,
  myId,
  onBack,
  onChanged,
}: {
  t: TranslateFn;
  api: ChatApi;
  chatId: number;
  myId: number;
  onBack: () => void;
  onChanged: () => void;
}) {
  const [chat, setChat] = useState<ChatDetail | null>(null);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [adding, setAdding] = useState<'person' | 'agent' | null>(null);
  const [focusAgent, setFocusAgent] = useState<number | null>(null);
  const lastId = useRef(0);
  const readMark = useRef(0);

  const merge = useCallback((incoming: ChatMessage[]) => {
    if (incoming.length === 0) return;
    setMessages((prev) => {
      const byId = new Map(prev.map((m) => [m.id, m]));
      for (const m of incoming) byId.set(m.id, m);
      return [...byId.values()].sort((a, b) => a.id - b.id);
    });
    lastId.current = Math.max(lastId.current, ...incoming.map((m) => m.id));
  }, []);

  const load = useCallback(async () => {
    try {
      const detail = await api.show(chatId);
      setChat(detail);
      setMessages(detail.messages);
      lastId.current = detail.messages.reduce((max, m) => Math.max(max, m.id), 0);
      setError(null);
    } catch (e) {
      setError(chatErrorText(e, ''));
    }
  }, [api, chatId]);

  useEffect(() => {
    let live = true;
    api.show(chatId).then(
      (detail) => {
        if (!live) return;
        setChat(detail);
        setMessages(detail.messages);
        lastId.current = detail.messages.reduce((max, m) => Math.max(max, m.id), 0);
      },
      (e: unknown) => live && setError(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [api, chatId]);

  const poll = useCallback(async () => {
    try {
      merge(await api.after(chatId, lastId.current));
    } catch {
      // A missed poll is retried by the next one.
    }
  }, [api, chatId, merge]);
  usePoll(poll, THREAD_MS, chat !== null);

  // Mark what is on screen as read.
  useEffect(() => {
    if (lastId.current > readMark.current && chat) {
      readMark.current = lastId.current;
      void api.read(chatId, lastId.current).then(onChanged, () => undefined);
    }
  }, [messages, chat, api, chatId, onChanged]);

  const refreshMembers = useCallback(async () => {
    try {
      const detail = await api.show(chatId);
      setChat(detail);
      merge(detail.messages);
    } catch (e) {
      setNotice(chatErrorText(e, ''));
    }
  }, [api, chatId, merge]);

  if (error !== null) {
    return (
      <div className="grid place-items-center p-8 xl:col-span-2">
        <AreaError message={error || t('error.generic', 'Something went wrong with Chat. Please try again.')} retryLabel={t('action.retry', 'Try again')} onRetry={() => void load()} />
      </div>
    );
  }
  if (!chat) {
    return (
      <div className="grid gap-3 p-6 xl:col-span-2" aria-busy="true">
        <Skeleton className="h-10 w-2/3" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const agents = chat.members.filter((m): m is ChatAgentMember => m.type === 'agent');
  const people = chat.members.filter((m) => m.type === 'person');

  return (
    <>
      <section aria-label={chat.title} className="flex min-h-0 flex-col">
        <header className="flex items-center gap-2.5 border-b border-border px-4 py-3">
          <IconButton icon="chevron-left" label={t('action.back', 'Back to chats')} className="md:hidden rtl:rotate-180" onClick={onBack} />
          <ChatIcon chat={chat} size={36} />
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-base font-semibold">{chat.title}</h1>
            <p className="truncate text-xs text-muted-foreground">{subtitle(chat, t)}</p>
          </div>
        </header>
        <Thread t={t} api={api} chat={chat} messages={messages} myId={myId} onMessage={(m) => merge([m])} onNotice={setNotice} />
        {notice !== null && (
          <p role="alert" className="mx-4 mb-2 rounded-control bg-error-tint px-3 py-2 text-sm text-error-text">
            {notice || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          </p>
        )}
        <Composer t={t} api={api} chat={chat} onSent={(m) => merge([m])} onError={setNotice} />
      </section>
      <SidePanel
        t={t}
        chat={chat}
        people={people}
        agents={agents}
        myId={myId}
        focusAgent={focusAgent}
        onFocusAgent={setFocusAgent}
        onAdd={(kind) => setAdding(kind)}
        onRemove={async (memberId) => {
          try {
            await api.remove(chat.id, memberId);
            await refreshMembers();
            onChanged();
          } catch (e) {
            setNotice(chatErrorText(e, ''));
          }
        }}
      />
      {adding !== null && (
        <AddMemberDialog
          open={adding !== null}
          kind={adding ?? 'agent'}
          onOpenChange={(o) => setAdding(o ? adding : null)}
          onKind={setAdding}
          api={api}
          chat={chat}
          myId={myId}
          onAdded={(detail) => {
            setAdding(null);
            setChat(detail);
            merge(detail.messages);
            onChanged();
          }}
        />
      )}
    </>
  );
}

function subtitle(chat: ChatDetail, t: TranslateFn): string {
  const agents = chat.members.filter((m) => m.type === 'agent').length;
  const people = chat.members.length - agents;
  const who =
    chat.type === 'direct' && chat.with?.type === 'agent'
      ? t('head.agent', 'Agent · works for {name}', {
          name: chat.with.works_for.name,
        })
      : agents > 0
        ? t('head.mixed', '{people} people and {agents} agents', {
            people: String(people),
            agents: String(agents),
          })
        : t('head.people', '{people} people', { people: String(people) });
  if (!chat.link) return who;
  const kind = {
    board: t('link.board', 'board'),
    meeting: t('link.meeting', 'meeting'),
    record: t('link.record', 'record'),
  }[chat.link.type];
  return `${who} · ${t('head.linked', 'linked to the {label} {kind}', { label: chat.link.label, kind })}`;
}

// ── Messages ─────────────────────────────────────────────────────────────────

function Thread({
  t,
  api,
  chat,
  messages,
  myId,
  onMessage,
  onNotice,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  messages: ChatMessage[];
  myId: number;
  onMessage: (m: ChatMessage) => void;
  onNotice: (s: string | null) => void;
}) {
  const bottom = useRef<HTMLDivElement>(null);
  const count = messages.length;
  useEffect(() => {
    bottom.current?.scrollIntoView({ block: 'end' });
  }, [count]);

  return (
    <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3.5" aria-live="polite">
      <div className="flex min-h-full flex-col justify-end gap-3.5">
        {messages.length === 0 && <p className="self-center text-sm text-muted-foreground">{t('thread.empty', 'No messages yet. Say hello.')}</p>}
        {messages.map((m) =>
          m.kind === 'system' || m.kind === 'line' ? (
            <SystemLine key={m.id} t={t} message={m} />
          ) : (
            <MessageRow key={m.id} t={t} api={api} message={m} chat={chat} myId={myId} onMessage={onMessage} onNotice={onNotice} />
          )
        )}
        <div ref={bottom} />
      </div>
    </div>
  );
}

function SystemLine({ t, message }: { t: TranslateFn; message: ChatMessage }) {
  const text = message.kind === 'line' ? `${message.author?.name ?? ''} ${message.body}`.trim() : systemText(message, t);
  return (
    <div className="flex max-w-[90%] items-center gap-1.5 self-center rounded-full bg-muted px-3 py-1 text-center text-xs text-muted-foreground">
      <Icon name={message.kind === 'line' ? 'transfer' : message.event?.event?.startsWith('agent') ? 'robot' : 'users'} size={12} />
      <span>{text}</span>
    </div>
  );
}

/** System lines are written from their event, so they read in the viewer's language. */
function systemText(m: ChatMessage, t: TranslateFn): string {
  const e = m.event;
  if (!e) return m.body;
  const by = e.by?.name ?? '';
  switch (e.event) {
    case 'created':
      return t('sys.created', '{by} started {title}.', {
        by,
        title: e.title ?? '',
      });
    case 'renamed':
      return t('sys.renamed', '{by} named the chat {title}.', {
        by,
        title: e.title ?? '',
      });
    case 'linked':
      return t('sys.linked', '{by} linked the chat to {label}.', {
        by,
        label: e.link?.label ?? '',
      });
    case 'unlinked':
      return t('sys.unlinked', '{by} removed the link.', { by });
    case 'person_added':
      return t('sys.personAdded', '{by} added {person}.', {
        by,
        person: e.person?.name ?? '',
      });
    case 'person_removed':
      return t('sys.personRemoved', '{by} removed {person}.', {
        by,
        person: e.person?.name ?? '',
      });
    case 'left':
      return t('sys.left', '{by} left.', { by });
    case 'agent_removed':
      return t('sys.agentRemoved', '{by} removed {agent}.', {
        by,
        agent: e.agent?.name ?? '',
      });
    case 'agent_added': {
      const reads =
        e.reads === 'week'
          ? t('sys.readsWeek', 'from the last 7 days')
          : e.reads === 'all'
            ? t('sys.readsAll', 'everything in it')
            : t('sys.readsNow', 'from now on');
      const post = (e.actions?.post ?? 'allowed') !== 'off';
      return post
        ? t('sys.agentAdded', '{by} added {agent}. It works for {for} here, reads this chat {reads} and can post.', {
            by,
            agent: e.agent?.name ?? '',
            for: e.works_for?.name ?? '',
            reads,
          })
        : t('sys.agentAddedQuiet', '{by} added {agent}. It works for {for} here, reads this chat {reads} and cannot post.', {
            by,
            agent: e.agent?.name ?? '',
            for: e.works_for?.name ?? '',
            reads,
          });
    }
    default:
      return m.body;
  }
}

function MessageRow({
  t,
  api,
  message,
  chat,
  myId,
  onMessage,
  onNotice,
}: {
  t: TranslateFn;
  api: ChatApi;
  message: ChatMessage;
  chat: ChatDetail;
  myId: number;
  onMessage: (m: ChatMessage) => void;
  onNotice: (s: string | null) => void;
}) {
  const { time } = useDateDisplay();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const author = message.author;
  const isAgent = author?.type === 'agent';
  const held = message.status === 'held';

  const decide = async (approve: boolean) => {
    setBusy(true);
    try {
      onMessage(await api.decide(message.id, approve));
    } catch (e) {
      onNotice(chatErrorText(e, ''));
    } finally {
      setBusy(false);
    }
  };

  const approveProposal = async (proposalId: number) => {
    setBusy(true);
    try {
      await api.approveProposal(proposalId);
      const fresh = await api.after(chat.id, message.id - 1);
      const mine = fresh.find((m) => m.id === message.id);
      if (mine) onMessage(mine);
    } catch (e) {
      onNotice(chatErrorText(e, ''));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid grid-cols-[2rem_minmax(0,1fr)] gap-2.5 text-sm leading-normal">
      <Avatar name={author?.name ?? ''} kind={isAgent ? 'agent' : 'person'} size={32} />
      <div className="min-w-0">
        <div className="mb-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <b className="text-sm font-semibold text-foreground">{author?.name}</b>
          {isAgent && <AgentTag t={t} />}
          {isAgent && author?.works_for && (
            <span>
              {author.works_for.id === myId
                ? t('msg.forYou', 'for you')
                : t('msg.for', 'for {name}', {
                    name: author.works_for.name.split(' ')[0],
                  })}
            </span>
          )}
          {message.created_at && <time dateTime={message.created_at}>{time(message.created_at)}</time>}
        </div>
        <div className={cn(isAgent && 'border-s-2 border-primary-tint ps-2.5', message.status === 'declined' && 'text-muted-foreground line-through')}>
          {held ? (
            <div className="grid max-w-[32.5rem] gap-1.5 rounded-card border-[1.5px] border-dashed border-input bg-background px-3.5 py-3">
              <Body text={message.body} mentions={message.mentions} />
              <footer className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <Badge variant="warning">
                  {t('msg.waiting', 'Waiting for {name}', {
                    name: message.waiting_for?.name.split(' ')[0] ?? '',
                  })}
                </Badge>
                {message.can_decide && (
                  <span className="ms-auto flex gap-1.5">
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => void decide(false)}>
                      {t('msg.decline', 'Decline')}
                    </Button>
                    <Button size="sm" icon="check" loading={busy} onClick={() => void decide(true)}>
                      {t('msg.let', 'Let it through')}
                    </Button>
                  </span>
                )}
              </footer>
            </div>
          ) : (
            <Body text={message.body} mentions={message.mentions} />
          )}
          {message.kind === 'proposal' && message.proposal && (
            <div className="mt-2 grid max-w-[32.5rem] gap-1.5 rounded-card border-[1.5px] border-dashed border-input bg-background px-3.5 py-3">
              <b className="text-sm font-semibold">{message.proposal.summary}</b>
              <footer className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                {message.proposal.status === 'waiting' ? (
                  <Badge variant="warning">
                    {t('msg.waiting', 'Waiting for {name}', {
                      name: message.proposal.waiting_for.name.split(' ')[0],
                    })}
                  </Badge>
                ) : (
                  <Badge variant={message.proposal.status === 'approved' ? 'success' : 'outline'}>{proposalStatus(message.proposal.status, t)}</Badge>
                )}
                <span className="ms-auto flex gap-1.5">
                  <Button variant="outline" size="sm" onClick={() => router.push('/admin/approvals')}>
                    {t('msg.view', 'View')}
                  </Button>
                  {message.proposal.can_decide && message.proposal.status === 'waiting' && (
                    <Button size="sm" icon="check" loading={busy} onClick={() => void approveProposal(message.proposal!.id)}>
                      {t('msg.approve', 'Approve')}
                    </Button>
                  )}
                </span>
              </footer>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function proposalStatus(status: string, t: TranslateFn): string {
  switch (status) {
    case 'approved':
      return t('proposal.approved', 'Approved');
    case 'declined':
      return t('proposal.declined', 'Declined');
    case 'expired':
      return t('proposal.expired', 'Expired');
    case 'undone':
      return t('proposal.undone', 'Undone');
    default:
      return status;
  }
}

/** The text, with the people and agents it mentions in the main colour. */
function Body({ text, mentions }: { text: string; mentions: ChatMention[] }) {
  const parts = useMemo(() => {
    if (mentions.length === 0) return [text];
    const names = mentions.map((m) => `@${m.name}`).sort((a, b) => b.length - a.length);
    const escaped = names.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return text.split(new RegExp(`(${escaped.join('|')})`, 'g'));
  }, [text, mentions]);
  const names = new Set(mentions.map((m) => `@${m.name}`));
  return (
    <p dir="auto" className="text-base whitespace-pre-wrap break-words">
      {parts.map((p, i) =>
        names.has(p) ? (
          <span key={i} className="font-medium text-primary">
            {p}
          </span>
        ) : (
          <span key={i}>{p}</span>
        )
      )}
    </p>
  );
}

function AgentTag({ t }: { t: TranslateFn }) {
  return (
    <span className="inline-flex h-[1.125rem] items-center gap-0.5 rounded-control-sm bg-primary-tint px-1.5 text-xs font-semibold text-primary-tint-foreground">
      <Icon name="robot" size={10} />
      {t('agent.tag', 'Agent')}
    </span>
  );
}

// ── Writing ──────────────────────────────────────────────────────────────────

function Composer({
  t,
  api,
  chat,
  onSent,
  onError,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  onSent: (m: ChatMessage) => void;
  onError: (s: string | null) => void;
}) {
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [picked, setPicked] = useState<ChatMention[]>([]);
  const [mentionQuery, setMentionQuery] = useState<string | null>(null);
  const box = useRef<HTMLTextAreaElement>(null);

  const candidates = useMemo(() => {
    if (mentionQuery === null) return [];
    const q = mentionQuery.toLowerCase();
    return chat.members
      .map((m): ChatMention => (m.type === 'agent' ? { type: 'agent', id: m.agent_id, name: m.name } : { type: 'person', id: m.profile_id, name: m.name }))
      .filter((m) => m.name.toLowerCase().includes(q))
      .slice(0, 8);
  }, [chat.members, mentionQuery]);

  const onChange = (value: string) => {
    setDraft(value);
    const caret = box.current?.selectionStart ?? value.length;
    const before = value.slice(0, caret);
    const at = /(^|\s)@([^\s@]{0,30})$/.exec(before);
    setMentionQuery(at ? at[2] : null);
  };

  const pick = (m: ChatMention) => {
    const caret = box.current?.selectionStart ?? draft.length;
    const before = draft.slice(0, caret).replace(/@([^\s@]{0,30})$/, `@${m.name} `);
    const next = before + draft.slice(caret);
    setDraft(next);
    setPicked((prev) => (prev.some((p) => p.type === m.type && p.id === m.id) ? prev : [...prev, m]));
    setMentionQuery(null);
    box.current?.focus();
  };

  const send = async () => {
    const text = draft.trim();
    if (text === '' || sending) return;
    setSending(true);
    onError(null);
    try {
      const mentions = picked.filter((m) => text.includes(`@${m.name}`)).map(({ type, id }) => ({ type, id }));
      onSent(await api.post(chat.id, text, mentions));
      setDraft('');
      setPicked([]);
    } catch (e) {
      onError(chatErrorText(e, ''));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="relative mx-4 mb-4 grid gap-2 rounded-card border border-input px-3 py-2.5 focus-within:outline-2 focus-within:outline-ring">
      {candidates.length > 0 && (
        <ul
          role="listbox"
          aria-label={t('compose.mentionList', 'People and agents in this chat')}
          className="absolute bottom-full start-0 mb-1 w-72 overflow-hidden rounded-card border border-border bg-popover py-1 shadow-md"
        >
          {candidates.map((c) => (
            <li key={`${c.type}${c.id}`}>
              <button
                type="button"
                role="option"
                aria-selected="false"
                className="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-muted"
                onClick={() => pick(c)}
              >
                <Avatar name={c.name} kind={c.type === 'agent' ? 'agent' : 'person'} size={24} />
                <span className="flex-1 truncate">{c.name}</span>
                {c.type === 'agent' && <AgentTag t={t} />}
              </button>
            </li>
          ))}
        </ul>
      )}
      <Textarea
        ref={box}
        value={draft}
        rows={1}
        maxLength={4000}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey && candidates.length === 0) {
            e.preventDefault();
            void send();
          }
          if (e.key === 'Escape') setMentionQuery(null);
        }}
        aria-label={t('compose.label', 'Message')}
        placeholder={t('compose.placeholder', 'Message {name} · type @ for a person or an agent', { name: chat.title })}
        className="min-h-10 resize-none border-0 p-0 shadow-none focus-visible:outline-none"
      />
      <div className="flex items-center gap-1">
        <IconButton
          icon="at"
          label={t('compose.mention', 'Mention a person or an agent')}
          onClick={() => {
            onChange(`${draft}${draft === '' || draft.endsWith(' ') ? '' : ' '}@`);
            box.current?.focus();
          }}
        />
        <Button size="sm" icon="send" className="ms-auto rtl:[&_svg]:-scale-x-100" loading={sending} disabled={draft.trim() === ''} onClick={() => void send()}>
          {t('compose.send', 'Send')}
        </Button>
      </div>
    </div>
  );
}

// ── Beside the chat ──────────────────────────────────────────────────────────

function SidePanel({
  t,
  chat,
  people,
  agents,
  myId,
  focusAgent,
  onFocusAgent,
  onAdd,
  onRemove,
}: {
  t: TranslateFn;
  chat: ChatDetail;
  people: ChatMember[];
  agents: ChatAgentMember[];
  myId: number;
  focusAgent: number | null;
  onFocusAgent: (id: number | null) => void;
  onAdd: (kind: 'person' | 'agent') => void;
  onRemove: (memberId: number) => Promise<void>;
}) {
  const dir = useLanguageDirection();
  const shown = agents.find((a) => a.member_id === focusAgent) ?? agents[0] ?? null;
  const agentName = (a: ChatAgentMember) => (dir === 'rtl' && a.name_ar ? a.name_ar : a.name);

  return (
    <aside
      aria-label={t('side.label', 'People and agents in this chat')}
      className="hidden min-h-0 content-start gap-4 overflow-y-auto border-s border-border p-4 xl:grid"
    >
      <h2 className="text-xs font-semibold text-muted-foreground">{t('side.people', 'People · {count}', { count: String(people.length) })}</h2>
      <ul className="grid gap-3">
        {people.map((p) =>
          p.type === 'person' ? (
            <Member
              key={p.member_id}
              name={p.name}
              sub={
                p.profile_id === myId
                  ? t('side.you', 'You')
                  : p.added_by
                    ? t('side.addedBy', 'Added by {name}', {
                        name: p.added_by.name,
                      })
                    : t('side.started', 'Started the chat')
              }
            />
          ) : null
        )}
      </ul>
      {agents.length > 0 && (
        <>
          <h2 className="text-xs font-semibold text-muted-foreground">
            {t('side.agents', 'Agents · {count}', {
              count: String(agents.length),
            })}
          </h2>
          <ul className="grid gap-3">
            {agents.map((a) => (
              <li key={a.member_id}>
                <button
                  type="button"
                  className={cn(
                    'w-full rounded-control text-start',
                    shown?.member_id === a.member_id && agents.length > 1 && 'outline-2 outline-offset-4 outline-primary-tint'
                  )}
                  onClick={() => onFocusAgent(a.member_id)}
                  aria-pressed={shown?.member_id === a.member_id}
                >
                  <Member
                    name={agentName(a)}
                    agent
                    tag={<AgentTag t={t} />}
                    sub={
                      a.works_for.id === myId
                        ? t('side.worksForYou', 'Works for you in this chat')
                        : t('side.worksFor', 'Works for {name} in this chat', {
                            name: a.works_for.name.split(' ')[0],
                          })
                    }
                  />
                </button>
              </li>
            ))}
          </ul>
        </>
      )}
      {shown && <MayDo t={t} agent={shown} name={agentName(shown)} onRemove={() => void onRemove(shown.member_id)} />}
      <div className="grid gap-2">
        <Button variant="outline" size="sm" icon="plus" onClick={() => onAdd('agent')}>
          {t('side.add', 'Add a person or an agent')}
        </Button>
        {chat.type === 'group' && people.length > 1 && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              const mine = people.find((p) => p.type === 'person' && p.profile_id === myId);
              if (mine) void onRemove(mine.member_id);
            }}
          >
            {t('side.leave', 'Leave this chat')}
          </Button>
        )}
      </div>
    </aside>
  );
}

function Member({ name, sub, agent, tag }: { name: string; sub: string; agent?: boolean; tag?: ReactNode }) {
  return (
    <span className="grid grid-cols-[2rem_minmax(0,1fr)] items-center gap-2.5 text-sm">
      <Avatar name={name} kind={agent ? 'agent' : 'person'} size={32} />
      <span className="min-w-0">
        <span className="flex items-center gap-1.5">
          <b className="truncate font-medium">{name}</b>
          {tag}
        </span>
        <small className="block truncate text-xs text-muted-foreground">{sub}</small>
      </span>
    </span>
  );
}

function MayDo({ t, agent, name, onRemove }: { t: TranslateFn; agent: ChatAgentMember; name: string; onRemove: () => void }) {
  const { date } = useDateDisplay();
  const mode = (m: ChatMode | undefined) => (m === 'allowed' ? t('may.yes', 'Yes') : m === 'ask' ? t('may.ask', 'Asks first') : t('may.no', 'No'));
  const others = Object.entries(agent.actions).filter(([k]) => k !== 'post');
  return (
    <div className="grid gap-1 rounded-card bg-muted px-3 py-2.5 text-xs">
      <b className="mb-0.5 text-sm font-semibold">{t('may.title', 'What {name} may do here', { name })}</b>
      <Row
        label={t('may.read', 'Read this chat')}
        value={
          agent.reads_from
            ? t('may.since', 'Since {date}', {
                date: date(agent.reads_from) ?? '',
              })
            : t('may.everything', 'Everything')
        }
      />
      <Row label={t('may.post', 'Post and reply')} value={mode(agent.actions.post ?? 'allowed')} />
      {others.map(([k, v]) => (
        <Row key={k} label={actionLabel(k)} value={mode(v)} />
      ))}
      <Row label={t('may.other', 'See other chats')} value={t('may.no', 'No')} />
      <Button variant="ghost" size="sm" className="mt-1 justify-self-start px-0 text-destructive" onClick={onRemove}>
        {t('may.remove', 'Remove {name}', { name })}
      </Button>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-2">
      <span>{label}</span>
      <span className="text-muted-foreground">{value}</span>
    </div>
  );
}

/** An action key as words, for actions the server names itself ("order_stock" → "Order stock"). */
function actionLabel(key: string): string {
  const words = key.replace(/[_.:-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}
