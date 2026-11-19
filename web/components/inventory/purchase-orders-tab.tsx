'use client';

/**
 * The Purchases tab of /inventory (WHIT-1234, register IV.12, D10: Purchases
 * sits inside Inventory until Money arrives): purchase orders newest first,
 * each with its supplier, where it is received, when it is expected, its total
 * in its own currency, how much has arrived, and its state.
 */

import { useRouter } from 'next/navigation';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { UserText } from '@/components/ui/user-text';
import { Badge } from '@whity/ui/badge';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { nameIn, type InventoryLocation, type InventoryPurchaseOrder } from '@/lib/inventory/api';

export function PurchaseOrderStatus({ order }: { order: Pick<InventoryPurchaseOrder, 'status'> }) {
  const t = useTranslation('admin');
  switch (order.status) {
    case 'draft':
      return <Badge variant="outline">{t('inventory.status.draft', 'Draft')}</Badge>;
    case 'waiting_approval':
      return <Badge variant="warning">{t('inventory.status.waitingShort', 'Waiting')}</Badge>;
    case 'approved':
      return <Badge variant="info">{t('inventory.po.status.approved', 'Approved')}</Badge>;
    case 'partly_done':
      return <Badge variant="info">{t('inventory.po.status.partly', 'Partly received')}</Badge>;
    case 'done':
      return <Badge variant="success">{t('inventory.po.status.done', 'Received')}</Badge>;
    default:
      return <Badge variant="outline">{t('inventory.status.cancelled', 'Cancelled')}</Badge>;
  }
}

interface Loaded {
  data: InventoryPurchaseOrder[] | null;
  loading: boolean;
  error: string | null;
  forbidden: boolean;
  refetch: () => void;
}

export function PurchaseOrdersTab({ orders, places }: { orders: Loaded; places: InventoryLocation[] }) {
  const t = useTranslation('admin');
  const router = useRouter();
  const dates = useDateDisplay();
  const language = useLanguageCode();
  const placeName = (id: number) => {
    const place = places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : '–';
  };

  const columns: DataTableColumn<InventoryPurchaseOrder>[] = [
    {
      id: 'number',
      header: t('inventory.column.number', 'Number'),
      cell: (row) => (
        <bdi dir="ltr" className="font-mono text-sm">
          {row.number}
        </bdi>
      ),
    },
    { id: 'supplier', header: t('inventory.po.supplier', 'Supplier'), cell: (row) => <UserText>{row.contact_name ?? ''}</UserText> },
    { id: 'place', header: t('inventory.po.receiveInto', 'Received into'), cell: (row) => placeName(row.location_id) },
    {
      id: 'expected',
      header: t('inventory.po.expected', 'Expected on'),
      cell: (row) => (row.expected_on ? (dates.date(row.expected_on) ?? '') : '–'),
    },
    {
      id: 'total',
      header: t('inventory.po.total', 'Total'),
      cell: (row) => <Money minor={row.total_minor} currency={row.currency} />,
    },
    {
      id: 'received',
      header: t('inventory.po.received', 'Received'),
      cell: (row) =>
        row.status === 'draft' || row.status === 'waiting_approval' || row.status === 'cancelled' ? (
          <span className="text-muted-foreground">–</span>
        ) : (
          <span className="font-mono tabular-nums" dir="ltr">
            {row.received_percent ?? 0}%
          </span>
        ),
    },
    { id: 'state', header: '', cell: (row) => <PurchaseOrderStatus order={row} /> },
  ];

  if (orders.error) {
    return (
      <LoadProblem
        forbidden={orders.forbidden}
        message={t('inventory.error.purchaseOrders', 'The purchase orders could not be loaded.')}
        onRetry={orders.refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }
  return (
    <DataTable
      columns={columns}
      data={orders.data ?? []}
      isLoading={orders.loading && orders.data === null}
      getRowId={(row) => String(row.id)}
      onRowClick={(row) => router.push(`/inventory/purchase-orders/${row.id}`)}
      emptyState={{
        title: t('inventory.po.empty', 'No purchase orders yet'),
        description: t('inventory.po.emptyHint', 'Order from your suppliers here, then receive what arrives against the order.'),
      }}
    />
  );
}
