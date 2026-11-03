/**
 * Sign-up approval is the system tenant's alone, and the server refuses everybody
 * else, so a workspace admin is neither offered that tab nor left on its refusal.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { ApprovalGatingTabs } from '@/components/admin/approval-gating-tabs';
import PendingRegistrationsPage from '@/app/(protected)/admin/registrations/page';

const replace = jest.fn();
let tenantId = 3;

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace }),
  usePathname: () => '/admin/registrations',
}));
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { tenant_id: tenantId } }),
}));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission: () => true, loading: false }),
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

describe('the Approval Gating tabs', () => {
  beforeEach(() => {
    replace.mockReset();
    global.fetch = jest.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: [] }) })) as unknown as typeof fetch;
  });

  it('does not offer Signup to a workspace', () => {
    tenantId = 3;
    render(<ApprovalGatingTabs active="password-resets" />);

    expect(screen.queryByTestId('approval-gating-tab-signup')).toBeNull();
    expect(screen.getByTestId('approval-gating-tab-password-resets')).toBeTruthy();
    expect(screen.getByTestId('approval-gating-tab-two-factor-recovery')).toBeTruthy();
  });

  it('offers Signup to the system tenant', () => {
    tenantId = 0;
    render(<ApprovalGatingTabs active="signup" />);

    expect(screen.getByTestId('approval-gating-tab-signup')).toBeTruthy();
  });

  it('takes a workspace admin to their own tab, without asking the server for the queue', async () => {
    tenantId = 3;
    render(<PendingRegistrationsPage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/admin/approval-gating/password-resets'));
    expect(screen.queryByTestId('registrations-access-denied')).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('keeps the system tenant on the queue', async () => {
    tenantId = 0;
    render(<PendingRegistrationsPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(replace).not.toHaveBeenCalled();
  });
});
