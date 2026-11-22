'use client';

/**
 * /inventory/adjustments (WHIT-1233, `TplAdjustments`, register N5, IV.14):
 * every change to stock that isn't a sale, a delivery or a transfer.
 *
 * - Numbers: waste this week at cost, what waits for approval, the most
 *   wasted item, and what counts found. (Waste as a share of sales joins when
 *   core has sales, P5.)
 * - One row per line: when, where, item, the change (losses red), the reason
 *   from the workspace's list, value at cost, who, and its state.
 * - Beside it: waste this week by reason, and the rules (the approval value
 *   and who approves). The end-of-day reminder joins with the phone (P2b
 *   mobile).
 *
 * Record waste is the one main button.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { Chart } from '@/components/lazy-heavy';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { UserText } from '@/components/ui/user-text';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { NobodyCanApprove } from '@/components/inventory/nobody-can-approve';
import { OperationStatus } from '@/components/inventory/operation-status';
import { RecordWasteDialog } from '@/components/inventory/record-waste-dialog';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useReasons } from '@/lib/inventory/use-reasons';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { KpiStrip } from '@whity/ui/kpi-strip';
import { Money } from '@whity/ui/money/money';
import { currencyExponent, moneyText, parseMoney } from '@whity/ui/money/currency';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  INVENTORY_READ,
  STOCK_ADJUST,
  inventoryApi,
  nameIn,
  quantityText,
  type InventoryOperation,
  type InventoryOperationLine,
} from '@/lib/inventory/api';

const WEEK_MS = 7 * 24 * 60 * 60 * 1000;

/** An amount kept left to right inside a sentence in either language (IV.21): "$25.00", never "25.00$". */
const ltr = (text: string) => `\u2066${text}\u2069`;

interface Row {
  key: string;
  operation: InventoryOperation;
  line: InventoryOperationLine;
  reason: string;
}

