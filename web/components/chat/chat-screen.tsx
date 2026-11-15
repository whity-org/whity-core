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

import { apiFetch } from '@/lib/api/client';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { EmptyState } from '@whity/ui/empty-state';
import { Icon, type IconName } from '@whity/ui/icon';
import { IconButton } from '@whity/ui/icon-button';
import { Skeleton } from '@whity/ui/skeleton';
import { Textarea } from '@whity/ui/textarea';
import { SearchField } from '@whity/ui/search-field';
import { AreaError } from '@whity/ui/page-states';
import { useLanguageDirection, useTranslation, type TranslateFn } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { cn } from '@/lib/utils';
import type { ChatAgentMember, ChatDetail, ChatInboxItem, ChatMember, ChatMention, ChatMessage, ChatMode, ChatSummary } from '@/lib/chat-wire';
import { useCapabilities } from '@/hooks/useCapabilities';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import { ChatSidePane, CustomerAvatar, CustomerChat, channelName, useListTime } from './customer-chat';
import { chatApi, chatErrorText, type ChatApi } from './chat-api';
import { AddMemberDialog } from './add-member-dialog';
import { NewChatDialog } from './new-chat-dialog';
import { useNewIntent } from '@/lib/commands/new-intent';

const THREAD_MS = 5000;
const LIST_MS = 15000;

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

/** Seconds between "I'm looking at this chat" beats; the server's ChatPushNotifier::HEARTBEAT. */
export const FOCUS_BEAT_MS = 20_000;

/**
 * While the open chat is on screen in a focused window, tell the server every
 * beat, so its new lines aren't pushed to this person's devices (WHIT-1077).
 * Losing focus, hiding the tab or leaving the chat says so at once.
 */
export function useChatFocus(chatId: number | null, send: (id: number, focused: boolean) => Promise<unknown>) {
  useEffect(() => {
    if (chatId === null) return;
    const looking = () => document.visibilityState === 'visible' && document.hasFocus();
    let on = false;
    const beat = () => {
      const now = looking();
      if (now || on) void send(chatId, now).catch(() => undefined);
      on = now;
    };
    beat();
    const timer = window.setInterval(beat, FOCUS_BEAT_MS);
    window.addEventListener('focus', beat);
    window.addEventListener('blur', beat);
    document.addEventListener('visibilitychange', beat);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', beat);
      window.removeEventListener('blur', beat);
      document.removeEventListener('visibilitychange', beat);
      if (on) void send(chatId, false).catch(() => undefined);
    };
  }, [chatId, send]);
}

/** Whether the right pane starts open on a wide screen; each person's own choice, remembered here. */
const DETAILS_KEY = 'whity.chat.details';

function readDetailsPref(): boolean {
  try {
    if (typeof window === 'undefined' || !window.matchMedia('(min-width: 1280px)').matches) return false;
    return window.localStorage.getItem(DETAILS_KEY) !== 'closed';
  } catch {
    return false;
  }
}

function saveDetailsPref(open: boolean) {
  try {
    window.localStorage.setItem(DETAILS_KEY, open ? 'open' : 'closed');
  } catch {
    // A private window keeps no preference; the pane still opens and closes.
  }
}

