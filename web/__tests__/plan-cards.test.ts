import { featureLines, localizePlanText, offersYearly, planCards, yearlySaving, type PriceRow } from '@/lib/billing/plan-cards';

const row = (plan_key: string, billing_period: string, unit_amount: number, extra: Partial<PriceRow> = {}): PriceRow => ({
  plan_key,
  name: plan_key[0].toUpperCase() + plan_key.slice(1),
  description: null,
  is_addon: false,
  unit_amount,
  currency: 'USD',
  billing_period,
  ...extra,
});

const ROWS: PriceRow[] = [
  row('enterprise', 'month', 100000),
  row('team', 'year', 69000),
  row('free', 'month', 0),
  row('team', 'month', 6900, { entitlements: { 'members.max': 25, 'brand.remove_badge': true } }),
  row('business', 'month', 19900),
  row('business', 'year', 199000, { is_current: true }),
];

describe('plan cards', () => {
  it('folds one row per price into one card per plan, cheapest first', () => {
    expect(planCards(ROWS, 'month').map((c) => c.plan_key)).toEqual(['free', 'team', 'business', 'enterprise']);
  });

  it('prices each card for the chosen period and falls back to monthly', () => {
    const yearly = planCards(ROWS, 'year');
    const team = yearly.find((c) => c.plan_key === 'team')!;
    const enterprise = yearly.find((c) => c.plan_key === 'enterprise')!;
    expect(team.price.billing_period).toBe('year');
    expect(team.periodFallback).toBe(false);
    expect(enterprise.price.billing_period).toBe('month');
    expect(enterprise.periodFallback).toBe(true);
  });

  it('says what yearly saves against twelve months', () => {
    const team = planCards(ROWS, 'year').find((c) => c.plan_key === 'team')!;
    expect(yearlySaving(team)).toBe(6900 * 12 - 69000);
    expect(yearlySaving(planCards(ROWS, 'month').find((c) => c.plan_key === 'team')!)).toBe(0);
  });

  it('marks the plan the workspace is on, whichever period it pays', () => {
    expect(planCards(ROWS, 'month').find((c) => c.plan_key === 'business')!.is_current).toBe(true);
  });

  it('only offers the switch when something is sold yearly', () => {
    expect(offersYearly(ROWS)).toBe(true);
    expect(offersYearly(ROWS.filter((r) => r.billing_period === 'month'))).toBe(false);
  });

  it('lists limits then features, unlimited marked, unknown keys left out', () => {
    expect(
      featureLines({
        'brand.custom_domain': true,
        'ratelimit.rpm': -1,
        'members.max': -1,
        'customers.per_month': 5000,
        'brand.own_app': false,
      })
    ).toEqual([
      { kind: 'limit', key: 'members.max', value: -1, unlimited: true },
      { kind: 'limit', key: 'customers.per_month', value: 5000, unlimited: false },
      { kind: 'feature', key: 'brand.custom_domain' },
    ]);
  });
});

describe('localizePlanText (WHIT-1398)', () => {
  const echo = (key: string, fallback: string) => `${key}|${fallback}`;

  it('gives the four standard plans translatable names and descriptions', () => {
    const text = localizePlanText(echo, 'free', 'Free', 'Up to 2 staff and 100 customers a month.');
    expect(text.name).toBe('billing.plans.plan.free.name|Free');
    expect(text.description).toBe('billing.plans.plan.free.description|Up to 3 staff and 200 customers a month.');
  });

  it('keeps the database text for any other plan', () => {
    expect(localizePlanText(echo, 'salon_pro', 'Salon Pro', null)).toEqual({ name: 'Salon Pro', description: null });
  });
});
