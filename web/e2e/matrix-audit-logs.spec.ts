import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';

/**
 * WC-173 role matrix — Audit Logs admin area (/admin/audit-logs).
 *
 * LIVE-VERIFIED access map (WC-175 #191: the "Audit Logs" nav link is
 * RBAC-filtered on requiredPermission audit:read; GET /api/audit-logs enforces
 * the same):
 *   admin    -> link shown, 200 (role grant, core migration)
 *   user     -> link HIDDEN (no audit:read; filtered out server-side, so the
 *               page is not reachable via nav)
 *   delegate -> link shown, 200 (delegated audit:read)
 */
test.describe('Audit Logs (role matrix)', () => {
  test('page surface follows the role access map', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role === 'user') {
      // The "Audit Logs" nav link is filtered out server-side for the plain
      // user (no audit:read), so the page is not reachable via nav.
      await expect(roleSession.shell.navLink('Audit Logs')).toHaveCount(0);
      return;
    }

    const listResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/audit-logs') &&
        res.request().method() === 'GET'
    );
    await roleSession.shell.clickNav('Audit Logs');
    await page.waitForURL('**/admin/audit-logs');
    expect((await listResponse).status()).toBe(200);

    await expect(
      page.getByRole('heading', { name: 'Audit Logs' })
    ).toBeVisible();
    // The chrome is not permission-gated; only the data call is.
    await expect(page.getByRole('button', { name: 'Refresh' })).toBeVisible();

    // admin (role grant) and delegate (delegated audit:read) read the trail.
    // A fresh database may legitimately hold zero entries, so pin "entries
    // or empty state" rather than a row count.
    await expect(
      page.getByTestId('audit-feed').or(page.getByText('No audit entries found'))
    ).toBeVisible();
  });

  test('admin can round-trip the action filter to the backend', async ({
    roleSession,
    role,
    page,
  }) => {
    test.skip(
      role !== 'admin',
      'The filter exercise needs guaranteed 200s; the per-role surface is pinned above.'
    );

    // A link can arrive with an action name that cannot exist: it must round-trip
    // to the API and deterministically produce the filtered empty state, with no
    // dependence on what audit data the environment holds.
    const impossibleAction = `matrix.e2e.${uniqueSuffix()}`;
    const filtered = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/audit-logs') &&
        res.url().includes(`action=${impossibleAction}`)
    );
    await page.goto(`/admin/audit-logs?action=${impossibleAction}`);
    expect((await filtered).status()).toBe(200);
    await expect(page.getByText('Nothing matches these filters')).toBeVisible();

    // Clear restores the unfiltered query (and whatever data it yields).
    const cleared = page.waitForResponse(
      (res) =>
        res.url().includes('/api/v1/audit-logs') &&
        !res.url().includes('action=')
    );
    await page.getByRole('button', { name: 'Clear' }).click();
    expect((await cleared).status()).toBe(200);
    await expect(
      page.getByTestId('audit-feed').or(page.getByText('No audit entries found'))
    ).toBeVisible();
  });
});
