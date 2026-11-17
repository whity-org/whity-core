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
import { calendarApi, type CalendarSlot, type ServiceChoice } from '@/lib/calendar/api';
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
  /**
   * WHIT-1173: several services back to back. With two or more, the free
   * times are those when all of them fit, and `serviceId`/`profileId` are
   * not used.
   */
  several?: ServiceChoice[] | null;
  /** With `several`: one person who does them all. */
  samePerson?: boolean;
  /** With `several`: moving this booking, so its own time counts as free. */
  exceptBookingId?: number | null;
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
  several = null,
  samePerson = false,
  exceptBookingId = null,
}: SlotPickerProps) {
  const t = useTranslation('calendar');
  const manyKey = several !== null && several.length > 1 ? several.map((c) => `${c.service_id}:${c.profile_id ?? 0}`).join(',') : '';
  const load = useCallback(
    (range: { from: string; to: string }) => {
      if (manyKey !== '') {
        // Rebuilt from the key, so a new array with the same choices does not reload.
        const choices = manyKey.split(',').map((part) => {
          const [service, person] = part.split(':').map(Number);
          return { service_id: service, profile_id: person === 0 ? null : person };
        });
        return calendarApi.availabilityMany(choices, { ...range, samePerson, exceptBookingId });
      }
      return serviceId === null ? Promise.resolve<CalendarSlot[]>([]) : calendarApi.availability(serviceId, { ...range, profileId });
    },
    [serviceId, profileId, manyKey, samePerson, exceptBookingId]
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
        loadKey={`${serviceId}|${profileId ?? ''}|${manyKey}|${samePerson ? 1 : 0}`}
        selected={selected}
        onSelect={onSelect}
        reloadKey={reloadKey}
        exclude={exclude === null ? null : { starts_at: exclude }}
      />
    </div>
  );
}
