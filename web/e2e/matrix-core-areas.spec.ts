import { test, expect } from './support/fixtures';

/**
 * WC-173 role matrix — core admin data areas sweep (Users / Roles / Tenants /
 * Organizational Units / System Statistics).
 *
 * Admin CRUD depth lives in the dedicated per-area specs (users.spec.ts,
 * roles.spec.ts, ous-*.spec.ts, stats.spec.ts) — the admin branch here only
 * pins the rendered surface (heading + primary control).
 *
 * NEW MODEL (WC-175 #191): `GET /api/navigation` is RBAC-filtered server-side
 * per caller, so each of these admin-gated links is hidden for any caller that
 * lacks the page's RBAC. All five areas require the admin role, which neither
 * the plain user nor the delegate holds (the delegated set is relations:read +
 * audit:read + hello:view ONLY), so for user AND delegate the nav link is
 * simply ABSENT — there is nothing to navigate to. We therefore pin the nav
 * link's absence for those roles instead of the old data-layer denial surface;
 * the admin still sees the full data surface.
 */
test.describe('Core admin areas (role matrix)', () => {
  test('Users page renders for admin; the nav link is hidden for user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role === 'admin') {
      await roleSession.shell.clickNav('Users');
      await page.waitForURL('**/admin/users');
      await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
      await expect(
        page.getByRole('button', { name: 'Invite people' })
      ).toBeVisible();
      // Named: the users page also renders a pending-invitations table.
      await expect(page.getByRole('table', { name: 'Users' })).toBeVisible();
    } else {
      // requiredRole 'admin' — the link is filtered out server-side.
      await expect(roleSession.shell.navLink('Users')).toHaveCount(0);
    }
  });

  test('Roles page renders for admin; the nav link is hidden for user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role === 'admin') {
      await roleSession.shell.clickNav('Roles');
      await page.waitForURL('**/admin/roles');
      await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();
      await expect(
        page.getByRole('button', { name: 'Create Role' })
      ).toBeVisible();
      await expect(page.getByRole('table')).toBeVisible();
    } else {
      // requiredRole 'admin' — the link is filtered out server-side.
      await expect(roleSession.shell.navLink('Roles')).toHaveCount(0);
    }
  });

  test('Tenants page renders for admin; the nav link is hidden for user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role === 'admin') {
      await roleSession.shell.clickNav('Tenants');
      await page.waitForURL('**/admin/tenants');
      await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
      await expect(
        page.getByRole('button', { name: 'Create Tenant' })
      ).toBeVisible();
      await expect(page.getByRole('table')).toBeVisible();
    } else {
      // requiredRole 'admin' — the link is filtered out server-side.
      await expect(roleSession.shell.navLink('Tenants')).toHaveCount(0);
    }
  });

  test('Organizational Units page renders for admin; the nav link is hidden for user and delegate', async ({
    roleSession,
    role,
    page,
  }) => {
    if (role === 'admin') {
      await roleSession.shell.clickNav('Organizational Units');
      await page.waitForURL('**/admin/ous');
      await expect(
        page.getByRole('heading', { name: 'Organisational units' }).first()
      ).toBeVisible();
      await expect(
        page.getByRole('button', { name: /New unit|Add the first unit/ }).first()
      ).toBeVisible();
    } else {
      // requiredRole 'admin' — the link is filtered out server-side.
      await expect(
        roleSession.shell.navLink('Organizational Units')
      ).toHaveCount(0);
    }
  });

  test("Dashboard is every role's link; System is hidden in a workspace", async ({ roleSession }) => {
    // System is gated on `stats:read` in the system tenant (H.3). None of these
    // roles is acting there, including the workspace admin who holds that slug.
    await expect(roleSession.shell.navLink('Dashboard')).toBeVisible();
    await expect(roleSession.shell.navLink('System')).toHaveCount(0);
  });
});
