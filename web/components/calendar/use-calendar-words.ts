'use client';

/**
 * The calendar's shared words (WHIT-944): weekday names, booking states,
 * where a booking came from, and what a refused request means in plain words.
 *
 * One file, so a word the calendar, the hours editor and the settings screen
 * all show has one key and one English source. Every call here is a literal
 * `t()` so `i18n:extract` can read it; the lookups switch on a closed set
 * rather than building a key.
 */

import { useMemo } from 'react';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { localizedName } from '@/lib/localized-name';
import { CalendarRequestError, type CalendarBooking, type CalendarService } from '@/lib/calendar/api';

export interface CalendarWords {
  /** "Monday" for ISO weekday 1. */
  weekday: (isoWeekday: number) => string;
  /** "Mon" for ISO weekday 1. */
  weekdayShort: (isoWeekday: number) => string;
  status: (status: CalendarBooking['status']) => string;
  source: (source: CalendarBooking['source']) => string;
  /** "45 min" */
  minutes: (n: number) => string;
  /** A service's name in the reader's language, else the other one. */
  serviceName: (service: CalendarService | null | undefined) => string;
  /** A refused request in plain words; `fallback` names what failed. */
  errorText: (error: unknown, fallback: string) => string;
}

export function useCalendarWords(): CalendarWords {
  const t = useTranslation('calendar');
  const language = useLanguageCode();

  return useMemo<CalendarWords>(() => {
    const weekday = (iso: number) => {
      switch (iso) {
        case 1:
          return t('weekday.monday', 'Monday');
        case 2:
          return t('weekday.tuesday', 'Tuesday');
        case 3:
          return t('weekday.wednesday', 'Wednesday');
        case 4:
          return t('weekday.thursday', 'Thursday');
        case 5:
          return t('weekday.friday', 'Friday');
        case 6:
          return t('weekday.saturday', 'Saturday');
        default:
          return t('weekday.sunday', 'Sunday');
      }
    };
    const weekdayShort = (iso: number) => {
      switch (iso) {
        case 1:
          return t('weekday.short.monday', 'Mon');
        case 2:
          return t('weekday.short.tuesday', 'Tue');
        case 3:
          return t('weekday.short.wednesday', 'Wed');
        case 4:
          return t('weekday.short.thursday', 'Thu');
        case 5:
          return t('weekday.short.friday', 'Fri');
        case 6:
          return t('weekday.short.saturday', 'Sat');
        default:
          return t('weekday.short.sunday', 'Sun');
      }
    };
    const status = (value: CalendarBooking['status']) => {
      switch (value) {
        case 'confirmed':
          return t('booking.status.confirmed', 'Booked');
        case 'pending_payment':
          return t('booking.status.pendingPayment', 'Waiting for payment');
        case 'cancelled':
          return t('booking.status.cancelled', 'Cancelled');
        default:
          return t('booking.status.expired', 'Not paid in time');
      }
    };
    const source = (value: CalendarBooking['source']) => {
      switch (value) {
        case 'public':
          return t('booking.source.public', 'Booked online by the customer');
        case 'agent':
          return t('booking.source.agent', 'Booked by an agent, approved by a person');
        default:
          return t('booking.source.staff', 'Booked by the team');
      }
    };
    const minutes = (n: number) => t('unit.minutes', '{n} min', { n });
    const serviceName = (service: CalendarService | null | undefined) =>
      service ? localizedName(service.name, language, 'en', t('service.unnamed', 'Unnamed service')) : t('service.unknown', 'A removed service');
    const errorText = (error: unknown, fallback: string) => {
      if (error instanceof CalendarRequestError) {
        switch (error.status) {
          case 409:
            return t('error.taken', 'That time was just taken. Pick another time.');
          case 403:
            return t('error.forbidden', 'You can’t change this. Ask someone who manages the calendar.');
          case 404:
            return t('error.gone', 'This was removed, or isn’t in this workspace any more.');
          case 422:
            // The server's sentence names the value at fault; it is English only for now.
            return error.serverMessage ?? fallback;
          default:
            return fallback;
        }
      }
      return fallback;
    };
    return { weekday, weekdayShort, status, source, minutes, serviceName, errorText };
  }, [t, language]);
}
