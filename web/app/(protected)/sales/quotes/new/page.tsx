'use client';

/**
 * /sales/quotes/new (WHIT-1341, design section 34 §3.1): a new quote, saved
 * as a draft and opened to check and send. `?customer=41` starts on one
 * customer (from a contact's page).
 */

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { NoAccess } from '@/components/no-access';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { QuoteForm } from '@/components/sales/quote-form';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { QUOTES_WRITE, salesApi } from '@/lib/sales/api';

export default function NewQuotePage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const toast = useToast();
  const currency = useWorkspaceCurrency();
  const customer = Number(useSearchParams().get('customer') ?? '') || undefined;
  const { has, loading } = useCapabilities();

  if (!loading && !has(QUOTES_WRITE)) {
    return <NoAccess area={t('sales.quote.area', 'writing quotes')} permission={QUOTES_WRITE} />;
  }

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={<Breadcrumb linkComponent={Link} items={[{ label: t('sales.title', 'Sales'), href: '/sales' }, { label: t('sales.quote.new', 'New quote') }]} />}
        title={t('sales.quote.new', 'New quote')}
        description={t('sales.quote.newHint', 'It is saved as a draft for you to check and send.')}
      />
      <QuoteForm
        currency={currency}
        customerId={customer}
        saveLabel={t('sales.form.saveDraft', 'Save draft')}
        onCancel={() => router.push('/sales')}
        onSave={async (body) => {
          const quote = await salesApi.create(body);
          toast.addToast(t('sales.quote.saved', 'Draft saved.'), 'success');
          router.push(`/sales/quotes/${quote.id}`);
        }}
      />
    </div>
  );
}
