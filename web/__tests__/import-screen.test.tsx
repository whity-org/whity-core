/**
 * The shared import screen (WHIT-1326, design section 34 §6.5): it draws
 * every kind from the server's description alone. The kinds offered make the
 * picker; a kind's targets name the columns; its required targets and options
 * hold the check back until they are met; then upload, check and run go to
 * the kind's own `path`.
 */

import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

const GET = jest.fn();
const apiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...a: unknown[]) => GET(...a), POST: jest.fn() },
  apiFetch: (...a: unknown[]) => apiFetch(...a),
}));

import { ImportScreen } from '@/components/import/import-screen';

const text = (en: string, ar = en) => ({ en, ar });
const words = { title: text('Import things'), create: text('New things'), update: text('Updated'), run: text('Import {count} things') };

const stock = {
  key: 'example.stock',
  label: text('Stock'),
  description: text('What each shelf holds.'),
  path: '/api/v1/example/imports/stock',
  area: 'example',
  back: { href: '/example', label: text('Go to things') },
  targets: [
    { key: 'code', label: text('Code'), hint: null },
    { key: 'shelf', label: text('Shelf'), hint: null },
  ],
  required: ['code'],
  options: [
    {
      key: 'shelf_id',
      type: 'select',
      label: text('Shelf for rows that name none'),
      hint: null,
      choices: [{ value: 3, label: text('Top shelf') }],
      default: null,
      required_unless: 'shelf',
      value_type: 'int',
      placeholder: null,
    },
  ],
  words,
};
const notes = {
  ...stock,
  key: 'example.notes',
  label: text('Notes'),
  description: text('Notes.'),
  path: '/api/v1/example/imports/notes',
  options: [],
  words: { ...words, title: text('Import notes') },
};

const job = (over: Record<string, unknown> = {}) => ({
  id: 9,
  file_name: 'stock.csv',
  status: 'uploaded',
  row_count: 2,
  columns: [
    { index: 0, header: 'Code', examples: ['A1'], empty: false, suggestion: { target: 'code', confidence: 'sure' } },
    { index: 1, header: 'Qty', examples: ['4'], empty: false, suggestion: null },
  ],
  targets: [
    { key: 'code', label: 'Code' },
    { key: 'shelf', label: 'Shelf' },
  ],
  options: null,
  summary: null,
  imported_at: null,
  undone_at: null,
  undo_until: null,
  can_undo: false,
  created_at: '2026-11-22T10:00:00Z',
  ...over,
});

const json = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } });

beforeEach(() => {
  GET.mockReset();
  apiFetch.mockReset();
  jest.useRealTimers();
});

it('offers every kind the server describes and starts on none when there are several', async () => {
  GET.mockResolvedValue({ data: { data: [stock, notes] }, response: new Response(null, { status: 200 }) });

  render(<ImportScreen />);

  expect(await screen.findByTestId('import-kind-example.stock')).toHaveTextContent('Stock');
  expect(screen.getByTestId('import-kind-example.notes')).toHaveTextContent('Notes');
  expect(screen.queryByTestId('import-read')).not.toBeInTheDocument();

  fireEvent.click(screen.getByTestId('import-kind-example.notes'));
  expect(screen.getByRole('heading', { name: 'Import notes' })).toBeInTheDocument();
  expect(screen.getByTestId('import-read')).toBeInTheDocument();
});

it('holds the check until the kind’s option or its column is chosen', async () => {
  GET.mockResolvedValue({ data: { data: [stock] }, response: new Response(null, { status: 200 }) });
  apiFetch.mockImplementation(async (url: string) => (url === '/api/v1/example/imports/stock' ? json(job(), 201) : json(null, 404)));

  render(<ImportScreen kind="example.stock" />);

  const input = await screen.findByLabelText('Spreadsheet');
  fireEvent.change(input, { target: { files: [new File(['Code,Qty\nA1,4'], 'stock.csv', { type: 'text/csv' })] } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('import-read'));
  });

  // No place for the whole file and no Shelf column: Whity asks, and checks nothing.
  expect(await screen.findByTestId('import-problem')).toHaveTextContent('Choose “Shelf for rows that name none”, or the column with the Shelf.');
  expect(apiFetch).toHaveBeenCalledTimes(1);
  expect(screen.getByTestId('import-run')).toBeDisabled();
  // The kind's own choice is drawn under the matching.
  expect(screen.getByText('Shelf for rows that name none')).toBeInTheDocument();
});

it('names a missing required column in the reader’s words', async () => {
  GET.mockResolvedValue({ data: { data: [notes] }, response: new Response(null, { status: 200 }) });
  apiFetch.mockResolvedValue(
    json(job({ columns: [{ index: 0, header: 'Qty', examples: ['4'], empty: false, suggestion: null }] }), 201)
  );

  render(<ImportScreen />);

  const input = await screen.findByLabelText('Spreadsheet');
  fireEvent.change(input, { target: { files: [new File(['Qty\n4'], 'notes.csv', { type: 'text/csv' })] } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('import-read'));
  });

  expect(await screen.findByTestId('import-problem')).toHaveTextContent('Choose the column with the Code.');
  await waitFor(() => expect(apiFetch).toHaveBeenCalledTimes(1));
});

it('checks with the matching and runs on the kind’s path when nothing is missing', async () => {
  GET.mockResolvedValue({ data: { data: [notes] }, response: new Response(null, { status: 200 }) });
  apiFetch.mockImplementation(async (url: string) => {
    if (url === '/api/v1/example/imports/notes') return json(job(), 201);
    if (url === '/api/v1/example/imports/notes/9/check') {
      return json(job({ status: 'checked', summary: { create: 2, update: 1, skip: 0, rows: [] } }));
    }
    if (url === '/api/v1/example/imports/notes/9/run') {
      return json(job({ status: 'imported', can_undo: true, summary: { create: 2, update: 1, skip: 0 } }));
    }
    return json(null, 404);
  });

  render(<ImportScreen />);

  const input = await screen.findByLabelText('Spreadsheet');
  fireEvent.change(input, { target: { files: [new File(['Code\nA1'], 'notes.csv', { type: 'text/csv' })] } });
  await act(async () => {
    fireEvent.click(screen.getByTestId('import-read'));
  });

  expect(await screen.findByTestId('import-create', {}, { timeout: 3000 })).toHaveTextContent('2');
  const check = apiFetch.mock.calls.find(([url]) => url === '/api/v1/example/imports/notes/9/check');
  expect(JSON.parse(String((check?.[1] as RequestInit).body))).toEqual({ mapping: { '0': 'code' } });

  const run = screen.getByTestId('import-run');
  expect(run).toHaveTextContent('Import 3 things');
  await act(async () => {
    fireEvent.click(run);
  });
  expect(await screen.findByTestId('import-undo')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Go to things' })).toHaveAttribute('href', '/example');
});
