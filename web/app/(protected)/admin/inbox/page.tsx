'use client';

/**
 * The inbox — what is awaiting the signed-in person (#978, consuming #881).
 *
 * WHY THIS IS A CONSUMER OF THE #881 SOURCE REGISTRY, NOT A ROUTING SCREEN
 * -----------------------------------------------------------------------
 * The obvious build was `/admin/document-routing/inbox`, reading routing's
 * recipient rows. Everything about the engine says not to.
 *
 * `document_route_recipients` IS an inbox, and #947 rejected giving it a surface
 * of its own in a line — "two inbox surfaces would be the same mistake as two
 * audit trails". #989 acted on that: routing ships
 * `DocumentRoutingInboxSource`, registers it as an #881 source, and owns NO
 * endpoint. `MeInboxApiHandler`'s docblock spells out the consequence — "routing's
 * recipients are a SOURCE, not a surface. The endpoint belongs to the registry;
 * routing contributes to it."
 *
 * A screen that read routing's rows directly would undo that on the client side.
 * The person receiving work from several systems would open the app and find
 * several correct lists whose union exists nowhere — and the moment a second
 * source registers, the aggregate becomes a migration rather than a read.
 *
 * So this screen knows nothing about routing except how to LINK to it. It reads
 * `GET /api/v1/me/inbox/sources`, renders whatever is registered, and treats
 * routing as one source among N. Today N is 1. Nothing here changes when it is 3.
 *
 * WHY IT DOES NOT MERGE THE SOURCES ITSELF
 * ----------------------------------------
 * This is the subtle half, and it is why the screen shows a tab per source
 * rather than one blended list.
 *
 * `MeInboxApiHandler` REQUIRES `?source=`. An unsourced request is a 422 naming
 * the registered keys, and the docblock says exactly why: #881 lists three
 * questions that arise only when sources are aggregated — ordering across
 * heterogeneous sources, per-source failure isolation, and pagination across
 * sources — and they need deciding before an aggregate ships. "Answering an
 * unsourced request would be deciding all three by accident."
 *
 * Merging client-side would decide all three by accident too, just somewhere the
 * server could not see. Interleaving two sources by `timestamp` picks an ordering;
 * dropping a source that errored picks a failure policy and hides it; paginating
 * the union picks a scheme that silently drops items when one source is deeper
 * than the other. Each would then be the de facto contract, established by a
 * screen rather than by #881.
 *
 * So: ONE surface (this page), one source in view at a time, each paginated by
 * its own server. The tab strip is the honest rendering of "several sources, no
 * decided merge" — and it is not a compromise the user pays for while there is
 * one source, because with one source there is no strip at all.
 *
 * When the server aggregate lands, this page gains an "All" tab that calls the
 * same endpoint with no `source`. Every sourced call it already makes keeps
 * working, which is the property `MeInboxApiHandler` was shaped to preserve.
 *
 * THREE TABS (M.43 on the web too, merge map N2, owner 16 Nov 2026)
 * ------------------------------------------------------------------
 * Waiting for me is the list above, plus agents' proposals as one more source
 * (they also stay on Approvals). Mentions and Updates are the person's notices
 * ({@see InboxNotices}). There is no header bell: Updates is where notices live.
 */

import { useCallback, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Badge } from '@whity/ui/badge';
import { Avatar } from '@whity/ui/avatar';
import { Icon } from '@whity/ui/icon';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import { InboxNotices } from '@/components/inbox/inbox-notices';
import type { AgentProposal } from '@/components/agents/proposals';
import {
  routingMeta,
  routingHref,
  type InboxItem,
  type InboxListResponse,
  type InboxSource,
  type InboxSourcesResponse,
} from '@/components/documents/routing-wire';

/** The registry key routing registers under (`InboxSourceRegistry::CORE_DOCUMENT_ROUTING`). */
const DOCUMENT_ROUTING_SOURCE = 'document_routing';

/** Agents' proposals, shown as one more source of Waiting for me (owner, 16 Nov 2026). */
const PROPOSALS_SOURCE = 'agent_proposals';

type InboxTab = 'waiting' | 'mentions' | 'updates';

