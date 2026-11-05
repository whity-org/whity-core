/**
 * A member with no `forms:submit` fills in a form opened to members through
 * `/me/forms`, and is never sent to an address that would only refuse them. Somebody
 * who holds the permission stays on `/forms`. Unsent answers are kept on the server.
 */

import React, { Suspense } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import FillFormPage from '@/app/(protected)/forms/[id]/page';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

let held: string[] = [];
let capabilitiesLoading = false;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    hasPermission: (permission: string) => held.includes(permission),
    loading: capabilitiesLoading,
  }),
}));

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

function serve(renderStatusAtForms: number, draft: unknown = null) {
  client.mockImplementation(async (url: string, init?: { method?: string }) => {
    if (url === '/api/v1/ous') return json(403, {});
    if (url.endsWith('/render')) {
      if (url.startsWith('/api/v1/forms/') && renderStatusAtForms !== 200) return json(renderStatusAtForms, { error: 'no' });

      return json(200, { data: rendered });
    }
    if (url.endsWith('/draft')) {
      if (init?.method === 'PUT') return json(200, { data: { step: 0 } });
      if (init?.method === 'DELETE') return json(200, { data: { deleted: true } });

      return json(200, { data: draft });
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

const urls = () => client.mock.calls.map(([url]) => String(url));
const submitted = () => urls().filter((url) => url.endsWith('/submissions'));
const draftCalls = (method: string) =>
  client.mock.calls.filter(([url, init]) => String(url).endsWith('/draft') && (init as { method?: string } | undefined)?.method === method);

describe('where a form is filled in', () => {
  beforeEach(() => {
    client.mockReset();
    window.localStorage.clear();
    held = ['forms:submit'];
    capabilitiesLoading = false;
  });

  it('goes straight to the member routes without the permission, with no refused calls', async () => {
    held = [];
    serve(403);

    await send(await open());

    await waitFor(() => expect(submitted()).toEqual(['/api/v1/me/forms/7/submissions']));
    expect(urls().some((url) => url.startsWith('/api/v1/forms'))).toBe(false);
    expect(urls()).not.toContain('/api/v1/ous');
  });

  it('asks for nothing until it knows what this person holds', async () => {
    capabilitiesLoading = true;
    serve(200);

    await act(async () => {
      render(
        <Suspense fallback={null}>
          <FillFormPage params={Promise.resolve({ id: '7' })} />
        </Suspense>
      );
    });

    expect(urls()).toEqual([]);
  });

  it('still falls back on a 403, for a permission taken away after the page learned it', async () => {
    serve(403);

    await send(await open());

    await waitFor(() => expect(submitted()).toEqual(['/api/v1/me/forms/7/submissions']));
  });

  it('stays on the ordinary routes for somebody who holds the permission', async () => {
    serve(200);

    await send(await open());

    await waitFor(() => expect(submitted()).toEqual(['/api/v1/forms/7/submissions']));
    expect(urls().some((url) => url.includes('/me/forms') && !url.endsWith('/draft'))).toBe(false);
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

  it('links to My submissions once it is sent', async () => {
    serve(200);

    await send(await open());

    const link = await screen.findByRole('link', { name: 'View your submissions' });
    expect(link.getAttribute('href')).toBe('/admin/x/my-form-submissions');
  });
});

describe('unsent answers', () => {
  beforeEach(() => {
    client.mockReset();
    window.localStorage.clear();
    held = ['forms:submit'];
    capabilitiesLoading = false;
  });

  it('brings back what the server kept, and says so', async () => {
    serve(200, { data: { supplier: 'Example Mill' }, step: 0, updated_at: '2026-11-05 08:00:00' });

    const input = (await open()) as HTMLInputElement;

    expect(input.value).toBe('Example Mill');
    expect(screen.getByText(/You started this earlier/)).toBeTruthy();
  });

  it('saves nothing just for opening the form', async () => {
    serve(200);

    await open();
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 1700));
    });

    expect(draftCalls('PUT')).toEqual([]);
  });

  it('saves to the server after a pause, and shows when', async () => {
    serve(200);

    await userEvent.type(await open(), 'Example Mill');

    expect(await screen.findByText(/Draft saved at/, undefined, { timeout: 3000 })).toBeTruthy();
    const body = JSON.parse(String((draftCalls('PUT').at(-1)?.[1] as { body: string }).body));
    expect(body).toEqual({ data: { supplier: 'Example Mill' }, step: 0 });
    expect(window.localStorage.getItem('whity:form-draft:7')).toBeNull();
  });

  it('moves answers an earlier version left in this browser, then removes them', async () => {
    window.localStorage.setItem('whity:form-draft:7', JSON.stringify({ supplier: 'Old Mill' }));
    serve(200);

    const input = (await open()) as HTMLInputElement;

    expect(input.value).toBe('Old Mill');
    await waitFor(() => expect(draftCalls('PUT').length).toBe(1));
    await waitFor(() => expect(window.localStorage.getItem('whity:form-draft:7')).toBeNull());
  });

  it('starts over by removing the draft and going back to a clean form', async () => {
    serve(200, { data: { supplier: 'Example Mill' }, step: 0, updated_at: '2026-11-05 08:00:00' });

    await open();
    await userEvent.click(screen.getByRole('button', { name: 'Start over' }));

    await waitFor(() => expect(draftCalls('DELETE').length).toBe(1));
    expect(((await screen.findByLabelText(/Supplier/)) as HTMLInputElement).value).toBe('');
  });

  it('can still send the form when a draft will not save', async () => {
    client.mockImplementation(async (url: string, init?: { method?: string }) => {
      if (url.endsWith('/render')) return json(200, { data: rendered });
      if (url.endsWith('/draft') && init?.method === 'PUT') return json(500, {});
      if (url.endsWith('/draft')) return json(200, { data: null });

      return json(201, { data: { reference: null } });
    });

    await userEvent.type(await open(), 'Example Mill');

    expect(await screen.findByText(/could not be saved/, undefined, { timeout: 3000 })).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Send' }));
    await waitFor(() => expect(submitted()).toEqual(['/api/v1/forms/7/submissions']));
  });
});
