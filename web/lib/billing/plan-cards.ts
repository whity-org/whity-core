/**
 * PLAN CARDS: one card per plan, priced for the period the reader picked
 * (owner 23 Nov 2026: "a normal cards view with clear what it comes with and
 * a top of page year/month switch").
 *
 * `/api/v1/billing/plans` lists one row per PRICE, so a plan sold monthly and
 * yearly arrives twice. These helpers fold the rows into plans, pick the price
 * for the chosen period (falling back to monthly for a plan sold only monthly,
 * such as Free or Enterprise), and turn entitlements into plain lines.
 */

export type BillingPeriod = 'month' | 'year';

export type EntitlementValue = boolean | number | string;

export interface PriceRow {
  plan_key: string;
  name: string;
  description: string | null;
  is_addon: boolean;
  is_current?: boolean;
  unit_amount: number;
  currency: string;
  billing_period: string;
  is_per_seat?: boolean;
  is_per_device?: boolean;
  entitlements?: Record<string, EntitlementValue>;
}

export interface PlanCard {
  plan_key: string;
  name: string;
  description: string | null;
  is_current: boolean;
  entitlements: Record<string, EntitlementValue>;
  /** The price shown for the chosen period. */
  price: PriceRow;
  /** The monthly price, when there is one; for "save" and the per-month figure. */
  monthly: PriceRow | null;
  /** The price is not in the chosen period (e.g. monthly only while Yearly is picked). */
  periodFallback: boolean;
}

const perMonth = (row: PriceRow): number =>
  row.billing_period === 'year' ? row.unit_amount / 12
    : row.billing_period === 'half_year' ? row.unit_amount / 6
      : row.unit_amount;

/** Whether any plan has a yearly price, so the switch is worth showing. */
export function offersYearly(rows: PriceRow[]): boolean {
  return rows.some((row) => row.billing_period === 'year');
}

export function planCards(rows: PriceRow[], period: BillingPeriod): PlanCard[] {
  const byPlan = new Map<string, PriceRow[]>();
  for (const row of rows) {
    byPlan.set(row.plan_key, [...(byPlan.get(row.plan_key) ?? []), row]);
  }

  const cards: PlanCard[] = [];
  for (const [planKey, prices] of byPlan) {
    const monthly = prices.find((p) => p.billing_period === 'month') ?? null;
    const exact = prices.find((p) => p.billing_period === period);
    const price = exact ?? monthly ?? prices[0];
    if (price === undefined) continue;
    cards.push({
      plan_key: planKey,
      name: price.name,
      description: price.description,
      is_current: prices.some((p) => p.is_current === true),
      entitlements: price.entitlements ?? {},
      price,
      monthly,
      periodFallback: exact === undefined,
    });
  }

  return cards.sort((a, b) => perMonth(a.price) - perMonth(b.price) || a.plan_key.localeCompare(b.plan_key));
}

/** What paying yearly saves against twelve monthly payments, in minor units; 0 when nothing. */
export function yearlySaving(card: PlanCard): number {
  if (card.price.billing_period !== 'year' || card.monthly === null) return 0;
  return Math.max(0, card.monthly.unit_amount * 12 - card.price.unit_amount);
}

/**
 * The lines a card lists, in a fixed order: the counted limits first, then
 * the features included. Keys a person would not recognise (rate limits,
 * render counts, device caps) are left out.
 */
export type FeatureLine =
  | { kind: 'limit'; key: string; value: number; unlimited: boolean }
  | { kind: 'feature'; key: string };

export const LIMIT_KEYS = [
  'members.max',
  'customers.per_month',
  'inventory.items.max',
  'invoices.per_month',
  'storage.quota_bytes',
  'messages.outbound.per_month',
  'agents.runs.per_month',
  'api.calls.per_month',
] as const;

export const FEATURE_KEYS = [
  'documents.form_printouts',
  'brand.remove_badge',
  'brand.custom_domain',
  'brand.own_email_sender',
  'brand.own_app',
  'sso.tenant_idp',
  'storage.custom_backend',
  'mcp.access',
] as const;

export function featureLines(entitlements: Record<string, EntitlementValue>): FeatureLine[] {
  const lines: FeatureLine[] = [];
  for (const key of LIMIT_KEYS) {
    const value = entitlements[key];
    if (typeof value === 'number') {
      lines.push({ kind: 'limit', key, value, unlimited: value < 0 });
    }
  }
  for (const key of FEATURE_KEYS) {
    if (entitlements[key] === true) {
      lines.push({ kind: 'feature', key });
    }
  }
  return lines;
}
