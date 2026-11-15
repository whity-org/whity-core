/**
 * Where a workspace stands against its plan (WHIT-932, design 3.4
 * `TplPlanLimit`, BM.11): the rows `GET /api/billing/limits` answers with and
 * the pure steps both Billing and the banner across the app take from them.
 * Passing a limit warns; nothing is ever blocked or removed.
 */

export interface PlanLimit {
  key: string;
  kind: 'count' | 'feature';
  used: number | null;
  limit: number | null;
  over: boolean;
  resets_at: string | null;
}

/** At 80% a count limit is "near": a quiet note on Billing only. */
export const NEAR_SHARE = 0.8;

/** The limits Billing and the banner name. Anything else the server adds is left out until it has words. */
export const KNOWN_LIMITS = [
  'members.max',
  'customers.per_month',
  'agents.runs.per_month',
  'messages.outbound.per_month',
  'documents.render.per_day',
  'documents.render.per_month',
  'brand.custom_domain',
] as const;

export function isKnown(key: string): boolean {
  return (KNOWN_LIMITS as readonly string[]).includes(key);
}

/** The rows worth a line: a count with a number, or a feature in use beyond the plan. */
export function shownRows(rows: PlanLimit[]): PlanLimit[] {
  return rows.filter((row) => isKnown(row.key) && (row.kind === 'feature' || row.limit !== null));
}

export type LimitState = 'over' | 'near' | 'fine';

export function stateOf(row: PlanLimit): LimitState {
  if (row.over) return 'over';
  if (row.kind === 'count' && row.used !== null && row.limit !== null && row.limit > 0 && row.used >= row.limit * NEAR_SHARE) {
    return 'near';
  }
  return 'fine';
}

/** How full the meter draws, 0-100; over draws full. */
export function meterPercent(row: PlanLimit): number {
  if (row.used === null || row.limit === null || row.limit <= 0) return 0;
  return Math.max(0, Math.min(100, Math.round((row.used / row.limit) * 100)));
}

/** By how many a count limit is passed, or 0. */
export function overBy(row: PlanLimit): number {
  return row.kind === 'count' && row.used !== null && row.limit !== null ? Math.max(0, row.used - row.limit) : 0;
}

/** Days the banner stays hidden after "Hide for 7 days". */
export const BANNER_HIDE_DAYS = 7;

export function bannerHideKey(tenantId: number | string | null | undefined): string {
  return `whity.planLimitBanner.hiddenUntil.${tenantId ?? 'none'}`;
}

/** Whether a stored "hidden until" still hides the banner at `now`. */
export function bannerHidden(hiddenUntil: string | null, now: Date = new Date()): boolean {
  if (!hiddenUntil) return false;
  const at = Date.parse(hiddenUntil);
  return !Number.isNaN(at) && at > now.getTime();
}
