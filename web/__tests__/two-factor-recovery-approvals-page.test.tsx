/**
 * Two-step resets (AG.1, TplGatingTwoStep): two admins, each confirming it's
 * them with a code; the requester and the first admin can't give the second.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const hasPermission = jest.fn<boolean, [string]>();
let currentUserId = 10;
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { id: currentUserId, tenant_id: 3 } }),
}));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import TwoFactorRecoveryApprovalsPage from '@/app/(protected)/admin/approval-gating/two-factor-recovery/page';

const REQUEST = {
  id: 701,
  profile_id: 22,
  email: 'ibrahim@examplebakery.test',
  display_name: 'Ibrahim Mansour',
  reason: 'Lost his phone and codes',
  created_at: '2026-11-12T08:12:00Z',
  requested_from: 'Chrome on Windows',
  last_signed_in_at: '2026-11-11T09:00:00Z',
  checks: [{ text: 'Called him on the number on file', by_profile_id: 11, by_name: 'Emad Nasser', at: '2026-11-12T08:30:00Z' }],
  first_approval: null as null | { profile_id: number; display_name: string; at: string; method: string },
};

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

function respond(data: unknown, ok = true, status = 200) {
  (global.fetch as jest.Mock).mockResolvedValueOnce({ ok, status, json: async () => data });
}

beforeEach(() => {
  jest.clearAllMocks();
  currentUserId = 10;
  grant('two_factor_recovery:approve');
  global.fetch = jest.fn();
});

it('shows Access Denied for a caller lacking the permission', async () => {
  grant();
  render(<TwoFactorRecoveryApprovalsPage />);

  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-access-denied')).toBeInTheDocument());
  expect(global.fetch).not.toHaveBeenCalled();
});

it('shows the request, where it came from and the checks made', async () => {
  respond({ data: [REQUEST] });
  render(<TwoFactorRecoveryApprovalsPage />);

  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-detail-701')).toBeInTheDocument());
  expect(screen.getByText('Chrome on Windows')).toBeInTheDocument();
  expect(screen.getByText('ibrahim@examplebakery.test')).toBeInTheDocument();
  expect(screen.getByText('Called him on the number on file')).toBeInTheDocument();
  expect(screen.getByText("There's no Undo for this")).toBeInTheDocument();
});

it('renders the empty state when there are no pending requests', async () => {
  respond({ data: [] });
  render(<TwoFactorRecoveryApprovalsPage />);

  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-empty')).toBeInTheDocument());
});

it('asks for a code before approving, then sends it', async () => {
  respond({ data: [REQUEST] });
  render(<TwoFactorRecoveryApprovalsPage />);
  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-approve-701')).toBeInTheDocument());

  respond({ data: { method: 'email_code', email_hint: 'a•••@examplebakery.test' } });
  fireEvent.click(screen.getByTestId('two-factor-recovery-approve-701'));
  await waitFor(() => expect(screen.getByText(/We sent a code to a•••@examplebakery.test/)).toBeInTheDocument());
  expect(global.fetch).toHaveBeenLastCalledWith(
    '/api/v1/2fa-recovery/identity-check',
    expect.objectContaining({ method: 'POST', body: JSON.stringify({ request_id: 701 }) }),
  );

  respond({ data: { id: 701, status: 'waiting_for_second_admin' } });
  respond({ data: [{ ...REQUEST, first_approval: { profile_id: 10, display_name: 'Amro Saleh', at: '2026-11-12T08:34:00Z', method: 'email_code' } }] });
  const boxes = screen.getAllByRole('textbox');
  fireEvent.change(boxes[boxes.length - 1], { target: { value: '123456' } });
  fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

  await waitFor(() => expect(addToast).toHaveBeenCalledWith('Approved. It now needs a second admin.', 'success'));
  expect((global.fetch as jest.Mock).mock.calls.some(
    ([url, init]) => url === '/api/v1/2fa-recovery/701/approve' && init.body === JSON.stringify({ code: '123456' }),
  )).toBe(true);
});

it('does not let the first admin give the second approval', async () => {
  respond({ data: [{ ...REQUEST, first_approval: { profile_id: 10, display_name: 'Amro Saleh', at: '2026-11-12T08:34:00Z', method: 'totp' } }] });
  render(<TwoFactorRecoveryApprovalsPage />);

  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-approve-701')).toBeDisabled());
  expect(screen.getByText('A different admin has to give the second approval')).toBeInTheDocument();
});

it('does not let the requester approve their own', async () => {
  currentUserId = 22;
  respond({ data: [REQUEST] });
  render(<TwoFactorRecoveryApprovalsPage />);

  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-approve-701')).toBeDisabled());
});

it('rejects, leaving the account unchanged', async () => {
  respond({ data: [REQUEST] });
  render(<TwoFactorRecoveryApprovalsPage />);
  await waitFor(() => expect(screen.getByTestId('two-factor-recovery-reject-701')).toBeInTheDocument());

  respond({ data: { id: 701, status: 'rejected' } });
  respond({ data: [] });
  fireEvent.click(screen.getByTestId('two-factor-recovery-reject-701'));

  await waitFor(() => expect(addToast).toHaveBeenCalledWith('Rejected. Their account is unchanged.', 'success'));
});
