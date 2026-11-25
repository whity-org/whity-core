'use client';

/**
 * One booking (WHIT-1416, design section 41 §8.2): when, what, who and where,
 * with Move and Cancel under the same rules as the booking's own link (the
 * notice and the furthest day ahead). Cancelling asks first, since it can't
 * be undone from here.
 */

import Link from 'next/link';
import { use, useState } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import { Money } from '@whity/ui/money/money';
import { Textarea } from '@whity/ui/textarea';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { UserText } from '@/components/ui/user-text';
import { LoadState, PageTitle, serverText, useAreaLoad, useLocalized } from '@/components/customer-area/area-parts';

type Booking = components['schemas']['CustomerAreaBooking'];
type Slot = { starts_at: string; ends_at: string };

export default function CustomerBooking({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const bookingId = Number(id);
  const t = useTranslation('customer');
  const { handle } = useCustomerArea();
  const [state, retry] = useAreaLoad<Booking>(
    () => api.GET('/api/v1/customer-area/{handle}/bookings/{id}', { params: { path: { handle, id: bookingId } } }),
    [handle, bookingId],
  );

  return (
    <div className="space-y-5">
      <Link href={areaHref(handle, 'bookings')} className="text-sm text-muted-foreground hover:underline">
        {t('booking.back', 'All bookings')}
      </Link>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && <BookingView handle={handle} booking={state.data} onChanged={retry} />}
    </div>
  );
}

function BookingView({ handle, booking, onChanged }: { handle: string; booking: Booking; onChanged: () => void }) {
  const t = useTranslation('customer');
  const name = useLocalized();
  const dates = useDateDisplay({ timeZone: booking.time_zone });
  const [mode, setMode] = useState<'view' | 'move' | 'cancel'>('view');
  const [reason, setReason] = useState('');
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const service = name((booking.service as { name?: Record<string, string> })?.name ?? null) || t('booking.untitled', 'Booking');

  const loadSlots = async () => {
    setMode('move');
    setProblem(null);
    setSlots(null);
    // Instants with an offset, at most 14 days apart (the booking link's rule).
    const from = new Date();
    const to = new Date(from.getTime() + 14 * 86400000);
    const { data, response } = await api.GET('/api/v1/customer-area/{handle}/bookings/{id}/slots', {
      params: { path: { handle, id: booking.id }, query: { from: from.toISOString(), to: to.toISOString() } },
    });
    setSlots(response.ok && data ? (data as { data: Slot[] }).data : []);
  };

  const move = async (startsAt: string) => {
    setBusy(true);
    setProblem(null);
    const { error, response } = await api.POST('/api/v1/customer-area/{handle}/bookings/{id}/move', {
      params: { path: { handle, id: booking.id } },
      body: { starts_at: startsAt },
    });
    setBusy(false);
    if (response.ok) {
      setMode('view');
      onChanged();
    } else {
      setProblem(serverText(error) ?? t('booking.moveFailed', 'The booking didn’t move. Choose another time.'));
    }
  };

  const cancel = async () => {
    setBusy(true);
    setProblem(null);
    const { error, response } = await api.POST('/api/v1/customer-area/{handle}/bookings/{id}/cancel', {
      params: { path: { handle, id: booking.id } },
      body: { reason: reason.trim() },
    });
    setBusy(false);
    if (response.ok) {
      setMode('view');
      onChanged();
    } else {
      setProblem(serverText(error) ?? t('booking.cancelFailed', 'The booking wasn’t cancelled. Try again.'));
    }
  };

  return (
    <>
      <PageTitle>
        <UserText>{service}</UserText>
      </PageTitle>
      <Card>
        <CardContent className="space-y-3 py-5">
          <dl className="grid gap-3 sm:grid-cols-2">
            <Fact label={t('booking.when', 'When')} value={dates.dateTime(booking.starts_at) ?? ''} />
            {booking.staff_name && <Fact label={t('booking.with', 'With')} value={booking.staff_name} />}
            {booking.room_name && <Fact label={t('booking.where', 'Where')} value={booking.room_name} />}
            {booking.price_minor != null && booking.currency && (
              <div>
                <dt className="text-sm text-muted-foreground">{t('booking.price', 'Price')}</dt>
                <dd>
                  <Money minor={booking.price_minor} currency={booking.currency} />
                </dd>
              </div>
            )}
          </dl>
          {booking.status === 'cancelled' && <p className="font-medium">{t('booking.cancelled', 'This booking is cancelled.')}</p>}
        </CardContent>
      </Card>
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}
      {booking.can_change && mode === 'view' && (
        <div className="flex flex-col gap-2 sm:flex-row max-sm:sticky max-sm:bottom-20 max-sm:bg-background max-sm:py-2">
          <Button size="lg" onClick={() => void loadSlots()} data-testid="customer-booking-move">
            {t('booking.move', 'Move')}
          </Button>
          <Button size="lg" variant="outline" onClick={() => setMode('cancel')} data-testid="customer-booking-cancel">
            {t('booking.cancel', 'Cancel booking')}
          </Button>
        </div>
      )}
      {mode === 'move' && (
        <Card>
          <CardContent className="space-y-3 py-5">
            <p className="font-medium">{t('booking.chooseTime', 'Choose a new time')}</p>
            {slots === null && <div className="h-24 animate-pulse rounded-lg bg-muted" />}
            {slots !== null && slots.length === 0 && <p className="text-muted-foreground">{t('booking.noSlots', 'No free times in the next two weeks. Write to the business to find one.')}</p>}
            {slots !== null && slots.length > 0 && (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {slots.slice(0, 40).map((slot) => (
                  <Button key={slot.starts_at} variant="outline" disabled={busy} onClick={() => void move(slot.starts_at)}>
                    {dates.dateTime(slot.starts_at)}
                  </Button>
                ))}
              </div>
            )}
            <Button variant="outline" onClick={() => setMode('view')}>
              {t('booking.keep', 'Keep this time')}
            </Button>
          </CardContent>
        </Card>
      )}
      {mode === 'cancel' && (
        <Card>
          <CardContent className="space-y-4 py-5">
            <p className="font-medium">{t('booking.cancelAsk', 'Cancel this booking? You can book again later.')}</p>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={500}
              dir="auto"
              aria-label={t('booking.reason', 'Why, if you’d like to say (optional)')}
              placeholder={t('booking.reason', 'Why, if you’d like to say (optional)')}
            />
            <div className="flex flex-col gap-2 sm:flex-row">
              <Button size="lg" variant="destructive" disabled={busy} onClick={() => void cancel()} data-testid="customer-booking-cancel-confirm">
                {t('booking.cancelConfirm', 'Cancel booking')}
              </Button>
              <Button size="lg" variant="outline" onClick={() => setMode('view')}>
                {t('booking.keepIt', 'Keep it')}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd>
        <UserText>{value}</UserText>
      </dd>
    </div>
  );
}
