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
 */

import { useMemo, useState } from 'react';
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
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import {
  routingMeta,
  type InboxItem,
  type InboxListResponse,
  type InboxSource,
  type InboxSourcesResponse,
} from '@/components/documents/routing-wire';

/** The registry key routing registers under (`InboxSourceRegistry::CORE_DOCUMENT_ROUTING`). */
const DOCUMENT_ROUTING_SOURCE = 'document_routing';

export default function InboxPage() {
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const { apiClient } = useAuth();
  const router = useRouter();

  const [selectedSource, setSelectedSource] = useState<string | null>(null);
  const [openOnly, setOpenOnly] = useState(true);
  const [page, setPage] = useState(1);

  const sources = useFetch<InboxSourcesResponse>(async () => {
    const response = await apiClient('/api/v1/me/inbox/sources');
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      throw new Error(body?.error ?? t('inbox.error.sources', 'Your inbox sources could not be loaded.'));
    }
    return (await response.json()) as InboxSourcesResponse;
  }, [apiClient]);

  const sourceList = useMemo<InboxSource[]>(() => sources.data?.data ?? [], [sources.data]);

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
    if (activeSource === null) return null;

    const params = new URLSearchParams({ source: activeSource, page: String(page) });
    if (!openOnly) params.set('open', '0');

    const response = await apiClient(`/api/v1/me/inbox?${params.toString()}`);
    if (!response.ok) {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      // Verbatim: a 422 names the registered source keys, which makes it fixable.
      throw new Error(body?.error ?? t('inbox.error.list', 'Your inbox could not be loaded.'));
    }
    return (await response.json()) as InboxListResponse;
  }, [apiClient, activeSource, openOnly, page]);

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
    if (!meta.open) {
      if (meta.closed_verdict === 'approved') return { label: t('inbox.state.approved', 'You approved'), done: true };
      if (meta.closed_verdict === 'rejected') return { label: t('inbox.state.rejected', 'You rejected'), done: true };
      return { label: t('inbox.state.done', 'Done'), done: true };
    }
    if (meta.decision) return { label: t('inbox.state.decision', 'Your decision'), done: false };
    if (meta.arrived_by === 'returned') {
      return { label: t('inbox.state.returned', 'Returned to you'), done: false };
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

  const header = (
    <AdminHeader
      title={t('inbox.title', 'Inbox')}
      description={t('inbox.description', 'Work awaiting you, from every part of the platform that sends any.')}
    />
  );

  if (sources.error !== null) {
    return (
      <div>
        {header}
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
      {header}

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

      {items.error !== null ? (
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
            const meta = routingMeta(row);
            if (meta !== null) router.push(`/admin/document-library/${meta.document_id}?tab=routing`);
          }}
          emptyState={{
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
