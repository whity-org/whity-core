/**
 * #884 — a 2FA policy can be edited. The PATCH route accepts one field, the
 * grace period; who a policy covers is identity, so the form shows it but
 * does not let it change.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();
const mockApiPatch = jest.fn();
const mockApiDelete = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
    DELETE: (...args: unknown[]) => mockApiDelete(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission: () => true }),
}));

import SecurityPolicySettingsPage from '@/app/(protected)/admin/settings/security/page';

const POLICY = {
  id: 7,
  tenant_id: 1,
  scope_type: 'ou',
  scope_id: 3,
  scope_name: 'Finance',
  grace_period_days: 14,
  created_at: '2026-01-01 00:00:00',
};

function page(data: unknown[]) {
  return Promise.resolve({
    data: { data, pagination: { page: 1, perPage: 100, total: data.length, totalPages: 1 } },
    error: undefined,
    response: { ok: true, status: 200 },
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/2fa-policies') return page([POLICY]);
    return page([]);
  });
  mockApiPatch.mockResolvedValue({
    data: { data: { ...POLICY, grace_period_days: 30 } },
    error: undefined,
    response: { ok: true, status: 200 },
  });
});

async function openEditor() {
  render(<SecurityPolicySettingsPage />);
  fireEvent.click(await screen.findByTestId('edit-policy-7'));
  return screen.findByTestId('two-factor-policy-form');
}

describe('2FA policies — edit', () => {
  it('shows the scope as fixed text and the current grace period', async () => {
    await openEditor();

    expect(screen.getByTestId('policy-scope-locked')).toHaveTextContent('Finance');
    expect(screen.queryByTestId('policy-scope-type')).not.toBeInTheDocument();
    expect(screen.getByTestId('policy-grace-period')).toHaveValue(14);
    expect(screen.queryByTestId('two-factor-policy-7')).not.toBeInTheDocument();
  });

  it('PATCHes only the grace period for that policy, then refreshes', async () => {
    await openEditor();
    fireEvent.change(screen.getByTestId('policy-grace-period'), { target: { value: '30' } });
    fireEvent.click(screen.getByTestId('save-two-factor-policy'));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith('/api/v1/2fa-policies/{id}', {
        params: { path: { id: 7 } },
        body: { grace_period_days: 30 },
      })
    );
    expect(mockApiPost).not.toHaveBeenCalled();
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('2FA policy updated.', 'success'));
    await waitFor(() => expect(screen.queryByTestId('two-factor-policy-form')).not.toBeInTheDocument());
  });

  it('rejects a negative grace period without calling the API', async () => {
    await openEditor();
    fireEvent.change(screen.getByTestId('policy-grace-period'), { target: { value: '-1' } });
    fireEvent.click(screen.getByTestId('save-two-factor-policy'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('non-negative'), 'error'));
    expect(mockApiPatch).not.toHaveBeenCalled();
  });

  it('keeps the form open and toasts the server error on failure', async () => {
    mockApiPatch.mockResolvedValue({
      data: undefined,
      error: { error: 'Policy not found' },
      response: { ok: false, status: 404 },
    });
    await openEditor();
    fireEvent.click(screen.getByTestId('save-two-factor-policy'));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Policy not found', 'error'));
    expect(screen.getByTestId('two-factor-policy-form')).toBeInTheDocument();
  });
});
