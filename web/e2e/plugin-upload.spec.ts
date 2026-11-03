import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { test, expect } from './support/fixtures';
import { ADMIN, DELEGATE_USER, SYSTEM_ADMIN } from './support/constants';
import { toastWithText } from './support/pages';
import { LoginPage } from './support/pages';
import {
  createAuthedApi,
  ensureDelegation,
  ensureUser,
  findUserIdByEmail,
  revokeDelegationsFor,
} from './support/api';
import { zipPluginDir } from './support/plugin-zip';

/**
 * WC-221 — plugin upload + per-action RBAC visibility (full-stack E2E).
 *
 * Two journeys against the real backend:
 *
 *  1. ADMIN uploads a fixture .zip from the operator menu (Upload plugin) ->
 *     it lands Turned off for review -> Turn on -> Active.
 *
 *  2. A `plugins:read`-ONLY principal sees the installed list, but the operator
 *     menu is absent and the row menu offers neither Turn on/off nor Uninstall
 *     (design 2.9: actions a person cannot use are not drawn).
 *
 * The read-only principal is the existing delegate account narrowed to JUST
 * `plugins:read`: this spec delegates that one permission to it via the admin
 * API in `beforeAll` (admin holds all six `plugins:*`, so the subset invariant
 * holds) and revokes it in `afterAll`, consistent with how the matrix harness
 * provisions delegated access. RBAC is enforced live server-side per request,
 * so the delegation takes effect on the delegate's existing session without a
 * re-login.
 *
 * The upload fixture is a SINGLE-top-level-directory plugin packaged into a
 * `.zip` at runtime (from e2e/fixtures/plugin-upload/) — it declares no routes,
 * permissions, hooks, or migrations, so staging/enabling it has no RBAC or DB
 * side effects. Cleanup uninstalls it so re-runs never hit the installer's
 * no-overwrite collision guard.
 */

const FIXTURE_PLUGIN_ID = 'WC221UploadFixture';
const FIXTURE_DIR = join(__dirname, 'fixtures', 'plugin-upload', FIXTURE_PLUGIN_ID);
const PLUGINS_URL = '/admin/plugins';

/** Best-effort uninstall of the fixture plugin so the next run can re-upload. */
async function uninstallFixture(baseURL: string): Promise<void> {
  const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
  try {
    await api
      .post(`/api/v1/plugins/${FIXTURE_PLUGIN_ID}/uninstall`, {
        data: { force: true },
      })
      .catch(() => undefined);
  } finally {
    await api.dispose();
  }
}

