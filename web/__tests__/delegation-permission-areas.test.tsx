/**
 * The delegate dialog's permission list: about ninety of them, so by area and
 * findable, in words, with codes only behind Show technical details (A.3).
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockGet = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...args: unknown[]) => mockGet(...args), POST: jest.fn() },
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

import { CreateDelegationModal } from '@/app/(protected)/admin/delegations/create-modal';

jest.setTimeout(30000);

const page = (data: unknown[]) => ({
  data: { data, pagination: { page: 1, perPage: 100, total: data.length, totalPages: 1 } },
  error: undefined,
  response: { ok: true, status: 200 },
});

beforeEach(() => {
  mockGet.mockImplementation(async (path: string) => {
    if (path === '/api/v1/permissions') {
      return page([
        { id: 1, name: 'users:read', description: 'List and view users', area: 'users', areaLabel: 'Users', source: 'db' },
        { id: 2, name: 'users:write', description: 'Change users', area: 'users', areaLabel: 'Users', source: 'db' },
        { id: 3, name: 'hello:view', description: 'HelloWorld plugin permission (hello:view)', source: 'plugin' },
      ]);
    }
    if (path === '/api/v1/roles') return page([{ id: 2, name: 'hello_viewer', tenant_id: 1 }]);
    return page([]);
  });
});

const open = async () => {
  render(<CreateDelegationModal isOpen onOpenChange={jest.fn()} onSuccess={jest.fn()} />);

  return screen.findByRole('dialog');
};

describe('The delegate dialog', () => {
  it('groups permissions by area', async () => {
    await open();

    const users = await screen.findByTestId('delegation-area-users');
    expect(within(users).getByRole('heading', { name: 'Users' })).toBeInTheDocument();
    expect(within(users).getByText('List and view users')).toBeInTheDocument();
    expect(within(users).getByText('Change users')).toBeInTheDocument();
  });

  it('puts a plugin permission in words, not its code', async () => {
    const dialog = await open();

    await screen.findByTestId('delegation-area-hello');
    expect(within(dialog).getByText('Hello view')).toBeInTheDocument();
    expect(within(dialog).queryByText(/hello:view/)).not.toBeInTheDocument();
  });

  it('finds a permission by its words', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    await open();
    await screen.findByTestId('delegation-area-users');

    await user.type(screen.getByRole('searchbox', { name: 'Find a permission' }), 'change');

    expect(screen.getByText('Change users')).toBeInTheDocument();
    expect(screen.queryByText('List and view users')).not.toBeInTheDocument();
    expect(screen.queryByTestId('delegation-area-hello')).not.toBeInTheDocument();
  });

  it('says whole workspace, not tenant-wide', async () => {
    const dialog = await open();
    await screen.findByTestId('delegation-area-users');

    expect(within(dialog).getByText('Whole workspace')).toBeInTheDocument();
    expect(within(dialog).queryByText(/Tenant-wide|\bOU\b/)).not.toBeInTheDocument();
  });
});
