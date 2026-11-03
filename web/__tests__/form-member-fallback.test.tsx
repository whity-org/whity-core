/**
 * A member with no `forms:submit` fills in a form opened to members through
 * `/me/forms`. Somebody who holds the permission stays on `/forms`.
 */

import React, { Suspense } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import FillFormPage from '@/app/(protected)/forms/[id]/page';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

const client = apiClient as unknown as jest.Mock;

const rendered = {
  form: { id: 7, name: { en: 'Supplier request' }, description: null, status: 'published' },
  fields: [
    {
      field_key: 'supplier',
      field_type: 'text',
      label: { en: 'Supplier' },
      help_text: null,
      is_required: true,
      options: [],
      multi_valued: false,
      position: 1,
    },
  ],
  sections: [{ key: null, field_keys: ['supplier'] }],
  prefill: {},
  unresolved_prefill: [],
  accepts_submissions: true,
};

const json = (status: number, body: unknown) => ({
  ok: status < 400,
  status,
  json: async () => body,
});

function serve(renderStatusAtForms: number) {
  client.mockImplementation(async (url: string, init?: { method?: string }) => {
    if (url === '/api/v1/ous') return json(403, {});
    if (url.endsWith('/render')) {
      if (url.startsWith('/api/v1/forms/') && renderStatusAtForms !== 200) return json(renderStatusAtForms, { error: 'no' });

      return json(200, { data: rendered });
    }
    if (init?.method === 'POST') return json(201, { data: { reference: null } });

    return json(404, {});
  });
}

async function open() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <FillFormPage params={Promise.resolve({ id: '7' })} />
      </Suspense>
    );
  });

  return screen.findByLabelText(/Supplier/);
}

async function send(input: HTMLElement) {
  await userEvent.type(input, 'Example Mill');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));
}

const submitted = () =>
  client.mock.calls.map(([url]) => String(url)).filter((url) => url.endsWith('/submissions'));

describe('where a form is filled in', () => {
  beforeEach(() => {
    client.mockReset();
    window.localStorage.clear();
  });

  it('falls back to the member routes on a 403', async () => {
    serve(403);

    await send(await open());

    await waitFor(() => expect(submitted()).toEqual(['/api/v1/me/forms/7/submissions']));
  });

  it('stays on the ordinary routes for somebody who holds the permission', async () => {
    serve(200);

    await send(await open());

    await waitFor(() => expect(submitted()).toEqual(['/api/v1/forms/7/submissions']));
    expect(client.mock.calls.some(([url]) => String(url).includes('/me/forms'))).toBe(false);
  });

  it('says the form is not available when neither route will serve it', async () => {
    client.mockImplementation(async (url: string) => json(url.includes('/me/forms') ? 404 : 403, { error: 'no' }));

    await act(async () => {
      render(
        <Suspense fallback={null}>
          <FillFormPage params={Promise.resolve({ id: '7' })} />
        </Suspense>
      );
    });

    expect(await screen.findByText('This form is not available')).toBeTruthy();
  });
});
