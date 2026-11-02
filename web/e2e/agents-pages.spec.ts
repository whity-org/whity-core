import { test, expect, type APIRequestContext } from '@playwright/test';
import { ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';

/**
 * E2E for the agents pages (WHIT-820), against the live stack: an admin makes
 * an agent from a preset and edits it, then starts using it, reads its grant
 * and connects an app. Agents can't be deleted, so this archives what it made.
 */

const SUFFIX = Date.now();

test.describe('Agents pages', () => {
  let api: APIRequestContext;
  const made: number[] = [];

  test.beforeAll(async ({ baseURL }) => {
    api = await createAuthedApi(baseURL ?? '', ADMIN);
  });

  test.afterAll(async () => {
    for (const id of made) {
      await api.post(`/api/v1/agents/${id}/archive`);
    }
    await api.dispose();
  });

  test('an admin creates an agent from a preset and renames it', async ({ page }) => {
    await page.goto('/admin/agents');
    await page.getByRole('button', { name: 'New agent' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Use this preset' }).first().click();

    await page.waitForURL(/\/admin\/agents\/\d+$/);
    made.push(Number(page.url().split('/').pop()));

    const name = `Clerk ${SUFFIX}`;
    await page.locator('#agent-name').fill(name);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Saved').first()).toBeVisible();

    await page.goto('/admin/agents');
    await expect(page.getByRole('link', { name })).toBeVisible();
  });

  test('a person starts using it, sees its grant, connects an app and stops', async ({ page }) => {
    const created = await api.post('/api/v1/agents', { data: { name: `Helper ${SUFFIX}`, role: 'Helps.' } });
    expect(created.ok(), await created.text()).toBe(true);
    const { data: agent } = (await created.json()) as { data: { id: number; slug: string } };
    made.push(agent.id);

    await page.goto('/admin/my-agents');
    const card = page.getByRole('listitem').filter({ hasText: `Helper ${SUFFIX}` });
    await card.getByRole('button', { name: 'Start using' }).click();

    await card.getByRole('button', { name: 'Connect an app' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(`/mcp/agents/${agent.slug}`)).toBeVisible();
    await page.keyboard.press('Escape');

    await card.getByRole('link', { name: 'What it may do' }).click();
    await page.waitForURL(`**/admin/my-agents/${agent.id}`);
    await expect(page.getByRole('heading', { name: `Helper ${SUFFIX}` })).toBeVisible();

    await page.goto('/admin/my-agents');
    await card.getByRole('button', { name: 'Stop using' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Stop using' }).click();
    await expect(card.getByRole('button', { name: 'Start using' })).toBeVisible();
  });
});
