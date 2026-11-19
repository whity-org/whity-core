import { test, expect } from './support/fixtures';
import { AppShell, LoginPage } from './support/pages';
import { ADMIN, SIDEBAR_SECTIONS } from './support/constants';

test.describe('Sidebar navigation (admin)', () => {
  test('all expected sidebar sections are present', async ({ adminPage }) => {
    // The sidebar now opens only the group holding the current page (#1007),
    // so open them all first — otherwise this asserts the disclosure state
    // rather than the RBAC-filtered set it exists to check.
    for (const section of SIDEBAR_SECTIONS) {
      // Opening a group folds the others, so each link opens its own.
      await adminPage.shell.revealNavLink(section.label);
      // navLink resolves by unique href, so "Settings" vs "Website Settings"
      // (both end in "Settings") don't collide under strict mode.
      await expect(adminPage.shell.navLink(section.label)).toBeVisible();
    }
    await expect(adminPage.shell.accountMenuButton).toBeVisible();
  });

  test('Users section loads its page with heading and table', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    // The page renders TWO tables (users, and pending invitations), and both
    // have an Email column — so every query here has to be scoped to one of
    // them, not just the query for the table element itself. Naming the table
    // and then searching the whole page again would fix the first strict-mode
    // violation and leave the second.
    const usersTable = page.getByRole('table', { name: 'Users' });
    await expect(usersTable).toBeVisible();
    await expect(usersTable.getByRole('columnheader', { name: 'Person' })).toBeVisible();
  });

  test('Roles section loads its page with heading, table and Create button', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Roles');
    await page.waitForURL('**/admin/roles');
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create Role' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Permissions' })).toBeVisible();
  });

  test('Organizational Units section loads its page', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(
      page.getByRole('heading', { name: 'Organisational units' }).first()
    ).toBeVisible();
    await expect(page.getByRole('button', { name: /New unit|Add the first unit/ }).first()).toBeVisible();
  });

  test("Tenants is not in a workspace admin's sidebar, and the URL says they have no access", async ({ adminPage, page }) => {
    await page.goto('/admin/tenants');
    await expect(adminPage.shell.navLink('Workspaces')).toHaveCount(0);
    await expect(page.getByText('You don’t have access to workspaces')).toBeVisible();
    await expect(page.getByRole('table')).toHaveCount(0);
  });

  test('Settings General leads a workspace admin to their workspace record', async ({ adminPage, page }) => {
    // Settings opens My settings first (WHIT-1081); General is in its sub-nav.
    await adminPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings/profile');
    await page.getByTestId('settings-area-link-general').click();
    await page.waitForURL('**/admin/settings');
    await page.getByRole('link', { name: 'Open workspace record' }).click();
    await page.waitForURL(/\/admin\/tenants\/\d+$/);
    await expect(page.getByText('This workspace could not be loaded')).toHaveCount(0);
  });

  test('Settings opens the Settings area, whose sub-nav reaches the data pages too', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Settings');
    await page.waitForURL('**/settings/profile');
    const area = page.getByTestId('settings-area-nav');
    await expect(area).toBeVisible();
    // The group heading, not the Security page's own link of the same name.
    await expect(area.locator('[data-settings-group]', { hasText: /^Security$/ })).toBeVisible();
    // Tags keeps its own route, inside the same area (WHIT-806, owner decision D1).
    await area.getByTestId('settings-area-link-tags').click();
    await page.waitForURL('**/admin/tags');
    await expect(page.getByTestId('settings-area-nav')).toBeVisible();
    await expect(page.getByTestId('settings-area-link-tags')).toHaveAttribute('aria-current', 'page');
  });

  test('the user row opens the account page', async ({ adminPage, page }) => {
    await adminPage.shell.openAccountSettings();
    // Your own settings are pages in the one Settings area (WHIT-1081); on a computer it opens Profile.
    await page.waitForURL('**/settings/profile');
    await expect(page.getByRole('heading', { name: 'Profile', level: 1 })).toBeVisible();
    await expect(page.getByTestId('settings-area-link-profile')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('settings-area-link-password')).toBeVisible();
  });

  test('Dashboard section loads the home page', async ({
    adminPage,
    page,
  }) => {
    // The "Dashboard" sidebar item opens the one home. Server statistics live on
    // /admin/stats (System).
    await adminPage.shell.clickNav('Dashboard');
    await page.waitForURL('**/dashboard');
    await expect(
      page.getByRole('heading', { name: /^Good (morning|afternoon|evening)/ })
    ).toBeVisible();
  });

  test('sidebar collapses and expands via the header toggle', async ({ adminPage }) => {
    const shell = adminPage.shell;
    // Expanded by default: brand heading + the person's name in the foot are visible.
    await expect(shell.brandHeading).toBeVisible();
    await expect(shell.accountName).toBeVisible();

    // Collapse: the "Whity" heading and the footer email block are hidden, but
    // the nav links remain reachable (icon-only mode).
    await shell.collapseToggle.click();
    await expect(shell.brandHeading).toBeHidden();
    await expect(shell.accountName).toBeHidden();
    await expect(shell.collapseToggle).toBeVisible();

    // Expand again: brand + footer return.
    await shell.collapseToggle.click();
    await expect(shell.brandHeading).toBeVisible();
    await expect(shell.accountName).toBeVisible();
  });

  test('footer shows "logged in as" the admin and the logout button works', async ({
    page,
  }) => {
    // This test LOGS OUT, which (since WC-185) revokes the session's access jti
    // into the global revoked_tokens table. The other admin/matrix specs share
    // the SAME persisted admin token via storageState (adminStatePath), so
    // logging THAT token out here would poison every subsequent admin test
    // (each one re-loads the now-revoked token from disk and gets 401 ->
    // login/refresh churn -> the e2e suite times out). So mint a FRESH,
    // throwaway admin session in this context and log THAT out instead; the
    // shared storage-state token is never revoked.
    //
    // Drop the shared session's cookies from THIS context first (the file on
    // disk is untouched). A signed-in visit to /login redirects to /dashboard,
    // and once the dashboard loads fast enough that redirect wins the race
    // against the sign-in click: the button detaches and the login never fires.
    await page.context().clearCookies();
    const login = new LoginPage(page);
    await login.loginExpectingSuccess(ADMIN);

    const shell = new AppShell(page);
    await shell.expectLoggedInAs(ADMIN.email);
    await shell.logout();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
  });

  test('nav groups collapse to the one holding the current page, and open on click', async ({
    adminPage,
    page,
  }) => {
    const shell = adminPage.shell;
    // A FULL load, not clickNav: the app-router layout keeps the sidebar
    // mounted across client navigations, so disclosure state survives them —
    // and clickNav opens every group on the way. Only a fresh mount shows
    // what a user actually arrives at.
    await page.goto('/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();

    const people = shell.navGroupToggle('people');
    const ai = shell.navGroupToggle('ai');
    await expect(people).toHaveAttribute('aria-expanded', 'true');
    await expect(ai).toHaveAttribute('aria-expanded', 'false');

    // Present but hidden — the distinction the RBAC specs depend on.
    await expect(shell.navLink('Connected AI apps')).toHaveCount(1);
    await expect(shell.navLink('Connected AI apps')).toBeHidden();

    await ai.click();
    await expect(ai).toHaveAttribute('aria-expanded', 'true');
    await expect(shell.navLink('Connected AI apps')).toBeVisible();
  });

  test('the one search reaches a page sitting in a closed group', async ({
    adminPage,
    page,
  }) => {
    const shell = adminPage.shell;
    await page.goto('/admin/users');
    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();

    await expect(shell.navLink('Connected AI apps')).toBeHidden();
    // One search since WHIT-1082: the sidebar has no filter box of its own.
    await expect(shell.navSearch).toHaveCount(0);

    await page.keyboard.press('Control+k');
    await page.keyboard.type('connected');
    // Reached without knowing which group holds it, and without opening it.
    await page.getByRole('option', { name: /Connected AI apps/ }).click();
    await page.waitForURL('**/admin/ai-principals');
  });
});
