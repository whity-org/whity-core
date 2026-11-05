'use client';

import { useCallback, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { IconFileText, IconWorld } from '@tabler/icons-react';
import type { DocumentKind } from '@whity/ui/editor/contract';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { AreaError } from '@whity/ui/page-states';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { DOCUMENTS_WRITE } from '@/lib/capabilities';
import { useToast } from '@/lib/toast-context';
import { createDocumentSource, listDocumentSourcesWithPeople } from '@/lib/documents/sources';

type Page = Awaited<ReturnType<typeof listDocumentSourcesWithPeople>>['rows'][number];
type Filter = 'all' | 'wiki' | 'mine';

/**
 * Notes and wiki pages written in the document editor (A30, `TplNotesWiki`).
 * Rows are filtered server-side (DocumentAccessPolicy); this screen only
 * narrows them to the two kinds it is about and, for "My notes", to the ones
 * the viewer wrote.
 */
export default function DocumentSourcesPage() {
  const t = useTranslation('documents');
  const router = useRouter();
  const { addToast } = useToast();
  const { apiClient, user } = useAuth();
  const dates = useDateDisplay();
  const { hasPermission } = useCapabilities();
  const canWrite = hasPermission(DOCUMENTS_WRITE);
  const [creating, setCreating] = useState<DocumentKind | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
  const viewerId = typeof user?.id === 'number' ? user.id : null;

  const load = useCallback(() => listDocumentSourcesWithPeople(), []);
  const { data: listed, loading, error, refetch } = useFetch(load);
  const rows = listed?.rows ?? null;
  const people = listed?.people;

  // Names for "Shared with". A viewer who cannot list units gets the fallback
  // wording instead of ids. "Written by" comes with the list.
  const directory = useFetch(async () => {
    const units = await fetchAllPages<{ id: number; name: string }>(apiClient, '/api/v1/ous').catch(() => null);
    return {
      units: new Map((units?.items ?? []).map((u) => [u.id, u.name])),
    };
  }, [apiClient]);

  const pages = useMemo(
    () =>
      (rows ?? [])
        .filter((row) => row.kind === 'note' || row.kind === 'wiki')
        .filter((row) =>
          filter === 'wiki' ? row.kind === 'wiki' : filter === 'mine' ? row.kind === 'note' && row.created_by === viewerId : true
        ),
    [rows, filter, viewerId]
  );

  const writer = useCallback(
    (row: Page): string => {
      if (row.created_by === null || row.created_by === undefined) return '—';
      if (row.created_by === viewerId) return t('blockEditor.list.you', 'You');
      return people?.[String(row.created_by)] ?? t('blockEditor.list.former', 'A former member');
    },
    [people, t, viewerId]
  );

  const sharedWith = useCallback(
    (row: Page): string => {
      if (row.scope === 'personal') {
        return row.created_by === viewerId
          ? t('blockEditor.list.sharedOnlyYou', 'Only you')
          : t('blockEditor.list.sharedOnlyWriter', 'Only the person who wrote it');
      }
      if (row.required_permission) {
        return t('blockEditor.list.sharedPermission', 'People with extra access');
      }
      if (row.owner_ou_id !== null && row.owner_ou_id !== undefined) {
        const unit = directory.data?.units.get(row.owner_ou_id);
        return unit
          ? t('blockEditor.list.sharedUnit', 'Everyone at {unit} and below', { unit })
          : t('blockEditor.list.sharedAUnit', 'Everyone in one unit and below');
      }
      return t('blockEditor.list.sharedEveryone', 'Everyone in the workspace');
    },
    [directory.data, t, viewerId]
  );

  const create = async (kind: DocumentKind) => {
    setCreating(kind);
    try {
      const row = await createDocumentSource(kind, '');
      router.push(`/admin/document-sources/${row.id}`);
    } catch {
      addToast(t('blockEditor.list.createFailed', 'The document wasn’t created. Try again.'), 'error');
      setCreating(null);
    }
  };

  const columns = useMemo<DataTableColumn<Page>[]>(
    () => [
      {
        accessorKey: 'title',
        header: t('blockEditor.list.column.page', 'Page'),
        enableSorting: true,
        cell: (row) => (
          <span className="flex min-w-0 items-center gap-3">
            {row.kind === 'wiki' ? (
              <IconWorld className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            ) : (
              <IconFileText className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            )}
            <span className="truncate font-medium text-foreground" dir="auto">
              {row.title || t('blockEditor.untitled', 'Untitled')}
            </span>
            <Badge variant="secondary">
              {row.kind === 'wiki' ? t('blockEditor.list.kindWiki', 'Wiki page') : t('blockEditor.list.kindNote', 'Note')}
            </Badge>
          </span>
        ),
      },
      { id: 'writer', header: t('blockEditor.list.column.writer', 'Written by'), cell: (row) => writer(row) },
      { id: 'shared', header: t('blockEditor.list.column.shared', 'Shared with'), cell: (row) => sharedWith(row) },
      ...dates.dateColumns<Page>([
        {
          id: 'updated_at',
          header: t('blockEditor.list.column.changed', 'Changed'),
          value: (row) => row.updated_at,
          enableSorting: true,
          className: 'text-muted-foreground',
        },
      ]),
    ],
    [dates, sharedWith, t, writer]
  );

  return (
    <div className="grid gap-6">
      <AdminHeader
        title={t('blockEditor.list.title', 'Notes and wiki')}
        description={t(
          'blockEditor.list.description',
          'Notes and wiki pages written in the document editor. They save as you type; only you see a new one until you share it.'
        )}
        action={
          canWrite ? (
            <div className="flex gap-2">
              <Button variant="outline" icon="plus" loading={creating === 'note'} disabled={creating !== null} onClick={() => void create('note')}>
                {t('blockEditor.list.newNote', 'New note')}
              </Button>
              <Button icon="world" loading={creating === 'wiki'} disabled={creating !== null} onClick={() => void create('wiki')}>
                {t('blockEditor.list.newWiki', 'New wiki page')}
              </Button>
            </div>
          ) : undefined
        }
      />

      <Tabs value={filter} onValueChange={(value) => setFilter(value as Filter)}>
        <TabsList aria-label={t('blockEditor.list.filter', 'Which pages to show')}>
          <TabsTrigger value="all">{t('blockEditor.list.filterAll', 'All')}</TabsTrigger>
          <TabsTrigger value="wiki">{t('blockEditor.list.filterWiki', 'Wiki')}</TabsTrigger>
          <TabsTrigger value="mine">{t('blockEditor.list.filterMine', 'My notes')}</TabsTrigger>
        </TabsList>
      </Tabs>

      {error ? (
        <AreaError
          message={t('blockEditor.list.loadFailed', 'The documents didn’t load')}
          retryLabel={t('blockEditor.list.retry', 'Try again')}
          onRetry={() => void refetch()}
        />
      ) : (
        <DataTable
          columns={columns}
          data={pages}
          getRowId={(row) => String(row.id)}
          isLoading={loading}
          onRowClick={(row) => router.push(`/admin/document-sources/${row.id}`)}
          ariaLabel={t('blockEditor.list.title', 'Notes and wiki')}
          pagination={{ pageSize: 25 }}
          emptyState={{
            title: canWrite
              ? t('blockEditor.list.emptyTitle', 'No pages yet')
              : t('blockEditor.list.emptyReaderTitle', 'No pages shared with you yet'),
            description: canWrite
              ? t('blockEditor.list.emptyWriter', 'Write the first wiki page for your team.')
              : t('blockEditor.list.emptyReader', 'Notes and wiki pages shared with you appear here.'),
          }}
        />
      )}
    </div>
  );
}
