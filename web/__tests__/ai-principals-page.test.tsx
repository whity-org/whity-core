/**
 * Connected AI apps: anyone signed in sees and manages their own connections
 * (`/api/mcp/tokens`); only a holder of `mcp:tokens:manage` also gets the
 * list of everyone's (`/api/admin/mcp/tokens`) as a tab.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AiPrincipalsPage from '@/app/(protected)/admin/ai-principals/page';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));
const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: mockAddToast }) }));
let mockManage = false;
jest.mock('@/hooks/useCapabilities', () => ({ useCapabilities: () => ({ hasPermission: () => mockManage }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
// The wizard has its own tests.
jest.mock('@/app/(protected)/admin/ai-principals/connect-wizard', () => ({ ConnectWizard: () => null }));

function json(body: unknown, status = 200): Response {
  return { ok: status < 300, status, json: async () => body } as Response;
}

const OWN = {
  jti: 'j-own',
  name: 'My Cursor',
  principal_kind: 'mcp-client',
  scope: ['tools:call', 'resources:read', 'prompts:read'],
  expires_at: '2027-01-01T00:00:00Z',
  created_at: '2026-11-01T00:00:00Z',
  last_used_at: null,
};

function api(calls: string[]) {
  mockApiClient.mockImplementation(async (path: string, init?: RequestInit) => {
    calls.push(`${init?.method ?? 'GET'} ${path}`);
    if (path === '/api/v1/mcp/tokens') return json({ tokens: [OWN] });
    if (path === '/api/v1/mcp/tokens/j-own' && init?.method === 'DELETE') return { ok: true, status: 204, json: async () => ({}) } as Response;
    if (path.startsWith('/api/v1/admin/mcp/tokens')) {
      return json({
        data: [{ id: 1, jti: 'j-other', userId: 9, tenantId: 1, name: 'Their Claude', principalKind: 'mcp-client', scope: [], expiresAt: null, createdAt: null, lastUsedAt: null }],
        pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 },
      });
    }
    if (path.startsWith('/api/v1/users')) return json({ data: [{ id: 9, name: 'Rana Haddad' }], pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 } });
    throw new Error(`unexpected request ${path}`);
  });
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockAddToast.mockReset();
  mockManage = false;
});

describe('Connected AI apps', () => {
  it('shows a member their own connections without asking for anyone else’s', async () => {
    const calls: string[] = [];
    api(calls);
    render(<AiPrincipalsPage />);

    expect(await screen.findByText('My Cursor')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Connect an AI app' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: /Everyone/ })).not.toBeInTheDocument();
    expect(calls.some((call) => call.includes('/admin/') || call.includes('/users'))).toBe(false);
  });

  it('disconnects a member’s own app through their own route, after asking', async () => {
    const calls: string[] = [];
    api(calls);
    const user = userEvent.setup();
    render(<AiPrincipalsPage />);

    await screen.findByText('My Cursor');
    await user.click(screen.getByRole('button', { name: 'More actions' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Disconnect' }));
    expect(calls).not.toContain('DELETE /api/v1/mcp/tokens/j-own');
    await user.click(await screen.findByRole('button', { name: 'Disconnect app' }));

    await waitFor(() => expect(calls).toContain('DELETE /api/v1/mcp/tokens/j-own'));
  });

  it('gives someone who manages connections a tab for everyone’s, with names', async () => {
    mockManage = true;
    const calls: string[] = [];
    api(calls);
    const user = userEvent.setup();
    render(<AiPrincipalsPage />);

    await screen.findByText('My Cursor');
    await user.click(screen.getByRole('tab', { name: /Everyone/ }));

    expect(await screen.findByText('Their Claude')).toBeInTheDocument();
    expect(await screen.findByText('Rana Haddad')).toBeInTheDocument();
  });
});
