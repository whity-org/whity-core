'use client';

/**
 * A booking's details, beside it on the grid (design 3.5, CV.6,
 * `TplCalendarWeek`): when, the customer and their phone, with whom, where,
 * what is paid and how it was booked; Move, Message, Cancel and Open booking.
 * Esc closes it (the grid listens). Move and Cancel open the booking sheet on
 * that step, so the rules (what cancelling does to a deposit, a time just
 * taken) stay in one place.
 */

import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import type { ReactNode } from 'react';
import { bookingMoney } from './booking-sheet';
import type { CalItem } from './time-grid';
import { useCalendarWords } from './use-calendar-words';

function Row({ icon, children, small }: { icon: IconName; children: ReactNode; small?: ReactNode }) {
  return (
    <div className="grid grid-cols-[16px_1fr] items-start gap-3">
      <Icon name={icon} className="mt-[3px] text-muted-foreground" />
      <div className="min-w-0">
        {children}
        {small !== undefined && small !== null && <small className="block text-xs text-muted-foreground">{small}</small>}
      </div>
    </div>
  );
}

export interface BookingDetailsProps {
  item: CalItem;
  timeZone: string;
  /** "With Awss Haddad". */
  withName: string;
  canChange: boolean;
  onClose: () => void;
  onMove: () => void;
  onCancel: () => void;
  onOpen: () => void;
  /** The phone's sheet (CV.11): Move, Call, Message, then Cancel booking. */
  inSheet?: boolean;
}

export function BookingDetails({ item, timeZone, withName, canChange, onClose, onMove, onCancel, onOpen, inSheet = false }: BookingDetailsProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const booking = item.booking;
  const day = dates.calendarLabel(item.starts_at, 'fullDate') ?? '';
  const when = item.allDay
    ? day
    : t('details.when', '{day} · {from} – {to}', { day, from: dates.time(item.starts_at) ?? '', to: dates.time(item.ends_at) ?? '' });
  const live = booking !== undefined && booking.status !== 'cancelled';

  let paid: string | null = null;
  if (booking !== undefined && booking.price_minor !== null && booking.price_minor !== undefined) {
    const amount = bookingMoney(booking.price_minor, booking.currency ?? '');
    paid =
      booking.status === 'pending_payment'
        ? t('details.waitingPayment', '{amount}, waiting for payment', { amount })
        : booking.payment_ref
          ? t('details.paid', '{amount} paid', { amount })
          : t('details.toPay', '{amount}, not paid yet', { amount });
  }
  const bookedOn = booking?.created_at ? dates.calendarLabel(booking.created_at, 'dayMonth') : null;
  const how =
    booking === undefined
      ? null
      : bookedOn !== null
        ? t('details.howOn', '{how} on {date}', { how: words.source(booking.source), date: bookedOn })
        : words.source(booking.source);

  return (
    <div
      role="dialog"
      aria-label={item.title}
      className={inSheet ? 'grid gap-3.5 text-base leading-[1.45] text-foreground' : 'grid w-[360px] gap-3.5 rounded-[14px] border border-border bg-card p-4 text-sm leading-[1.45] text-foreground shadow-float'}
      data-testid="calendar-details"
    >
      <div className="grid grid-cols-[16px_1fr_auto] items-start gap-3">
        <span className="mt-1 size-4 rounded" style={{ background: item.colour }} aria-hidden="true" />
        <div className="min-w-0">
          <h3 className={`m-0 text-lg leading-snug font-semibold ${item.kind === 'cancelled' ? 'line-through' : ''}`}>{item.title}</h3>
          <p className="m-0 mt-0.5 text-muted-foreground">{when}</p>
        </div>
        {inSheet ? <span /> : (
          <Button variant="ghost" size="icon-sm" onClick={onClose} aria-label={t('action.close', 'Close')} title={t('action.close', 'Close')}>
            <Icon name="close" />
          </Button>
        )}
      </div>
      {booking !== undefined && (
        <Row icon="customer" small={booking.customer.phone ? <span dir="ltr">{booking.customer.phone}</span> : undefined}>
          {booking.customer.name ?? t('booking.noName', 'No name given')}
        </Row>
      )}
      {withName !== '' && <Row icon="user">{t('details.with', 'With {name}', { name: withName })}</Row>}
      {item.where !== '' && <Row icon="store">{item.where}</Row>}
      {paid !== null && <Row icon="money">{paid}</Row>}
      {how !== null && <Row icon="clock">{how}</Row>}
      {booking !== undefined && inSheet && (
        <>
          <div className="grid grid-cols-3 gap-2">
            {live && canChange && (
              <Button variant="outline" className="h-12" onClick={onMove} data-testid="calendar-details-move">
                <Icon name="calendar" />
                {t('details.move', 'Move')}
              </Button>
            )}
            {booking.customer.phone ? (
              <Button variant="outline" className="h-12" asChild>
                <a href={`tel:${booking.customer.phone.replace(/\s+/g, '')}`}>
                  <Icon name="phone" />
                  {t('details.call', 'Call')}
                </a>
              </Button>
            ) : null}
            {booking.customer.email ? (
              <Button variant="outline" className="h-12" asChild>
                <a href={`mailto:${booking.customer.email}`}>
                  <Icon name="send" />
                  {t('details.message', 'Message')}
                </a>
              </Button>
            ) : null}
          </div>
          {live && canChange && (
            <Button variant="ghost" className="h-12" onClick={onCancel} data-testid="calendar-details-cancel">
              {t('details.cancelBooking', 'Cancel booking')}
            </Button>
          )}
          <Button variant="ghost" className="h-12" onClick={onOpen} data-testid="calendar-details-open">
            {t('details.open', 'Open booking')}
            <Icon name="chevron-right" className="rtl:-scale-x-100" />
          </Button>
        </>
      )}
      {booking !== undefined && !inSheet && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            {live && canChange && (
              <Button size="sm" variant="outline" onClick={onMove} data-testid="calendar-details-move">
                <Icon name="calendar" />
                {t('details.move', 'Move')}
              </Button>
            )}
            {live && booking.customer.email && (
              <Button size="sm" variant="outline" asChild>
                <a href={`mailto:${booking.customer.email}`}>
                  <Icon name="send" />
                  {t('details.message', 'Message')}
                </a>
              </Button>
            )}
            {live && canChange && (
              <Button size="sm" variant="ghost" onClick={onCancel} data-testid="calendar-details-cancel">
                {t('action.cancel', 'Cancel')}
              </Button>
            )}
          </div>
          <div>
            <Button size="sm" variant="ghost" onClick={onOpen} data-testid="calendar-details-open">
              {t('details.open', 'Open booking')}
              <Icon name="chevron-right" className="rtl:-scale-x-100" />
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
