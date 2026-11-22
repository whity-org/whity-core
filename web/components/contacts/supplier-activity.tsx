'use client';

/**
 * A supplier on their contact page (WHIT-1234, design 31 §4.4, `TplContacts`):
 * what they supply and at what price, and their purchase orders, newest
 * first, each opening its page. Shown to people who see purchases; New
 * purchase order starts one for this supplier for people who can order.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCapabilities } from '@/hooks/useCapabilities';
import { UserText } from '@/components/ui/user-text';
import { useFetch } from '@/hooks/useFetch';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { PurchaseOrderDialog } from '@/components/inventory/purchase-order-dialog';
import { PurchaseOrderStatus } from '@/components/inventory/purchase-orders-tab';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { PURCHASES_READ, PURCHASES_WRITE, inventoryApi, nameIn } from '@/lib/inventory/api';

export function SupplierActivity({ contactId }: { contactId: number }) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const dates = useDateDisplay();
  const router = useRouter();
  const currency = useWorkspaceCurrency();
  const { has } = useCapabilities();
  const canSee = has(PURCHASES_READ);
  const canOrder = has(PURCHASES_WRITE);
  const [ordering, setOrdering] = useState(false);
  const { data } = useFetch(async () => {
    if (!canSee) return null;
    const [supplies, orders, places] = await Promise.all([
      inventoryApi.supplierItems({ contactId }),
      inventoryApi.purchaseOrders({ contactId, limit: 20 }),
      inventoryApi.locations(),
    ]);
    return { supplies, orders, places };
  }, [contactId, canSee]);

  if (!canSee || data === null) return null;

  return (
    <div className="grid gap-6 lg:grid-cols-2" data-testid="supplier-activity">
      <section className="space-y-3 rounded-lg border border-border p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
          <Icon name="package" size={16} />
          {t('contacts.supplies', 'Supplies')}
        </h2>
        {data.supplies.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t('contacts.supplies.none', 'No prices yet. Add them on an item’s page, under Suppliers.')}
          </p>
        ) : (
          <ul className="divide-y divide-border text-sm">
            {data.supplies.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                <Link href={`/inventory/items/${s.item_id}`} className="hover:underline">
                  <UserText>{nameIn(s.item_names, s.item_name, language)}</UserText>
                </Link>
                <span>
                  <Money minor={s.price_minor} currency={s.currency} />{' '}
                  <span className="text-muted-foreground">{t('inventory.prices.per', 'a {unit}', { unit: s.unit })}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3 rounded-lg border border-border p-5">
        <div className="flex items-center justify-between gap-3">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
            <Icon name="receipt" size={16} />
            {t('contacts.purchaseOrders', 'Purchase orders')}
          </h2>
          {canOrder && data.places.length > 0 && (
            <Button variant="outline" size="sm" onClick={() => setOrdering(true)} data-testid="contact-new-po">
              <Icon name="plus" />
              {t('inventory.po.new', 'New purchase order')}
            </Button>
          )}
        </div>
        {data.orders.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('contacts.purchaseOrders.none', 'No purchase orders yet.')}</p>
        ) : (
          <ul className="divide-y divide-border text-sm">
            {data.orders.map((o) => (
              <li key={o.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                <Link href={`/inventory/purchase-orders/${o.id}`} className="hover:underline">
                  <bdi dir="ltr" className="font-mono">
                    {o.number}
                  </bdi>
                  <span className="text-muted-foreground"> · {dates.date(o.created_at) ?? ''}</span>
                </Link>
                <span className="flex items-center gap-3">
                  <Money minor={o.total_minor} currency={o.currency} />
                  <PurchaseOrderStatus order={o} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {ordering && (
        <PurchaseOrderDialog
          open={ordering}
          onOpenChange={setOrdering}
          places={data.places}
          currency={currency}
          supplierId={contactId}
          onSaved={(order) => router.push(`/inventory/purchase-orders/${order.id}`)}
        />
      )}
    </div>
  );
}
