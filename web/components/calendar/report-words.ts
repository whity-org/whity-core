'use client';

/** Words for the appointment report and the home card (WHIT-1437, AP.6). */

import { useTranslation } from '@whity/features/i18n';

/** A rate, 0 to 1, as "12.5%" with Western digits; "–" when nothing was due. */
export function percent(rate: number | null | undefined): string {
  return rate === null || rate === undefined ? '–' : `${(rate * 100).toFixed(1)}%`;
}

/** The change in percentage points against the period before, in words. */
export function useTrend() {
  const t = useTranslation('calendar');
  return (now: number | null | undefined, before: number | null | undefined): string => {
    if (now === null || now === undefined || before === null || before === undefined) {
      return t('reports.trend.none', 'Nothing to compare with yet');
    }
    const points = Math.round((now - before) * 1000) / 10;
    if (points === 0) return t('reports.trend.same', 'Same as the period before');
    return points > 0
      ? t('reports.trend.up', 'Up {points} points on the period before', { points: points.toFixed(1) })
      : t('reports.trend.down', 'Down {points} points on the period before', { points: Math.abs(points).toFixed(1) });
  };
}

