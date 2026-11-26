'use client';

/**
 * One line on a customer's record about their bookings (WHIT-1427, design
 * section 39 §4.3, AP.8): "Came 12 · Didn't come 2 · Late cancels 1 · since
 * March 2026", and the flag "Missed 2 of the last 5" when the workspace's
 * policy applies, always in words. A person who manages the calendar can
 * start the count again from today (AP.10); it is recorded. Nothing shows to
 * someone who can't read the calendar, or for a customer with no bookings.
 */

import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { calendarApi, type CalendarCustomerAttendance } from '@/lib/calendar/api';
import { useCalendarAccess } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';

export function CustomerAttendanceLine({ contactId }: { contactId: number }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();
  const { calendarLabel } = useDateDisplay();
  const [changed, setChanged] = useState<CalendarCustomerAttendance | null>(null);
  const [resetting, setResetting] = useState(false);
  const { data } = useFetch(
    () => (access.canRead ? calendarApi.customerAttendance({ contactId }) : Promise.resolve(null)),
    [contactId, access.canRead]
  );
  const line = changed ?? data;
  if (!line || line.since === null) return null;

  const reset = async () => {
    setResetting(true);
    try {
      setChanged(await calendarApi.resetCustomerAttendance({ contact_id: contactId }));
      addToast(t('attendance.resetDone', 'Their count starts again from today'), 'success');
    } catch (error) {
      addToast(words.errorText(error, t('booking.actError', 'That didn’t work. Try again.')), 'error');
    } finally {
      setResetting(false);
    }
  };

  return (
    <div className="space-y-1 text-sm" data-testid="customer-attendance">
      <p className="text-foreground">
        {t('attendance.line', 'Came {came} · Didn’t come {noShow} · Late cancels {late} · since {since}', {
          came: line.came,
          noShow: line.no_show,
          late: line.late_cancels,
          since: calendarLabel(line.since, 'monthYear') ?? '—',
        })}
      </p>
      {line.flag && (
        <div className="flex flex-wrap items-center gap-3">
          <span className="inline-flex items-center gap-1 text-warning-text" data-testid="customer-attendance-flag">
            <Icon name="alert" size={14} aria-hidden />
            {t('flag.missed', 'Missed {misses} of the last {of}', { misses: line.flag.misses, of: line.flag.of })}
          </span>
          {access.canManage && (
            <Button variant="outline" size="sm" onClick={() => void reset()} loading={resetting} data-testid="customer-attendance-reset">
              {t('attendance.reset', 'Start again from today')}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
