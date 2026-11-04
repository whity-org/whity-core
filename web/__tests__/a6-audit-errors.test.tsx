/**
 * A6: the audit log shows people and ids only when Show technical details is
 * on (the API returns an actor id, never a name), and the errors inbox leads
 * with the message, keeping the class and file:line for the technical view.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  action: 'role.updated',
  targetType: 'role',
  targetId: 5,
  metadata: { field: 'name' },
  ipAddress: '203.0.113.9',
  createdAt: '2026-11-02 10:31:00',
  actor: { kind: 'person', name: 'Rana Haddad', agent: null, forPerson: null },
  target: { type: 'role', name: 'Branch manager' },
  sentence: 'Rana Haddad changed role Branch manager',
  sentenceTemplate: '{actor} changed role {target}',
  area: 'people',
  how: null,
};

const AGENT_ENTRY = {
  ...ENTRY,
  id: 2,
  actor: { kind: 'agent', name: 'Billing assistant for Rana', agent: 'Billing assistant', forPerson: 'Rana' },
  sentence: 'Billing assistant for Rana changed role Branch manager',
  how: 'Through a connected agent',
};

const FACETS = {
  people: [{ id: 42, name: 'Rana Haddad' }],
  agents: [{ id: 'jti-1', name: 'Billing assistant' }],
  areas: [{ id: 'people', name: 'People' }],
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

let auditRows: unknown[] = [ENTRY];
let auditStatus = 200;

beforeEach(() => {
  auditRows = [ENTRY];
  auditStatus = 200;
  apiClient.mockReset();
  apiClient.mockImplementation(async (url: string) => {
    if (url.includes('/audit-logs/facets')) {
      return { ok: true, status: 200, json: async () => ({ data: FACETS }) };
    }
    if (url.includes('/audit-logs')) {
      return {
        ok: auditStatus === 200,
        status: auditStatus,
        json: async () => ({ data: auditRows, pagination: { page: 1, perPage: 25, total: auditRows.length, totalPages: 1 } }),
      };
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ data: [ENTRY], pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 } }),
    };
  });
});

describe('audit log', () => {
  it('says what happened with people by name, and no ids or addresses by default', async () => {
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByText('Rana Haddad')).toBeInTheDocument();
    expect(screen.getByText('Branch manager')).toBeInTheDocument();
    expect(screen.queryByText('#42', { exact: false })).toBeNull();
    expect(screen.queryByText('203.0.113.9')).toBeNull();
    expect(screen.queryByText('role.updated')).toBeNull();
  });

  it('adds codes, ids and addresses with Show technical details on', async () => {
    renderWith(true, <AuditLogsPage />);
    expect(await screen.findByText('role.updated')).toBeInTheDocument();
    expect(screen.getByText('#42')).toBeInTheDocument();
    expect(screen.getByText('203.0.113.9')).toBeInTheDocument();
    expect(screen.getByText('role #5')).toBeInTheDocument();
  });

  it('shows an agent as a chip for its person, with how', async () => {
    auditRows = [AGENT_ENTRY];
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByText('Billing assistant')).toBeInTheDocument();
    expect(screen.getByText('Through a connected agent')).toBeInTheDocument();
  });

  it('never reads a placeholder out of a name', async () => {
    auditRows = [{ ...ENTRY, actor: { ...ENTRY.actor, name: '{target}' } }];
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByText('{target}')).toBeInTheDocument();
  });

  it('says a refusal is a refusal, not an empty log', async () => {
    auditStatus = 403;
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByText('You can’t open the audit log')).toBeInTheDocument();
    expect(screen.queryByText('No audit entries found')).toBeNull();
  });

  it('says a failure is a failure, with a way to try again', async () => {
    auditStatus = 500;
    renderWith(false, <AuditLogsPage />);
    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('asks the server for the CSV, with the technical columns only when they are on', async () => {
    auditRows = [];
    renderWith(false, <AuditLogsPage />);
    const exportButton = await screen.findByRole('button', { name: 'Export' });
    URL.createObjectURL = jest.fn(() => 'blob:x');
    URL.revokeObjectURL = jest.fn();
    apiClient.mockImplementationOnce(async () => ({ ok: true, status: 200, blob: async () => new Blob(['x']) }));
    fireEvent.click(exportButton);
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining('/api/v1/audit-logs/export?')));
    const call = apiClient.mock.calls.find(([url]: [string]) => url.includes('/export'));
    expect(call?.[0]).not.toContain('technical=1');
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
