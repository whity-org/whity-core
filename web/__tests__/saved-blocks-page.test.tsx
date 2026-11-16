import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The saved blocks library (editor M7; E.21, E.22): this person's own blocks
 * and the workspace library (TplSavedBlocks): filters, a row menu to rename,
 * share and delete, a side panel with the text and where a synced block is used, where a synced block still in use needs
 * documents:publish and the name typed to delete anyway.
 */

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: jest.fn(), user: { id: 12, email: 'amro@example.com', role: '', tenant_id: 1 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

const mockList = jest.fn();
const mockUpdate = jest.fn();
const mockDelete = jest.fn();
const mockUsage = jest.fn();
jest.mock('@/lib/documents/saved-blocks', () => ({
  listLibrary: () => mockList(),
  updateLibraryBlock: (...a: unknown[]) => mockUpdate(...a),
  deleteLibraryBlock: (...a: unknown[]) => mockDelete(...a),
  libraryBlockUsage: (...a: unknown[]) => mockUsage(...a),
}));

import SavedBlocksPage from '@/app/(protected)/admin/document-sources/saved-blocks/page';

const block = (over: Record<string, unknown>) => ({
  id: 5,
  name: 'Bank details',
  description: null,
  mode: 'synced',
  sharing: 'workspace',
  owner: { kind: 'person', id: 10, name: 'Awss Haddad' },
  blocks: [],
  perDocumentParts: [],
  perDocumentKeys: [],
  version: 3,
  usedIn: 12,
  archivedAt: null,
  createdAt: '2026-11-01T10:00:00Z',
  updatedAt: '2026-11-10T10:00:00Z',
  ...over,
});
const BANK = block({});
const TERMS = block({ id: 6, name: 'Payment terms', mode: 'copy', sharing: 'me', owner: { kind: 'person', id: 12, name: 'Amro Saleh' }, usedIn: 0 });

function grant(...perms: string[]) {
  hasPermission.mockImplementation((p) => perms.includes(p));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockList.mockResolvedValue([BANK, TERMS]);
  mockUsage.mockResolvedValue({ documents: [{ id: 40, title: 'Example Bakery invoice', kind: 'template', version: 3 }], hidden: 1 });
});

it('lists blocks with their kind, who can use them and where synced ones are used, filtered by kind (TplSavedBlocks)', async () => {
  grant('documents:read', 'documents:write');
  render(<SavedBlocksPage />);

  const bank = await screen.findByRole('row', { name: /Bank details/ });
  expect(within(bank).getByText('Synced')).toBeInTheDocument();
  expect(within(bank).getByText('Everyone in the workspace')).toBeInTheDocument();
  expect(within(bank).getByText('12 documents')).toBeInTheDocument();
  const terms = screen.getByRole('row', { name: /Payment terms/ });
  expect(within(terms).getByText('Copy')).toBeInTheDocument();
  expect(within(terms).getByText('Only me')).toBeInTheDocument();

  await userEvent.click(screen.getByTestId('saved-blocks-filter-kind'));
  await userEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Copy' }));
  await userEvent.keyboard('{Escape}');
  await waitFor(() => expect(screen.queryByRole('row', { name: /Bank details/ })).toBeNull());
  expect(screen.getByRole('row', { name: /Payment terms/ })).toBeInTheDocument();
});

it('searches by name', async () => {
  grant('documents:read');
  render(<SavedBlocksPage />);
  await screen.findByRole('row', { name: /Bank details/ });
  await userEvent.type(screen.getByRole('searchbox', { name: 'Search saved blocks' }), 'terms');
  await waitFor(() => expect(screen.queryByRole('row', { name: /Bank details/ })).toBeNull());
  expect(screen.getByRole('row', { name: /Payment terms/ })).toBeInTheDocument();
});

it('renames your own block from its row menu', async () => {
  grant('documents:read', 'documents:write');
  mockUpdate.mockResolvedValue({ ok: true, block: { ...TERMS, name: 'Terms' } });
  render(<SavedBlocksPage />);

  await userEvent.click(await screen.findByRole('button', { name: 'More for Payment terms' }));
  // Sharing with the workspace is for people who manage the library.
  expect(screen.queryByRole('menuitem', { name: 'Let everyone in the workspace use it' })).toBeNull();
  await userEvent.click(await screen.findByRole('menuitem', { name: 'Rename' }));
  const dialog = await screen.findByRole('dialog', { name: 'Rename saved block' });
  const name = within(dialog).getByLabelText('Name');
  await userEvent.clear(name);
  await userEvent.type(name, 'Terms');
  await userEvent.click(within(dialog).getByRole('button', { name: 'Rename' }));
  expect(mockUpdate).toHaveBeenCalledWith(6, { name: 'Terms' });
});

it('shows a synced block, its per-document parts and where it is used in the side panel', async () => {
  grant('documents:read', 'documents:write');
  mockList.mockResolvedValue([
    block({
      blocks: [{ id: 'p1', type: 'paragraph', content: [{ type: 'text', text: 'Pay to: ' }, { type: 'text', text: 'Workspace name', marks: [{ type: 'perDocument', key: 'pd_payto001' }] }] }],
      perDocumentParts: [{ key: 'pd_payto001', label: { en: 'Pay to' }, kind: 'text', required: true }],
      version: 4,
    }),
  ]);
  render(<SavedBlocksPage />);

  await userEvent.click(await screen.findByText('Bank details'));
  const panel = await screen.findByRole('dialog');
  expect(within(panel).getByText('Synced block')).toBeInTheDocument();
  expect(within(panel).getByText('Used in 12 documents')).toBeInTheDocument();
  expect(within(panel).getByText('Workspace name')).toHaveAttribute('title', 'Filled in each document, required: Pay to');
  expect(await within(panel).findByRole('link', { name: 'Example Bakery invoice' })).toHaveAttribute('href', '/admin/document-sources/40');
  expect(within(panel).getByText('Changes at next publish')).toBeInTheDocument();
  expect(within(panel).getByText('And 1 you can’t see')).toBeInTheDocument();
  // Someone else's workspace block, without documents:publish: read only.
  expect(within(panel).queryByRole('button', { name: 'Rename' })).toBeNull();
});

it('deleting a synced block still in use asks again, and needs the name typed', async () => {
  grant('documents:read', 'documents:write', 'documents:publish');
  mockDelete.mockResolvedValueOnce({ ok: false, usedIn: 12 }).mockResolvedValueOnce({ ok: true });
  render(<SavedBlocksPage />);

  await userEvent.click(await screen.findByRole('button', { name: 'More for Bank details' }));
  await userEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }));
  const first = await screen.findByRole('alertdialog');
  await userEvent.click(within(first).getByRole('button', { name: 'Delete' }));
  expect(mockDelete).toHaveBeenNthCalledWith(1, 5, false);

  const again = await screen.findByRole('alertdialog', { name: 'Bank details is used in 12 documents' });
  const anyway = within(again).getByRole('button', { name: 'Delete anyway' });
  expect(anyway).toBeDisabled();
  await userEvent.type(within(again).getByRole('textbox'), 'Bank details');
  await userEvent.click(anyway);
  await waitFor(() => expect(mockDelete).toHaveBeenNthCalledWith(2, 5, true));
  expect(addToast).toHaveBeenCalledWith('Saved block deleted', 'success');
});
