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
import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
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
import type { ChatAttachment, ChatAgentMember, ChatDetail, ChatInboxItem, ChatLayout, ChatMember, ChatMention, ChatMessage, ChatMode, ChatReadMark, ChatSummary } from '@/lib/chat-wire';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { PollDialog, RecordDialog } from './blocks';
import { PendingFiles, VoiceRecorder, attachmentsLine, fileSize, type PendingFile } from './attachments';
import { ComposerBanner, MessageBody, MessageItem, NewLine, PinnedBar, TypingLine, type MessageActions } from './message-parts';
import { useCapabilities } from '@/hooks/useCapabilities';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import { ChatSidePane, CustomerAvatar, CustomerChat, channelName, useListTime } from './customer-chat';
import { chatApi, chatErrorText, uploadToChat, type ChatApi } from './chat-api';
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
  // UC.7: bubbles or compact rows, the person's own choice (My settings › Language and display).
  const [layout, setLayout] = useState<ChatLayout>('bubbles');
  useEffect(() => {
    let live = true;
    api.layout().then(
      (pref) => live && setLayout(pref.layout),
      () => undefined
    );
    return () => {
      live = false;
    };
  }, [api]);
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

  // UC.8: mute a chat for yourself; mentions still reach you.
  const mute = useCallback(
    async (id: number, muted: boolean) => {
      setItems((prev) => prev?.map((i) => (i.id === id ? { ...i, muted } : i)) ?? prev);
      try {
        await api.mute(id, muted);
      } catch (e) {
        setListError(chatErrorText(e, ''));
        void loadList();
      }
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
    <div className="relative flex h-[calc(100dvh-8.5rem)] min-h-[32rem] overflow-hidden rounded-card border border-border bg-card max-sm:min-h-0 max-sm:rounded-none max-sm:border-0">
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
            muted={current?.muted ?? false}
            onMute={(muted) => void mute(selected, muted)}
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
            layout={layout}
            unreadAtOpen={unreadAtOpen?.id === selected ? unreadAtOpen.n : 0}
            pinned={current?.pinned ?? false}
            onPin={(pinned) => void pin(selected, pinned)}
            muted={current?.muted ?? false}
            onMute={(muted) => void mute(selected, muted)}
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
          customers={customersOn && has('messages:reply')}
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
        <div role="tablist" aria-label={t('list.show', 'Show')} className="flex flex-wrap gap-1.5 max-sm:-mx-4 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:px-4 max-sm:[scrollbar-width:none]">
          {tabs.map((tb) => (
            <button
              key={tb.value}
              type="button"
              role="tab"
              aria-selected={tab === tb.value}
              onClick={() => onTab(tb.value)}
              className={cn(
                'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm max-sm:h-10 max-sm:text-base',
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
          {item.muted && (
            <span role="img" aria-label={t('mute.muted', 'Muted')} className="text-muted-foreground">
              <Icon name="notification" size={14} />
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
  if (m.body === '' && m.block) {
    const line = m.block.type === 'poll' ? t('poll.line', 'Poll: {question}', { question: m.block.question }) : m.block.title;
    return !m.author || c.type === 'direct' ? line : `${m.author.name.split(' ')[0]}: ${line}`;
  }
  if (m.body === '' && (m.attachments?.length ?? 0) > 0) {
    const line = attachmentsLine(t, m.attachments);
    return !m.author || c.type === 'direct' ? line : `${m.author.name.split(' ')[0]}: ${line}`;
  }
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

/** What the right pane shows for a team chat (UC.2): its people and agents, a thread, or search. */
type Side = { kind: 'details' } | { kind: 'thread'; rootId: number } | { kind: 'search' } | null;

function OpenChat({
  t,
  api,
  chatId,
  myId,
  layout,
  unreadAtOpen,
  pinned,
  onPin,
  muted,
  onMute,
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
  muted: boolean;
  onMute: (muted: boolean) => void;
  layout: ChatLayout;
  /** Unread lines when the chat opened: the "n new" line goes above them. */
  unreadAtOpen: number;
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
  const [readMarks, setReadMarks] = useState<ChatReadMark[]>([]);
  const [typing, setTyping] = useState<string[]>([]);
  const [pins, setPins] = useState<ChatMessage[]>([]);
  const [side, setSide] = useState<Side>(detailsOpen ? { kind: 'details' } : null);
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null);
  const [editing, setEditing] = useState<ChatMessage | null>(null);
  const [highlight, setHighlight] = useState<{ id: number; words: string } | null>(null);
  // The first unread line when the chat opened; it stays put while the chat is open.
  const [newFrom, setNewFrom] = useState<number | null>(null);
  const lastId = useRef(0);
  const readMark = useRef(0);
  const since = useRef<string | null>(null);

  const merge = useCallback((incoming: ChatMessage[]) => {
    if (incoming.length === 0) return;
    setMessages((prev) => {
      const byId = new Map(prev.map((m) => [m.id, m]));
      // Thread-only replies live in the thread pane, not the chat.
      for (const m of incoming) if (m.thread_root_id === null || m.in_chat) byId.set(m.id, m);
      return [...byId.values()].sort((a, b) => a.id - b.id);
    });
    lastId.current = Math.max(lastId.current, ...incoming.map((m) => m.id));
  }, []);

  const apply = useCallback((detail: ChatDetail) => {
    setChat(detail);
    setMessages(detail.messages);
    lastId.current = detail.messages.reduce((max, m) => Math.max(max, m.id), 0);
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await api.show(chatId));
      setError(null);
    } catch (e) {
      setError(chatErrorText(e, ''));
    }
  }, [api, chatId, apply]);

  useEffect(() => {
    let live = true;
    api.show(chatId).then(
      (detail) => live && apply(detail),
      (e: unknown) => live && setError(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [api, chatId, apply]);

  if (newFrom === null && chat !== null) {
    const fromOthers = messages.filter((m) => m.kind === 'message' && !(m.author?.type === 'person' && m.author.profile_id === myId));
    const first = unreadAtOpen > 0 ? fromOthers[Math.max(0, fromOthers.length - unreadAtOpen)] : undefined;
    setNewFrom(first ? first.id : 0);
  }

  // One poll brings new lines, changed ones (edits, deletes, reactions, pins,
  // thread replies), read marks for Seen, who is typing and the pins.
  const poll = useCallback(async () => {
    try {
      const state = await api.state(chatId, lastId.current, since.current);
      merge(state.messages);
      if (state.changed.length > 0) {
        setMessages((prev) => prev.map((m) => state.changed.find((c) => c.id === m.id) ?? m));
      }
      setReadMarks(state.read_marks);
      setTyping(state.typing);
      setPins(state.pinned);
      since.current = state.now;
    } catch {
      // A missed poll is retried by the next one.
    }
  }, [api, chatId, merge]);
  useEffect(() => {
    if (chat !== null) void poll();
  }, [chat, poll]);
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

  const replace = useCallback((m: ChatMessage) => {
    setMessages((prev) => prev.map((x) => (x.id === m.id ? m : x)));
    setPins((prev) => (m.pinned ? [m, ...prev.filter((p) => p.id !== m.id)] : prev.filter((p) => p.id !== m.id)));
  }, []);

  // Scroll to a message in place (a quote, a pin, a search result), loading earlier pages when it is further back.
  const goTo = useCallback(
    async (id: number, words = '') => {
      setHighlight({ id, words });
      let have = messages;
      for (let i = 0; i < 10 && !have.some((m) => m.id === id) && have.length > 0; i++) {
        try {
          const earlier = await api.before(chatId, have[0].id);
          if (earlier.length === 0) break;
          have = [...earlier, ...have];
          merge(earlier);
        } catch {
          break;
        }
      }
      window.requestAnimationFrame(() => document.getElementById(`chat-msg-${id}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
    },
    [api, chatId, merge, messages]
  );

  const actions: MessageActions = useMemo(
    () => ({
      onReply: (m) => {
        setEditing(null);
        setReplyTo(m);
      },
      onThread: (m) => setSide({ kind: 'thread', rootId: m.thread_root_id ?? m.id }),
      onReact: (m, emoji) => {
        api.react(m.id, emoji).then(replace, (e: unknown) => setNotice(chatErrorText(e, '')));
      },
      onPin: (m, on) => {
        api.pinMessage(m.id, on).then(replace, (e: unknown) => setNotice(chatErrorText(e, '')));
      },
      onCopy: (m) => {
        void navigator.clipboard?.writeText(m.body).catch(() => undefined);
      },
      onEdit: (m) => {
        setReplyTo(null);
        setEditing(m);
      },
      onDelete: (m) => {
        api.deleteMessage(m.id).then(replace, (e: unknown) => setNotice(chatErrorText(e, '')));
      },
      onVote: (m, choices) => {
        api.vote(m.id, choices).then(replace, (e: unknown) => setNotice(chatErrorText(e, '')));
      },
      onQuote: (id) => void goTo(id),
    }),
    [api, replace, goTo]
  );

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
  const toggleSide = (kind: 'details' | 'search') => {
    if (kind === 'details') onToggleDetails();
    setSide((s) => (s?.kind === kind ? null : { kind }));
  };

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
          {/* On a phone the header keeps Back, the chat and its details (UC.13); search and pin stay on the computer. */}
          <IconButton icon="search" label={t('search.open', 'Search in this chat')} className="max-sm:hidden" pressed={side?.kind === 'search'} onClick={() => toggleSide('search')} />
          <IconButton
            className="max-sm:hidden"
            icon="star"
            label={pinned ? t('pin.off', 'Unpin this chat') : t('pin.on', 'Pin this chat to the top')}
            pressed={pinned}
            onClick={() => onPin(!pinned)}
          />
          <IconButton
            icon="users"
            label={side?.kind === 'details' ? t('details.hide', 'Hide details') : t('details.show', 'Show details')}
            pressed={side?.kind === 'details'}
            onClick={() => toggleSide('details')}
          />
        </header>
        <PinnedBar t={t} pinned={pins} onOpen={(id) => void goTo(id)} />
        <Thread
          t={t}
          chat={chat}
          messages={messages}
          myId={myId}
          layout={layout}
          readMarks={readMarks}
          typing={typing}
          newFrom={newFrom}
          highlight={highlight}
          actions={actions}
          onMessage={(m) => merge([m])}
          onNotice={setNotice}
          api={api}
        />
        {notice !== null && (
          <p role="alert" className="mx-4 mb-2 rounded-control bg-error-tint px-3 py-2 text-sm text-error-text">
            {notice || t('error.generic', 'Something went wrong with Chat. Please try again.')}
          </p>
        )}
        <Composer
          t={t}
          api={api}
          chat={chat}
          initialDraft={initialDraft}
          replyTo={replyTo}
          editing={editing}
          onCancelReply={() => setReplyTo(null)}
          onCancelEdit={() => setEditing(null)}
          onSent={(m) => {
            merge([m]);
            setReplyTo(null);
          }}
          onEdited={(m) => {
            replace(m);
            setEditing(null);
          }}
          onError={setNotice}
        />
      </section>
      {side?.kind === 'details' && (
        <SidePanel
          t={t}
          api={api}
          muted={muted}
          onMute={onMute}
          onClose={() => toggleSide('details')}
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
      {side?.kind === 'thread' && (
        <ThreadPane
          key={side.rootId}
          t={t}
          api={api}
          chat={chat}
          rootId={side.rootId}
          myId={myId}
          layout={layout}
          readMarks={readMarks}
          actions={actions}
          onPosted={(m) => {
            if (m.in_chat) merge([m]);
          }}
          onClose={() => setSide(null)}
        />
      )}
      {side?.kind === 'search' && (
        <SearchPane t={t} api={api} chat={chat} people={people} onOpen={(id, words) => void goTo(id, words)} onClose={() => setSide(null)} />
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
  layout,
  readMarks,
  typing,
  newFrom,
  highlight,
  actions,
  onMessage,
  onNotice,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  messages: ChatMessage[];
  myId: number;
  layout: ChatLayout;
  readMarks: ChatReadMark[];
  typing: string[];
  newFrom: number | null;
  highlight: { id: number; words: string } | null;
  actions: MessageActions;
  onMessage: (m: ChatMessage) => void;
  onNotice: (s: string | null) => void;
}) {
  const scroller = useRef<HTMLDivElement>(null);
  const bottom = useRef<HTMLDivElement>(null);
  // Stays at the newest line while the reader is there; reading further up, nothing jumps.
  const atBottom = useRef(true);
  const newCount = newFrom ? messages.filter((m) => m.id >= newFrom && m.kind === 'message' && !(m.author?.type === 'person' && m.author.profile_id === myId)).length : 0;
  useEffect(() => {
    const el = scroller.current;
    if (!el) return undefined;
    const stick = () => {
      if (atBottom.current) el.scrollTop = el.scrollHeight;
    };
    stick();
    // Lines grow after they render (fonts, reactions, a pane opening beside them): stay at the bottom.
    if (typeof ResizeObserver === 'undefined' || !el.firstElementChild) return undefined;
    const observer = new ResizeObserver(stick);
    observer.observe(el.firstElementChild);
    observer.observe(el);
    return () => observer.disconnect();
  }, [messages, typing.length]);

  return (
    <div
      ref={scroller}
      className="min-h-0 flex-1 overflow-y-auto bg-muted/40 px-4 py-3.5"
      aria-live="polite"
      onScroll={(e) => {
        const el = e.currentTarget;
        atBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
      }}
    >
      <div className={cn('flex min-h-full flex-col justify-end', layout === 'bubbles' ? 'gap-3' : 'gap-1.5')}>
        {messages.length === 0 && <p className="self-center text-sm text-muted-foreground">{t('thread.empty', 'No messages yet. Say hello.')}</p>}
        {messages.map((m) => (
          <Fragment key={m.id}>
            {newFrom !== null && newFrom > 0 && m.id === newFrom && newCount > 0 && <NewLine t={t} count={newCount} />}
            {m.kind === 'system' || m.kind === 'line' ? (
              <SystemLine t={t} message={m} />
            ) : (
              <MessageRow
                t={t}
                api={api}
                message={m}
                chat={chat}
                myId={myId}
                layout={layout}
                readMarks={readMarks}
                actions={actions}
                highlight={highlight?.id === m.id ? highlight.words : undefined}
                onMessage={onMessage}
                onNotice={onNotice}
              />
            )}
          </Fragment>
        ))}
        <TypingLine t={t} names={typing} />
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
  layout,
  readMarks,
  actions,
  inThread = false,
  highlight,
  onMessage,
  onNotice,
}: {
  t: TranslateFn;
  api: ChatApi;
  message: ChatMessage;
  chat: ChatDetail;
  myId: number;
  layout: ChatLayout;
  readMarks: ChatReadMark[];
  actions: MessageActions;
  inThread?: boolean;
  highlight?: string;
  onMessage: (m: ChatMessage) => void;
  onNotice: (s: string | null) => void;
}) {
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

  // What only some messages carry: an agent's held line, a proposal card. Dashed: not happened yet.
  const extra =
    held || (message.kind === 'proposal' && message.proposal) || message.status === 'declined' ? (
      <div className={cn(message.status === 'declined' && 'text-muted-foreground line-through')}>
        {held ? (
          <div className="grid max-w-[32.5rem] gap-1.5 rounded-card border-[1.5px] border-dashed border-input bg-background px-3.5 py-3">
            <MessageBody text={message.body} mentions={message.mentions} highlight={highlight} />
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
          <MessageBody text={message.body} mentions={message.mentions} highlight={highlight} />
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
    ) : undefined;

  return (
    <MessageItem
      t={t}
      message={message}
      layout={layout}
      myId={myId}
      readMarks={readMarks}
      actions={actions}
      inThread={inThread}
      highlight={highlight}
      agentTag={isAgent ? <AgentTag t={t} /> : undefined}
      forLine={
        isAgent && author?.works_for
          ? author.works_for.id === myId
            ? t('msg.forYou', 'for you')
            : t('msg.for', 'for {name}', { name: author.works_for.name.split(' ')[0] })
          : null
      }
    >
      {extra}
    </MessageItem>
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

function AgentTag({ t }: { t: TranslateFn }) {
  return (
    <span className="inline-flex h-[1.125rem] items-center gap-0.5 rounded-control-sm bg-primary-tint px-1.5 text-xs font-semibold text-primary-tint-foreground">
      <Icon name="robot" size={10} />
      {t('agent.tag', 'Agent')}
    </span>
  );
}

// ── Writing ──────────────────────────────────────────────────────────────────

/** Seconds between "I'm typing" beats while the person types; the server keeps each for 6. */
const TYPING_BEAT_MS = 3000;

function Composer({
  t,
  api,
  chat,
  initialDraft,
  replyTo = null,
  editing = null,
  threadRoot,
  alsoLabel,
  placeholder,
  onCancelReply,
  onCancelEdit,
  onSent,
  onEdited,
  onError,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  initialDraft?: string;
  replyTo?: ChatMessage | null;
  editing?: ChatMessage | null;
  /** In the thread pane: replies go under this message. */
  threadRoot?: number;
  /** In the thread pane: the "Also send to …" box's words. */
  alsoLabel?: string;
  placeholder?: string;
  onCancelReply?: () => void;
  onCancelEdit?: () => void;
  onSent: (m: ChatMessage) => void;
  onEdited?: (m: ChatMessage) => void;
  onError: (s: string | null) => void;
}) {
  const [draft, setDraft] = useState(initialDraft ?? '');
  const [sending, setSending] = useState(false);
  const [picked, setPicked] = useState<ChatMention[]>([]);
  const [mentionQuery, setMentionQuery] = useState<string | null>(null);
  const [alsoToChat, setAlsoToChat] = useState(false);
  const box = useRef<HTMLTextAreaElement>(null);
  const lastBeat = useRef(0);
  const [editingId, setEditingId] = useState<number | null>(null);
  // UC.10: files on their way, and the voice recorder.
  const [pending, setPending] = useState<PendingFile[]>([]);
  const [recording, setRecording] = useState(false);
  // UC.5: a poll or a Whity record to send.
  const [blockDialog, setBlockDialog] = useState<'poll' | 'record' | null>(null);
  const sendBlock = async (block: Record<string, unknown>) => {
    try {
      onSent(
        await api.post(chat.id, '', [], {
          block,
          ...(replyTo ? { reply_to: replyTo.id } : {}),
          ...(threadRoot ? { thread_root: threadRoot, also_to_chat: alsoToChat } : {}),
        })
      );
      setBlockDialog(null);
    } catch (e) {
      onError(chatErrorText(e, ''));
    }
  };
  const photoInput = useRef<HTMLInputElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  const patchPending = (key: string, change: Partial<PendingFile>) =>
    setPending((prev) => prev.map((f) => (f.key === key ? { ...f, ...change } : f)));

  const startUpload = async (item: PendingFile): Promise<ChatAttachment | null> => {
    patchPending(item.key, { status: 'uploading', progress: 0, error: undefined });
    try {
      const meta: { duration_ms?: number; width?: number; height?: number } = { duration_ms: item.durationMs };
      if (item.kind === 'image' && item.preview) {
        const size = await new Promise<{ w: number; h: number }>((resolve) => {
          const img = new Image();
          img.onload = () => resolve({ w: img.naturalWidth, h: img.naturalHeight });
          img.onerror = () => resolve({ w: 0, h: 0 });
          img.src = item.preview!;
        });
        meta.width = size.w;
        meta.height = size.h;
      }
      const attachment = await uploadToChat(chat.id, item.file, item.name, meta, (fraction) => patchPending(item.key, { progress: fraction }));
      if (item.kind === 'voice' && item.transcript) await api.transcribe(attachment.id, item.transcript).catch(() => undefined);
      patchPending(item.key, { status: 'ready', progress: 1, attachment });
      return attachment;
    } catch (e) {
      // Not sent, and nothing lost: the file stays here with Try again.
      patchPending(item.key, { status: 'failed', error: chatErrorText(e, '') });
      return null;
    }
  };

  const addFiles = (files: FileList | File[] | null) => {
    if (!files) return;
    const items: PendingFile[] = Array.from(files)
      .slice(0, 10)
      .map((file) => {
        const kind: PendingFile['kind'] = file.type.startsWith('image/') ? 'image' : file.type.startsWith('video/') ? 'video' : 'file';
        return {
          key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
          file,
          name: file.name,
          kind,
          progress: 0,
          status: 'uploading',
          preview: kind === 'image' ? URL.createObjectURL(file) : undefined,
        };
      });
    setPending((prev) => [...prev, ...items]);
    items.forEach((item) => void startUpload(item));
  };

  const removePending = (key: string) =>
    setPending((prev) => {
      const gone = prev.find((f) => f.key === key);
      if (gone?.preview) URL.revokeObjectURL(gone.preview);
      return prev.filter((f) => f.key !== key);
    });

  const sendVoice = async (blob: Blob, durationMs: number, transcript: string) => {
    setRecording(false);
    const item: PendingFile = {
      key: `voice-${Date.now()}`,
      file: blob,
      name: `voice-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm'}`,
      kind: 'voice',
      progress: 0,
      status: 'uploading',
      durationMs,
      transcript,
    };
    setPending((prev) => [...prev, item]);
    const attachment = await startUpload(item);
    if (!attachment) return;
    try {
      onSent(
        await api.post(chat.id, '', [], {
          attachments: [attachment.id],
          ...(replyTo ? { reply_to: replyTo.id } : {}),
          ...(threadRoot ? { thread_root: threadRoot, also_to_chat: alsoToChat } : {}),
        })
      );
      removePending(item.key);
    } catch (e) {
      patchPending(item.key, { status: 'failed', error: chatErrorText(e, '') });
    }
  };

  // Editing puts the message's words in the box; leaving edit clears it.
  if ((editing?.id ?? null) !== editingId) {
    setEditingId(editing?.id ?? null);
    setDraft(editing ? editing.body : '');
  }
  useEffect(() => {
    if (editing || replyTo) box.current?.focus();
  }, [editing, replyTo]);

  const candidates = useMemo(() => {
    if (mentionQuery === null) return [];
    const q = mentionQuery.toLowerCase();
    const members = chat.members.map(
      (m): ChatMention => (m.type === 'agent' ? { type: 'agent', id: m.agent_id, name: m.name } : { type: 'person', id: m.profile_id, name: m.name })
    );
    // UC.10: @everyone in a group tells every person in it.
    const everyone: ChatMention[] = chat.type === 'group' ? [{ type: 'everyone', id: 0, name: 'everyone' }] : [];
    return [...members, ...everyone].filter((m) => m.name.toLowerCase().includes(q)).slice(0, 8);
  }, [chat.members, chat.type, mentionQuery]);

  const beat = () => {
    const now = Date.now();
    if (now - lastBeat.current < TYPING_BEAT_MS) return;
    lastBeat.current = now;
    void api.typing(chat.id).catch(() => undefined);
  };

  const onChange = (value: string) => {
    setDraft(value);
    if (value !== '' && !editing) beat();
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

  const ready = pending.filter((f) => f.status === 'ready' && f.attachment);
  const uploading = pending.some((f) => f.status === 'uploading');
  const canSend = !sending && !uploading && (draft.trim() !== '' || (ready.length > 0 && !editing));

  const send = async () => {
    const text = draft.trim();
    if (!canSend) return;
    setSending(true);
    onError(null);
    try {
      if (editing) {
        onEdited?.(await api.edit(editing.id, text));
      } else {
        const mentions = picked.filter((m) => text.includes(`@${m.name}`)).map(({ type, id }) => ({ type, id }));
        onSent(
          await api.post(chat.id, text, mentions, {
            ...(replyTo ? { reply_to: replyTo.id } : {}),
            ...(threadRoot ? { thread_root: threadRoot, also_to_chat: alsoToChat } : {}),
            ...(ready.length > 0 ? { attachments: ready.map((f) => f.attachment!.id) } : {}),
          })
        );
        ready.forEach((f) => removePending(f.key));
      }
      // Sent: the box empties. Not sent: the words stay, so nothing is lost.
      setDraft('');
      setPicked([]);
    } catch (e) {
      onError(chatErrorText(e, ''));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="grid gap-2 px-4 pb-4">
      {replyTo && (
        <ComposerBanner
          tone="reply"
          title={t('compose.replyingTo', 'Replying to {name}', { name: replyTo.author?.name ?? '' })}
          text={replyTo.body}
          closeLabel={t('compose.cancelReply', 'Stop replying')}
          onClose={() => onCancelReply?.()}
        />
      )}
      {editing && (
        <ComposerBanner
          tone="edit"
          title={t('compose.editing', 'Editing')}
          text={editing.body}
          closeLabel={t('compose.cancelEdit', 'Stop editing')}
          onClose={() => onCancelEdit?.()}
        />
      )}
      <PendingFiles
        t={t}
        files={pending}
        onRemove={removePending}
        onRetry={(key) => {
          const item = pending.find((f) => f.key === key);
          if (item) void startUpload(item);
        }}
      />
      {recording ? (
        <VoiceRecorder
          t={t}
          onDone={(blob, ms, words) => void sendVoice(blob, ms, words)}
          onCancel={() => setRecording(false)}
          onError={(message) => {
            setRecording(false);
            onError(message);
          }}
        />
      ) : (
      <div
        className="relative flex items-end gap-1 rounded-card border border-input bg-card px-2 py-1.5 focus-within:outline-2 focus-within:outline-ring"
        onDragOver={(e) => {
          if (!editing && e.dataTransfer.types.includes('Files')) e.preventDefault();
        }}
        onDrop={(e) => {
          if (editing || e.dataTransfer.files.length === 0) return;
          e.preventDefault();
          addFiles(e.dataTransfer.files);
        }}
      >
        {!editing && (
          <>
            <input ref={photoInput} type="file" accept="image/*,video/*" multiple hidden onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
            <input ref={fileInput} type="file" multiple hidden onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="icon" aria-label={t('attach.label', 'Add photos, files and more')}>
                  <Icon name="plus" size={18} />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start" side="top">
                <DropdownMenuItem onSelect={() => photoInput.current?.click()}>
                  <Icon name="image" size={16} />
                  {t('attach.photos', 'Photos and videos')}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => fileInput.current?.click()}>
                  <Icon name="document" size={16} />
                  {t('attach.file', 'File')}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setBlockDialog('record')}>
                  <Icon name="invoice" size={16} />
                  <span className="grid">
                    {t('attach.record', 'A Whity record')}
                    <small className="text-xs text-muted-foreground">{t('attach.recordHint', 'Booking or document')}</small>
                  </span>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setBlockDialog('poll')}>
                  <Icon name="list-bullet" size={16} />
                  {t('attach.poll', 'Poll')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </>
        )}
        {candidates.length > 0 && (
          <ul
            role="listbox"
            aria-label={t('compose.mentionList', 'People and agents in this chat')}
            className="absolute bottom-full start-0 mb-1 w-72 overflow-hidden rounded-card border border-border bg-popover py-1 shadow-md"
          >
            <li className="px-3 pt-1 pb-1 text-xs font-medium text-muted-foreground">{t('compose.inThisChat', 'In this chat')}</li>
            {candidates.map((c) => (
              <li key={`${c.type}${c.id}`}>
                <button
                  type="button"
                  role="option"
                  aria-selected="false"
                  className="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-muted"
                  onClick={() => pick(c)}
                >
                  {c.type === 'everyone' ? (
                    <span className="grid size-6 place-items-center rounded-full bg-muted">
                      <Icon name="users" size={14} />
                    </span>
                  ) : (
                    <Avatar name={c.name} kind={c.type === 'agent' ? 'agent' : 'person'} size={24} />
                  )}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{c.type === 'everyone' ? '@everyone' : c.name}</span>
                    {c.type === 'everyone' && (
                      <span className="block text-xs text-muted-foreground">
                        {t('compose.everyoneHint', 'Tells all {count} people', { count: String(chat.people_count) })}
                      </span>
                    )}
                  </span>
                  {c.type === 'agent' && <AgentTag t={t} />}
                </button>
              </li>
            ))}
          </ul>
        )}
        <IconButton
          icon="at"
          label={t('compose.mention', 'Mention a person or an agent')}
          onClick={() => {
            onChange(`${draft}${draft === '' || draft.endsWith(' ') ? '' : ' '}@`);
            box.current?.focus();
          }}
        />
        <Textarea
          ref={box}
          value={draft}
          rows={1}
          maxLength={4000}
          onChange={(e) => onChange(e.target.value)}
          onPaste={(e) => {
            if (!editing && e.clipboardData.files.length > 0) {
              e.preventDefault();
              addFiles(e.clipboardData.files);
            }
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey && candidates.length === 0) {
              e.preventDefault();
              void send();
            }
            if (e.key === 'Escape') {
              if (mentionQuery !== null) setMentionQuery(null);
              else if (editing) onCancelEdit?.();
              else if (replyTo) onCancelReply?.();
            }
          }}
          aria-label={t('compose.label', 'Message')}
          placeholder={placeholder ?? t('compose.placeholderShort', 'Message {name}', { name: chat.title })}
          className="max-h-36 min-h-10 flex-1 resize-none overflow-y-auto border-0 bg-transparent p-0 py-2 text-base shadow-none [field-sizing:content] focus-visible:border-0 focus-visible:ring-0 focus-visible:outline-none max-sm:text-lg md:text-base"
        />
        {!editing && draft.trim() === '' && ready.length === 0 && (
          <Button type="button" variant="ghost" size="icon" aria-label={t('voice.record', 'Record a voice message')} onClick={() => setRecording(true)}>
            <Icon name="microphone" size={18} />
          </Button>
        )}
        <Button
          type="button"
          size="icon-sm"
          className="mb-1 rtl:[&_svg]:-scale-x-100"
          disabled={!canSend}
          onClick={() => void send()}
          aria-label={editing ? t('compose.save', 'Save') : t('compose.send', 'Send')}
          title={editing ? t('compose.save', 'Save') : t('compose.send', 'Send')}
        >
          <Icon name={editing ? 'check' : 'send'} size={16} />
        </Button>
      </div>
      )}
      <PollDialog t={t} open={blockDialog === 'poll'} onOpenChange={(o) => setBlockDialog(o ? 'poll' : null)} onSend={(block) => sendBlock(block)} />
      {blockDialog === 'record' && (
        <RecordDialog t={t} api={api} open onOpenChange={(o) => setBlockDialog(o ? 'record' : null)} onPick={(block) => sendBlock(block)} />
      )}
      {threadRoot !== undefined && alsoLabel && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 accent-primary" checked={alsoToChat} onChange={(e) => setAlsoToChat(e.target.checked)} />
          {alsoLabel}
        </label>
      )}
    </div>
  );
}

// ── Threads and search (UC.6, UC.8) ──────────────────────────────────────────

function ThreadPane({
  t,
  api,
  chat,
  rootId,
  myId,
  layout,
  readMarks,
  actions,
  onPosted,
  onClose,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  rootId: number;
  myId: number;
  layout: ChatLayout;
  readMarks: ChatReadMark[];
  actions: MessageActions;
  onPosted: (m: ChatMessage) => void;
  onClose: () => void;
}) {
  const [root, setRoot] = useState<ChatMessage | null>(null);
  const [replies, setReplies] = useState<ChatMessage[]>([]);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const thread = await api.thread(rootId);
      setRoot(thread.root);
      setReplies(thread.replies);
    } catch (e) {
      setNotice(chatErrorText(e, ''));
    }
  }, [api, rootId]);
  useEffect(() => {
    let live = true;
    api.thread(rootId).then(
      (thread) => {
        if (!live) return;
        setRoot(thread.root);
        setReplies(thread.replies);
      },
      (e: unknown) => live && setNotice(chatErrorText(e, ''))
    );
    return () => {
      live = false;
    };
  }, [api, rootId]);
  usePoll(load, THREAD_MS, root !== null);

  // In the thread, reactions and the rest change this pane's copy too.
  const local: MessageActions = {
    ...actions,
    onThread: () => undefined,
    onReact: (m, emoji) => {
      api.react(m.id, emoji).then(
        (fresh) => {
          setReplies((prev) => prev.map((x) => (x.id === fresh.id ? fresh : x)));
          if (fresh.id === root?.id) setRoot(fresh);
        },
        (e: unknown) => setNotice(chatErrorText(e, ''))
      );
    },
  };

  return (
    <ChatSidePane title={t('thread.title', 'Thread')} subtitle={chat.title} onClose={onClose} closeLabel={t('thread.close', 'Close the thread')}>
      {root === null ? (
        <Skeleton className="h-24 w-full" />
      ) : (
        <div className="-mx-4 -mt-4 -mb-4 flex flex-1 flex-col">
          <div className="grid gap-3 px-4 pt-4">
            <MessageRow t={t} api={api} message={root} chat={chat} myId={myId} layout={layout} readMarks={readMarks} actions={local} inThread onMessage={setRoot} onNotice={setNotice} />
            <div className="flex items-center gap-3 text-sm text-muted-foreground" role="separator">
              <span className="h-px flex-1 bg-border" />
              {replies.length === 1 ? t('msg.oneReply', '1 reply') : t('msg.replies', '{count} replies', { count: String(replies.length) })}
              <span className="h-px flex-1 bg-border" />
            </div>
            {replies.map((m) => (
              <MessageRow
                key={m.id}
                t={t}
                api={api}
                message={m}
                chat={chat}
                myId={myId}
                layout={layout}
                readMarks={readMarks}
                actions={local}
                inThread
                onMessage={(x) => setReplies((prev) => prev.map((y) => (y.id === x.id ? x : y)))}
                onNotice={setNotice}
              />
            ))}
          </div>
          {notice !== null && (
            <p role="alert" className="mx-4 mt-2 rounded-control bg-error-tint px-3 py-2 text-sm text-error-text">
              {notice || t('error.generic', 'Something went wrong with Chat. Please try again.')}
            </p>
          )}
          <div className="sticky bottom-0 mt-auto bg-card pt-3">
            <Composer
              t={t}
              api={api}
              chat={chat}
              threadRoot={root.id}
              placeholder={t('thread.placeholder', 'Reply in thread')}
              alsoLabel={t('thread.also', 'Also send to {chat}', { chat: chat.title })}
              onSent={(m) => {
                setReplies((prev) => [...prev, m]);
                onPosted(m);
              }}
              onError={setNotice}
            />
          </div>
        </div>
      )}
    </ChatSidePane>
  );
}

function SearchPane({
  t,
  api,
  chat,
  people,
  onOpen,
  onClose,
}: {
  t: TranslateFn;
  api: ChatApi;
  chat: ChatDetail;
  people: ChatMember[];
  onOpen: (id: number, words: string) => void;
  onClose: () => void;
}) {
  const { dateTime } = useDateDisplay();
  const [query, setQuery] = useState('');
  const [words, setWords] = useState('');
  const [from, setFrom] = useState<number | null>(null);
  const [results, setResults] = useState<ChatMessage[] | null>(null);

  useEffect(() => {
    if (words === '' && from === null) return;
    let live = true;
    api.search(chat.id, words, from).then(
      (found) => live && setResults(found),
      () => live && setResults([])
    );
    return () => {
      live = false;
    };
  }, [api, chat.id, words, from]);

  const shown = words === '' && from === null ? null : results;

  return (
    <ChatSidePane title={t('search.title', 'Search in this chat')} subtitle={chat.title} onClose={onClose} closeLabel={t('search.close', 'Close search')}>
      <SearchField
        value={query}
        onValueChange={(v) => {
          setQuery(v);
          if (v === '') setWords('');
        }}
        onSearch={(v) => setWords(v.trim())}
        label={t('search.label', 'Words to find')}
        clearLabel={t('list.clear', 'Clear search')}
        placeholder={t('search.placeholder', 'Find words in this chat')}
      />
      <div role="group" aria-label={t('search.from', 'From')} className="flex flex-wrap gap-1.5">
        {people.map((p) =>
          p.type === 'person' ? (
            <button
              key={p.member_id}
              type="button"
              aria-pressed={from === p.profile_id}
              onClick={() => setFrom(from === p.profile_id ? null : p.profile_id)}
              className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-full border px-2.5 text-sm',
                from === p.profile_id ? 'border-primary-tint bg-primary-tint text-primary-tint-foreground' : 'border-border hover:bg-muted'
              )}
            >
              <Avatar name={p.name} size={20} />
              {p.name.split(' ')[0]}
            </button>
          ) : null
        )}
      </div>
      {shown === null ? (
        <p className="text-sm text-muted-foreground">{t('search.hint', 'Type words, or pick a person, to find their messages.')}</p>
      ) : shown.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t('search.none', 'Nothing matches.')}</p>
      ) : (
        <ul className="grid gap-2">
          {shown.map((m) => (
            <li key={m.id}>
              <button
                type="button"
                onClick={() => onOpen(m.id, words)}
                className="grid w-full gap-1 rounded-control border border-border px-3 py-2 text-start hover:bg-muted"
              >
                <span className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                  <span className="font-medium text-foreground">{m.author?.name}</span>
                  {dateTime(m.created_at)}
                </span>
                <MessageBody text={m.body} mentions={m.mentions} highlight={words} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </ChatSidePane>
  );
}

// ── Beside the chat ──────────────────────────────────────────────────────────

function SidePanel({
  t,
  api,
  muted,
  onMute,
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
  api: ChatApi;
  muted: boolean;
  onMute: (muted: boolean) => void;
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
      <PhotosAndFiles t={t} api={api} chatId={chat.id} />
      <div className="grid gap-2">
        <Button variant="outline" size="sm" icon="plus" onClick={() => onAdd('agent')}>
          {t('side.add', 'Add a person or an agent')}
        </Button>
        <Button variant="ghost" size="sm" icon="notification" aria-pressed={muted} onClick={() => onMute(!muted)}>
          {muted ? t('mute.off', 'Unmute') : t('mute.on', 'Mute')}
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

/** The chat's photos and files, newest first (UC.8). */
function PhotosAndFiles({ t, api, chatId }: { t: TranslateFn; api: ChatApi; chatId: number }) {
  const [items, setItems] = useState<ChatAttachment[] | null>(null);
  useEffect(() => {
    let live = true;
    api.media(chatId, null).then(
      (found) => live && setItems(found),
      () => live && setItems([])
    );
    return () => {
      live = false;
    };
  }, [api, chatId]);
  if (!items || items.length === 0) return null;
  const photos = items.filter((a) => a.kind === 'image').slice(0, 8);
  const files = items.filter((a) => a.kind === 'file').slice(0, 5);
  return (
    <section className="grid gap-2">
      <h2 className="text-xs font-semibold text-muted-foreground">{t('side.media', 'Photos and files')}</h2>
      {photos.length > 0 && (
        <div className="grid grid-cols-4 gap-1">
          {photos.map((a) => (
            <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="block aspect-square overflow-hidden rounded-control-sm bg-muted">
              {/* eslint-disable-next-line @next/next/no-img-element -- a signed-in file from the API */}
              <img src={a.url} alt={a.filename ?? t('file.photo', 'Photo')} loading="lazy" className="size-full object-cover" />
            </a>
          ))}
        </div>
      )}
      {files.map((a) => (
        <a key={a.id} href={a.url} download={a.filename ?? undefined} className="flex items-center gap-2 rounded-control px-1 py-1 text-sm hover:bg-muted">
          <Icon name="document" size={16} />
          <span dir="auto" className="min-w-0 flex-1 truncate text-start">
            {a.filename}
          </span>
          <span className="text-xs text-muted-foreground">{fileSize(a.byte_size)}</span>
        </a>
      ))}
    </section>
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
