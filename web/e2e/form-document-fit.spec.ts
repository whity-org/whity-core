import type { APIRequestContext } from '@playwright/test';
import { test, expect } from './support/fixtures';

/**
 * A form against its final document template, in the real builder: the warning
 * in both directions, and adding what is missing to the form. Template-from-form
 * and the final PDF are proven against a real schema by the server's tests, since
 * a published template made here could not be removed from the shared database
 * while a form still points at it.
 *
 * It makes one draft form and one template, and deletes both.
 */

const SUFFIX = Date.now().toString(36);

async function makeTemplate(api: APIRequestContext): Promise<number> {
  const created = await api.post('/api/v1/document-sources', {
    data: {
      kind: 'template',
      title: `Supplier letter ${SUFFIX}`,
      scope: 'tenant',
      own_fields: [
        { key: 'supplier', label: 'Supplier' },
        { key: 'delivery_date', label: 'Delivery date' },
      ],
      blocks: [
        {
          id: 'b1',
          type: 'paragraph',
          content: [
            { type: 'field', path: 'template.supplier', label: 'Supplier' },
            { type: 'field', path: 'template.delivery_date', label: 'Delivery date' },
          ],
        },
      ],
    },
  });
  expect(created.status()).toBe(201);
  const id = (await created.json()).data.id as number;
  const published = await api.post(`/api/v1/document-sources/${id}/publish`, { headers: { 'If-Match': '"1"' } });
  expect(published.status()).toBe(201);
  return id;
}

async function makeForm(api: APIRequestContext, templateId: number): Promise<number> {
  const created = await api.post('/api/v1/forms', {
    data: { form_key: `fit_${SUFFIX}`, name: { en: `Supplier request ${SUFFIX}` } },
  });
  expect(created.status()).toBe(201);
  const id = (await created.json()).data.id as number;
  for (const [field_key, en] of [['supplier', 'Supplier'], ['notes', 'Notes']]) {
    const field = await api.post(`/api/v1/forms/${id}/fields`, {
      data: { field_key, field_type: 'text', label: { en } },
    });
    expect(field.status()).toBe(201);
  }
  const linked = await api.patch(`/api/v1/forms/${id}`, { data: { final_template_id: templateId } });
  expect(linked.status()).toBe(200);
  return id;
}

test.describe('a form and its final document template', () => {
  let templateId: number;
  let formId: number;

  test.beforeEach(async ({ adminApi }) => {
    templateId = await makeTemplate(adminApi);
    formId = await makeForm(adminApi, templateId);
  });

  test.afterEach(async ({ adminApi }) => {
    await adminApi.delete(`/api/v1/forms/${formId}`);
    await adminApi.delete(`/api/v1/document-sources/${templateId}`);
  });

  test('warns both ways and adds what the template asks for', async ({ adminPage, page }) => {
    void adminPage;
    await page.goto(`/admin/x/forms-builder/${formId}`);
    await page.getByRole('tab', { name: 'Settings' }).click();

    const needs = page.getByTestId('document-fit-needs');
    await expect(needs.getByText('Delivery date')).toBeVisible();
    const unused = page.getByTestId('document-fit-unused');
    await expect(unused.getByText('Notes')).toBeVisible();

    await needs.getByRole('button', { name: 'Add to the form' }).click();
    await expect(needs).toHaveCount(0);

    await page.getByRole('tab', { name: 'Questions' }).click();
    await expect(page.getByText('Delivery date').first()).toBeVisible();
  });
});
