import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { ADMIN, SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi, ensureUser } from './support/api';

/**
 * Switching workspace refreshes what is on screen, in this tab and in every
 * other tab, without a manual reload (owner, 2026-11-22: "switching tenants,
 * and similar actions should auto-refresh the page").
 *
 * A person made for this run is staff in the admin's workspace and owns a
 * workspace of their own, which holds a contact the other does not. Two tabs
 * are open: Contacts and Billing. Switching in the first must change the
 * switcher, the list and, in the second tab, the switcher and Billing, with
 * neither page reloading (a marker on `window` survives).
 */

const KEYS = [
  'workspaces.self_create_enabled',
  'workspaces.max_owned_per_person',
  'workspaces.additional_plan',
] as const;

const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

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

const switcher = (page: Page) => page.getByRole('button', { name: /^Switch workspace, current:/ });

/** Marks the document, so a full reload (which would clear it) is caught. */
async function mark(page: Page): Promise<void> {
  await page.evaluate(() => {
    (window as unknown as { __noReload?: boolean }).__noReload = true;
  });
}

async function stillMarked(page: Page): Promise<boolean> {
  return page.evaluate(() => (window as unknown as { __noReload?: boolean }).__noReload === true);
}

test.describe('Switching workspace refreshes the page and the other tabs (2026-11-22)', () => {
  // Signed out: the person made below signs in in this context.
  test.use({ storageState: { cookies: [], origins: [] } });

  test('the switcher, a list and Billing show the new workspace, in both tabs, without a reload', async ({
    page,
    context,
    baseURL,
  }) => {
    if (!baseURL) test.skip();
    const system = await createAuthedApi(baseURL!, SYSTEM_ADMIN);
    const admin = await createAuthedApi(baseURL!, ADMIN);
    const original = await readGlobals(system);
    const run = Date.now().toString(36);
    const person = { email: `e2e-switch-${run}@example.com`, password: `E2e-switch-${run}-Ws1!` };
    const ownName = `E2E switch ${run}`;
    const contactName = `Only in ${ownName}`;

    try {
      await writeGlobals(system, {
        'workspaces.self_create_enabled': 'true',
        'workspaces.max_owned_per_person': '0',
        'workspaces.additional_plan': '',
      });

      // Staff in the admin's workspace, signed in here.
      await ensureUser(admin, { email: person.email, password: person.password, role: 'user' });
      const login = await page.request.post('/api/v1/login', { data: person, headers: XHR });
      expect(login.status(), 'sign in as the new person').toBe(200);
      const startTenant = ((await (await page.request.get('/api/v1/me')).json()) as { user: { tenant_id: number } })
        .user.tenant_id;

      // Their own workspace, with a contact only it has; then back where they started.
      const created = await page.request.post('/api/v1/workspaces', { data: { name: ownName }, headers: XHR });
      expect(created.status(), 'POST /api/v1/workspaces').toBe(201);
      const ownTenant = ((await created.json()) as { data: { tenant_id: number } }).data.tenant_id;
      const into = await page.request.post('/api/v1/auth/switch-tenant', { data: { tenant_id: ownTenant }, headers: XHR });
      expect(into.status()).toBe(200);
      const contact = await page.request.post('/api/v1/contacts', {
        data: { name: contactName, kind: 'customer' },
        headers: XHR,
      });
      expect(contact.status(), 'POST /api/v1/contacts').toBeLessThan(300);
      const back = await page.request.post('/api/v1/auth/switch-tenant', { data: { tenant_id: startTenant }, headers: XHR });
      expect(back.status()).toBe(200);

      // Tab one: Contacts. Tab two: Billing.
      await page.goto('/contacts');
      await expect(switcher(page)).toBeVisible();
      await expect(switcher(page)).not.toHaveAccessibleName(new RegExp(ownName));
      await expect(page.getByText(contactName)).toHaveCount(0);

      const second = await context.newPage();
      await second.goto('/billing');
      await expect(switcher(second)).toBeVisible();
      await expect(switcher(second)).not.toHaveAccessibleName(new RegExp(ownName));

      await mark(page);
      await mark(second);

      // Switch in tab one.
      await switcher(page).click();
      await page.getByRole('option').filter({ hasText: ownName }).getByRole('button').click();

      // Tab one: the switcher and the list are the new workspace's, on the same page.
      await expect(switcher(page)).toHaveAccessibleName(new RegExp(ownName));
      await expect(page.getByText(contactName)).toBeVisible();
      await expect(page).toHaveURL(/\/contacts$/);
      expect(await stillMarked(page), 'tab one did not reload').toBe(true);

      // Tab two followed by itself: the switcher, and Billing as the new workspace's owner.
      await expect(switcher(second)).toHaveAccessibleName(new RegExp(ownName));
      await expect(second.getByTestId('billing-usage-link')).toBeVisible();
      await expect(second.getByTestId('invoices-unreadable')).toHaveCount(0);
      await expect(second).toHaveURL(/\/billing$/);
      expect(await stillMarked(second), 'tab two did not reload').toBe(true);

      // And back from tab two: tab one follows, and the contact leaves its list.
      await switcher(second).click();
      await second.getByRole('option').filter({ hasNotText: ownName }).first().getByRole('button').click();
      await expect(switcher(page)).not.toHaveAccessibleName(new RegExp(ownName));
      await expect(page.getByText(contactName)).toHaveCount(0);
      expect(await stillMarked(page), 'tab one did not reload').toBe(true);

      await second.close();
    } finally {
      await writeGlobals(system, original).catch(() => undefined);
      await system.dispose();
      await admin.dispose();
    }
  });
});
