import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { ADMIN, SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi, ensureUser } from './support/api';

/**
 * The payment step when creating a further workspace (WHIT-1207, owner
 * 2026-11-18), against the live stack.
 *
 * Creating a second workspace leads to /setup/pay: "Add a card" takes the card
 * now and the first charge is when the 30-day trial ends; "Not now" keeps the
 * trial. A workspace whose trial ended unpaid shows a read-only banner, and a
 * payer is reminded in the last 7 days.
 *
 * The CI stack has no payment provider, which the real server says
 * (`pay_online: false`, `card_step: false`). So the first test lets the real
 * trial state through and only marks the provider as present, then stands in
 * for the provider's hosted page; the second runs with no provider at all,
 * exactly as served. Like the WHIT-1203 spec, it runs as a person made for the
 * run, and puts the operator settings and plan back afterwards.
 */

const KEYS = [
  'workspaces.self_create_enabled',
  'workspaces.max_owned_per_person',
  'workspaces.additional_plan',
  'workspaces.additional_trial_days',
  'billing.default_currency',
] as const;

async function readGlobals(api: APIRequestContext): Promise<Record<string, string>> {
  const res = await api.get('/api/v1/settings/global');
  expect(res.status()).toBe(200);
  const body = (await res.json()) as { data: { global: Record<string, string> } };
  return Object.fromEntries(KEYS.map((key) => [key, body.data.global[key] ?? '']));
}

async function writeGlobals(api: APIRequestContext, settings: Record<string, string>): Promise<void> {
  const res = await api.patch('/api/v1/settings/global', { data: { settings } });
  expect(res.status(), 'PATCH /api/v1/settings/global').toBe(200);
}

/** A Team plan at $69.00 a month as the further-workspace plan, a person who owns one workspace, signed in. */
async function setUp(page: Page, baseURL: string) {
  const system = await createAuthedApi(baseURL, SYSTEM_ADMIN);
  const admin = await createAuthedApi(baseURL, ADMIN);
  const original = await readGlobals(system);
  const run = crypto.randomUUID().slice(0, 12);
  const planKey = `e2e-team-pay-${run}`;
  const person = { email: `e2e-pay-step-${run}@example.com`, password: `E2e-pay-${run}-Ws1!` };

  const plan = await system.post('/api/v1/plans', { data: { plan_key: planKey, name: 'Team', is_active: true } });
  expect(plan.status(), 'POST /api/v1/plans').toBe(201);
  const planId = ((await plan.json()) as { data: { id: number } }).data.id;
  const price = await system.post(`/api/v1/plans/${planId}/prices`, {
    data: { currency: 'USD', unit_amount: 6900, billing_period: 'month' },
  });
  expect(price.status(), 'POST /api/v1/plans/{id}/prices').toBe(201);

  await writeGlobals(system, {
    'workspaces.self_create_enabled': 'true',
    'workspaces.max_owned_per_person': '0',
    'workspaces.additional_plan': planKey,
    'workspaces.additional_trial_days': '30',
    'billing.default_currency': 'USD',
  });

  await ensureUser(admin, { email: person.email, password: person.password, role: 'user' });
  const headers = { 'X-Requested-With': 'XMLHttpRequest' };
  const login = await page.request.post('/api/v1/login', { data: person, headers });
  expect(login.status(), 'sign in as the new person').toBe(200);

  // The first own workspace is free and never sees the step.
  const first = await page.request.post('/api/v1/workspaces', { data: { name: `E2E free ${run}` }, headers });
  expect(first.status()).toBe(201);
  const firstTrial = await page.request.get('/api/v1/billing/trial');
  expect(firstTrial.status()).toBe(200);

  const restore = async () => {
    await writeGlobals(system, original).catch(() => undefined);
    await system.delete(`/api/v1/plans/${planId}`).catch(() => undefined);
    await system.dispose();
    await admin.dispose();
  };

  return { system, run, restore };
}

async function createSecondWorkspace(page: Page, name: string): Promise<number> {
  await page.goto('/workspaces/new');
  await expect(page.getByTestId('workspace-new-paid')).toContainText('This one starts a 30-day trial of Team, then');
  await page.getByLabel('Workspace name').fill(name);
  const post = page.waitForResponse((res) => res.url().endsWith('/api/v1/workspaces') && res.request().method() === 'POST');
  await page.getByRole('button', { name: 'Create workspace' }).click();
  const response = await post;
  expect(response.status()).toBe(201);

  return ((await response.json()) as { data: { tenant_id: number } }).data.tenant_id;
}

