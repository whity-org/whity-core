import { test, expect, type APIRequestContext } from '@playwright/test';
import { ADMIN, SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi, ensureUser } from './support/api';

/**
 * "Each new workspace pays" (WHIT-1203), against the live stack.
 *
 * With `workspaces.additional_plan` set, a person who already owns a workspace
 * is told on /workspaces/new, before creating it, that their first workspace is
 * free and this one starts a trial of the paid plan, with its monthly price.
 * Creating it puts it on that plan in a trial, behind block_all.
 *
 * It runs as a person made for this run, not the seeded admin: a workspace
 * with members cannot be deleted, and the admin owning extra workspaces would
 * make every later admin sign-in stop at "choose a workspace". The operator
 * settings and the plan are put back afterwards.
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

test.describe('Create a further workspace on a paid plan (WHIT-1203)', () => {
  // Signed out: the person made below signs in in this context.
  test.use({ storageState: { cookies: [], origins: [] } });

  test('the create screen names the trial and price, and the workspace starts that trial', async ({
    page,
    baseURL,
  }) => {
    if (!baseURL) test.skip();
    const system = await createAuthedApi(baseURL!, SYSTEM_ADMIN);
    const admin = await createAuthedApi(baseURL!, ADMIN);
    const original = await readGlobals(system);
    const run = Date.now().toString(36);
    const planKey = `e2e-team-${run}`;
    const person = { email: `e2e-paid-ws-${run}@example.com`, password: `E2e-paid-${run}-Ws1!` };
    let planId: number | null = null;

    try {
      const plan = await system.post('/api/v1/plans', { data: { plan_key: planKey, name: 'Team', is_active: true } });
      expect(plan.status(), 'POST /api/v1/plans').toBe(201);
      planId = ((await plan.json()) as { data: { id: number } }).data.id;
      const price = await system.post(`/api/v1/plans/${planId}/prices`, {
        data: { currency: 'USD', unit_amount: 6900, billing_period: 'month' },
      });
      expect(price.status(), 'POST /api/v1/plans/{id}/prices').toBe(201);

      await writeGlobals(system, {
        'workspaces.self_create_enabled': 'true',
        'workspaces.max_owned_per_person': '0',
        'workspaces.additional_plan': planKey,
        'workspaces.additional_trial_days': '14',
        'billing.default_currency': 'USD',
      });

      // Staff in the admin's workspace (invited, so free), signed in here.
      await ensureUser(admin, { email: person.email, password: person.password, role: 'user' });
      const headers = { 'X-Requested-With': 'XMLHttpRequest' };
      const login = await page.request.post('/api/v1/login', { data: person, headers });
      expect(login.status(), 'sign in as the new person').toBe(200);

      // Their first own workspace is free: no notice yet.
      const before = await page.request.get('/api/v1/workspaces/new');
      const beforeState = (await before.json()) as { data: { owned: number; starts_on: unknown } };
      expect(beforeState.data).toMatchObject({ owned: 0, starts_on: null });
      const first = await page.request.post('/api/v1/workspaces', { data: { name: `E2E first ${run}` }, headers });
      expect(first.status()).toBe(201);

      await page.goto('/workspaces/new');
      const notice = page.getByTestId('workspace-new-paid');
      await expect(notice).toBeVisible();
      await expect(notice).toContainText('Your first workspace is free.');
      await expect(notice).toContainText('This one starts a 14-day trial of Team, then');
      await expect(page.getByTestId('workspace-new-price')).toHaveText('$69.00');
      // One main button.
      await expect(page.getByRole('button', { name: 'Create workspace' })).toHaveCount(1);

      await page.getByLabel('Workspace name').fill(`E2E paid workspace ${Date.now().toString(36)}`);
      const post = page.waitForResponse(
        (res) => res.url().includes('/api/v1/workspaces') && res.request().method() === 'POST'
      );
      await page.getByRole('button', { name: 'Create workspace' }).click();
      const response = await post;
      expect(response.status()).toBe(201);
      const tenantId = ((await response.json()) as { data: { tenant_id: number } }).data.tenant_id;
      // Onward to the template picker (or the dashboard), as before.
      await expect(page).not.toHaveURL(/\/workspaces\/new/);

      const sub = await system.get(`/api/v1/tenants/${tenantId}/subscription`);
      expect(sub.status()).toBe(200);
      const subscription = (await sub.json()) as {
        data: { status: string; plan: { plan_key: string } | null; enforcement_mode: string | null; current_period_end: string | null };
      };
      expect(subscription.data.status).toBe('trialing');
      expect(subscription.data.enforcement_mode).toBe('block_all');
      expect(subscription.data.current_period_end).not.toBeNull();
      expect(subscription.data.plan?.plan_key).toBe(planKey);
    } finally {
      await writeGlobals(system, original).catch(() => undefined);
      if (planId !== null) {
        await system.delete(`/api/v1/plans/${planId}`).catch(() => undefined);
      }
      await system.dispose();
      await admin.dispose();
    }
  });
});
