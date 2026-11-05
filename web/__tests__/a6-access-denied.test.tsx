/**
 * A6 final pass: a refused request is "you don't have access" in plain words,
 * never "Failed to load" with Try again, never an empty list, and never a
 * permission code in the sentence.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { DisplayPreferencesContextProvider } from '@whity/ui/display-preferences';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient, user: { tenant_id: 1, id: 5 } }),
}));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: () => true, hasPermission: () => true, loading: false }),
}));

import { NoAccess } from '@/components/no-access';
import AgentsPage from '@/app/(protected)/admin/agents/page';
import TenantsPage from '@/app/(protected)/admin/tenants/page';
import BillingPage from '@/app/(protected)/billing/page';
import AuditLogsPage from '@/app/(protected)/admin/audit-logs/page';

const refused = { ok: false, status: 403, json: async () => ({ error: 'Forbidden' }) };

function withTechnical(technical: boolean, ui: React.ReactElement) {
  return render(
    <DisplayPreferencesContextProvider value={{ textSize: 'standard', showTechnicalDetails: technical, update: () => {} }}>
      {ui}
    </DisplayPreferencesContextProvider>
  );
}

beforeEach(() => {
  jest.clearAllMocks();
  apiClient.mockResolvedValue(refused);
});

describe('NoAccess', () => {
  it('keeps the permission code out of the sentence unless technical details are on', () => {
    const { unmount } = withTechnical(false, <NoAccess area="plugins" permission="plugins:read" />);
    expect(screen.getByText('You don’t have access to plugins')).toBeInTheDocument();
    expect(screen.queryByText(/plugins:read/)).toBeNull();
    unmount();
    withTechnical(true, <NoAccess area="plugins" permission="plugins:read" />);
    expect(screen.getByText('Needs the plugins:read permission.')).toBeInTheDocument();
  });
});

describe('a refused list', () => {
  it('agents: no access, no failure, no New agent button', async () => {
    withTechnical(false, <AgentsPage />);
    expect(await screen.findByText('You don’t have access to agents')).toBeInTheDocument();
    expect(screen.queryByText('The agents could not be loaded.')).toBeNull();
    expect(screen.queryByRole('button', { name: 'New agent' })).toBeNull();
  });

  it('billing: no access and no "No invoices yet"', async () => {
    withTechnical(false, <BillingPage />);
    expect(await screen.findByText('You don’t have access to billing')).toBeInTheDocument();
    expect(screen.queryByText('No invoices yet')).toBeNull();
    expect(screen.queryByText('Failed to load invoices')).toBeNull();
  });

  it('audit log: no filters and no Export when the log is refused', async () => {
    withTechnical(false, <AuditLogsPage />);
    expect(await screen.findByText('You can’t open the audit log')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Export' })).toBeNull();
    expect(screen.queryByLabelText('Who')).toBeNull();
  });
});

describe('workspaces', () => {
  it('are the system tenant’s: a workspace admin gets no access, not their own row', async () => {
    withTechnical(false, <TenantsPage />);
    expect(await screen.findByText('You don’t have access to workspaces')).toBeInTheDocument();
    expect(screen.queryByText('Tenants')).toBeNull();
  });
});