test.describe('Plugin upload + RBAC visibility (WC-221)', () => {
  // Grant the delegate ONLY plugins:read for this spec; revoke afterward.
  test.beforeAll(async ({ baseURL }) => {
    if (baseURL === undefined) {
      throw new Error('baseURL is required to provision the read-only delegate');
    }
    const api = await createAuthedApi(baseURL, ADMIN);
    try {
      const granteeId = await ensureUser(api, {
        email: DELEGATE_USER.email,
        password: DELEGATE_USER.password,
        role: 'user',
      });
      await ensureDelegation(api, {
        granteeType: 'user',
        granteeId,
        permissions: ['plugins:read'],
      });
    } finally {
      await api.dispose();
    }
    // Ensure a stale fixture from an interrupted prior run cannot block upload.
    await uninstallFixture(baseURL);
  });

  test.afterAll(async ({ baseURL }) => {
    if (baseURL === undefined) {
      return;
    }
    await uninstallFixture(baseURL);
    // Revoke the throwaway plugins:read delegation (other specs expect the
    // delegate's baseline delegated set; a left-over grant would leak into them).
    const api = await createAuthedApi(baseURL, ADMIN);
    try {
      const granteeId = await findUserIdByEmail(api, DELEGATE_USER.email);
      if (granteeId !== null) {
        await revokeDelegationsFor(api, 'user', granteeId);
        // Restore the delegate's BASELINE delegated set the rest of the suite
        // relies on (auth.setup grants these; revokeDelegationsFor cleared them).
        await ensureDelegation(api, {
          granteeType: 'user',
          granteeId,
          permissions: ['relations:read', 'audit:read', 'hello:view'],
        });
      }
    } finally {
      await api.dispose();
    }
  });

  test('admin uploads a fixture package, it stages disabled, then enables to active', async ({
    page,
    baseURL,
  }) => {
    expect(baseURL, 'baseURL is required').toBeDefined();

    // Build the fixture .zip on disk so the file chooser has a real path.
    const zip = await zipPluginDir(FIXTURE_DIR);
    const workDir = await mkdtemp(join(tmpdir(), 'wc221-upload-'));
    const zipPath = join(workDir, `${FIXTURE_PLUGIN_ID}.zip`);
    await writeFile(zipPath, zip);

    try {
      await page.goto(PLUGINS_URL);
      await expect(page.getByRole('heading', { name: 'Plugins', exact: true })).toBeVisible();

      // Open the Upload dialog from the operator menu and submit the archive.
      await page.getByRole('button', { name: 'Operator tools' }).click();
      await page.getByRole('menuitem', { name: /Upload plugin/i }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog.getByText('Upload plugin', { exact: true })).toBeVisible();

      await dialog.locator('input[type="file"]').setInputFiles(zipPath);

      const uploadResponse = page.waitForResponse(
        (res) =>
          res.url().includes('/api/v1/plugins/upload') &&
          res.request().method() === 'POST'
      );
      await dialog.getByRole('button', { name: /^Upload$/i }).click();
      expect((await uploadResponse).status()).toBe(200);

      await expect(
        toastWithText(page, /staged successfully/i)
      ).toBeVisible();

      // The staged plugin appears Turned off for review.
      const row = page.getByRole('row').filter({ hasText: FIXTURE_PLUGIN_ID });
      await expect(row).toBeVisible();
      await expect(row.getByText('Turned off')).toBeVisible();

      // Enable it -> the lifecycle status flips to active.
      const enableResponse = page.waitForResponse(
        (res) =>
          res.url().includes(`/api/v1/plugins/${FIXTURE_PLUGIN_ID}/enable`) &&
          res.request().method() === 'POST'
      );
      await row.getByRole('button', { name: `More actions for ${FIXTURE_PLUGIN_ID}` }).click();
      await page.getByRole('menuitem', { name: 'Turn on' }).click();
      expect((await enableResponse).status()).toBe(200);

      await expect(toastWithText(page, /successfully enabled/i)).toBeVisible();
      const refreshedRow = page.getByRole('row').filter({ hasText: FIXTURE_PLUGIN_ID });
      await expect(refreshedRow.getByText('Active', { exact: true })).toBeVisible();
    } finally {
      await rm(workDir, { recursive: true, force: true });
    }
  });

  test('a plugins:read-only delegate sees the list with no operator menu, Turn on/off or Uninstall', async ({
    browser,
    baseURL,
  }) => {
    if (baseURL === undefined) {
      throw new Error('baseURL is required');
    }

    // Drive the delegate in its OWN context (this spec runs admin-authenticated
    // by default). A fresh UI login picks up the live plugins:read delegation.
    //
    // storageState MUST be forced empty: a manually-created context inherits
    // the project's `use.storageState`, which here is the stored ADMIN
    // session. With admin cookies already present, /login redirects straight
    // to /dashboard, the form never renders, and the wait for the POST
    // /api/v1/login response hangs until the 45s test timeout — the whole test
    // having never authenticated as the delegate at all. (baseURL comes along
    // for the same reason, so the relative goto()s below resolve.)
    // settings-2fa.spec.ts and profile.spec.ts use the same idiom.
    const context = await browser.newContext({
      baseURL,
      storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();
    try {
      const login = new LoginPage(page);
      await login.loginExpectingSuccess(DELEGATE_USER);

      await page.goto(PLUGINS_URL);
      // plugins:read is held, so the console renders (not Access Denied).
      await expect(page.getByRole('heading', { name: 'Plugins', exact: true })).toBeVisible();
      await expect(page.getByText('You cannot manage plugins')).toHaveCount(0);

      // Actions this person cannot use are not drawn: no operator menu.
      await expect(page.getByRole('button', { name: 'Operator tools' })).toHaveCount(0);

      // A row's menu offers Details, but neither Turn on/off nor Uninstall.
      const firstMenu = page.getByRole('button', { name: /^More actions for / }).first();
      if ((await firstMenu.count()) > 0) {
        await firstMenu.click();
        await expect(page.getByRole('menuitem', { name: 'Details' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /^Turn (on|off)$/ })).toHaveCount(0);
        await expect(page.getByRole('menuitem', { name: 'Uninstall' })).toHaveCount(0);
      }
    } finally {
      await context.close();
    }
  });
});
