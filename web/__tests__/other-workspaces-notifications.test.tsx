/**
 * WHIT-1081: Notifications shows your other workspaces' state, read-only
 * (owner decision 15 Nov 2026). Only for someone in several workspaces.
 */

import { render, screen, within } from '@testing-library/react';

let mockMemberships: { tenant_id: number; tenant_name: string; role: string }[] = [];
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { tenant_id: 1 }, memberships: mockMemberships }),
}));

const mockGet = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...args: unknown[]) => mockGet(...args) } }));

import { OtherWorkspacesNotifications } from '@/components/settings/other-workspaces-notifications';

beforeEach(() => {
  mockGet.mockReset();
  mockMemberships = [
    { tenant_id: 1, tenant_name: 'Example Bakery', role: 'owner' },
    { tenant_id: 2, tenant_name: 'Example Clinic', role: 'member' },
    { tenant_id: 3, tenant_name: 'Amman Coffee Co.', role: 'member' },
  ];
});

describe('OtherWorkspacesNotifications', () => {
  it('lists each other workspace with its devices and email and push state, not the current one', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [
          { tenant_id: 1, current: true, push_devices: 2, email: 'on', push: 'on' },
          { tenant_id: 2, current: false, push_devices: 1, email: 'off', push: 'some' },
          { tenant_id: 3, current: false, push_devices: 0, email: 'on', push: 'on' },
        ],
      },
    });
    render(<OtherWorkspacesNotifications />);
    const clinic = await screen.findByTestId('other-workspace-2');
    expect(within(clinic).getByText('Example Clinic')).toBeInTheDocument();
    expect(clinic).toHaveTextContent('Push on 1 device · Email: off · Push: some kinds');
    expect(screen.getByTestId('other-workspace-3')).toHaveTextContent('No device with push · Email: on · Push: on');
    expect(screen.queryByTestId('other-workspace-1')).toBeNull();
    expect(mockGet).toHaveBeenCalledWith('/api/v1/me/notification-summary');
  });

  it('asks nothing and shows nothing for someone in one workspace', () => {
    mockMemberships = [{ tenant_id: 1, tenant_name: 'Example Bakery', role: 'owner' }];
    const { container } = render(<OtherWorkspacesNotifications />);
    expect(container).toBeEmptyDOMElement();
    expect(mockGet).not.toHaveBeenCalled();
  });

  it('shows nothing when the read fails, rather than a wrong answer', async () => {
    mockGet.mockRejectedValue(new Error('network'));
    const { container } = render(<OtherWorkspacesNotifications />);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(container).toBeEmptyDOMElement();
  });
});
