'use client';

/**
 * A quote's or invoice's lines and totals as the customer sees them
 * (WHIT-1416): description, quantity × price, the line total, then the
 * subtotal, tax and total. Amounts in the document's own currency, Western
 * digits, left to right (CU.1–CU.4).
 */

import type { ReactNode } from 'react';
import { Card, CardContent } from '@whity/ui/card';
import { Money } from '@whity/ui/money/money';
import { useTranslation } from '@whity/features/i18n';
import { UserText } from '@/components/ui/user-text';

export interface DocLine {
  description: string;
  quantity: string;
  price_minor: number;
  discount_bp?: number;
  total_minor: number;
}

/** "2.500" as "2.5": quantities read best without trailing zeros. */
function quantityText(decimal: string): string {
  if (!decimal.includes('.')) return decimal;
  return decimal.replace(/0+$/, '').replace(/\.$/, '');
}

export function DocumentLines({
  lines,
  currency,
  subtotal,
  tax,
  total,
  pricesIncludeTax,
  extra,
}: {
  lines: DocLine[];
  currency: string;
  subtotal: number;
  tax: number;
  total: number;
  pricesIncludeTax: boolean;
  extra?: ReactNode;
}) {
  const t = useTranslation('customer');
  return (
    <Card>
      <CardContent className="p-0">
        {lines.length > 0 && (
          <ul className="divide-y divide-border border-b border-border" data-testid="customer-doc-lines">
            {lines.map((line, i) => (
              <li key={i} className="flex items-start justify-between gap-4 px-5 py-3">
                <div className="min-w-0">
                  <UserText className="block">{line.description}</UserText>
                  <span className="text-sm text-muted-foreground">
                    <bdi dir="ltr" className="font-mono">
                      {quantityText(line.quantity)} × <Money minor={line.price_minor} currency={currency} />
                    </bdi>
                    {(line.discount_bp ?? 0) > 0 && (
                      <span className="ms-2">
                        {t('doc.discount', 'Discount')}{' '}
                        <bdi dir="ltr" className="font-mono">
                          {(line.discount_bp ?? 0) / 100}%
                        </bdi>
                      </span>
                    )}
                  </span>
                </div>
                <Money minor={line.total_minor} currency={currency} className="shrink-0" />
              </li>
            ))}
          </ul>
        )}
        <dl className="space-y-2 px-5 py-4 text-sm">
          <Row label={pricesIncludeTax ? t('doc.subtotalIncl', 'Subtotal (prices include tax)') : t('doc.subtotal', 'Subtotal')}>
            <Money minor={subtotal} currency={currency} />
          </Row>
          <Row label={t('doc.tax', 'Sales tax')}>
            <Money minor={tax} currency={currency} />
          </Row>
          <div className="flex justify-between text-base font-semibold">
            <dt>{t('doc.total', 'Total')}</dt>
            <dd data-testid="customer-doc-total">
              <Money minor={total} currency={currency} />
            </dd>
          </div>
          {extra}
        </dl>
      </CardContent>
    </Card>
  );
}

export function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

/** The workspace's own How to pay text in the reader's language (CA.15). */
export function HowToPay({ text, arabic }: { text: { en: string; ar: string }; arabic: boolean }) {
  const t = useTranslation('customer');
  const body = arabic ? text.ar || text.en : text.en || text.ar;
  return (
    <Card data-testid="customer-how-to-pay">
      <CardContent className="space-y-2 py-5">
        <p className="font-semibold">{t('doc.howToPay', 'How to pay')}</p>
        <UserText className="block whitespace-pre-line text-muted-foreground">{body !== '' ? body : t('doc.howToPayNone', 'Ask the business how they would like to be paid.')}</UserText>
      </CardContent>
    </Card>
  );
}
