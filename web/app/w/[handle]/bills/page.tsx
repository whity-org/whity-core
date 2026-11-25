'use client';

/**
 * Bills (WHIT-1416, design section 41 §8.3, CA.15): quotes, invoices,
 * credit notes and receipts in one list, newest first, with a Kind filter.
 */

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useCustomerArea } from '@/lib/customer-area';
import { Card, CardContent } from '@whity/ui/card';
import { cn } from '@whity/ui/utils';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { LoadState, Outcome, PageTitle, useAreaLoad } from '@/components/customer-area/area-parts';
import { BillLine } from '@/components/customer-area/area-rows';

type Kind = '' | 'quote' | 'invoice' | 'credit_note' | 'receipt';
type Bills = { bills: components['schemas']['CustomerAreaBill'][]; how_to_pay: { en: string; ar: string } };

export default function CustomerBills() {
  const t = useTranslation('customer');
  const { handle } = useCustomerArea();
  const [kind, setKind] = useState<Kind>('');
  const [state, retry] = useAreaLoad<Bills>(
    () => api.GET('/api/v1/customer-area/{handle}/bills', { params: { path: { handle }, query: kind === '' ? {} : { kind } } }),
    [handle, kind],
  );
  const kinds: { id: Kind; label: string }[] = [
    { id: '', label: t('bills.all', 'All') },
    { id: 'quote', label: t('bills.quotes', 'Quotes') },
    { id: 'invoice', label: t('bills.invoices', 'Invoices') },
    { id: 'receipt', label: t('bills.receipts', 'Receipts') },
    { id: 'credit_note', label: t('bills.credits', 'Credit notes') },
  ];

  return (
    <div className="space-y-5">
      <PageTitle>{t('tab.bills', 'Bills')}</PageTitle>
      <div role="tablist" aria-label={t('bills.kind', 'Kind')} className="flex flex-wrap gap-2">
        {kinds.map((k) => (
          <button
            key={k.id || 'all'}
            role="tab"
            type="button"
            aria-selected={kind === k.id}
            onClick={() => setKind(k.id)}
            className={cn(
              'h-10 rounded-full border px-4 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
              kind === k.id ? 'border-primary bg-primary text-primary-foreground' : 'border-input text-muted-foreground hover:bg-muted',
            )}
          >
            {k.label}
          </button>
        ))}
      </div>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' &&
        (state.data.bills.length === 0 ? (
          <Outcome icon="invoice" tone="muted" title={t('bills.none', 'Nothing here yet')} body={t('bills.noneBody', 'Quotes, invoices and receipts the business sends you will show here.')} />
        ) : (
          <Card>
            <CardContent className="divide-y divide-border p-0">
              {state.data.bills.map((bill) => (
                <BillLine key={`${bill.kind}-${bill.id}`} handle={handle} bill={bill} />
              ))}
            </CardContent>
          </Card>
        ))}
    </div>
  );
}
