import { Suspense } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Editor } from '@tiptap/core';

/**
 * Edit original (editor M7, E.21, E.38): a saved block's blocks in the
 * editor, saved explicitly (it updates every document using a synced block),
 * against the version it was opened at, so a save made meanwhile is never
 * overwritten.
 */

jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ user: { id: 12, email: 'amro@example.com' } }) }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));
jest.mock('@/hooks/useCapabilities', () => ({ useCapabilities: () => ({ hasPermission: () => true }) }));

const mockGet = jest.fn();
const mockUpdate = jest.fn();
jest.mock('@/lib/documents/saved-blocks', () => ({
  getLibraryBlock: (...a: unknown[]) => mockGet(...a),
  updateLibraryBlock: (...a: unknown[]) => mockUpdate(...a),
}));

import EditSavedBlockPage from '@/app/(editor)/admin/document-sources/saved-blocks/[id]/page';

const BANK = {
  id: 5,
  name: 'Bank details',
  description: null,
  mode: 'synced',
  sharing: 'workspace',
  owner: { kind: 'person', id: 12, name: 'Amro Saleh' },
  blocks: [{ id: 's1', type: 'paragraph', content: [{ type: 'text', text: 'IBAN JO00' }] }],
  perDocumentKeys: [],
  version: 3,
  usedIn: 12,
  archivedAt: null,
  createdAt: '2026-11-01T10:00:00Z',
  updatedAt: '2026-11-10T10:00:00Z',
};

beforeAll(() => {
  global.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
});

beforeEach(() => {
  jest.clearAllMocks();
  mockGet.mockResolvedValue(BANK);
});

async function openAndEdit() {
  let container!: HTMLElement;
  await act(async () => {
    ({ container } = render(
      <Suspense fallback={null}>
        <EditSavedBlockPage params={Promise.resolve({ id: '5' })} />
      </Suspense>
    ));
  });
  await screen.findByText('IBAN JO00');
  const editor = (container.querySelector('.ProseMirror') as HTMLElement & { editor: Editor }).editor;
  act(() => {
    editor.commands.setTextSelection(editor.state.doc.content.size - 1);
    editor.view.dispatch(editor.state.tr.insertText(' 1234'));
  });
  return screen.findByRole('region', { name: 'Unsaved changes' });
}

it('saves the edited blocks against the version it opened, naming the documents it updates', async () => {
  mockUpdate.mockResolvedValue({ ok: true, block: { ...BANK, version: 4 } });
  const bar = await openAndEdit();
  expect(bar).toHaveTextContent('Saving updates the 12 documents that use it.');

  await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  await waitFor(() => expect(mockUpdate).toHaveBeenCalled());
  const [id, patch] = mockUpdate.mock.calls[0];
  expect(id).toBe(5);
  expect(patch.version).toBe(3);
  expect(patch.blocks[0]).toMatchObject({ id: 's1', type: 'paragraph', content: [{ type: 'text', text: 'IBAN JO00 1234' }] });
  await waitFor(() => expect(screen.queryByRole('region', { name: 'Unsaved changes' })).toBeNull());
});

it('says so when someone else saved meanwhile, and keeps the edits', async () => {
  mockUpdate.mockResolvedValue({ ok: false, reason: 'changed' });
  await openAndEdit();
  await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
  await waitFor(() => expect(addToast).toHaveBeenCalledWith('Someone changed this block meanwhile. Close it and open it again.', 'error'));
  expect(screen.getByRole('region', { name: 'Unsaved changes' })).toBeInTheDocument();
});
