'use client';

/**
 * /inventory/import (WHIT-1326, design section 34 §6.5): items and their
 * variants, opening stock and supplier prices, on the shared import screen.
 * `?kind=inventory.items` starts on one kind.
 */

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useTranslation } from '@whity/features/i18n';
import { ImportScreen } from '@/components/import/import-screen';

export default function InventoryImportPage() {
  const t = useTranslation('admin');
  const kind = useSearchParams().get('kind') ?? undefined;
  return (
    <ImportScreen
      area="inventory"
      kind={kind}
      breadcrumb={
        <Link href="/inventory" className="hover:underline">
          {t('inventory.title', 'Inventory')}
        </Link>
      }
    />
  );
}
