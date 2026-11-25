import { test, expect, type Page, type TestInfo } from '@playwright/test';

/**
 * The collapsed sidebar, the 56px icon rail (AppShellCollapsed, register 4.5),
 * checked in a real browser at 1280×800 and 1280×600 and in Arabic.
 *
 * jest cannot see layout, and the rail's problems were all layout: a native
 * scrollbar inside the 56px column pushing the icons off-centre, uneven icon
 * boxes, and a workspace chip and avatar of other sizes. So this measures the
 * rendered geometry rather than comparing pixels (which differ by platform
 * font rendering), and attaches a screenshot of each case to the report.
 */

const RAIL = 56;
const TARGET = 40;

async function collapse(page: Page) {
  await page.goto('/dashboard');
  const rail = page.locator('aside[data-slot="app-sidebar"]');
  await expect(rail).toBeVisible();
  // The links arrive after the navigation fetch (and, in Arabic, the catalogue).
  await expect(rail.locator('[data-slot="app-sidebar-nav"] a').first()).toBeVisible();
  await page.waitForLoadState('networkidle');
  if ((await rail.getAttribute('data-collapsed')) === null) {
    await rail.locator('[data-slot="app-sidebar-collapse"]').click();
  }
  await expect(rail).toHaveAttribute('data-collapsed', 'true');
  // The width animates (200ms); measure once it has settled.
  await expect.poll(async () => Math.round((await rail.boundingBox())?.width ?? 0)).toBe(RAIL);
  return rail;
}

async function checkRail(page: Page, testInfo: TestInfo, name: string) {
  const rail = await collapse(page);
  const box = await rail.boundingBox();
  expect(box).not.toBeNull();
  expect(Math.round(box!.width)).toBe(RAIL);
  const centre = box!.x + box!.width / 2;

  // No native scrollbar takes room inside the rail: the list is as wide as
  // its content box, whether or not it overflows.
  const nav = rail.locator('[data-slot="app-sidebar-nav"]');
  const gutter = await nav.evaluate((el) => (el as HTMLElement).offsetWidth - el.clientWidth);
  expect(gutter).toBe(0);
  // Playwright hides scrollbars in its own browser, so also check the style
  // that hides them for people.
  expect(await nav.evaluate((el) => getComputedStyle(el).scrollbarWidth)).toBe('none');
  // It still scrolls when there is more than fits.
  expect(await nav.evaluate((el) => getComputedStyle(el).overflowY)).toBe('auto');

  // Every visible icon, the workspace chip and the avatar: 40px squares on
  // one centred column.
  const targets = rail.locator(
    '[data-slot="app-sidebar-link"]:visible, [data-slot="tenant-switcher"], [data-testid="account-menu"]',
  );
  const count = await targets.count();
  expect(count).toBeGreaterThan(2);
  for (let i = 0; i < count; i++) {
    const target = targets.nth(i);
    const b = await target.boundingBox();
    if (!b) continue;
    const label = (await target.getAttribute('aria-label')) ?? (await target.innerText());
    expect.soft(Math.round(b.width), `${label} width`).toBe(TARGET);
    expect.soft(Math.round(b.height), `${label} height`).toBe(TARGET);
    expect.soft(Math.abs(b.x + b.width / 2 - centre), `${label} centred`).toBeLessThanOrEqual(1);
  }

  // The account row stays on screen on a short window (PW.10).
  const account = rail.getByTestId('account-menu');
  await expect(account).toBeInViewport();

  // Each icon names itself in a tooltip beside the rail.
  const first = rail.locator('[data-slot="app-sidebar-nav"] [data-slot="app-sidebar-link"]').first();
  await first.hover();
  const tip = page.getByRole('tooltip');
  await expect(tip).toBeVisible();
  const accessibleName = ((await first.textContent()) ?? '').trim();
  await expect(tip).toContainText(accessibleName.split(',')[0]);

  await testInfo.attach(name, { body: await page.screenshot(), contentType: 'image/png' });
  return box!;
}

test.describe('The collapsed sidebar rail', () => {
  for (const height of [800, 600]) {
    test(`is one centred column of 40px targets with no scrollbar at 1280×${height}`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width: 1280, height });
      await checkRail(page, testInfo, `rail-1280x${height}`);
    });
  }

  test.describe('in Arabic', () => {
    test.afterEach(async ({ page }) => {
      // Leave the shared account in English so the next spec starts LTR.
      await page.request.patch('/api/v1/settings/language', {
        data: { language_code: 'en' },
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
    });

    test('sits on the right with the same column at 1280×600', async ({ page }, testInfo) => {
      await page.setViewportSize({ width: 1280, height: 600 });
      const response = await page.request.patch('/api/v1/settings/language', {
        data: { language_code: 'ar' },
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      test.skip(!response.ok(), 'Arabic is not enabled on this installation');
      await page.goto('/dashboard');
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

      const box = await checkRail(page, testInfo, 'rail-ar-1280x600');
      expect(Math.round(box.x + box.width)).toBe(1280);
    });
  });
});
