'use client';

/**
 * Time off saved over bookings (WHIT-1440, design section 39 §5.4, AP.17):
 * the bookings it covers, each with Move, which opens the booking on its Move
 * panel so the customer is asked first ("Email the new time", CV.9), and Keep
 * them, which leaves them where they are. Nothing moves on its own.
 */

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useFetch } from '@/hooks/useFetch';
import { calendarApi, type CalendarBooking } from '@/lib/calendar/api';
import { BookingSheet } from './booking-sheet';
import { useCalendarWords } from './use-calendar-words';

export function TimeOffCovered({
  bookings,
  timeZone,
  onClose,
}: {
  /** The bookings the new time off covers; the dialog is open while there are any. */
  bookings: CalendarBooking[];
  timeZone: string;
  onClose: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const open = bookings.length > 0;
  const [moving, setMoving] = useState<CalendarBooking | null>(null);
  const [moved, setMoved] = useState<number[]>([]);
  const { data } = useFetch(async () => (open ? Promise.all([calendarApi.services(), calendarApi.staff()]) : null), [open]);

  const serviceOf = (booking: CalendarBooking): string => {
    const service = data?.[0].find((s) => s.id === booking.service_id);
    return service ? words.serviceName(service) : '';
  };
  const left = bookings.filter((b) => !moved.includes(b.id));
  const close = () => {
    setMoved([]);
    onClose();
  };

  return (
    <>
      <Dialog open={open && moving === null} onOpenChange={(next) => !next && close()}>
        {open && (
          <DialogContent data-testid="time-off-covered">
            <DialogHeader>
              <DialogTitle>
                {left.length === 1
                  ? t('timeOff.covered.titleOne', 'This time off covers a booking')
                  : t('timeOff.covered.title', 'This time off covers {count} bookings', { count: left.length })}
              </DialogTitle>
              <DialogDescription>
                {t(
                  'timeOff.covered.body',
                  'The time off is saved. Move each booking to another time (the customer is asked first), or keep them where they are.'
                )}
              </DialogDescription>
            </DialogHeader>
            {left.length === 0 ? (
              <p className="text-sm text-muted-foreground" role="status">
                {t('timeOff.covered.allMoved', 'Every booking it covered has been moved.')}
              </p>
            ) : (
              <ul className="divide-y divide-divider" data-testid="time-off-covered-list">
                {left.map((booking) => (
                  <li key={booking.id} className="flex min-h-12 items-center gap-3 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="m-0 truncate text-sm font-medium text-foreground">{booking.customer.name}</p>
                      <p className="m-0 truncate text-sm text-muted-foreground">
                        <span dir="ltr" className="tabular-nums">
                          {dates.dateTime(booking.starts_at) ?? '—'}
                        </span>
                        {serviceOf(booking) !== '' ? ` · ${serviceOf(booking)}` : ''}
                      </p>
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={data === null || data === undefined}
                      onClick={() => setMoving(booking)}
                      data-testid={`time-off-covered-move-${booking.id}`}
                    >
                      {t('timeOff.covered.move', 'Move')}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
            <DialogFooter>
              <Button onClick={close} data-testid="time-off-covered-keep">
                {left.length === 0 ? t('timeOff.covered.done', 'Done') : t('timeOff.covered.keep', 'Keep them')}
              </Button>
            </DialogFooter>
          </DialogContent>
        )}
      </Dialog>
      {data && (
        <BookingSheet
          booking={moving}
          startWith="move"
          onClose={() => setMoving(null)}
          services={data[0]}
          staff={data[1]}
          timeZone={timeZone}
          canChange
          onChanged={(changed, what) => {
            if (what === 'moved' || what === 'cancelled') {
              setMoved((done) => [...done, changed.id]);
              setMoving(null);
            }
          }}
        />
      )}
    </>
  );
}
