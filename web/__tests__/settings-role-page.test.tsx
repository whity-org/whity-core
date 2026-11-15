/**
 * WHIT-1081: You in [workspace] › Your role. A role belongs to one workspace,
 * so the page names it, lists every role held there (WHIT-1079), and shows
 * the person's roles in their other workspaces underneath.
 */

import { render, screen, within } from '@testing-library/react';

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { tenant_id: 4, role: 'owner' },
    memberships: [
      { tenant_id: 4, tenant_name: 'Example Bakery', role: 'owner', roles: ['owner', 'editor'] },
      { tenant_id: 9, tenant_name: 'Amman Coffee Co.', role: 'member' },
    ],
  }),
}));

import RoleSettingsPage from '@/app/(protected)/settings/role/page';

describe('Your role', () => {
  it('names the workspace and every role held there, then the other workspaces', () => {
    render(<RoleSettingsPage />);
    expect(screen.getByRole('heading', { name: 'Your role', level: 1 })).toBeInTheDocument();
    expect(screen.getByText('In Example Bakery')).toBeInTheDocument();
    const roles = within(screen.getByTestId('role-list')).getAllByRole('listitem').map((li) => li.textContent);
    expect(roles).toHaveLength(2);
    const others = screen.getByTestId('other-workspace-roles');
    expect(within(others).getByText('Amman Coffee Co.')).toBeInTheDocument();
    expect(within(others).queryByText('Example Bakery')).toBeNull();
  });
});
