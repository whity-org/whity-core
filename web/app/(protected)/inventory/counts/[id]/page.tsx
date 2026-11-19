'use client';

/**
 * /inventory/counts/[id] (WHIT-1233, register IV.15): one count at one place.
 *
 * While counting, each line takes what is on the shelf; Save keeps what was
 * typed, and the stock's expected figure is taken as each line is saved, so
 * sales during a long count are not differences. In a blind count, people who
 * can't adjust stock don't see expected. Finish count is the one main button:
 * differences post as count corrections, or wait for approval when one is
 * worth more than the workspace's value. Once done, the page shows what
 * differed.
 */

import { use, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { Input } from '@/components/ui/input';
import { OperationStatus } from '@/components/inventory/operation-status';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  INVENTORY_READ,
  STOCK_COUNT,
  inventoryApi,
  inventoryErrorText,
  nameIn,
  quantityText,
  type InventoryOperationLine,
} from '@/lib/inventory/api';

export default function InventoryCountPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const countId = Number(id);
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canCount = has(STOCK_COUNT);
  const [typed, setTyped] = useState<Record<number, string>>({});
  const [working, setWorking] = useState<'save' | 'finish' | 'cancel' | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, error, forbidden, refetch } = useFetch(async () => {
    const [count, places] = await Promise.all([inventoryApi.operation(countId), inventoryApi.locations({ inactive: true })]);
    return { count, places };
  }, [countId]);

  if (!accessLoading && !has(INVENTORY_READ)) {
    return <NoAccess area={t('inventory.area', 'inventory')} permission={INVENTORY_READ} />;
  }
  if (error) {
    return (
      <LoadProblem
        forbidden={forbidden}
        message={t('inventory.error.count', 'The count could not be loaded.')}
        onRetry={refetch}
        area={t('inventory.area', 'inventory')}
      />
    );
  }
  if (data === null) {
    return <div className="h-48 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }

  const { count, places } = data;
  const lines = count.lines ?? [];
  const place = places.find((p) => p.id === count.location_id);
  const placeName = place ? nameIn(place.names, place.name, language) : '';
  const counting = count.status === 'draft' && canCount;
  const pending = lines.filter((l) => (typed[l.id] ?? '').trim() !== '');
  const counted = lines.filter((l) => l.counted !== null && l.counted !== undefined).length;

  const save = async (): Promise<boolean> => {
    if (pending.length === 0) return true;
    for (const line of pending) {
      const value = (typed[line.id] ?? '').trim();
      if (!/^\d+(\.\d+)?$/.test(value)) {
        setProblem(t('inventory.count.badAmount', '{item}: count 0 or more, in {unit}.', { item: line.item_name, unit: line.unit }));
        return false;
      }
    }
    setWorking('save');
    setProblem(null);
    try {
      await inventoryApi.recordCount(
        count.id,
        pending.map((l) => ({ variant_id: l.variant_id, counted: (typed[l.id] ?? '').trim() }))
      );
      setTyped({});
      refetch();
      return true;
    } catch (e) {
      setProblem(inventoryErrorText(e));
      return false;
    } finally {
      setWorking(null);
    }
  };

  const finish = async () => {
    if (!(await save())) return;
    setWorking('finish');
    setProblem(null);
    try {
      const done = await inventoryApi.finishCount(count.id);
      toast.addToast(
        done.status === 'waiting_approval'
          ? t('inventory.count.waiting', 'The differences are waiting for approval. Stock changes once they are approved.')
          : t('inventory.count.done', 'Count finished. The stock now matches what you counted.'),
        'success'
      );
      refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setWorking(null);
    }
  };

  const cancel = async () => {
    setWorking('cancel');
    setProblem(null);
    try {
      await inventoryApi.cancelCount(count.id);
      refetch();
    } catch (e) {
      setProblem(inventoryErrorText(e));
    } finally {
      setWorking(null);
    }
  };

  const qty = (value: string | null | undefined, unit: string, tone = false) =>
    value === null || value === undefined ? (
      <span className="text-muted-foreground">–</span>
    ) : (
      <bdi dir="ltr" className={`font-mono tabular-nums ${tone && Number(value) < 0 ? 'text-destructive' : ''}`}>
        {tone && Number(value) > 0 ? '+' : ''}
        {quantityText(value)} <span className="text-xs text-muted-foreground">{unit}</span>
      </bdi>
    );

  const showExpected = lines.some((l) => l.expected !== null && l.expected !== undefined) || !count.is_blind;

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Link href="/inventory?tab=counts" className="hover:underline">
            {t('inventory.title', 'Inventory')}
          </Link>
        }
        title={t('inventory.count.heading', 'Count {number}', { number: count.number })}
        description={[
          placeName,
          t('inventory.count.progress', '{counted} of {total} counted', { counted: String(counted), total: String(lines.length) }),
          count.is_blind ? t('inventory.count.blind', 'Blind count') : null,
          dates.dateTime(count.created_at),
        ]
          .filter((part) => part)
          .join(' · ')}
        action={
          counting ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" loading={working === 'cancel'} disabled={working !== null} onClick={() => void cancel()}>
                {t('inventory.count.cancel', 'Cancel count')}
              </Button>
              <Button variant="outline" loading={working === 'save'} disabled={working !== null || pending.length === 0} onClick={() => void save()}>
                {t('action.save', 'Save')}
              </Button>
              <Button loading={working === 'finish'} disabled={working !== null} onClick={() => void finish()} data-testid="count-finish">
                <Icon name="check" />
                {t('inventory.count.finish', 'Finish count')}
              </Button>
            </div>
          ) : (
            <OperationStatus operation={count} />
          )
        }
      />

      {count.status === 'waiting_approval' && (
        <Alert>
          <AlertDescription>
            {t('inventory.count.waitingNotice', 'Some differences are worth more than the approval value, so they wait in Approvals. The stock changes once they are approved.')}
          </AlertDescription>
        </Alert>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm" data-testid="count-lines">
          <thead className="bg-muted/50 text-start text-xs text-muted-foreground">
            <tr>
              <th className="px-4 py-3 text-start font-medium">{t('inventory.column.item', 'Item')}</th>
              {showExpected && <th className="px-4 py-3 text-end font-medium">{t('inventory.column.expected', 'Expected')}</th>}
              <th className="px-4 py-3 text-end font-medium">{t('inventory.column.counted', 'Counted')}</th>
              {showExpected && <th className="px-4 py-3 text-end font-medium">{t('inventory.column.difference', 'Difference')}</th>}
              {count.status !== 'draft' && <th className="px-4 py-3 text-end font-medium">{t('inventory.column.value', 'Value')}</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {lines.map((line: InventoryOperationLine) => (
              <tr key={line.id} className="h-12">
                <td className="px-4 py-2">
                  <div className="font-medium">{line.item_name}</div>
                  <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">
                    {line.sku}
                  </bdi>
                </td>
                {showExpected && <td className="px-4 py-2 text-end">{qty(line.expected, line.unit)}</td>}
                <td className="px-4 py-2 text-end">
                  {counting ? (
                    <div className="ms-auto w-32">
                      <Input
                        id={`count-line-${line.id}`}
                        dir="ltr"
                        inputMode="decimal"
                        className="text-end font-mono"
                        aria-label={t('inventory.count.countedOf', 'Counted, {item}, in {unit}', { item: line.item_name, unit: line.unit })}
                        placeholder={line.counted != null ? quantityText(line.counted) : line.unit}
                        value={typed[line.id] ?? ''}
                        onChange={(e) => setTyped({ ...typed, [line.id]: e.target.value })}
                      />
                    </div>
                  ) : (
                    qty(line.counted, line.unit)
                  )}
                </td>
                {showExpected && <td className="px-4 py-2 text-end">{qty(line.difference, line.unit, true)}</td>}
                {count.status !== 'draft' && (
                  <td className="px-4 py-2 text-end">
                    {line.value_minor != null && line.currency && line.difference && Number(line.difference) !== 0 ? (
                      <Money minor={line.value_minor} currency={line.currency} />
                    ) : (
                      <span className="text-muted-foreground">–</span>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        {lines.length === 0 && (
          <p className="p-6 text-center text-sm text-muted-foreground">
            {t('inventory.count.noLines', 'Nothing was in stock here when the count started.')}
          </p>
        )}
      </div>
    </div>
  );
}
