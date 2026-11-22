import { test, expect } from '@playwright/test';
import { AppShell } from './support/pages';

/**
 * App-wide RTL support: interface direction is a PROPERTY OF THE CHOSEN
 * LANGUAGE, not a toggle of its own. Picking Arabic in the sidebar's language
 * switcher flips <html dir> for the whole UI, and the choice persists because
 * it is written to the user's profile (PATCH /api/v1/settings/language).
 *
 * The old standalone direction toggle is gone: a language and a direction that
 * disagree is not a state a user can usefully be in.
 */
test.describe('Interface direction follows the language', () => {
  test.afterEach(async ({ page }) => {
    // Leave the shared account in English so the next spec starts LTR.
    await page.request.patch('/api/v1/settings/language', {
      data: { language_code: 'en' },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
  });

  test('choosing Arabic flips <html dir>, and the choice survives a reload', async ({ page }) => {
    await page.goto('/dashboard');
    const html = page.locator('html');
    const shell = new AppShell(page);

    // Defaults to LTR once the provider resolves English.
    await expect(html).toHaveAttribute('dir', 'ltr');

    // Choosing Arabic mirrors the whole document — no separate control.
    await shell.chooseLanguage('العربية');
    await expect(html).toHaveAttribute('dir', 'rtl');
    await expect(html).toHaveAttribute('lang', 'ar');

    // The preference lives on the profile, so it survives a reload.
    await page.reload();
    await expect(html).toHaveAttribute('dir', 'rtl');

    // And switching back to English un-mirrors it.
    await shell.chooseLanguage('English');
    await expect(html).toHaveAttribute('dir', 'ltr');
  });

  test('no standalone direction toggle remains in the UI', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByTestId('direction-toggle')).toHaveCount(0);
    await expect(page.getByLabel('Toggle interface direction')).toHaveCount(0);
  });
});

/**
 * WHIT-1379: a form keeps its own direction. An English-only form inside the
 * Arabic interface reads left to right; the page around it stays right to left.
 */
test.describe('A form reads in its own direction', () => {
  let formId: number | null = null;

  test.afterEach(async ({ page }) => {
    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (formId !== null) await page.request.delete(`/api/v1/forms/${formId}`, { headers });
    formId = null;
    await page.request.patch('/api/v1/settings/language', { data: { language_code: 'en' }, headers });
  });

  test('an English form stays left to right in the Arabic interface', async ({ page }) => {
    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    const suffix = Date.now().toString(36);
    const created = await page.request.post('/api/v1/forms', {
      data: { form_key: `dir_${suffix}`, name: { en: `Supplier request ${suffix}` } },
      headers,
    });
    expect(created.status()).toBe(201);
    formId = (await created.json()).data.id as number;
    const field = await page.request.post(`/api/v1/forms/${formId}/fields`, {
      data: { field_key: 'supplier', field_type: 'text', label: { en: 'Supplier' } },
      headers,
    });
    expect(field.status()).toBe(201);

    const chosen = await page.request.patch('/api/v1/settings/language', { data: { language_code: 'ar' }, headers });
    expect(chosen.ok()).toBe(true);

    await page.goto(`/forms/${formId}/preview`);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const form = page.locator('[data-content-direction]');
    await expect(form).toHaveAttribute('dir', 'ltr');
    await expect(form).toHaveAttribute('lang', 'en');
    await expect(form.getByLabel(/Supplier/)).toBeVisible();
    // Its text starts on the left, as English does.
    expect(await form.getByLabel(/Supplier/).evaluate((el) => getComputedStyle(el).direction)).toBe('ltr');
  });
});
