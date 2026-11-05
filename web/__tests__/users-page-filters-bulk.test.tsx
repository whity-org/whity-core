/**
 * The users list's filters and bulk bar (design 2.9, A10): what they ask the server
 * for, and that a refusal comes back in words. The rules behind a refusal are the
 * server's, tested in PeopleServerRealEngineTest.
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import UsersPage from '@/app/(protected)/admin/users/page';

jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));

const mockHasPermission = jest.fn();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: (slug: string) => mockHasPermission(slug) }),
}));

const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: mockAddToast }) }));

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({ apiClient: (...args: unknown[]) => mockApiClient(...args) }));
jest.mock('@/lib/api/client', () => ({ api: { PATCH: jest.fn() } }));
jest.mock('@/app/(protected)/admin/users/create-modal', () => ({ CreateUserModal: () => null }));
jest.mock('@/app/(protected)/admin/users/delete-modal', () => ({ DeleteUserModal: () => null }));
jest.mock('@/app/(protected)/admin/users/invitations-panel', () => ({ InvitationsPanel: () => null }));
jest.mock('@/app/(protected)/admin/users/memberships-modal', () => ({ MembershipsModal: () => null }));

const person = (id: number, name: string, tenantId = 1) => ({
  id,
  name,
  displayName: name,
  email: `${name.toLowerCase()}@example.test`,
  role: 'user',
  roleId: 2,
  tenantId,
  ou_id: null,
  ouName: null,
  createdAt: '2026-01-01 00:00:00',
  lastActiveAt: null,
  status: 'active',
  accountStatus: 'active',
  authMethod: 'local',
  twoStep: { enabled: false, required: false, requiredDirectly: false },
});

const json = (body: unknown, status = 200) => ({ ok: status < 400, status, json: async () => body });

let bulkResponse: ReturnType<typeof json>;

beforeEach(() => {
  jest.clearAllMocks();
  mockHasPermission.mockReturnValue(true);
  bulkResponse = json({ data: { changed: [], unchanged: [] } });
  mockApiClient.mockImplementation(async (path: string) => {
    if (path.startsWith('/api/v1/users/bulk/')) return bulkResponse;
    if (path.startsWith('/api/v1/users')) {
      return json({ data: [person(5, 'Rana'), person(6, 'Omar')], pagination: { total: 2, totalPages: 1 } });
    }
    if (path.startsWith('/api/v1/roles')) {
      return json({ data: [{ id: 2, name: 'user' }, { id: 3, name: 'Baker' }], pagination: { total: 2, totalPages: 1 } });
    }
    if (path.startsWith('/api/v1/ous')) {
      return json({ data: [{ id: 9, name: 'Sweifieh' }], pagination: { total: 1, totalPages: 1 } });
    }
    return json({ data: [] });
  });
});

const usersRequests = () =>
  mockApiClient.mock.calls.map((call) => String(call[0])).filter((path) => path.startsWith('/api/v1/users?'));

const bulkCall = () => mockApiClient.mock.calls.find((call) => String(call[0]).startsWith('/api/v1/users/bulk/'));

describe('UsersPage filters', () => {
  it('asks for active people by default', async () => {
    render(<UsersPage />);
    await screen.findByText('Rana');
    expect(usersRequests().at(-1)).toContain('account_status=active');
  });

  it('asks the server for a role and names the value on the button', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await screen.findByText('Rana');

    await user.click(await screen.findByTestId('users-filter-role'));
    await user.click(await screen.findByRole('menuitemcheckbox', { name: 'Baker' }));

    await waitFor(() => expect(usersRequests().at(-1)).toContain('role_id=3'));
    expect(screen.getByTestId('users-filter-role')).toHaveTextContent('Role: Baker');
  });

  it('shows everyone under All', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await screen.findByText('Rana');

    await user.click(screen.getByRole('button', { name: 'All' }));

    await waitFor(() => expect(usersRequests().at(-1)).not.toContain('account_status'));
  });
});

describe('UsersPage bulk bar', () => {
  const selectBoth = async (user: ReturnType<typeof userEvent.setup>) => {
    await screen.findByText('Rana');
    await user.click(screen.getByRole('checkbox', { name: 'Select everyone on this page' }));
  };

  it('sends each person with their workspace, and names the role before changing it', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await selectBoth(user);

    await user.click(await screen.findByTestId('users-bulk-role'));
    await user.click(await screen.findByRole('menuitem', { name: 'Baker' }));
    const dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).getByText('Change the 2 selected to Baker?')).toBeInTheDocument();
    expect(bulkCall()).toBeUndefined();

    await user.click(within(dialog).getByRole('button', { name: 'Change role' }));

    await waitFor(() => expect(bulkCall()).toBeDefined());
    expect(bulkCall()?.[0]).toBe('/api/v1/users/bulk/role');
    expect(JSON.parse(String((bulkCall()?.[1] as { body: string }).body))).toEqual({
      role_id: 3,
      targets: [
        { profile_id: 5, tenant_id: 1 },
        { profile_id: 6, tenant_id: 1 },
      ],
    });
    await waitFor(() => expect(mockAddToast).toHaveBeenCalledWith('Role changed.', 'success'));
  });

  it('says why when the server refuses', async () => {
    bulkResponse = json(
      { error: 'Nothing was changed', details: { refused: [{ profile_id: 6, tenant_id: 1, reason: 'outranks_you' }] } },
      422
    );
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await selectBoth(user);

    await user.click(await screen.findByTestId('users-bulk-role'));
    await user.click(await screen.findByRole('menuitem', { name: 'Baker' }));
    await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Change role' }));

    await waitFor(() =>
      expect(mockAddToast).toHaveBeenCalledWith('Omar has access you do not, so you cannot change their role.', 'error')
    );
  });

  it('says this person when one is selected, and keeps its words while closing', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await screen.findByText('Rana');
    await user.click(screen.getAllByRole('checkbox', { name: /Select/ })[1]);

    await user.click(await screen.findByTestId('users-bulk-role'));
    await user.click(await screen.findByRole('menuitem', { name: 'Baker' }));
    const dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).getByText('Change this person to Baker?')).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: 'Change role' }));

    await waitFor(() => expect(bulkCall()).toBeDefined());
    expect(screen.queryByText(/Change the 0 selected/)).not.toBeInTheDocument();
    expect(screen.queryByText(/to \?$/)).not.toBeInTheDocument();
  });

  it('offers only what the reader may do', async () => {
    mockHasPermission.mockImplementation((slug: string) => slug !== 'security:manage');
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await selectBoth(user);

    expect(await screen.findByTestId('users-bulk-role')).toBeInTheDocument();
    expect(screen.queryByTestId('users-bulk-two-step')).not.toBeInTheDocument();
  });

  it('asks before requiring two-step, then sends it', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    render(<UsersPage />);
    await selectBoth(user);

    await user.click(await screen.findByTestId('users-bulk-two-step'));
    const dialog = await screen.findByRole('alertdialog');
    expect(bulkCall()).toBeUndefined();
    await user.click(within(dialog).getByRole('button', { name: 'Require two-step' }));

    await waitFor(() => expect(bulkCall()?.[0]).toBe('/api/v1/users/bulk/require-two-step'));
  });
});
