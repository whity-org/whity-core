/**
 * Settings › Company stamps (#1729): the list, who may change it, adding a
 * stamp with its image read in the browser, and archiving.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

const granted = new Set<string>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, hasPermission: (p: string) => granted.has(p) }),
}));

import DocumentStampsPage from '@/app/(protected)/admin/document-stamps/page';
import { holdersChanged, imageProblem, refusalOf, type CompanyStamp } from '@/app/(protected)/admin/document-stamps/model';

const IMAGE = 'data:image/png;base64,iVBORw0KGgo=';

const STAMP: CompanyStamp = {
  id: 3,
  name: 'Company seal',
  legal_name: 'Example Bakery LLC',
  image: IMAGE,
  image_hash: 'c'.repeat(64),
  holders: { people: [{ profile_id: 21, name: 'Amro Saleh' }], roles: [{ role_id: 5, name: 'Finance' }] },
  created_at: '2026-11-20T10:00:00Z',
  updated_at: '2026-11-20T10:00:00Z',
  archived_at: null,
};

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as unknown as Response;
}

const page = (data: unknown[]) => ({ data, pagination: { page: 1, perPage: 100, total: data.length, totalPages: 1 } });

function serve(stamps: CompanyStamp[]) {
  mockApiClient.mockReset();
  mockApiClient.mockImplementation(async (url: string, init?: { method?: string }) => {
    const method = init?.method ?? 'GET';
    if (url.startsWith('/api/v1/document-stamps') && method === 'GET') return json(200, { data: stamps });
    if (url === '/api/v1/document-stamps' && method === 'POST') return json(201, { data: { ...STAMP, id: 9 } });
    if (url.endsWith('/archive') && method === 'POST') return json(200, { data: { ...STAMP, archived_at: 'now' } });
    if (url.startsWith('/api/v1/users')) return json(200, page([{ id: 21, name: 'Amro Saleh', email: 'amro@example.com' }, { id: 22, name: 'Awss Haddad', email: null }]));
    if (url.startsWith('/api/v1/roles')) return json(200, page([{ id: 5, name: 'Finance' }, { id: 6, name: 'Directors' }]));
    return json(404, {});
  });
}

beforeEach(() => {
  granted.clear();
  addToast.mockReset();
});

describe('the company stamps page', () => {
  it('lists the stamps with their legal name and holders, read-only without documents:stamps', async () => {
    granted.add('documents:read');
    serve([STAMP]);
    render(<DocumentStampsPage />);

    const row = await screen.findByTestId('stamp-row');
    expect(within(row).getByText('Company seal')).toBeInTheDocument();
    expect(within(row).getByText('Stands for Example Bakery LLC')).toBeInTheDocument();
    expect(within(row).getByText('Held by Amro Saleh, Finance (role)')).toBeInTheDocument();
    expect(within(row).getByRole('img')).toHaveAttribute('src', IMAGE);

    expect(screen.getByText('You can see the stamps but not change them')).toBeInTheDocument();
    expect(screen.queryByTestId('stamps-add')).toBeNull();
    expect(within(row).queryByRole('button', { name: 'Archive' })).toBeNull();
  });

  it('asks for archived stamps too when the box is ticked', async () => {
    granted.add('documents:read');
    serve([STAMP]);
    render(<DocumentStampsPage />);
    await screen.findByTestId('stamp-row');

    fireEvent.click(screen.getByRole('checkbox', { name: 'Show archived stamps' }));
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/document-stamps?archived=true'));
  });

  it('archives a stamp for someone who manages stamps', async () => {
    granted.add('documents:read');
    granted.add('documents:stamps');
    serve([STAMP]);
    render(<DocumentStampsPage />);

    const row = await screen.findByTestId('stamp-row');
    fireEvent.click(within(row).getByRole('button', { name: 'Archive' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/document-stamps/3/archive',
        expect.objectContaining({ method: 'POST' })
      )
    );
  });

  it('adds a stamp: name, legal name, image read as a data URL, and holders', async () => {
    granted.add('documents:read');
    granted.add('documents:stamps');
    serve([]);
    render(<DocumentStampsPage />);

    expect(await screen.findByText('No company stamps yet')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('stamps-add'));

    const editor = await screen.findByTestId('stamp-editor');
    const save = within(editor).getByTestId('stamp-editor-save');
    expect(save).toBeDisabled();

    fireEvent.change(within(editor).getByLabelText(/^Name/), { target: { value: 'Company seal' } });
    fireEvent.change(within(editor).getByLabelText(/^Legal name it stands for/), {
      target: { value: 'Example Bakery LLC' },
    });

    // A file of the wrong type is refused before anything is read.
    const input = within(editor).getByTestId('stamp-editor-file');
    fireEvent.change(input, { target: { files: [new File(['x'], 'stamp.gif', { type: 'image/gif' })] } });
    expect(await within(editor).findByText('Choose a PNG or JPEG file.')).toBeInTheDocument();

    fireEvent.change(input, { target: { files: [new File(['png-bytes'], 'stamp.png', { type: 'image/png' })] } });
    expect(await within(editor).findByRole('img', { name: 'The chosen image' })).toBeInTheDocument();

    fireEvent.click(await within(editor).findByRole('checkbox', { name: 'Finance' }));
    expect(save).toBeEnabled();
    fireEvent.click(save);

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/document-stamps', expect.objectContaining({ method: 'POST' }))
    );
    const call = mockApiClient.mock.calls.find(([url, init]) => url === '/api/v1/document-stamps' && init?.method === 'POST');
    const body = JSON.parse(String(call?.[1]?.body));
    expect(body).toMatchObject({
      name: 'Company seal',
      legal_name: 'Example Bakery LLC',
      holders: { people: [], roles: [5] },
    });
    expect(body.image).toMatch(/^data:image\/png;base64,/);
  });
});

describe('the company stamps model', () => {
  it('checks the image type and size', () => {
    expect(imageProblem({ type: 'image/png', size: 1000 })).toBeNull();
    expect(imageProblem({ type: 'image/jpeg', size: 524_288 })).toBeNull();
    expect(imageProblem({ type: 'image/jpeg', size: 524_289 })).toBe('size');
    expect(imageProblem({ type: 'image/svg+xml', size: 10 })).toBe('type');
  });

  it('tells changed holders from the same ones in another order', () => {
    const same = { name: '', legal_name: '', image: '', people: [21], roles: [5] };
    expect(holdersChanged(STAMP, same)).toBe(false);
    expect(holdersChanged(STAMP, { ...same, roles: [5, 6] })).toBe(true);
  });

  it('reads the field a refusal names', () => {
    expect(refusalOf({ error: 'Too large', details: { field: 'image' } }, 'x')).toEqual({ message: 'Too large', field: 'image' });
    expect(refusalOf(undefined, 'Fallback')).toEqual({ message: 'Fallback', field: null });
  });
});