export default function InboxPage() {
  const t = useTranslation('documents');
  const [tab, setTab] = useState<InboxTab>('waiting');

  // The tabs' counts: Waiting for me from the waiting list, the other two from
  // the unread notices of each kind.
  const counts = useFetch<{ waiting: number; mentions: number; updates: number }>(async () => {
    const [waiting, notices] = await Promise.all([
      api.GET('/api/v1/me/waiting', { params: { query: { limit: 1 } as never } }).catch(() => null),
      api.GET('/api/v1/me/notifications', { params: { query: { per_page: 1 } as never } }).catch(() => null),
    ]);
    const w = (waiting?.data ?? null) as { counts?: { total?: number } } | null;
    const n = (notices?.data ?? null) as { unread?: { mentions?: number; updates?: number } } | null;
    return { waiting: w?.counts?.total ?? 0, mentions: n?.unread?.mentions ?? 0, updates: n?.unread?.updates ?? 0 };
  }, []);
  const refreshCounts = useCallback(() => {
    counts.refetch();
    window.dispatchEvent(new Event(WAITING_CHANGED_EVENT));
  }, [counts]);

  const count = (n: number | undefined) =>
    n !== undefined && n > 0 ? <span className="ms-1.5 text-xs text-muted-foreground tabular-nums">{n}</span> : null;

  return (
    <div>
      <AdminHeader
        title={t('inbox.title', 'Inbox')}
        description={t('inbox.description', 'Work awaiting you, from every part of the platform that sends any.')}
      />
      <Tabs value={tab} onValueChange={(value) => setTab(value as InboxTab)} className="mb-4">
        <TabsList variant="line" aria-label={t('inbox.tabs', 'Inbox')}>
          <TabsTrigger value="waiting" data-testid="inbox-tab-waiting">
            {t('inbox.tab.waiting', 'Waiting for me')}
            {count(counts.data?.waiting)}
          </TabsTrigger>
          <TabsTrigger value="mentions" data-testid="inbox-tab-mentions">
            {t('inbox.tab.mentions', 'Mentions')}
            {count(counts.data?.mentions)}
          </TabsTrigger>
          <TabsTrigger value="updates" data-testid="inbox-tab-updates">
            {t('inbox.tab.updates', 'Updates')}
            {count(counts.data?.updates)}
          </TabsTrigger>
        </TabsList>
      </Tabs>
      {tab === 'waiting' ? <WaitingForMe /> : <InboxNotices tab={tab} onChanged={refreshCounts} />}
    </div>
  );
}

