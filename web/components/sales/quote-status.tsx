'use client';

/** A quote's state as a badge (design section 34 §3.2): plain words, tokens only. */

import { Badge } from '@whity/ui/badge';
import { useTranslation } from '@whity/features/i18n';
import type { QuoteStatus as Status } from '@/lib/sales/api';

export function useQuoteStatusLabel(): (status: Status) => string {
  const t = useTranslation('admin');
  return (status) => {
    switch (status) {
      case 'draft':
        return t('sales.status.draft', 'Draft');
      case 'waiting_approval':
        return t('sales.status.waiting', 'Waiting for approval');
      case 'ready':
        return t('sales.status.ready', 'Ready to send');
      case 'sent':
        return t('sales.status.sent', 'Sent');
      case 'accepted':
        return t('sales.status.accepted', 'Accepted');
      case 'declined':
        return t('sales.status.declined', 'Declined');
      case 'expired':
        return t('sales.status.expired', 'Expired');
      case 'converted':
        return t('sales.status.converted', 'Turned into a sale');
      case 'replaced':
        return t('sales.status.replaced', 'Replaced by a newer revision');
      default:
        return t('sales.status.cancelled', 'Withdrawn');
    }
  };
}

export function QuoteStatus({ status }: { status: Status }) {
  const label = useQuoteStatusLabel()(status);
  const variant =
    status === 'accepted' || status === 'converted'
      ? 'success'
      : status === 'declined'
        ? 'destructive'
        : status === 'waiting_approval' || status === 'expired'
          ? 'warning'
          : status === 'sent' || status === 'ready'
            ? 'info'
            : 'outline';
  return (
    <Badge variant={variant} data-testid="quote-status">
      {label}
    </Badge>
  );
}
