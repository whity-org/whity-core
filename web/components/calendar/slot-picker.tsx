'use client';

/**
 * Pick a free time in the staff calendar (WHIT-944): New booking and Move. Since
 * WHIT-1051 (issue #1933, ADR 0018) this is the public booking pages' picker,
 * `FreeTimePicker`, fed by `GET /calendar/availability` for one service, so
 * staff and customers choose a time the same way. This file only knows where
 * the staff's times come from and keeps the props the booking dialogs already
 * use.
 *
 * `reloadKey` refetches: the booking dialog bumps it after a 409, so the time
 * that was just taken disappears and the others are fresh.
 */

import { useCallback } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { FreeTimePicker } from '@/components/booking/free-time-picker';
import { calendarApi, type CalendarSlot } from '@/lib/calendar/api';
import type { CalendarDate } from '@/lib/calendar/layout';

export interface SlotPickerProps {
  id: string;
  serviceId: number | null;
  /** One person, or null for whoever is free. */
  profileId: number | null;
  date: CalendarDate;
  onDateChange: (date: CalendarDate) => void;
  timeZone: string;
  selected: CalendarSlot | null;
  onSelect: (slot: CalendarSlot | null) => void;
  reloadKey?: number;
  /** A time to leave out, such as the booking's own when moving it. */
  exclude?: string | null;
}

export function SlotPicker({
  id,
  serviceId,
  profileId,
  date,
  onDateChange,
  timeZone,
  selected,
  onSelect,
  reloadKey = 0,
  exclude = null,
}: SlotPickerProps) {
  const t = useTranslation('calendar');
  const load = useCallback(
    (range: { from: string; to: string }) =>
      serviceId === null ? Promise.resolve<CalendarSlot[]>([]) : calendarApi.availability(serviceId, { ...range, profileId }),
    [serviceId, profileId]
  );

  if (serviceId === null) {
    return (
      <p className="text-sm text-muted-foreground" data-testid={`${id}-slots`}>
        {t('slots.chooseService', 'Choose a service to see its free times.')}
      </p>
    );
  }

  return (
    <div data-testid={`${id}-slots`}>
      <FreeTimePicker<CalendarSlot>
        id={id}
        timeZone={timeZone}
        earliest={null}
        startOn={date}
        day={date}
        onDayChange={onDateChange}
        latest={null}
        load={load}
        loadKey={`${serviceId}|${profileId ?? ''}`}
        selected={selected}
        onSelect={onSelect}
        reloadKey={reloadKey}
        exclude={exclude === null ? null : { starts_at: exclude }}
      />
    </div>
  );
}
