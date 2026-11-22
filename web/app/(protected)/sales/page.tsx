'use client';

/**
 * /sales (WHIT-1341, design section 34 §3.8, owner answer Q4): Operations ›
 * Sales, for every workspace, inventory or not. The Quotes tab lists quotes
 * newest first, each with its number and revision, its customer, how long it
 * is valid, its total in its own currency and its state; a row opens the
 * quote. Sales orders join as a tab when inventory's P5 lands. The brand
 * team's card is an Ask; this follows section 21's list page meanwhile.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { UserText } from '@/components/ui/user-text';
import { QuoteStatus, useQuoteStatusLabel } from '@/components/sales/quote-status';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { QUOTES_READ, QUOTES_WRITE, QUOTE_STATUSES, salesApi, type QuoteStatus as Status, type SalesQuote } from '@/lib/sales/api';

const ALL = '__all';

export default function SalesPage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const dates = useDateDisplay();
  const statusLabel = useQuoteStatusLabel();
  const { has, loading: accessLoading } = useCapabilities();
  const [status, setStatus] = useState<Status | null>(null);
  const quotes = useFetch(() => salesApi.quotes({ status }), [status]);

  if (!accessLoading && !has(QUOTES_READ)) {
    return <NoAccess area={t('sales.area', 'sales')} permission={QUOTES_READ} />;
  }

  const columns: DataTableColumn<SalesQuote>[] = [
    {
      id: 'number',
      header: t('sales.column.number', 'Number'),
      cell: (row) =>
        row.number ? (
          <bdi dir="ltr" className="font-mono text-sm">
            {row.number}
            {row.revision > 1 && <span className="ms-1 text-muted-foreground">r{row.revision}</span>}
          </bdi>
        ) : (
          <span className="text-muted-foreground">{t('sales.noNumber', 'Not sent yet')}</span>
        ),
    },
    { id: 'customer', header: t('sales.column.customer', 'Customer'), cell: (row) => <UserText>{row.contact_name}</UserText> },
    { id: 'valid', header: t('sales.column.validUntil', 'Valid until'), cell: (row) => dates.date(row.valid_until) ?? '' },
    { id: 'total', header: t('sales.column.total', 'Total'), cell: (row) => <Money minor={row.total_minor} currency={row.currency} /> },
    { id: 'state', header: '', cell: (row) => <QuoteStatus status={row.status} /> },
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('sales.title', 'Sales')}
        description={t('sales.description', 'Quotes to your customers: send them, see who accepted, and turn them into orders.')}
        action={
          has(QUOTES_WRITE) ? (
            <Button asChild data-testid="quote-new">
              <Link href="/sales/quotes/new">
                <Icon name="plus" />
                {t('sales.quote.new', 'New quote')}
              </Link>
            </Button>
          ) : undefined
        }
      />

      <Tabs value="quotes">
        <TabsList>
          <TabsTrigger value="quotes">{t('sales.tab.quotes', 'Quotes')}</TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-60 space-y-1.5">
          <label htmlFor="quote-status-filter" className="text-sm font-medium">
            {t('sales.filter.status', 'Show')}
          </label>
          <Select value={status ?? ALL} onValueChange={(v) => setStatus(v === ALL ? null : (v as Status))}>
            <SelectTrigger id="quote-status-filter">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>{t('sales.filter.all', 'All quotes')}</SelectItem>
              {QUOTE_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {statusLabel(s)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {quotes.error ? (
        <LoadProblem
          forbidden={quotes.forbidden}
          message={t('sales.error.quotes', 'The quotes could not be loaded.')}
          onRetry={quotes.refetch}
          area={t('sales.area', 'sales')}
        />
      ) : (
        <DataTable
          columns={columns}
          data={quotes.data ?? []}
          isLoading={quotes.loading && quotes.data === null}
          getRowId={(row) => String(row.id)}
          onRowClick={(row) => router.push(`/sales/quotes/${row.id}`)}
          emptyState={{
            title: status === null ? t('sales.empty', 'No quotes yet') : t('sales.emptyFiltered', 'No quotes like that'),
            description: t('sales.emptyHint', 'Write a quote, send it to your customer, and they can accept it online.'),
          }}
        />
      )}
    </div>
  );
}
