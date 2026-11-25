'use client';

/**
 * One receipt in the customer area (WHIT-1416, design section 41 §8.3): what
 * was paid, when and how. Read-only.
 */

import Link from 'next/link';
import { use } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Card, CardContent } from '@whity/ui/card';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { UserText } from '@/components/ui/user-text';
import { LoadState, PageTitle, useAreaLoad } from '@/components/customer-area/area-parts';
import { Row } from '@/components/customer-area/document-parts';

type Receipt = components['schemas']['CustomerAreaReceipt'];

export default function CustomerReceipt({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const receiptId = Number(id);
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const { handle } = useCustomerArea();
  const [state, retry] = useAreaLoad<Receipt>(
    () => api.GET('/api/v1/customer-area/{handle}/bills/receipts/{id}', { params: { path: { handle, id: receiptId } } }),
    [handle, receiptId],
  );
  const methods: Record<string, string> = {
    cash: t('receipt.method.cash', 'Cash'),
    bank: t('receipt.method.bank', 'Bank transfer'),
    card: t('receipt.method.card', 'Card'),
    cheque: t('receipt.method.cheque', 'Cheque'),
    cliq: t('receipt.method.cliq', 'CliQ'),
  };

  return (
    <div className="space-y-5">
      <Link href={areaHref(handle, 'bills')} className="text-sm text-muted-foreground hover:underline">
        {t('doc.back', 'All bills')}
      </Link>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && (
        <>
          <PageTitle>
            {t('bill.kind.receipt', 'Receipt')}{' '}
            <bdi dir="ltr" className="font-mono">
              {state.data.number}
            </bdi>
          </PageTitle>
          <Card>
            <CardContent className="py-5">
              <dl className="space-y-3">
                <Row label={t('receipt.amount', 'Amount paid')}>
                  <Money minor={state.data.total_minor} currency={state.data.currency} />
                </Row>
                <Row label={t('receipt.date', 'Paid on')}>{dates.date(state.data.date ?? '')}</Row>
                {state.data.method && <Row label={t('receipt.method', 'How')}>{methods[state.data.method] ?? state.data.method}</Row>}
                {state.data.reference && (
                  <Row label={t('receipt.reference', 'Reference')}>
                    <UserText>{state.data.reference}</UserText>
                  </Row>
                )}
              </dl>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
