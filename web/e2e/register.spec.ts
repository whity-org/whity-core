import { test, expect } from './support/fixtures';
import { SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';

/**
 * Instance sign-up governance (SettingsRegistry): a fresh instance is closed to
 * sign-up and requires operator approval, so the happy path needs both flipped.
 */
const SIGNUP_OPEN: Record<string, string> = {
  'auth.self_registration_enabled': 'true',
  'auth.registration_approval_required': 'false',
};

/**
 * Self-service registration (WC-235). The `page` fixture is unauthenticated
 * (like auth.spec.ts), so these exercise the logged-out signup flow end to end
 * through the real Next.js proxy → backend path.
 */
test.describe('Self-service registration (WC-235)', () => {
  test('the login page links to registration and back', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: 'Create a workspace' }).click();
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.getByRole('button', { name: 'Create workspace' })).toBeVisible();

    await page.getByRole('link', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/login$/);
  });

  test('empty fields trigger inline client-side validation (no request fired)', async ({
    page,
  }) => {
    await page.goto('/register');
    await page.getByRole('button', { name: 'Create workspace' }).click();

    await expect(page.getByText('Workspace name is required')).toBeVisible();
    await expect(page.getByText('Email is required')).toBeVisible();
    await expect(page.getByText('Password is required')).toBeVisible();
    await expect(page).toHaveURL(/\/register$/);
  });

  test('a short password is rejected client-side', async ({ page }) => {
    await page.goto('/register');
    await page.getByLabel('Workspace name').fill('Shorty WS');
    await page.getByLabel('Email').fill('shorty@e2e.test');
    await page.getByLabel('Password', { exact: true }).fill('short');
    await page.getByRole('button', { name: 'Create workspace' }).click();

    await expect(page.getByText('Password must be at least 12 characters')).toBeVisible();
    await expect(page).toHaveURL(/\/register$/);
  });

  test('a new workspace can be created and signs the owner straight in', async ({
    page,
    baseURL,
  }) => {
    // A fresh instance is closed to sign-up and holds new workspaces for approval
    // (WC-696206d8), so the operator opens it for this test and the finally
    // below puts back whatever was there.
    const operator = await createAuthedApi(baseURL!, SYSTEM_ADMIN);
    const read = await operator.get('/api/v1/settings/global');
    expect(read.status()).toBe(200);
    const global = ((await read.json()) as { data: { global: Record<string, string> } }).data
      .global;
    const original = Object.fromEntries(
      Object.keys(SIGNUP_OPEN).map((key) => [key, global[key] ?? (key.includes('approval') ? 'true' : 'false')])
    );
    const open = await operator.patch('/api/v1/settings/global', {
      data: { settings: SIGNUP_OPEN },
    });
    expect(open.ok(), 'opening sign-up as the operator').toBe(true);
    try {
      // Unique per run so the tenant name / email never collide (CI runs on a
      // fresh stack; the timestamp keeps retries independent too).
      const stamp = Date.now();
      const email = `owner-${stamp}@e2e.test`;
      const workspace = `E2E Workspace ${stamp}`;

      await page.goto('/register');
      // A value typed before the form hydrates is wiped when React takes over, so
      // fill until the values stick.
      const name = page.getByLabel('Workspace name');
      const mail = page.getByLabel('Email');
      const password = page.getByLabel('Password', { exact: true });
      await expect(async () => {
        await name.fill(workspace);
        await mail.fill(email);
        await password.fill('a-strong-e2e-password');
        await expect(name).toHaveValue(workspace, { timeout: 500 });
        await expect(mail).toHaveValue(email, { timeout: 500 });
      }).toPass();
      await page.getByRole('button', { name: 'Create workspace' }).click();

      // Success provisions the workspace, chains login, and redirects in. Landing
      // inside the app (rather than being bounced back to /login) proves the owner
      // was signed in. Since workspace templates (November 2026) a new owner may
      // be sent to the template picker first (TEMPLATE_PICKER_PATH in
      // web/lib/workspace-template.ts) instead of /dashboard.
      const signedIn = (url: URL) =>
        url.pathname === '/dashboard' || url.pathname === '/setup/template';
      await page.waitForURL(signedIn);
      expect(signedIn(new URL(page.url()))).toBe(true);
    } finally {
      await operator.patch('/api/v1/settings/global', {
        data: { settings: original },
      });
      await operator.dispose();
    }
  });
});
