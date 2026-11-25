'use client';

/**
 * Rows shared by Home, Bookings and Bills (WHIT-1416): a booking and a bill
 * as one line each, 48px and up, with the date and amount (Western digits,
 * left to right) and the status in words.
 */

import Link from 'next/link';
import type { components } from '@whity/api-client';
import { Badge } from '@whity/ui/badge';
import { Icon } from '@whity/ui/icon';
import { Money } from '@whity/ui/money/money';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { UserText } from '@/components/ui/user-text';
import { areaHref } from '@/lib/customer-area';
import { useLocalized } from './area-parts';

export type BookingRow = components['schemas']['CustomerAreaBookingRow'];
export type BillRow = components['schemas']['CustomerAreaBill'];

export function BookingLine({ handle, booking, timeZone }: { handle: string; booking: BookingRow; timeZone: string }) {
  const t = useTranslation('customer');
  const dates = useDateDisplay({ timeZone });
  const name = useLocalized();
  const status: Record<string, string> = {
    confirmed: t('booking.status.confirmed', 'Booked'),
    requested: t('booking.status.requested', 'Waiting for the business'),
    pending_payment: t('booking.status.pending_payment', 'Waiting for payment'),
    cancelled: t('booking.status.cancelled', 'Cancelled'),
    expired: t('booking.status.expired', 'Not completed'),
  };

  return (
    <Link
      href={areaHref(handle, `bookings/${booking.id}`)}
      className="flex min-h-12 items-center gap-3 px-4 py-3 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
      data-testid="customer-booking-row"
    >
      <Icon name="appointment" size={20} className="shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <UserText className="block truncate font-medium">{name(booking.service_name as Record<string, string>) || t('booking.untitled', 'Booking')}</UserText>
        <span className="text-sm text-muted-foreground">
          {dates.dateTime(booking.starts_at)}
          {booking.staff_name ? ` · ${booking.staff_name}` : ''}
        </span>
      </div>
      <Badge variant={booking.status === 'cancelled' || booking.status === 'expired' ? 'outline' : 'secondary'}>{status[booking.status] ?? booking.status}</Badge>
      <Icon name="chevron-right" size={18} className="shrink-0 text-muted-foreground" />
    </Link>
  );
}

export function useBillWords() {
  const t = useTranslation('customer');
  const kind: Record<string, string> = {
    quote: t('bill.kind.quote', 'Quote'),
    invoice: t('bill.kind.invoice', 'Invoice'),
    credit_note: t('bill.kind.credit_note', 'Credit note'),
    receipt: t('bill.kind.receipt', 'Receipt'),
  };
  const status = (bill: Pick<BillRow, 'kind' | 'status' | 'overdue' | 'due_minor'>): string => {
    if (bill.kind === 'quote') {
      return (
        {
          sent: t('bill.status.quoteWaiting', 'Waiting for you'),
          accepted: t('bill.status.accepted', 'Accepted'),
          converted: t('bill.status.accepted', 'Accepted'),
          declined: t('bill.status.declined', 'Declined'),
          expired: t('bill.status.expired', 'Expired'),
        } as Record<string, string>
      )[bill.status] ?? bill.status;
    }
    if (bill.kind === 'receipt') return t('bill.status.paid', 'Paid');
    if (bill.kind === 'credit_note') return t('bill.status.credit', 'Credit');
    if (bill.status === 'paid') return t('bill.status.paid', 'Paid');
    if (bill.status === 'credited') return t('bill.status.credited', 'Credited');
    if (bill.overdue) return t('bill.status.overdue', 'Overdue');
    return (bill.due_minor ?? 0) > 0 ? t('bill.status.due', 'To pay') : t('bill.status.paid', 'Paid');
  };
  return { kind, status };
}

export function billHref(handle: string, bill: Pick<BillRow, 'kind' | 'id'>): string {
  const path = bill.kind === 'quote' ? 'quotes' : bill.kind === 'receipt' ? 'receipts' : 'invoices';
  return areaHref(handle, `bills/${path}/${bill.id}`);
}

export function BillLine({ handle, bill }: { handle: string; bill: BillRow }) {
  const dates = useDateDisplay();
  const words = useBillWords();
  const due = (bill.due_minor ?? 0) > 0;

  return (
    <Link
      href={billHref(handle, bill)}
      className="flex min-h-12 items-center gap-3 px-4 py-3 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
      data-testid="customer-bill-row"
    >
      <Icon name={bill.kind === 'quote' ? 'quote' : bill.kind === 'receipt' ? 'receipt' : 'invoice'} size={20} className="shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <span className="block font-medium">
          {words.kind[bill.kind]}{' '}
          <bdi dir="ltr" className="font-mono">
            {bill.number}
          </bdi>
        </span>
        <span className="text-sm text-muted-foreground">{dates.date(bill.date ?? '')}</span>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        <Money minor={due ? (bill.due_minor ?? 0) : bill.total_minor} currency={bill.currency} />
        <Badge variant={bill.overdue ? 'warning' : bill.waiting ? 'default' : 'outline'}>{words.status(bill)}</Badge>
      </div>
    </Link>
  );
}
