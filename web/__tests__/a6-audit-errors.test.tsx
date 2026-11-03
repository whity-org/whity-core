/**
 * A6: the audit log shows people and ids only when Show technical details is
 * on (the API returns an actor id, never a name), and the errors inbox leads
 * with the message, keeping the class and file:line for the technical view.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { DisplayPreferencesContextProvider } from '@whity/ui/display-preferences';

jest.mock('next/navigation', () => ({ useSearchParams: () => new URLSearchParams() }));

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient }) }));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: () => true, hasPermission: () => true, loading: false }),
}));

import AuditLogsPage from '@/app/(protected)/admin/audit-logs/page';
import ErrorsPage from '@/app/(protected)/admin/errors/page';

const ENTRY = {
  id: 1,
  tenantId: 1,
  actorUserId: 42,
  action: 'auth.login.success',
  targetType: 'role',
  targetId: 5,
  metadata: { via: 'password' },
  ipAddress: '203.0.113.9',
  createdAt: '2026-11-01T10:00:00Z',
};

function renderWith(technical: boolean, ui: React.ReactElement) {
  return render(
    <DisplayPreferencesContextProvider
      value={{ textSize: 'standard', showTechnicalDetails: technical, update: () => {} }}
    >
      {ui}
    </DisplayPreferencesContextProvider>
  );
}

beforeEach(() => {
  apiClient.mockResolvedValue({
    ok: true,
    json: async () => ({ data: [ENTRY], pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 } }),
  });
});

describe('audit log', () => {
  it('shows no ids, addresses or detail blobs by default', async () => {
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByText('A person')).toBeInTheDocument();
    expect(screen.queryByText('#42', { exact: false })).toBeNull();
    expect(screen.queryByText('203.0.113.9')).toBeNull();
    expect(screen.queryByText('role #5')).toBeNull();
    expect(screen.queryByText('Actor (User ID)')).toBeNull();
  });

  it('adds them with Show technical details on', async () => {
    renderWith(true, <AuditLogsPage />);
    expect(await screen.findByText('A person (#42)')).toBeInTheDocument();
    expect(screen.getByText('203.0.113.9')).toBeInTheDocument();
    expect(screen.getByText('role #5')).toBeInTheDocument();
    expect(screen.getByText('Actor (User ID)')).toBeInTheDocument();
  });
});

describe('errors inbox', () => {
  const GROUP = {
    id: 3,
    type: 'RuntimeException',
    message: 'The invoice could not be saved',
    file: 'src/Billing/Saver.php',
    line: 88,
    environment: 'production',
    occurrences: 2,
    status: 'unresolved',
    first_seen_at: '2026-11-01T10:00:00Z',
    last_seen_at: '2026-11-02T10:00:00Z',
  };

  beforeEach(() => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: [GROUP], counts: { unresolved: 1 } }),
    }) as unknown as typeof fetch;
  });

  it('leads with the message and hides class and location by default', async () => {
    renderWith(false, <ErrorsPage />);
    expect(await screen.findByText('The invoice could not be saved')).toBeInTheDocument();
    expect(screen.queryByText('RuntimeException')).toBeNull();
    expect(screen.queryByText('src/Billing/Saver.php:88')).toBeNull();
  });

  it('shows class and location with technical details on', async () => {
    renderWith(true, <ErrorsPage />);
    expect(await screen.findByText('RuntimeException')).toBeInTheDocument();
    expect(screen.getByText('src/Billing/Saver.php:88')).toBeInTheDocument();
  });
});
