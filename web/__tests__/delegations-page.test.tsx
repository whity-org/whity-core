/**
 * The delegations list (design 2.9, A14): names from the API, Until, and a revoke that
 * happens at once with Undo.
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DelegationsPage from '@/app/(protected)/admin/delegations/page';

const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: mockAddToast }) }));

const mockGet = jest.fn();
const mockDelete = jest.fn();
const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockGet(...args),
    DELETE: (...args: unknown[]) => mockDelete(...args),
    POST: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('@/app/(protected)/admin/delegations/create-modal', () => ({ CreateDelegationModal: () => null }));
// OR.1: the page also reads the caller's own "I'm away" and everyone's away periods.
const mockApiClient = jest.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: mockAway }) }));
const mockAway: unknown = null;
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient: mockApiClient, user: { id: 1 } }) }));

const row = {
  id: 7,
  tenantId: 1,
  grantorProfileId: 1,
  grantorUserId: 1,
  granteeType: 'profile',
  granteeId: 2,
  permission: 'users:read',
  ouId: 4,
  grantedAt: '2026-10-01 00:00:00',
  revokedAt: null,
  expiresAt: null,
  status: 'active',
  grantorName: 'Rana Haddad',
  granteeName: 'Dana Yousef',
  ouName: 'Abdoun',
  permissionDescription: 'List and view users',
};

const ended = { ...row, id: 8, status: 'expired', expiresAt: '2026-10-02 00:00:00', granteeName: 'Omar Khalil' };

beforeEach(() => {
  jest.clearAllMocks();
  mockGet.mockImplementation(async (_path: string, options: { params: { query: { includeRevoked?: boolean } } }) => ({
    data: { data: options.params.query.includeRevoked ? [row, ended] : [row] },
    response: { status: 200, ok: true },
  }));
  mockDelete.mockResolvedValue({ error: undefined, response: { ok: true, status: 200 } });
  mockPost.mockResolvedValue({ error: undefined, response: { ok: true, status: 200 } });
});

describe('DelegationsPage', () => {
  it('shows who, where and until in words', async () => {
    render(<DelegationsPage />);

    expect(await screen.findByText('Rana Haddad')).toBeInTheDocument();
    expect(screen.getByText('Dana Yousef')).toBeInTheDocument();
    expect(screen.getByText('Abdoun')).toBeInTheDocument();
    expect(screen.getByText('No end date')).toBeInTheDocument();
  });

  it('keeps ended delegations under All, with nothing left to do', async () => {
    const user = userEvent.setup();
    render(<DelegationsPage />);
    await screen.findByText('Dana Yousef');

    await user.click(screen.getByRole('button', { name: 'All' }));

    expect(await screen.findByText('Omar Khalil')).toBeInTheDocument();
    expect(screen.getByText('Ended')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Revoke' })).toHaveLength(1);
  });

  it('revokes at once and offers Undo, which restores the same one', async () => {
    const user = userEvent.setup();
    render(<DelegationsPage />);
    await screen.findByText('Dana Yousef');

    await user.click(screen.getByRole('button', { name: 'Revoke' }));

    await waitFor(() => expect(mockDelete).toHaveBeenCalled());
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(mockAddToast).toHaveBeenCalled());
    const [, , , action] = mockAddToast.mock.calls[0];
    expect(action.label).toBe('Undo');

    action.onClick();

    await waitFor(() =>
      expect(mockPost).toHaveBeenCalledWith('/api/v1/delegations/{id}/restore', { params: { path: { id: 7 } } })
    );
  });

  it('says so when it is too late to undo', async () => {
    mockPost.mockResolvedValue({ error: { error: 'x' }, response: { ok: false, status: 409 } });
    const user = userEvent.setup();
    render(<DelegationsPage />);
    await screen.findByText('Dana Yousef');
    await user.click(screen.getByRole('button', { name: 'Revoke' }));
    await waitFor(() => expect(mockAddToast).toHaveBeenCalled());

    mockAddToast.mock.calls[0][3].onClick();

    await waitFor(() =>
      expect(mockAddToast).toHaveBeenCalledWith(
        'This can no longer be undone. To give the access back, delegate it again.',
        'error'
      )
    );
  });
});