/** Waiting for me: every source of work, one source in view at a time. */
function WaitingForMe() {
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const router = useRouter();

  const [selectedSource, setSelectedSource] = useState<string | null>(null);
  const [openOnly, setOpenOnly] = useState(true);
  const [page, setPage] = useState(1);

  const sources = useFetch<InboxSourcesResponse>(async () => {
    const { data, error, response } = await api.GET('/api/v1/me/inbox/sources');
    if (!response.ok) {
      const body = (error ?? null) as { error?: string } | null;
      throw new Error(body?.error ?? t('inbox.error.sources', 'Your inbox sources could not be loaded.'));
    }
    return data as unknown as InboxSourcesResponse;
  }, []);

  const proposals = useFetch<AgentProposal[]>(async () => {
    const { data, response } = await api.GET('/api/v1/me/agent-proposals', { params: { query: { status: 'waiting' } } });
    if (!response.ok) return [];
    const body = (data ?? null) as { data?: unknown } | null;
    return Array.isArray(body?.data) ? (body.data as AgentProposal[]) : [];
  }, []);
  const waitingProposals = useMemo(() => proposals.data ?? [], [proposals.data]);

  const sourceList = useMemo<InboxSource[]>(() => {
    const registered = sources.data?.data ?? [];
    return waitingProposals.length > 0
      ? [...registered, { key: PROPOSALS_SOURCE, label: '', open_count: waitingProposals.length } as InboxSource]
      : registered;
  }, [sources.data, waitingProposals.length]);

  /**
   * Which source is in view. Derived, not defaulted in an effect: an explicit
   * choice wins, otherwise the first registered key.
   */
  const activeSource = useMemo<string | null>(() => {
    if (selectedSource !== null && sourceList.some((s) => s.key === selectedSource)) {
      return selectedSource;
    }
    return sourceList.length > 0 ? sourceList[0].key : null;
  }, [selectedSource, sourceList]);

  const items = useFetch<InboxListResponse | null>(async () => {
    if (activeSource === null || activeSource === PROPOSALS_SOURCE) return null;

    const { data, error, response } = await api.GET('/api/v1/me/inbox', {
      params: { query: { source: activeSource, page, // `0`, as before: the server reads 0 and false alike, and the wire stays the same.
        ...(openOnly ? {} : { open: 0 as unknown as boolean }) } },
    });
    if (!response.ok) {
      const body = (error ?? null) as { error?: string } | null;
      // Verbatim: a 422 names the registered source keys, which makes it fixable.
      throw new Error(body?.error ?? t('inbox.error.list', 'Your inbox could not be loaded.'));
    }
    return data as unknown as InboxListResponse;
  }, [activeSource, openOnly, page]);

  const rows = items.data?.data ?? [];
  const pagination = items.data?.pagination ?? null;

  const selectSource = (key: string): void => {
    // Page reset happens in the SAME handler as the change that invalidates it.
    setSelectedSource(key);
    setPage(1);
  };

  const sourceLabel = (source: InboxSource): string =>
    source.key === DOCUMENT_ROUTING_SOURCE
      ? t('inbox.source.documentRouting', 'Documents awaiting you')
      : source.key === 'messages'
        ? t('inbox.source.messages', 'Conversations assigned to you')
        : source.key === PROPOSALS_SOURCE
          ? t('inbox.source.proposals', 'Agents asking you')
          : source.key === 'chat_held_lines'
            ? t('inbox.source.heldLines', 'Agents waiting in chats')
            : source.label;
  const activeSourceLabel = sourceList.find((s) => s.key === activeSource);

  /**
   * The state is a word the app composes and translates, from the machine
   * fields the source publishes. The server's own sentence ("Forwarded to you")
   * is English with no key to translate, so it is not shown.
   */
  const stateOf = (row: InboxItem): { label: string; done: boolean } | null => {
    const meta = routingMeta(row);
    if (meta === null) return null;
    if (meta.document_withdrawn) return { label: t('inbox.state.withdrawn', 'Withdrawn'), done: true };
    if (!meta.open) {
      if (meta.closed_verdict === 'approved') return { label: t('inbox.state.approved', 'You approved'), done: true };
      if (meta.closed_verdict === 'rejected') return { label: t('inbox.state.rejected', 'You rejected'), done: true };
      return { label: t('inbox.state.done', 'Done'), done: true };
    }
    if (meta.decision) return { label: t('inbox.state.decision', 'Your decision'), done: false };
    if (meta.arrived_by === 'returned') {
      return { label: t('inbox.state.returned', 'Returned to you'), done: false };
    }
    if (meta.arrived_by === 'sent_back') {
      return { label: t('inbox.state.sentBack', 'Sent back to you for changes'), done: false };
    }
    if (meta.arrived_by === 'noted' || meta.arrived_by === 'acknowledged') {
      return { label: t('inbox.state.info', 'For your information'), done: false };
    }
    return { label: t('inbox.state.review', 'To review'), done: false };
  };

  const columns: DataTableColumn<InboxItem>[] = [
    {
      id: 'title',
      header: t('inbox.column.item', 'Item'),
      cell: (row) => (
        <div className="flex items-center gap-3">
          <span
            aria-hidden="true"
            className="grid size-9 shrink-0 place-items-center rounded-card bg-muted text-muted-foreground"
          >
            <Icon name="document" size={16} />
          </span>
          <div className="min-w-0">
            <p className="truncate font-medium text-foreground">{row.title}</p>
            <p className="truncate text-xs text-muted-foreground">
              {row.subtitle !== null && row.subtitle !== ''
                ? row.subtitle
                : activeSourceLabel !== undefined
                  ? sourceLabel(activeSourceLabel)
                  : ''}
            </p>
          </div>
        </div>
      ),
    },
    {
      id: 'from',
      header: t('inbox.column.from', 'From'),
      cell: (row) => {
        if (row.sender?.kind === 'public') return t('inbox.from.public', 'Public form');
        if (!row.sender?.name) return <span className="text-muted-foreground">—</span>;
        return (
          <div className="flex items-center gap-2">
            <Avatar name={row.sender.name} size={28} />
            <span className="truncate">{row.sender.name}</span>
          </div>
        );
      },
    },
    {
      id: 'status',
      header: t('inbox.column.status', 'State'),
      cell: (row) => {
        const state = stateOf(row);
        if (state === null) return null;
        return <Badge variant={state.done ? 'secondary' : 'warning'}>{state.label}</Badge>;
      },
    },
    // The "Arrived" column goes when this workspace hides dates (#1068).
    ...dates.dateColumns<InboxItem>([
      {
        id: 'timestamp',
        header: t('inbox.column.arrived', 'Arrived'),
        value: (row) => row.timestamp,
        className: 'text-xs text-muted-foreground',
      },
    ]),
  ];

  if (sources.error !== null) {
    return (
      <div>
        <AreaError
          message={sources.error}
          retryLabel={t('inbox.tryAgain', 'Try again')}
          onRetry={() => sources.refetch()}
        />
      </div>
    );
  }

  return (
    <div>
      {/* Tabs per source, only when there is more than one. */}
      {sourceList.length > 1 && (
        <Tabs value={activeSource ?? undefined} onValueChange={selectSource} className="mb-4">
          <TabsList variant="line" aria-label={t('inbox.sources', 'Inbox sources')}>
            {sourceList.map((source) => (
              <TabsTrigger key={source.key} value={source.key}>
                {sourceLabel(source)}
                {source.open_count > 0 && (
                  <span className="ms-1.5 text-xs text-muted-foreground tabular-nums">{source.open_count}</span>
                )}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      )}

      <div className="mb-4">
        <ToggleGroup
          label={t('inbox.filter.label', 'Show')}
          required
          value={openOnly ? 'open' : 'all'}
          options={[
            { value: 'open', label: t('inbox.filter.open', 'Waiting for me') },
            { value: 'all', label: t('inbox.filter.all', 'Including done') },
          ]}
          onChange={(value) => {
            setOpenOnly(value !== 'all');
            setPage(1);
          }}
        />
      </div>

      {activeSource === PROPOSALS_SOURCE ? (
        <ul className="divide-y divide-border rounded-card border border-border bg-card" aria-label={t('inbox.source.proposals', 'Agents asking you')}>
          {waitingProposals.map((proposal) => (
            <li key={proposal.id}>
              <button
                type="button"
                onClick={() => router.push('/admin/approvals')}
                className="flex min-h-12 w-full items-center gap-3 px-4 py-3 text-start hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                data-testid={`inbox-proposal-${proposal.id}`}
              >
                <Avatar name={proposal.agent?.name ?? ''} kind="agent" size={28} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-foreground">{proposal.summary}</span>
                  <span className="block truncate text-sm text-muted-foreground">{proposal.agent?.name ?? ''}</span>
                </span>
                <Badge variant="warning">{t('inbox.state.decision', 'Your decision')}</Badge>
                {dates.dateTime(proposal.createdAt) !== null && (
                  <span className="shrink-0 text-sm text-muted-foreground">{dates.dateTime(proposal.createdAt)}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      ) : items.error !== null ? (
        <AreaError
          message={items.error}
          retryLabel={t('inbox.tryAgain', 'Try again')}
          onRetry={() => items.refetch()}
        />
      ) : sourceList.length === 0 && !sources.loading ? (
        // Nothing on this installation sends work to an inbox: a different fact
        // from "no items", which an empty list would misreport as "up to date".
        <p className="rounded-card border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
          {t(
            'inbox.noSources',
            'Nothing on this installation sends work to an inbox yet, so there is nothing to show.'
          )}
        </p>
      ) : (
        <DataTable<InboxItem>
          columns={columns}
          data={rows}
          getRowId={(row) => row.id}
          isLoading={items.loading || sources.loading}
          ariaLabel={t('inbox.table.label', 'Inbox items')}
          onRowClick={(row) => {
            // Messages (ADR 0016): a conversation assigned to the caller.
            if (row.resource_type === 'conversation' && row.resource_id) {
              router.push(`/chat?c=${encodeURIComponent(row.resource_id)}&k=customer`);
              return;
            }
            // C3: an agent's line held for you opens its chat, where you decide it.
            if (row.resource_type === 'chat' && row.resource_id) {
              router.push(`/chat?c=${encodeURIComponent(row.resource_id)}`);
              return;
            }
            const meta = routingMeta(row);
            if (meta !== null) router.push(routingHref(meta));
          }}
          emptyState={{
            moment: openOnly ? 'all-caught-up' : 'empty-documents',
            once: openOnly ? 'inbox-open' : 'inbox-all',
            title: openOnly
              ? t('inbox.empty.open.title', 'Nothing is waiting for you')
              : t('inbox.empty.all.title', 'Nothing has reached you yet'),
            description: openOnly
              ? t(
                  'inbox.empty.open.description',
                  'When a document is routed to you, it appears here. Switch to “Including done” to see items you have already acted on.'
                )
              : t(
                  'inbox.empty.all.description',
                  'No item from this source has ever been addressed to you.'
                ),
          }}
          pagination={
            pagination !== null
              ? {
                  pageIndex: pagination.page - 1,
                  pageSize: pagination.perPage,
                  pageCount: pagination.totalPages,
                  total: pagination.total,
                  onPaginationChange: (pageIndex: number) => setPage(pageIndex + 1),
                }
              : undefined
          }
        />
      )}
    </div>
  );
}
