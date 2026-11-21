'use client';

/**
 * /inventory/purchase-orders/[id] (WHIT-1234, `TplReceiving`, register IV.12):
 * one purchase order.
 *
 * - Order: ordered and received side by side, line by line, in the unit it is
 *   bought in; a line still short says how many have not arrived, in red. The
 *   totals are in the order's own currency (CU.1-CU.4).
 * - Receiving: each receipt (GRN-0001) with when, who, and what came.
 * - History: who drafted, sent, approved and received it, and when, oldest
 *   first.
 *
 * The one main button follows the state: Send while a draft, Receive once
 * approved. A short line that won't come is closed from its row. The Bill tab
 * joins with Money.
 */

import { use, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { UserText } from '@/components/ui/user-text';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { PrintMenu } from '@/components/inventory/print-menu';
import { PurchaseOrderStatus } from '@/components/inventory/purchase-orders-tab';
import { ReceiveOrderDialog } from '@/components/inventory/receive-order-dialog';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  PURCHASES_READ,
  PURCHASES_WRITE,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryPurchaseOrderLine,
} from '@/lib/inventory/api';

type Tab = 'order' | 'receiving' | 'history';

interface HistoryEntry {
  at: string;
  text: string;
  icon: 'edit' | 'send' | 'check' | 'check-in' | 'close';
}

/** Ordered minus received, in the line's unit; 0 when it all came. */
function shortBy(line: InventoryPurchaseOrderLine): number {
  return Math.max(0, Math.round((Number(line.quantity) - Number(line.received)) * 1000) / 1000);
}

