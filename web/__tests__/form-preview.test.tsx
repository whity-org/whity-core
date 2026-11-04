/**
 * The author's preview of a form, which works for a draft and sends nothing.
 */

import React, { Suspense } from 'react';
import { act, render, screen } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import PreviewFormPage from '@/app/(protected)/forms/[id]/preview/page';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

const client = apiClient as unknown as jest.Mock;

const preview = {
  form: { name: { en: 'Supplier request' }, description: null, submit_label: null },
  fields: [
    { field_key: 'supplier', field_type: 'text', label: { en: 'Supplier' }, help_text: null, is_required: true, options: [], multi_valued: false, position: 1 },
  ],
  sections: [{ key: null, field_keys: ['supplier'] }],
  preview: true,
};

const json = (status: number, body: unknown) => ({ ok: status < 400, status, json: async () => body });

async function open() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <PreviewFormPage params={Promise.resolve({ id: '7' })} />
      </Suspense>
    );
  });
}

describe('the form preview', () => {
  beforeEach(() => client.mockReset());

  it('shows a draft as it will be filled in, and sends nothing', async () => {
    client.mockImplementation(async (url: string) => (url.endsWith('/preview') ? json(200, { data: preview }) : json(404, {})));
    await open();

    expect(await screen.findByText(/Nothing you enter here is sent/)).toBeInTheDocument();
    await userEvent.type(await screen.findByLabelText(/Supplier/), 'Example Mill');
    await userEvent.click(screen.getByRole('button', { name: 'Send' }));

    expect(await screen.findByText('This is a preview. Nothing was sent.')).toBeInTheDocument();
    const posts = client.mock.calls.filter(([, init]) => (init as { method?: string } | undefined)?.method === 'POST');
    expect(posts).toEqual([]);
  });

  it('says so when the preview cannot be opened', async () => {
    client.mockImplementation(async () => json(403, { error: 'no' }));
    await open();

    expect(await screen.findByText('This preview could not be opened')).toBeInTheDocument();
  });
});