test.describe('The payment step when creating a further workspace (WHIT-1207)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('asks for a card, keeps the trial free, and comes back to setting up', async ({ page, baseURL }) => {
    if (!baseURL) test.skip();
    const { run, restore } = await setUp(page, baseURL!);

    try {
      // The real trial state, with the provider marked present (none on CI).
      const seen: { trial: Record<string, unknown> | null; checkout: unknown } = { trial: null, checkout: null };
      await page.route('**/api/v1/billing/trial', async (route) => {
        const real = await route.fetch();
        const body = (await real.json()) as { data: Record<string, unknown> | null };
        seen.trial = body.data;
        const data = body.data === null ? null : { ...body.data, pay_online: true, card_step: true };
        await route.fulfill({ response: real, json: { data } });
      });
      // The provider's hosted page sends the payer straight back.
            await page.route('**/api/v1/billing/checkout', async (route) => {
        seen.checkout = route.request().postDataJSON();
        await route.fulfill({ json: { data: { url: 'https://checkout.example.test/cs_e2e', trial_days: 30 } } });
      });
      // The app only ever leaves for an https page; this one sends the payer back.
      await page.route('https://checkout.example.test/**', (route) =>
        route.fulfill({ status: 302, headers: { Location: `${baseURL}/setup/pay/done?checkout=cs_e2e` } })
      );
      await page.route('**/api/v1/billing/return**', (route) =>
        route.fulfill({
          json: {
            data: {
              has_access: true,
              status: 'trialing',
              plan: 'Team',
              access_until: new Date(Date.now() + 30 * 86_400_000).toISOString(),
              cancel_at_period_end: false,
              checkout_status: 'complete',
            },
          },
        })
      );

      await createSecondWorkspace(page, `E2E pay step ${run}`);

      await expect(page).toHaveURL(/\/setup\/pay$/);
      const step = page.getByTestId('workspace-pay-step');
      await expect(page.getByRole('heading', { name: /^Add a card for E2E pay step/ })).toBeVisible();
      await expect(step).toContainText('Your 30-day trial of Team is free.');
      await expect(page.getByTestId('workspace-pay-price')).toHaveText('$69.00');
      await expect(step).toContainText('Cancel before then and you pay nothing.');
      // The server's own answer: a 30-day trial of Team, no card yet, not read-only.
      expect(seen.trial).toMatchObject({ plan_name: 'Team', status: 'trialing', days_left: 30, read_only: false, card_added: false });
      // One main button; Not now is the outline beside it.
      await expect(page.getByRole('button', { name: 'Add a card' })).toHaveCount(1);
      await expect(page.getByRole('button', { name: 'Not now' })).toBeVisible();

      await page.getByRole('button', { name: 'Add a card' }).click();
      await expect(page).toHaveURL(/\/setup\/pay\/done\?checkout=cs_e2e/);
      expect(seen.checkout).toEqual({ plan_key: seen.trial?.plan_key, after: 'setup' });
      await expect(page.getByTestId('workspace-pay-added')).toContainText('Card added');
      await expect(page.getByTestId('workspace-pay-added')).toContainText('Your trial stays free until');

      await page.getByTestId('workspace-pay-continue').click();
      await expect(page).toHaveURL(/\/(setup\/template|dashboard)/);
    } finally {
      await restore();
    }
  });

  test('with no provider it goes straight on; an ended trial is read-only, and the last week reminds', async ({
    page,
    baseURL,
  }) => {
    if (!baseURL) test.skip();
    const { system, run, restore } = await setUp(page, baseURL!);

    try {
      const tenantId = await createSecondWorkspace(page, `E2E no provider ${run}`);
      // Nothing to pay with online: the step passes through to setting up.
      await expect(page).toHaveURL(/\/(setup\/template|dashboard)/);

      const trial = await page.request.get('/api/v1/billing/trial');
      expect(((await trial.json()) as { data: unknown }).data).toMatchObject({
        status: 'trialing',
        days_left: 30,
        pay_online: false,
        card_step: false,
      });

      const at = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 19).replace('T', ' ');

      // Three days left: the owner is reminded.
      const soon = await system.put(`/api/v1/tenants/${tenantId}/subscription`, { data: { current_period_end: at(3) } });
      expect(soon.status()).toBe(200);
      await page.goto('/dashboard');
      const reminder = page.getByTestId('workspace-trial-reminder');
      await expect(reminder).toContainText(/ends in 3 days|ends tomorrow|ends in 2 days/);
      await expect(reminder).toContainText('ask the people who run this Whity how to pay');

      // Ended unpaid: read-only, nothing deleted, no reminder.
      const ended = await system.put(`/api/v1/tenants/${tenantId}/subscription`, { data: { current_period_end: at(-1) } });
      expect(ended.status()).toBe(200);
      await page.goto('/dashboard');
      const banner = page.getByTestId('workspace-read-only-banner');
      await expect(banner).toContainText('is read-only.');
      await expect(banner).toContainText('The trial of Team ended without payment. Nothing was deleted.');
      await expect(banner).toContainText('Ask the people who run this Whity how to pay.');
      await expect(page.getByTestId('workspace-trial-reminder')).toHaveCount(0);
    } finally {
      await restore();
    }
  });
});