export default function PurchaseOrderPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const orderId = Number(id);
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canWrite = has(PURCHASES_WRITE);
  const [tab, setTab] = useState<Tab>('order');
  const [receiving, setReceiving] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, error, forbidden, refetch } = useFetch(async () => {
    const [order, places, receipts] = await Promise.all([
      inventoryApi.purchaseOrder(orderId),
      inventoryApi.locations({ inactive: true }),
      inventoryApi.operations({ kind: 'receipt', orderId, lines: true }),
    ]);
    return { order, places, receipts };
  }, [orderId]);

  if (!accessLoading && !has(PURCHASES_READ)) {
    return <NoAccess area={t('inventory.po.area', 'purchase orders')} permission={PURCHASES_READ} />;
  }
  if (error) {
    return (
      <LoadProblem
        forbidden={forbidden}
        message={t('inventory.error.purchaseOrder', 'The purchase order could not be loaded.')}
        onRetry={refetch}
        area={t('inventory.po.area', 'purchase orders')}
      />
    );
  }
  if (data === null) {
    return <div className="h-48 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }

  const { order, places, receipts } = data;
  const lines = order.lines ?? [];
  const place = places.find((p) => p.id === order.location_id);
  const placeName = place ? nameIn(place.names, place.name, language) : '';
  const receivable = order.status === 'approved' || order.status === 'partly_done';

  const act = async (what: string, work: () => Promise<unknown>, done: string) => {
    setBusy(what);
    setProblem(null);
    try {
      await work();
      toast.addToast(done, 'success');
      refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setBusy(null);
    }
  };

  // What happened to the order, from its own times and its receipts.
  const history: HistoryEntry[] = [
    {
      at: order.created_at,
      text: order.created_by_name
        ? t('inventory.po.history.created', 'Drafted by {name}', { name: order.created_by_name })
        : t('inventory.po.history.createdNoName', 'Drafted'),
      icon: 'edit' as const,
    },
    ...(order.submitted_at ? [{ at: order.submitted_at, text: t('inventory.po.history.sent', 'Sent for approval'), icon: 'send' as const }] : []),
    ...(order.approved_at
      ? [
          {
            at: order.approved_at,
            text: order.approved_by_name
              ? t('inventory.po.history.approved', 'Approved by {name}', { name: order.approved_by_name })
              : t('inventory.po.history.approvedNoName', 'Approved'),
            icon: 'check' as const,
          },
        ]
      : []),
    ...receipts.map((r) => ({
      at: r.done_at ?? r.created_at,
      text: r.created_by_name
        ? t('inventory.po.history.received', '{number} received by {name}', { number: r.number, name: r.created_by_name })
        : t('inventory.po.history.receivedNoName', '{number} received', { number: r.number }),
      icon: 'check-in' as const,
    })),
    ...(order.status === 'done' && order.done_at
      ? [{ at: order.done_at, text: t('inventory.po.history.done', 'Everything received or closed'), icon: 'check' as const }]
      : []),
    ...(order.cancelled_at ? [{ at: order.cancelled_at, text: t('inventory.po.history.cancelled', 'Cancelled'), icon: 'close' as const }] : []),
  ].sort((a, b) => a.at.localeCompare(b.at));

  const subtitle = [
    t('inventory.po.heading', 'Purchase order {number}', { number: order.number }),
    order.approved_by_name ? t('inventory.po.approvedBy', 'approved by {name}', { name: order.approved_by_name }) : null,
    order.expected_on ? t('inventory.po.expectedOn', 'expected {date}', { date: dates.date(order.expected_on) ?? '' }) : null,
    placeName,
  ]
    .filter((part) => part)
    .join(' · ');

  const action = !canWrite ? (
    <PurchaseOrderStatus order={order} />
  ) : order.status === 'draft' ? (
    <div className="flex flex-wrap gap-2">
      <Button
        variant="outline"
        loading={busy === 'cancel'}
        disabled={busy !== null}
        onClick={() => void act('cancel', () => inventoryApi.cancelPurchaseOrder(order.id), t('inventory.po.cancelled', 'Purchase order cancelled.'))}
      >
        {t('inventory.po.cancel', 'Cancel order')}
      </Button>
      <Button
        loading={busy === 'send'}
        disabled={busy !== null}
        data-testid="po-send"
        onClick={() =>
          void act('send', () => inventoryApi.submitPurchaseOrder(order.id), t('inventory.po.sent', 'Sent. It is approved, or waiting in Approvals.'))
        }
      >
        <Icon name="send" />
        {t('inventory.po.send', 'Send for approval')}
      </Button>
    </div>
  ) : receivable ? (
    <Button onClick={() => setReceiving(true)} data-testid="po-receive">
      <Icon name="check-in" />
      {order.status === 'partly_done' ? t('inventory.po.receiveMore', 'Receive more') : t('inventory.po.receive', 'Receive')}
    </Button>
  ) : (
    <PurchaseOrderStatus order={order} />
  );

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Breadcrumb
            linkComponent={Link}
            items={[{ label: t('inventory.tab.purchases', 'Purchases'), href: '/inventory?tab=purchases' }, { label: order.number }]}
          />
        }
        title={order.contact_name ?? order.number}
        description={subtitle}
        action={
          <div className="flex flex-wrap gap-2">
            {order.status !== 'cancelled' && (
              <PrintMenu print={(lang) => inventoryApi.printPurchaseOrder(order.id, lang)} fileName={order.number} testId="po-print" />
            )}
            {action}
          </div>
        }
      />

      {order.status === 'waiting_approval' && (
        <Alert>
          <AlertDescription>{t('inventory.po.waitingNotice', 'Waiting for approval on its route. It can be received once approved.')}</AlertDescription>
        </Alert>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)}>
        <TabsList>
          <TabsTrigger value="order">{t('inventory.po.tab.order', 'Order')}</TabsTrigger>
          <TabsTrigger value="receiving">
            {t('inventory.po.tab.receiving', 'Receiving')}
            {receipts.length > 0 && <span className="ms-2 font-mono text-xs">{receipts.length}</span>}
          </TabsTrigger>
          <TabsTrigger value="history">{t('inventory.po.tab.history', 'History')}</TabsTrigger>
        </TabsList>
      </Tabs>

      {tab === 'order' ? (
        <section className="overflow-x-auto rounded-lg border border-border" data-testid="po-lines">
          <div className="px-4 pb-2 pt-4">
            <h2 className="font-semibold">
              <bdi dir="ltr">{order.number}</bdi> · <UserText>{order.contact_name}</UserText>
            </h2>
            <p className="text-sm text-muted-foreground">{t('inventory.po.linesHint', 'Ordered and received, line by line')}</p>
          </div>
          <table className="w-full text-sm">
            <thead className="bg-muted/50 text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-3 text-start font-medium">{t('inventory.column.item', 'Item')}</th>
                <th className="px-4 py-3 text-end font-medium">{t('inventory.po.ordered', 'Ordered')}</th>
                <th className="px-4 py-3 text-end font-medium">{t('inventory.po.received', 'Received')}</th>
                <th className="px-4 py-3 text-end font-medium">{t('inventory.po.price', 'Price')}</th>
                <th className="px-4 py-3 text-end font-medium">{t('inventory.po.total', 'Total')}</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {lines.map((line) => {
                const short = shortBy(line);
                return (
                  <tr key={line.id} className="h-12">
                    <td className="px-4 py-2">
                      <UserText className="block">{nameIn(line.item_names, line.item_name, language)}</UserText>
                      <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
                        {line.sku}
                      </bdi>
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-end">
                      <bdi dir="ltr" className="font-mono tabular-nums">
                        {quantityText(line.quantity)} <span className="text-xs text-muted-foreground">{line.unit}</span>
                      </bdi>
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-end">
                      <bdi dir="ltr" className={`font-mono tabular-nums ${receivable && short > 0 && Number(line.received) > 0 ? 'text-destructive' : ''}`}>
                        {quantityText(line.received)}
                      </bdi>
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-end">
                      <Money minor={line.price_minor} currency={order.currency} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-end">
                      <Money minor={line.line_total_minor} currency={order.currency} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-2 text-end">
                      {line.is_closed ? (
                        <Badge variant="outline">{t('inventory.po.closed', 'Closed short')}</Badge>
                      ) : line.is_over ? (
                        <Badge variant="warning">{t('inventory.po.overBadge', 'More than ordered')}</Badge>
                      ) : short === 0 && Number(line.received) > 0 ? (
                        <Badge variant="success">{t('inventory.po.matches', 'Matches')}</Badge>
                      ) : receivable && Number(line.received) > 0 ? (
                        <span className="inline-flex items-center gap-2">
                          <Badge variant="destructive">
                            {t('inventory.po.notReceived', '{count} not received', { count: quantityText(String(short)) })}
                          </Badge>
                          {canWrite && (
                            <Button
                              variant="outline"
                              size="sm"
                              loading={busy === `close-${line.id}`}
                              disabled={busy !== null}
                              onClick={() =>
                                void act(
                                  `close-${line.id}`,
                                  () => inventoryApi.closePurchaseOrderLine(order.id, line.id),
                                  t('inventory.po.lineClosed', 'Closed: the rest won’t come.')
                                )
                              }
                            >
                              {t('inventory.po.close', 'Won’t come')}
                            </Button>
                          )}
                        </span>
                      ) : null}
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot className="border-t border-border text-sm">
              {order.tax_minor > 0 && (
                <>
                  <tr>
                    <td className="px-4 py-2 text-muted-foreground" colSpan={4}>
                      {t('inventory.po.subtotal', 'Subtotal')}
                    </td>
                    <td className="px-4 py-2 text-end">
                      <Money minor={order.subtotal_minor} currency={order.currency} />
                    </td>
                    <td />
                  </tr>
                  <tr>
                    <td className="px-4 py-2 text-muted-foreground" colSpan={4}>
                      {t('inventory.po.tax', 'Tax')}
                    </td>
                    <td className="px-4 py-2 text-end">
                      <Money minor={order.tax_minor} currency={order.currency} />
                    </td>
                    <td />
                  </tr>
                </>
              )}
              <tr className="font-semibold">
                <td className="px-4 py-3" colSpan={4}>
                  {t('inventory.po.total', 'Total')}
                </td>
                <td className="px-4 py-3 text-end">
                  <Money minor={order.total_minor} currency={order.currency} />
                </td>
                <td />
              </tr>
            </tfoot>
          </table>
          {order.notes !== '' && (
            <p className="border-t border-border px-4 py-3 text-sm">
              <UserText>{order.notes}</UserText>
            </p>
          )}
        </section>
      ) : tab === 'history' ? (
        <ol className="divide-y divide-border rounded-lg border border-border" data-testid="po-history">
          {history.map((entry, i) => (
            <li key={`${entry.at}-${i}`} className="flex items-center gap-3 px-4 py-3 text-sm">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Icon name={entry.icon} size={16} />
              </span>
              <span className="min-w-0 flex-1">{entry.text}</span>
              <span className="text-xs text-muted-foreground">{dates.dateTime(entry.at) ?? ''}</span>
            </li>
          ))}
        </ol>
      ) : (
        <div className="space-y-4" data-testid="po-receipts">
          {receipts.length === 0 ? (
            <p className="rounded-lg border border-border p-8 text-center text-sm text-muted-foreground">
              {t('inventory.po.noReceipts', 'Nothing has arrived yet.')}
            </p>
          ) : (
            receipts.map((receipt) => (
              <section key={receipt.id} className="rounded-lg border border-border p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <h2 className="font-semibold">
                    {t('inventory.po.receivedAt', 'Received {when}, {place}', {
                      when: dates.dateTime(receipt.done_at ?? receipt.created_at) ?? '',
                      place: placeName,
                    })}
                  </h2>
                  <PrintMenu
                    print={(lang) => inventoryApi.printReceipt(receipt.id, lang)}
                    fileName={receipt.number}
                    size="sm"
                    testId="po-receipt-print"
                  />
                </div>
                <p className="text-sm text-muted-foreground">
                  <bdi dir="ltr" className="font-mono">
                    {receipt.number}
                  </bdi>
                  {receipt.created_by_name ? <> · {t('inventory.po.by', 'by {name}', { name: receipt.created_by_name })}</> : null}
                  {receipt.note ? (
                    <>
                      {' · '}
                      <UserText>{receipt.note}</UserText>
                    </>
                  ) : null}
                </p>
                <dl className="mt-3 divide-y divide-border text-sm">
                  {(receipt.lines ?? []).map((l) => (
                    <div key={l.id} className="grid grid-cols-[12rem_1fr] gap-3 py-2.5">
                      <dt className="text-muted-foreground">{nameIn(l.item_names, l.item_name, language)}</dt>
                      <dd>
                        <bdi dir="ltr" className="font-mono tabular-nums">
                          {quantityText(l.entered_quantity ?? l.quantity ?? '0')} {l.entered_unit ?? l.unit}
                        </bdi>
                      </dd>
                    </div>
                  ))}
                </dl>
              </section>
            ))
          )}
        </div>
      )}

      {receiving && (
        <ReceiveOrderDialog
          order={order}
          onOpenChange={setReceiving}
          onDone={(next) => {
            setReceiving(false);
            toast.addToast(
              next.status === 'done'
                ? t('inventory.po.allReceived', 'Everything has arrived. The stock is in {place}.', { place: placeName })
                : t('inventory.po.partReceived', 'Received. The stock is in {place}.', { place: placeName }),
              'success'
            );
            refetch();
          }}
        />
      )}
    </div>
  );
}
