/**
 * The usage screen's pure steps (section 33 §5, SC.14): the rows
 * `GET /api/usage` answers with, and how a number is written.
 * Past a base is a warning only; nothing is ever refused (BM.4).
 */

export type UsageState = 'unlimited' | 'ok' | 'near' | 'over';

export interface UsageMeter {
  meter: string;
  key: string;
  kind: 'counted' | 'snapshot';
  unit: 'count' | 'bytes';
  used: number;
  peak: number;
  base: number | null;
  state: UsageState;
  dimensions: Record<string, number>;
  resets_at: string | null;
}

export interface UsageReport {
  period: string;
  current: boolean;
  resets_at: string | null;
  meters: UsageMeter[];
  periods: string[];
}

const NUMBER = new Intl.NumberFormat('en-US');

/** Western digits, left to right, in both languages. */
export function formatCount(n: number): string {
  return NUMBER.format(n);
}

const UNITS = ['bytes', 'KB', 'MB', 'GB', 'TB'] as const;

/** 2 GB, 512 MB: powers of 1024, one decimal under 10. */
export function formatBytes(n: number): string {
  let value = Math.max(0, n);
  let unit = 0;
  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024;
    unit++;
  }
  const shown = unit === 0 || value >= 10 ? Math.round(value) : Math.round(value * 10) / 10;
  return `${NUMBER.format(shown)} ${UNITS[unit]}`;
}

export function formatAmount(meter: Pick<UsageMeter, 'unit'>, n: number): string {
  return meter.unit === 'bytes' ? formatBytes(n) : formatCount(n);
}

/** How full the meter draws, 0-100; over draws full; no base draws nothing. */
export function usagePercent(meter: Pick<UsageMeter, 'used' | 'base'>): number {
  if (meter.base === null || meter.base <= 0) return meter.base === 0 && meter.used > 0 ? 100 : 0;
  return Math.max(0, Math.min(100, Math.round((meter.used / meter.base) * 100)));
}

/** By how much a meter is past its base, or 0. */
export function usageOverBy(meter: Pick<UsageMeter, 'used' | 'base'>): number {
  return meter.base === null ? 0 : Math.max(0, meter.used - meter.base);
}

/** The split worth a line: dimensions with a name, largest first. */
export function shownDimensions(meter: Pick<UsageMeter, 'dimensions'>): Array<[string, number]> {
  return Object.entries(meter.dimensions)
    .filter(([name, n]) => name !== '' && n > 0)
    .sort((a, b) => b[1] - a[1]);
}