/** A stored timestamp (UTC, "2026-11-19 08:20:00") as a time. */
function timeOf(value: string | null | undefined): number {
  if (!value) return 0;
  const parsed = Date.parse(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
  return Number.isNaN(parsed) ? 0 : parsed;
}

export default function WasteAndAdjustmentsPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canAdjust = has(STOCK_ADJUST);
  const { reasons, reasonName } = useReasons();
  const workspaceCurrency = useWorkspaceCurrency();
  const [recording, setRecording] = useState(false);

  const { data, error, forbidden, loading, refetch } = useFetch(async () => {
    const [adjustments, counts, places, settings] = await Promise.all([
      inventoryApi.operations({ kind: 'adjustment', lines: true, limit: 100 }),
      inventoryApi.operations({ kind: 'count', status: 'done', lines: true, limit: 50 }),
      inventoryApi.locations({ inactive: true }),
      inventoryApi.settings().catch(() => null),
    ]);
    // "This week" counts back from when the page loaded.
    return { adjustments, counts, places, settings, since: Date.now() - WEEK_MS };
  }, []);

  const waste = useMemo(() => new Set(reasons.filter((r) => r.is_waste).map((r) => r.code)), [reasons]);

  const rows: Row[] = useMemo(
    () =>
      (data?.adjustments ?? []).flatMap((op) =>
        (op.lines ?? []).map((line) => ({
          key: `${op.id}-${line.id}`,
          operation: op,
          line,
          reason: (line.reason_code ?? '') !== '' ? (line.reason_code ?? '') : (op.reason_code ?? ''),
        }))
      ),
    [data]
  );

  const numbers = useMemo(() => {
    const since = data?.since ?? 0;
    const thisWeek = rows.filter((r) => r.operation.status === 'done' && timeOf(r.operation.done_at) >= since);
    const wasted = thisWeek.filter((r) => waste.has(r.reason) && Number(r.line.change ?? 0) < 0 && r.line.value_minor != null);
    const currency = wasted.find((r) => r.line.currency)?.line.currency ?? data?.adjustments.find((o) => o.currency)?.currency ?? null;
    const wasteMinor = wasted.reduce((sum, r) => sum + (r.line.value_minor ?? 0), 0);
    const byItem = new Map<string, { minor: number; place: number | null }>();
    for (const r of wasted) {
      const label = nameIn(r.line.item_names, r.line.item_name, language);
      const current = byItem.get(label) ?? { minor: 0, place: r.operation.location_id ?? null };
      byItem.set(label, { minor: current.minor + (r.line.value_minor ?? 0), place: current.place });
    }
    const most = [...byItem.entries()].sort((a, b) => b[1].minor - a[1].minor)[0] ?? null;
    const byReason = new Map<string, number>();
    for (const r of wasted) byReason.set(r.reason, (byReason.get(r.reason) ?? 0) + (r.line.value_minor ?? 0));
    const waiting = (data?.adjustments ?? []).filter((o) => o.status === 'waiting_approval');
    const found = (data?.counts ?? [])
      .filter((c) => timeOf(c.done_at) >= since)
      .flatMap((c) => c.lines ?? [])
      .reduce((sum, l) => sum + (Number(l.difference ?? 0) > 0 ? (l.value_minor ?? 0) : 0), 0);
    return { currency, wasteMinor, most, byReason, waiting, found };
  }, [rows, waste, data, language]);

  if (!accessLoading && !has(INVENTORY_READ)) {
    return <NoAccess area={t('inventory.area', 'inventory')} permission={INVENTORY_READ} />;
  }

  const placeName = (id: number | null | undefined) => {
    const place = data?.places.find((p) => p.id === id);
    return place ? nameIn(place.names, place.name, language) : '–';
  };
  const money = (minor: number) => (numbers.currency ? moneyText(minor, numbers.currency) : '–');
  const firstWaiting = numbers.waiting[0];
  // Waiting with nobody to approve them: nothing changed in stock (WHIT-1301).
  const stuck = numbers.waiting.filter((o) => o.nobody_can_approve);
  const sendAgain = async (id: number) => {
    try {
      const sent = await inventoryApi.submitAdjustment(id);
      toast.addToast(
        sent.nobody_can_approve
          ? t('inventory.approval.stillNobody', 'Still nobody can approve it, so it keeps waiting.')
          : t('inventory.adjustWaiting', 'Sent for approval. The stock changes once it is approved.'),
        sent.nobody_can_approve ? 'warning' : 'success'
      );
    } catch (e) {
      toast.addToast(e instanceof Error ? e.message : t('inventory.approval.sendFailed', 'It could not be sent again.'), 'error');
    }
    refetch();
  };
  // The approval value is stored as an amount ("25"); shown as money in the
  // workspace's currency with its decimals (CU.1-CU.4): $25.00, JOD 25.000.
  const limitText = (amount: string) => {
    try {
      return ltr(moneyText(parseMoney(amount, workspaceCurrency), workspaceCurrency));
    } catch {
      return ltr(amount);
    }
  };
  // "19 Nov 21:05": short enough for the column, as on the card.
  const when = (value: string | null | undefined) =>
    [dates.calendarLabel(value, 'dayMonth'), dates.time(value)].filter((part) => part).join(' ');

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Breadcrumb
            linkComponent={Link}
            items={[{ label: t('inventory.title', 'Inventory'), href: '/inventory' }, { label: t('inventory.waste.page', 'Waste and adjustments') }]}
          />
        }
        title={t('inventory.waste.page', 'Waste and adjustments')}
        description={t('inventory.waste.pageHint', 'Every change to stock that isn’t a sale, a delivery or a transfer')}
        action={
          canAdjust && (data?.places.length ?? 0) > 0 ? (
            <Button onClick={() => setRecording(true)} data-testid="waste-record">
              <Icon name="plus" />
              {t('inventory.waste.title', 'Record waste')}
            </Button>
          ) : undefined
        }
      />

      {error ? (
        <LoadProblem
          forbidden={forbidden}
          message={t('inventory.error.adjustments', 'The waste and adjustments could not be loaded.')}
          onRetry={refetch}
          area={t('inventory.area', 'inventory')}
        />
      ) : (
        <>
          {data && (
            // Wrapped: the strip's own m-0 would cancel the page's spacing below it.
            <div>
              <KpiStrip
              items={[
                { label: t('inventory.waste.kpiWeek', 'This week, at cost'), value: money(numbers.wasteMinor) },
                {
                  label: t('inventory.waste.kpiWaiting', 'Waiting for approval'),
                  value: String(numbers.waiting.length),
                  note:
                    firstWaiting !== undefined
                      ? [firstWaiting.lines?.[0] ? nameIn(firstWaiting.lines[0].item_names, firstWaiting.lines[0].item_name, language) : null, firstWaiting.value_minor != null && firstWaiting.currency ? ltr(moneyText(firstWaiting.value_minor, firstWaiting.currency)) : null]
                          .filter((part) => part)
                          .join(', ')
                      : undefined,
                },
                {
                  label: t('inventory.waste.kpiMost', 'Most wasted'),
                  value: numbers.most?.[0] ?? '–',
                  note: numbers.most ? placeName(numbers.most[1].place) : undefined,
                },
                {
                  label: t('inventory.waste.kpiFound', 'Found in counts'),
                  value: numbers.found > 0 ? `+${money(numbers.found)}` : money(0),
                },
              ]}
              />
            </div>
          )}

          {stuck.map((o) => (
            <NobodyCanApprove
              key={o.id}
              number={o.number}
              onSendAgain={canAdjust ? () => sendAgain(o.id) : undefined}
              testId={`nobody-can-approve-${o.id}`}
            />
          ))}

          <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full text-sm" data-testid="waste-rows">
                <thead className="bg-muted/50 text-xs text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-start font-medium">{t('inventory.column.when', 'When')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('inventory.column.where', 'Where')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('inventory.column.item', 'Item')}</th>
                    <th className="px-4 py-3 text-end font-medium">{t('inventory.column.change', 'Change')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('inventory.column.reason', 'Reason')}</th>
                    <th className="px-4 py-3 text-end font-medium">{t('inventory.column.value', 'Value')}</th>
                    <th className="px-4 py-3 text-start font-medium">{t('inventory.column.by', 'By')}</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {rows.map(({ key, operation, line, reason }) => (
                    <tr key={key} className="h-12 align-top">
                      <td className="whitespace-nowrap px-4 py-3">{when(operation.done_at ?? operation.created_at)}</td>
                      <td className="px-4 py-3">{placeName(operation.location_id)}</td>
                      <td className="px-4 py-3 font-medium">
                        <UserText>{nameIn(line.item_names, line.item_name, language)}</UserText>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-end">
                        <bdi dir="ltr" className={`font-mono tabular-nums ${Number(line.change ?? 0) < 0 ? 'text-destructive' : ''}`}>
                          {Number(line.change ?? 0) > 0 ? '+' : ''}
                          {quantityText(line.change ?? '0')} <span className="text-xs text-muted-foreground">{line.unit}</span>
                        </bdi>
                      </td>
                      <td className="px-4 py-3">
                        {reasonName(reason)}
                        {(line.note || operation.note) && (
                          <div className="text-xs text-muted-foreground">
                            <UserText>{line.note || operation.note}</UserText>
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-end">
                        {line.value_minor != null && line.currency ? <Money minor={line.value_minor} currency={line.currency} /> : <span className="text-muted-foreground">–</span>}
                      </td>
                      <td className="px-4 py-3">
                        <UserText>{operation.created_by_name ?? '–'}</UserText>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3">
                        <OperationStatus operation={operation} short />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!loading && rows.length === 0 && (
                <div className="p-8 text-center">
                  <p className="font-medium">{t('inventory.waste.empty', 'No waste or adjustments yet')}</p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {t('inventory.waste.emptyHint', 'Record what is thrown away at the end of the day, with why.')}
                  </p>
                </div>
              )}
            </div>

            <div className="space-y-6">
              <section className="space-y-2 rounded-lg border border-border p-5" data-testid="waste-chart">
                <h2 className="font-semibold">{t('inventory.waste.chart', 'Waste this week')}</h2>
                <p className="text-sm text-muted-foreground">{t('inventory.waste.chartHint', 'At cost, by reason')}</p>
                {numbers.byReason.size === 0 ? (
                  <p className="py-6 text-sm text-muted-foreground">{t('inventory.waste.chartNone', 'Nothing wasted this week.')}</p>
                ) : (
                  <Chart
                    type="bar"
                    data={[...numbers.byReason.entries()].map(([code, minor]) => ({
                      label: reasonName(code),
                      value: minor / 10 ** (numbers.currency ? currencyExponent(numbers.currency) : 2),
                    }))}
                    xKey="label"
                    series={[{ key: 'value', label: t('inventory.waste.chartHint', 'At cost, by reason'), color: 1 }]}
                    height={160}
                    legend={false}
                  />
                )}
              </section>
              <section className="space-y-2 rounded-lg border border-border p-5" data-testid="waste-rules">
                <h2 className="font-semibold">{t('inventory.waste.rules', 'Rules')}</h2>
                <dl className="divide-y divide-border text-sm">
                  <div className="grid grid-cols-[8rem_1fr] gap-3 py-2.5">
                    <dt className="text-muted-foreground">{t('inventory.waste.needsApproval', 'Needs approval')}</dt>
                    <dd>
                      {data?.settings?.approve_adjustments_above
                        ? t('inventory.waste.over', 'Over {amount} a line, at the buying price', {
                            amount: limitText(data.settings.approve_adjustments_above),
                          })
                        : t('inventory.waste.never', 'Never')}
                    </dd>
                  </div>
                  <div className="grid grid-cols-[8rem_1fr] gap-3 py-2.5">
                    <dt className="text-muted-foreground">{t('inventory.waste.approvedBy', 'Approved by')}</dt>
                    <dd>
                      {data?.settings?.adjustment_route_template_id
                        ? t('inventory.waste.byRoute', 'The route chosen in Flows')
                        : t('inventory.waste.byManagers', 'The people who run inventory')}
                    </dd>
                  </div>
                </dl>
              </section>
            </div>
          </div>
        </>
      )}

      {recording && data && (
        <RecordWasteDialog
          open={recording}
          onOpenChange={setRecording}
          places={data.places.filter((p) => p.is_active)}
          reasons={reasons}
          onDone={(adjustment) => {
            setRecording(false);
            toast.addToast(
              adjustment.nobody_can_approve
                ? t('inventory.approval.nobodyToast', 'Saved, but nobody can approve it yet, so the stock hasn’t changed. Someone who runs the inventory settings has been told.')
                : adjustment.status === 'waiting_approval'
                  ? t('inventory.adjustWaiting', 'Sent for approval. The stock changes once it is approved.')
                  : t('inventory.waste.recorded', 'Waste recorded.'),
              adjustment.nobody_can_approve ? 'warning' : 'success'
            );
            refetch();
          }}
        />
      )}
    </div>
  );
}