export function ChatScreen() {
  const t = useTranslation('chat');
  const { user } = useAuth();
  const { has } = useCapabilities();
  const router = useRouter();
  const params = useSearchParams();
  const selected = Number(params.get('c') ?? '') || null;
  // A customer conversation opened by address that isn't in the list (closed long ago).
  const kindHint = params.get('k') === 'customer' ? 'customer' : null;
  const myId = Number((user as { profile_id?: number; id?: number } | null)?.profile_id ?? (user as { id?: number } | null)?.id ?? 0);
  const api = useMemo(() => chatApi(apiFetch), []);

  const [items, setItems] = useState<ChatInboxItem[] | null>(null);
  const [customersOn, setCustomersOn] = useState(false);
  const [listError, setListError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>('all');
  const [query, setQuery] = useState('');
  const [newOpen, setNewOpen] = useState(false);
  // The chat screen renders only in the browser (useSearchParams under Suspense), so the preference is read at once.
  const [detailsOpen, setDetailsOpen] = useState(readDetailsPref);
  const toggleDetails = useCallback(() => {
    setDetailsOpen((was) => {
      saveDetailsPref(!was);
      return !was;
    });
  }, []);
  // ⌘K `/new message` (WHIT-1069) opens New chat.
  useNewIntent('message', () => setNewOpen(true));
  // ⌘K `/ask` (WHIT-1070): the direct chat with that agent, the words in the
  // message box and not sent. The agent answers here, proposing; you approve.
  const [askDraft, setAskDraft] = useState<{ chatId: number; text: string } | null>(null);

  const loadList = useCallback(async () => {
    try {
      const inbox = await api.inbox();
      setItems(inbox.data);
      setCustomersOn(inbox.customers);
      setListError(null);
    } catch (e) {
      setListError(chatErrorText(e, ''));
    }
  }, [api]);

  useEffect(() => {
    let live = true;
    api.inbox().then(
      (inbox) => {
        if (!live) return;
        setItems(inbox.data);
        setCustomersOn(inbox.customers);
      },
      (e: unknown) => live && setListError(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [api]);
  usePoll(loadList, LIST_MS, true);

  const current = items?.find((i) => i.id === selected) ?? null;
  const currentKind: 'team' | 'customer' | null =
    selected === null ? null : current ? current.kind : items === null ? null : (kindHint ?? 'team');
  useChatFocus(currentKind === 'team' ? selected : null, api.focus);

  // The unread count when a chat opened, for its "n new" line; it stays put while the chat is open.
  const [unreadAtOpen, setUnreadAtOpen] = useState<{ id: number; n: number } | null>(null);
  if (selected !== null && items !== null && unreadAtOpen?.id !== selected) {
    setUnreadAtOpen({ id: selected, n: items.find((i) => i.id === selected)?.unread ?? 0 });
  }

  const open = useCallback(
    (id: number | null) => {
      router.replace(id === null ? '/chat' : `/chat?c=${id}`, {
        scroll: false,
      });
    },
    [router]
  );

  useNewIntent('ask', ({ text, target }) => {
    if (target === undefined) return;
    void (async () => {
      try {
        // Opens the direct chat, or starts it: one per person and agent, as New chat does.
        const started = await api.direct({ agent_id: target });
        setAskDraft({ chatId: started.id, text });
        void loadList();
        open(started.id);
      } catch (e) {
        setListError(chatErrorText(e, ''));
      }
    })();
  });

  const pin = useCallback(
    async (id: number, pinned: boolean) => {
      setItems((prev) => prev?.map((i) => (i.id === id ? { ...i, pinned } : i)) ?? prev);
      try {
        await api.pin(id, pinned);
      } catch (e) {
        setListError(chatErrorText(e, ''));
      }
      void loadList();
    },
    [api, loadList]
  );

  const readCustomer = useCallback(
    (id: number, messageId: number) => {
      void api.readCustomer(id, messageId).then(
        () => {
          setItems((prev) => prev?.map((i) => (i.id === id ? { ...i, unread: 0 } : i)) ?? prev);
          window.dispatchEvent(new Event(WAITING_CHANGED_EVENT));
        },
        () => undefined
      );
    },
    [api]
  );

  const counts = useMemo(() => {
    const all = items ?? [];
    return {
      unread: all.filter((i) => i.unread > 0).length,
      customers: all.filter((i) => i.kind === 'customer' && i.unread > 0).length,
    };
  }, [items]);

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return (items ?? []).filter((i) => {
      if (!inTab(i, tab)) return false;
      if (q === '') return true;
      return itemTitle(i, t).toLowerCase().includes(q) || itemPreview(i, t).toLowerCase().includes(q);
    });
  }, [items, tab, query, t]);

  if (listError !== null && items === null) {
    return (
      <AreaError
        message={listError || t('error.generic', 'Something went wrong with Chat. Please try again.')}
        retryLabel={t('action.retry', 'Try again')}
        onRetry={() => void loadList()}
      />
    );
  }

  return (
    <div className="relative flex h-[calc(100dvh-8.5rem)] min-h-[32rem] overflow-hidden rounded-card border border-border bg-card">
      <ChatList
        t={t}
        items={items}
        visible={visible}
        selected={selected}
        tab={tab}
        counts={counts}
        customersOn={customersOn}
        query={query}
        onTab={setTab}
        onQuery={setQuery}
        onOpen={open}
        onNew={() => setNewOpen(true)}
        hidden={selected !== null}
      />
      <div className={cn('relative min-w-0 flex-1', selected === null ? 'hidden md:flex' : 'flex')}>
        {selected !== null && currentKind === 'customer' ? (
          <CustomerChat
            key={selected}
            id={selected}
            canReply={has('messages:reply')}
            canManage={has('messages:manage')}
            unreadAtOpen={unreadAtOpen?.id === selected ? unreadAtOpen.n : 0}
            detailsOpen={detailsOpen}
            pinned={current?.pinned ?? false}
            onPin={(pinned) => void pin(selected, pinned)}
            onToggleDetails={toggleDetails}
            onBack={() => open(null)}
            onChanged={() => void loadList()}
            onRead={(messageId) => readCustomer(selected, messageId)}
          />
        ) : selected !== null && currentKind === 'team' ? (
          <OpenChat
            key={selected}
            t={t}
            api={api}
            chatId={selected}
            myId={myId}
            pinned={current?.pinned ?? false}
            onPin={(pinned) => void pin(selected, pinned)}
            detailsOpen={detailsOpen}
            onToggleDetails={toggleDetails}
            initialDraft={askDraft?.chatId === selected ? askDraft.text : undefined}
            onBack={() => open(null)}
            onChanged={() => void loadList()}
          />
        ) : selected !== null ? (
          <div className="grid flex-1 content-start gap-3 p-6" aria-busy="true">
            <Skeleton className="h-10 w-2/3" />
            <Skeleton className="h-64 w-full" />
          </div>
        ) : (
          <div className="grid flex-1 place-items-center p-8">
            <EmptyState
              icon={<Icon name="comment" size={28} />}
              title={t('empty.title', 'Choose a chat')}
              description={
                customersOn
                  ? t('empty.descriptionAll', 'Your team, your customers and your agents are on the left. Start a chat to talk to someone.')
                  : t('empty.description', 'Chats with people and agents appear on the left. Start one to talk to your team.')
              }
              action={
                <Button variant="outline" icon="plus" onClick={() => setNewOpen(true)}>
                  {t('new.action', 'New chat')}
                </Button>
              }
            />
          </div>
        )}
      </div>
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

/** The list's tabs (UC.1). Customers shows only to people who may read customer conversations. */
type Tab = 'all' | 'unread' | 'customers' | 'team' | 'groups' | 'agents';

function isAgentChat(i: ChatInboxItem): boolean {
  return i.kind === 'team' && i.type === 'direct' && i.with?.type === 'agent';
}

function inTab(i: ChatInboxItem, tab: Tab): boolean {
  switch (tab) {
    case 'unread':
      return i.unread > 0;
    case 'customers':
      return i.kind === 'customer';
    case 'team':
      return i.kind === 'team' && !isAgentChat(i);
    case 'groups':
      return i.kind === 'team' && i.type === 'group';
    case 'agents':
      return i.kind === 'team' && (isAgentChat(i) || i.agents_count > 0);
    default:
      return true;
  }
}

function itemTitle(i: ChatInboxItem, t: TranslateFn): string {
  if (i.kind === 'team') return i.title;
  return i.title || t('customer.visitor', 'Website visitor');
}

function itemPreview(i: ChatInboxItem, t: TranslateFn): string {
  if (i.kind === 'team') return preview(i, t);
  const m = i.last_message;
  if (!m) return i.subject ?? '';
  if (m.from === 'customer') return m.body;
  return t('list.teamWrote', '{name}: {text}', { name: m.author?.name.split(' ')[0] ?? t('list.team', 'Team'), text: m.body });
}

function ChatList({
  t,
  items,
  visible,
  selected,
  tab,
  counts,
  customersOn,
  query,
  onTab,
  onQuery,
  onOpen,
  onNew,
  hidden,
}: {
  t: TranslateFn;
  items: ChatInboxItem[] | null;
  visible: ChatInboxItem[];
  selected: number | null;
  tab: Tab;
  counts: { unread: number; customers: number };
  customersOn: boolean;
  query: string;
  onTab: (tab: Tab) => void;
  onQuery: (q: string) => void;
  onOpen: (id: number) => void;
  onNew: () => void;
  hidden: boolean;
}) {
  const pinned = visible.filter((i) => i.pinned);
  const rest = visible.filter((i) => !i.pinned);
  const tabs: { value: Tab; label: string; count?: number }[] = [
    { value: 'all', label: t('tab.all', 'All') },
    { value: 'unread', label: t('tab.unread', 'Unread'), count: counts.unread },
    ...(customersOn ? [{ value: 'customers' as const, label: t('tab.customers', 'Customers'), count: counts.customers }] : []),
    { value: 'team', label: t('tab.team', 'Team') },
    { value: 'groups', label: t('tab.groups', 'Groups') },
    { value: 'agents', label: t('tab.agents', 'Agents and bots') },
  ];

  return (
    <nav
      aria-label={t('list.label', 'Chats')}
      className={cn('flex min-h-0 w-full shrink-0 flex-col border-e border-border md:w-[21.25rem]', hidden && 'hidden md:flex')}
    >
      <header className="grid gap-3 px-4 pt-4 pb-3">
        <div className="flex items-center gap-2">
          <h1 className="flex-1 text-xl font-semibold">{t('title', 'Chat')}</h1>
          <IconButton icon="edit" label={t('new.action', 'New chat')} onClick={onNew} />
        </div>
        <SearchField
          value={query}
          onValueChange={onQuery}
          onSearch={onQuery}
          label={t('list.search', 'Search chats')}
          clearLabel={t('list.clear', 'Clear search')}
          placeholder={t('list.search', 'Search chats')}
        />
        <div role="tablist" aria-label={t('list.show', 'Show')} className="flex flex-wrap gap-1.5">
          {tabs.map((tb) => (
            <button
              key={tb.value}
              type="button"
              role="tab"
              aria-selected={tab === tb.value}
              onClick={() => onTab(tb.value)}
              className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm',
                tab === tb.value ? 'border-primary-tint bg-primary-tint font-medium text-primary-tint-foreground' : 'border-border text-foreground hover:bg-muted'
              )}
            >
              {tb.label}
              {tb.count !== undefined && tb.count > 0 && <span>{tb.count}</span>}
            </button>
          ))}
        </div>
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto pb-3">
        {items === null ? (
          <div className="grid gap-2 p-3" aria-busy="true">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : visible.length === 0 ? (
          <p className="p-4 text-sm text-muted-foreground">
            {items.length === 0 ? t('list.none', 'No chats yet. Start one with New chat.') : t('list.noMatch', 'No chats match.')}
          </p>
        ) : (
          <>
            {pinned.length > 0 && <ListSection title={t('list.pinned', 'Pinned')} items={pinned} selected={selected} onOpen={onOpen} t={t} />}
            {rest.length > 0 && <ListSection title={pinned.length > 0 ? t('list.all', 'All') : null} items={rest} selected={selected} onOpen={onOpen} t={t} />}
          </>
        )}
      </div>
    </nav>
  );
}

function ListSection({
  title,
  items,
  selected,
  onOpen,
  t,
}: {
  title: string | null;
  items: ChatInboxItem[];
  selected: number | null;
  onOpen: (id: number) => void;
  t: TranslateFn;
}) {
  return (
    <section>
      {title !== null && <h2 className="px-4 pt-3 pb-1 text-sm font-medium text-muted-foreground">{title}</h2>}
      <ul>
        {items.map((i) => (
          <li key={i.id}>
            <ListRow item={i} active={i.id === selected} onOpen={() => onOpen(i.id)} t={t} />
          </li>
        ))}
      </ul>
    </section>
  );
}

/** Minutes, hours or days since, for the "Waiting 12 min" tag. */
function waitingFor(since: string, t: TranslateFn): string {
  const minutes = Math.max(1, Math.round((Date.now() - new Date(since).getTime()) / 60000));
  if (minutes < 60) return t('list.waitingMin', 'Waiting {n} min', { n: String(minutes) });
  const hours = Math.round(minutes / 60);
  if (hours < 24) return t('list.waitingHours', 'Waiting {n} h', { n: String(hours) });
  return t('list.waitingDays', 'Waiting {n} d', { n: String(Math.round(hours / 24)) });
}

function ListRow({ item, active, onOpen, t }: { item: ChatInboxItem; active: boolean; onOpen: () => void; t: TranslateFn }) {
  const when = useListTime()(item.last_message_at);
  const ta = useTranslation('admin');
  const title = itemTitle(item, t);
  const line = itemPreview(item, t);
  const agent = isAgentChat(item);

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-current={active ? 'true' : undefined}
      className={cn(
        'grid w-full grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-3 border-s-[3px] border-transparent px-3.5 py-2.5 text-start hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
        active && 'border-primary bg-primary-tint hover:bg-primary-tint'
      )}
    >
      {item.kind === 'customer' ? (
        <CustomerAvatar name={title} channel={item.channel} size={40} label={channelName(item.channel, ta)} />
      ) : (
        <ChatIcon chat={item} size={40} />
      )}
      <span className="min-w-0">
        <span className="flex min-w-0 items-center gap-1.5">
          <span className={cn('truncate', item.unread > 0 ? 'font-semibold' : 'font-medium')}>{title}</span>
          {agent && <AgentTag t={t} />}
          {item.kind === 'customer' && item.waiting_since && (
            <Badge variant="warning" className="shrink-0">
              {waitingFor(item.waiting_since, t)}
            </Badge>
          )}
          {item.kind === 'customer' && item.status === 'closed' && (
            <Badge variant="outline" className="shrink-0">
              {ta('messages.status.closed', 'Closed')}
            </Badge>
          )}
        </span>
        <span dir="auto" className="block truncate text-start text-sm text-muted-foreground">
          {line}
        </span>
      </span>
      <span className="grid justify-items-end gap-1 self-start pt-0.5">
        {when && <span className={cn('text-xs', item.unread > 0 ? 'font-medium text-primary' : 'text-muted-foreground')}>{when}</span>}
        <span className="flex items-center gap-1">
          {item.kind === 'customer' && item.assignee && (
            <span title={item.assignee.name} className="inline-flex">
              <Avatar name={item.assignee.name} size={20} />
              <span className="sr-only">{item.assignee.name}</span>
            </span>
          )}
          {item.unread > 0 && (
            <span
              className="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-xs text-primary-foreground"
              aria-label={t('list.unread', '{count} unread', { count: String(item.unread) })}
            >
              {item.unread}
            </span>
          )}
        </span>
      </span>
    </button>
  );
}

function preview(c: ChatSummary, t: TranslateFn): string {
  const m = c.last_message;
  if (!m) return '';
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
    <span aria-hidden="true" className="grid shrink-0 place-items-center rounded-full bg-muted text-foreground" style={{ width: size, height: size }}>
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
  pinned,
  onPin,
  detailsOpen,
  onToggleDetails,
  initialDraft,
  onBack,
  onChanged,
}: {
  t: TranslateFn;
  api: ChatApi;
  chatId: number;
  myId: number;
  pinned: boolean;
  onPin: (pinned: boolean) => void;
  detailsOpen: boolean;
  onToggleDetails: () => void;
  /** Words to start the message box with (⌘K `/ask`). */
  initialDraft?: string;
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
      <div className="grid flex-1 place-items-center p-8">
        <AreaError
          message={error || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          retryLabel={t('action.retry', 'Try again')}
          onRetry={() => void load()}
        />
      </div>
    );
  }
  if (!chat) {
    return (
      <div className="grid flex-1 content-start gap-3 p-6" aria-busy="true">
        <Skeleton className="h-10 w-2/3" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const agents = chat.members.filter((m): m is ChatAgentMember => m.type === 'agent');
  const people = chat.members.filter((m) => m.type === 'person');

  return (
    <>
      <section aria-label={chat.title} className="flex min-h-0 min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-2.5 border-b border-border px-4 py-2.5">
          <IconButton icon="chevron-left" label={t('action.back', 'Back to chats')} className="md:hidden rtl:rotate-180" onClick={onBack} />
          <ChatIcon chat={chat} size={36} />
          <div className="min-w-0 flex-1">
            <h1 className="truncate text-base font-semibold">{chat.title}</h1>
            <p className="truncate text-sm text-muted-foreground">{subtitle(chat, t)}</p>
          </div>
          <IconButton
            icon="star"
            label={pinned ? t('pin.off', 'Unpin this chat') : t('pin.on', 'Pin this chat to the top')}
            pressed={pinned}
            onClick={() => onPin(!pinned)}
          />
          <IconButton
            icon="users"
            label={detailsOpen ? t('details.hide', 'Hide details') : t('details.show', 'Show details')}
            pressed={detailsOpen}
            onClick={onToggleDetails}
          />
        </header>
        <Thread t={t} api={api} chat={chat} messages={messages} myId={myId} onMessage={(m) => merge([m])} onNotice={setNotice} />
        {notice !== null && (
          <p role="alert" className="mx-4 mb-2 rounded-control bg-error-tint px-3 py-2 text-sm text-error-text">
            {notice || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          </p>
        )}
        <Composer t={t} api={api} chat={chat} initialDraft={initialDraft} onSent={(m) => merge([m])} onError={setNotice} />
      </section>
      {detailsOpen && (
        <SidePanel
          t={t}
          onClose={onToggleDetails}
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
      )}
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
  const sentAt = time(message.created_at);
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
          {/* null when the workspace hides dates (ui.hide_dates): then no time at all, not even in an attribute */}
          {sentAt !== null && <time>{sentAt}</time>}
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
  initialDraft,
  onSent,
  onError,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  initialDraft?: string;
  onSent: (m: ChatMessage) => void;
  onError: (s: string | null) => void;
}) {
  const [draft, setDraft] = useState(initialDraft ?? '');
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
  onClose,
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
  onClose: () => void;
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
    <ChatSidePane
      title={chat.title}
      subtitle={t('side.label', 'People and agents in this chat')}
      onClose={onClose}
      closeLabel={t('details.close', 'Close details')}
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
    </ChatSidePane>
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
