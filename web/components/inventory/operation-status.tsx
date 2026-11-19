'use client';

/**
 * The state of an adjustment, count or transfer in words (WHIT-1233), as a
 * tinted pill: done is green, waiting for approval amber, on the way blue.
 */

import { Badge } from '@whity/ui/badge';
import { useTranslation } from '@whity/features/i18n';
import type { InventoryOperation } from '@/lib/inventory/api';

export function OperationStatus({ operation }: { operation: Pick<InventoryOperation, 'kind' | 'status'> }) {
  const t = useTranslation('admin');
  switch (operation.status) {
    case 'done':
      return <Badge variant="success">{t('inventory.status.done', 'Done')}</Badge>;
    case 'waiting_approval':
      return <Badge variant="warning">{t('inventory.status.waiting', 'Waiting for approval')}</Badge>;
    case 'in_transit':
      return <Badge variant="info">{t('inventory.status.onTheWay', 'On the way')}</Badge>;
    case 'cancelled':
      return <Badge variant="outline">{t('inventory.status.cancelled', 'Cancelled')}</Badge>;
    default:
      return (
        <Badge variant="outline">
          {operation.kind === 'count' ? t('inventory.status.counting', 'Counting') : t('inventory.status.draft', 'Draft')}
        </Badge>
      );
  }
}
