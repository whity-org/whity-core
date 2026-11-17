import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import type { APIRequestContext } from '@playwright/test';
import { test, expect } from './support/fixtures';
import { ADMIN, SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';
import { toastWithText } from './support/pages';
import { zipPluginDir } from './support/plugin-zip';

/**
 * WHIT-1202 — a locked card on the dashboard starts the template's trial.
 *
 * Two throwaway plugins are written and uploaded per run, with names unique to
 * the run, so a re-run never meets a trial an earlier run already started:
 *
 *  - a paid plugin ("E2E Counters"), installed but not on for the workspace;
 *  - a free template plugin whose one template offers it, with a 30-day trial
 *    and nothing else to apply, so the shared admin workspace keeps its data.
 *
 * The admin workspace applies the template, its dashboard shows the paid plugin
 * as a locked card, and Start your 30-day free trial starts it and the card goes.
 * Cleanup uninstalls both plugins.
 */

const STAMP = Date.now().toString(36);
const PAID = `E2eLockedPaid${STAMP}`;
const TEMPLATES = `E2eLockedTpl${STAMP}`;
const TEMPLATE_KEY = `e2e-locked-${STAMP}`;
const DISPLAY_NAME = 'E2E Counters';

function paidPlugin(): string {
  return `<?php

declare(strict_types=1);

namespace ${PAID};

use Whity\\Sdk\\PluginAccessInterface;
use Whity\\Sdk\\PluginDisplayNameInterface;
use Whity\\Sdk\\PluginInterface;

/** WHIT-1202 e2e: a paid plugin a template offers. No routes, tables or permissions. */
final class Plugin implements PluginInterface, PluginAccessInterface, PluginDisplayNameInterface
{
    public function getName(): string { return '${PAID}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getDisplayName(): array { return ['en' => '${DISPLAY_NAME}', 'ar' => 'E2E للكاونترات']; }
    public function getAccess(): ?array
    {
        return ['entitlement' => 'e2e_locked.access', 'product' => 'plugin.e2e-locked-${STAMP}', 'trialDays' => 14];
    }
}
`;
}

function templatePlugin(): string {
  const template = {
    key: TEMPLATE_KEY,
    version: 1,
    name: { en: 'E2E counter', ar: 'كاونتر للاختبار' },
    summary: { en: 'A template for the locked card test.', ar: 'قالب لاختبار البطاقة المقفلة.' },
    icon: 'store',
    setsUp: [{ en: 'Nothing, it only offers a plugin', ar: 'لا شيء، يعرض إضافة فقط' }],
    offer: {
      plugin: PAID,
      trialDays: 30,
      free: [{ en: 'Bookings and customers', ar: 'الحجوزات والعملاء' }],
      paid: [
        { en: 'Orders at the counter by QR code', ar: 'الطلبات عند الكاونتر برمز QR' },
        { en: 'A daily takings summary', ar: 'ملخص يومي للمبيعات' },
      ],
    },
  };

  return `<?php

declare(strict_types=1);

namespace ${TEMPLATES};

use Whity\\Sdk\\PluginInterface;
use Whity\\Sdk\\PluginWorkspaceTemplatesInterface;

/** WHIT-1202 e2e: one template that offers ${PAID}. */
final class Plugin implements PluginInterface, PluginWorkspaceTemplatesInterface
{
    public function getName(): string { return '${TEMPLATES}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getMigrations(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getWorkspaceTemplates(): array
    {
        return [json_decode(<<<'JSON'
${JSON.stringify(template)}
JSON, true)];
    }
}
`;
}

async function install(api: APIRequestContext, name: string, source: string): Promise<void> {
  const workDir = await mkdtemp(join(tmpdir(), 'whit1202-'));
  try {
    const dir = join(workDir, name);
    await mkdir(dir);
    await writeFile(join(dir, 'Plugin.php'), source);
    const upload = await api.post('/api/v1/plugins/upload', {
      multipart: { package: { name: `${name}.zip`, mimeType: 'application/zip', buffer: await zipPluginDir(dir) } },
    });
    expect(upload.status(), `upload ${name}: ${await upload.text()}`).toBe(200);
    const enable = await api.post(`/api/v1/plugins/${name}/enable`);
    expect(enable.status(), `enable ${name}: ${await enable.text()}`).toBe(200);
  } finally {
    await rm(workDir, { recursive: true, force: true });
  }
}

async function uninstall(baseURL: string): Promise<void> {
  const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
  try {
    for (const name of [TEMPLATES, PAID]) {
      await api.post(`/api/v1/plugins/${name}/uninstall`, { data: { force: true, now: true } }).catch(() => undefined);
    }
  } finally {
    await api.dispose();
  }
}

test.describe('A locked card on the dashboard (WHIT-1202)', () => {
  test.beforeAll(async ({ baseURL }) => {
    if (baseURL === undefined) throw new Error('baseURL is required');
    const operator = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    try {
      await install(operator, PAID, paidPlugin());
      await install(operator, TEMPLATES, templatePlugin());
    } finally {
      await operator.dispose();
    }

    const admin = await createAuthedApi(baseURL, ADMIN);
    try {
      // A new install starts off for every workspace: the admin turns the free
      // template plugin on here. The paid one stays off, so its card shows.
      const on = await admin.post(`/api/v1/workspace/code-plugins/${TEMPLATES}/enable`);
      expect(on.status(), await on.text()).toBe(200);
      const applied = await admin.post(`/api/v1/workspace/templates/${TEMPLATE_KEY}/apply`, { data: { examples: false } });
      expect(applied.status(), await applied.text()).toBe(200);
    } finally {
      await admin.dispose();
    }
  });

  test.afterAll(async ({ baseURL }) => {
    if (baseURL !== undefined) await uninstall(baseURL);
  });

  test('starts the 30-day trial from the card, and the card goes', async ({ page }) => {
    await page.goto('/dashboard');

    const card = page.locator('[data-slot="locked-card"]').filter({ hasText: DISPLAY_NAME });
    await expect(card).toBeVisible();
    await expect(card.getByText('Orders at the counter by QR code')).toBeVisible();
    await expect(card.getByRole('link', { name: "See what's included" })).toHaveAttribute('href', `/admin/plugins#plugin-${PAID}`);

    const started = page.waitForResponse(
      (res) => res.url().includes(`/api/v1/workspace/templates/${TEMPLATE_KEY}/setup/offer/trial`) && res.request().method() === 'POST'
    );
    await card.getByRole('button', { name: 'Start your 30-day free trial' }).click();
    expect((await started).status()).toBe(200);
    await expect(toastWithText(page, `Your 30-day trial of ${DISPLAY_NAME} has started.`)).toBeVisible();
    await expect(card).toHaveCount(0);

    // It stays gone: the workspace has had its trial.
    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('[data-slot="locked-card"]').filter({ hasText: DISPLAY_NAME })).toHaveCount(0);
  });
});
