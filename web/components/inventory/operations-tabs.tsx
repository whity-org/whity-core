'use client';

/**
 * The Counts and Transfers tabs of /inventory (WHIT-1233, `TplInventory`):
 * newest first, each with its number, places, how far it got and who did it.
 * A count opens its page to keep counting; a transfer on the way opens to be
 * received.
 */

import { useRouter } from 'next/navigation';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { OperationStatus } from '@/components/inventory/operation-status';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { nameIn, type InventoryLocation, type InventoryOperation } from '@/lib/inventory/api';

interface Loaded {
  data: InventoryOperation[] | null;
  loading: boolean;
  error: string | null;
  forbidden: boolean;
  refetch: () => void;
}

function usePlaceName(places: InventoryLocation[]) {
  const language = useLanguageCode();
  return (id: number | null | undefined) => {
    const place = places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : '–';
  };
}

export function CountsTab({ counts, places }: { counts: Loaded; places: InventoryLocation[] }) {
  const t = useTranslation('admin');
  const router = useRouter();
  const dates = useDateDisplay();
  const placeName = usePlaceName(places);

  const columns: DataTableColumn<InventoryOperation>[] = [
    {
      id: 'number',
      header: t('inventory.column.number', 'Number'),
      cell: (row) => (
        <bdi dir="ltr" className="font-mono text-sm">
          {row.number}
        </bdi>
      ),
    },
    { id: 'place', header: t('inventory.column.place', 'Place'), cell: (row) => placeName(row.location_id) },
    {
      id: 'progress',
      header: t('inventory.column.counted', 'Counted'),
      cell: (row) => (
        <span className="font-mono tabular-nums" dir="ltr">
          {row.counted_count ?? 0} / {row.line_count ?? 0}
        </span>
      ),
    },
    {
      id: 'by',
      header: t('inventory.column.by', 'By'),
      cell: (row) => row.created_by_name ?? '–',
    },
    {
      id: 'when',
      header: t('inventory.column.started', 'Started'),
      cell: (row) => dates.dateTime(row.created_at) ?? '',
    },
    { id: 'state', header: '', cell: (row) => <OperationStatus operation={row} /> },
  ];

  if (counts.error) {
    return (
      <LoadProblem
        forbidden={counts.forbidden}
        message={t('inventory.error.counts', 'The counts could not be loaded.')}
        onRetry={counts.refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }
  return (
    <DataTable
      columns={columns}
      data={counts.data ?? []}
      isLoading={counts.loading && counts.data === null}
      getRowId={(row) => String(row.id)}
      onRowClick={(row) => router.push(`/inventory/counts/${row.id}`)}
      emptyState={{
        title: t('inventory.counts.empty', 'No counts yet'),
        description: t('inventory.counts.emptyHint', 'Count a place to set its stock right. Differences are kept in the history.'),
      }}
    />
  );
}

export function TransfersTab({
  transfers,
  places,
  onOpen,
}: {
  transfers: Loaded;
  places: InventoryLocation[];
  onOpen: (transfer: InventoryOperation) => void;
}) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const placeName = usePlaceName(places);

  const columns: DataTableColumn<InventoryOperation>[] = [
    {
      id: 'number',
      header: t('inventory.column.number', 'Number'),
      cell: (row) => (
        <bdi dir="ltr" className="font-mono text-sm">
          {row.number}
        </bdi>
      ),
    },
    {
      id: 'route',
      header: t('inventory.column.route', 'From → to'),
      cell: (row) => `${placeName(row.from_location_id)} → ${placeName(row.to_location_id)}`,
    },
    {
      id: 'lines',
      header: t('inventory.column.items', 'Items'),
      cell: (row) => <span className="font-mono tabular-nums">{row.line_count ?? 0}</span>,
    },
    { id: 'by', header: t('inventory.column.by', 'By'), cell: (row) => row.created_by_name ?? '–' },
    {
      id: 'when',
      header: t('inventory.column.sent', 'Sent'),
      cell: (row) => (row.sent_at ? (dates.dateTime(row.sent_at) ?? '') : '–'),
    },
    { id: 'state', header: '', cell: (row) => <OperationStatus operation={row} /> },
  ];

  if (transfers.error) {
    return (
      <LoadProblem
        forbidden={transfers.forbidden}
        message={t('inventory.error.transfers', 'The transfers could not be loaded.')}
        onRetry={transfers.refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }
  return (
    <DataTable
      columns={columns}
      data={transfers.data ?? []}
      isLoading={transfers.loading && transfers.data === null}
      getRowId={(row) => String(row.id)}
      onRowClick={(row) => (row.status === 'in_transit' ? onOpen(row) : undefined)}
      emptyState={{
        title: t('inventory.transfers.empty', 'No transfers yet'),
        description: t('inventory.transfers.emptyHint', 'Move stock between places with Transfer.'),
      }}
    />
  );
}
