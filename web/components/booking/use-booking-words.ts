'use client';

/**
 * The public booking pages' shared words (WHIT-945): a service's name in the
 * reader's language, its length, its price, a booking's state, and what a
 * refused request means in plain words. Every call is a literal `t()` so
 * `i18n:extract` can read it.
 */

import { useMemo } from 'react';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { formatMoney } from '@whity/ui/money/currency';
import { localizedName, type LocalizedNames } from '@/lib/localized-name';
import { PublicBookingError, type PublicBooking } from '@/lib/booking/public-api';

/** Money in normal units with the currency's decimals: JOD 12.500. */
export function moneyText(minor: number, currency: string): string {
  try {
    return formatMoney(minor, currency, 'en-GB');
  } catch {
    return `${currency} ${minor}`;
  }
}

export interface BookingWords {
  name: (names: LocalizedNames | unknown[] | null | undefined) => string;
  /** "30 min", "1 hr 30 min" */
  duration: (minutes: number | null | undefined) => string | null;
  /** "JOD 12.500", "No charge", or null when the service shows no price. */
  price: (minor: number | null | undefined, currency: string | null | undefined) => string | null;
  status: (status: PublicBooking['status']) => string;
  /** A refused request in plain words; `fallback` names what failed. */
  errorText: (error: unknown, fallback: string) => string;
}

export function useBookingWords(): BookingWords {
  const t = useTranslation('booking');
  const language = useLanguageCode();

  return useMemo<BookingWords>(() => {
    const name = (names: LocalizedNames | unknown[] | null | undefined) =>
      localizedName(Array.isArray(names) ? {} : names, language, 'en', t('service.unnamed', 'A service'));

    const duration = (minutes: number | null | undefined) => {
      if (minutes === null || minutes === undefined || minutes <= 0) return null;
      const hours = Math.floor(minutes / 60);
      const rest = minutes % 60;
      if (hours === 0) return t('unit.minutes', '{n} min', { n: rest });
      if (rest === 0) return t('unit.hours', '{h} hr', { h: hours });
      return t('unit.hoursMinutes', '{h} hr {n} min', { h: hours, n: rest });
    };

    const price = (minor: number | null | undefined, currency: string | null | undefined) => {
      if (minor === null || minor === undefined || !currency) return null;
      if (minor === 0) return t('price.free', 'No charge');
      return moneyText(minor, currency);
    };

    const status = (value: PublicBooking['status']) => {
      switch (value) {
        case 'confirmed':
          return t('status.confirmed', 'Booked');
        case 'pending_payment':
          return t('status.pendingPayment', 'Waiting for payment');
        case 'requested':
          return t('status.requested', 'Request sent: waiting for an answer');
        case 'cancelled':
          return t('status.cancelled', 'Cancelled');
        default:
          return t('status.expired', 'Not paid in time');
      }
    };

    const errorText = (error: unknown, fallback: string) => {
      if (!(error instanceof PublicBookingError)) {
        return t('error.network', 'We couldn’t reach the booking service. Check your connection and try again.');
      }
      switch (error.status) {
        case 409:
          return t('error.taken', 'Someone has just booked that time. Pick another free time; your details are kept.');
        case 429:
          return t('error.tooMany', 'Too many tries from this connection. Wait a few minutes and try again.');
        case 503:
          return t('error.unavailable', 'Booking is unavailable right now. Try again later.');
        case 404:
          return t('error.gone', 'This booking page isn’t available any more.');
        case 422:
          switch (error.field) {
            case 'name':
              return t('field.name.error', 'Enter your name.');
            case 'email':
              // The server also says "email" when an address has several bookings coming up already.
              return /several/i.test(error.message)
                ? t('field.email.tooMany', 'You already have several bookings coming up here. Cancel one first, or contact the business.')
                : t('field.email.error', 'Enter a valid email address.');
            case 'phone':
              return t('field.phone.error', 'Enter a phone number with digits only, such as +962 79 555 0142.');
            case 'bot_token':
              return t('error.bot', 'We couldn’t confirm you are a person. Try again.');
            default:
              return fallback;
          }
        default:
          return fallback;
      }
    };

    return { name, duration, price, status, errorText };
  }, [t, language]);
}
