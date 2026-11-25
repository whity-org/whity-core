'use client';

/**
 * One invoice or credit note in the customer area (WHIT-1416, design section
 * 41 §8.3, CA.15): its lines and totals, what is paid and what is due, and
 * How to pay in the workspace's own words. Pay now comes with a payment
 * plugin (BM.16), not in core.
 */

import Link from 'next/link';
import { use } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { LoadState, PageTitle, useAreaLoad } from '@/components/customer-area/area-parts';
import { useBillWords } from '@/components/customer-area/area-rows';
import { DocumentLines, HowToPay, Row } from '@/components/customer-area/document-parts';

type Invoice = components['schemas']['CustomerAreaInvoice'];

export default function CustomerInvoice({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const invoiceId = Number(id);
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const words = useBillWords();
  const { currentLanguage } = useCurrentLanguage();
  const { handle } = useCustomerArea();
  const [state, retry] = useAreaLoad<Invoice>(
    () => api.GET('/api/v1/customer-area/{handle}/bills/invoices/{id}', { params: { path: { handle, id: invoiceId } } }),
    [handle, invoiceId],
  );

  return (
    <div className="space-y-5">
      <Link href={areaHref(handle, 'bills')} className="text-sm text-muted-foreground hover:underline">
        {t('doc.back', 'All bills')}
      </Link>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && (
        <>
          <PageTitle>
            {words.kind[state.data.kind] ?? words.kind.invoice}{' '}
            <bdi dir="ltr" className="font-mono">
              {state.data.number}
            </bdi>
          </PageTitle>
          <p className="text-muted-foreground">
            {words.status({ kind: state.data.kind as 'invoice', status: state.data.status, overdue: state.data.overdue ?? false, due_minor: state.data.due_minor })}
            {state.data.date ? ` · ${dates.date(state.data.date)}` : ''}
            {state.data.due_date && state.data.due_minor > 0 ? ` · ${t('invoice.dueBy', 'Due by {date}', { date: dates.date(state.data.due_date) ?? '' })}` : ''}
          </p>
          <DocumentLines
            lines={state.data.lines.map((l) => ({ description: l.description, quantity: l.quantity, price_minor: l.unit_price_minor ?? 0, discount_bp: l.discount_bp, total_minor: l.total_minor }))}
            currency={state.data.currency}
            subtotal={state.data.subtotal_minor ?? 0}
            tax={state.data.tax_minor ?? 0}
            total={state.data.total_minor}
            pricesIncludeTax={state.data.prices_include_tax ?? false}
            extra={
              <>
                {(state.data.paid_minor ?? 0) > 0 && (
                  <Row label={t('invoice.paid', 'Paid')}>
                    <Money minor={state.data.paid_minor ?? 0} currency={state.data.currency} />
                  </Row>
                )}
                {state.data.due_minor > 0 && (
                  <div className="flex justify-between text-base font-semibold" data-testid="customer-invoice-due">
                    <dt>{t('invoice.toPay', 'To pay')}</dt>
                    <dd>
                      <Money minor={state.data.due_minor} currency={state.data.currency} />
                    </dd>
                  </div>
                )}
              </>
            }
          />
          {state.data.kind === 'invoice' && state.data.due_minor > 0 && (
            <HowToPay text={state.data.how_to_pay} arabic={String(currentLanguage ?? '').startsWith('ar')} />
          )}
        </>
      )}
    </div>
  );
}
