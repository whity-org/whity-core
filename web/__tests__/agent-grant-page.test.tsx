/**
 * WHIT-810 — one agent credential's grant, over WHIT-832's
 * /api/mcp/tokens/{jti}/grant.
 *
 *  - rows group by resource and show the chosen mode, or what the default amounts to;
 *  - a withheld mode can't be picked, and says why;
 *  - a change is a PATCH of that one permission, and the page shows what the server answers;
 *  - someone who didn't issue it is told it isn't theirs to set (the server's 404).
 */

import React from 'react';
import { render, screen, waitFor, within, fireEvent } from '@testing-library/react';
import { grantPath, readGrant, shownMode, type GrantRow } from '@/components/agents/agent-grant';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient: mockApiClient }) }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('next/navigation', () => ({ useParams: () => ({ jti: 'jti-billing' }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import AgentGrantPage from '@/app/(protected)/admin/ai-principals/[jti]/page';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

function row(permission: string, overrides: Partial<GrantRow> = {}): GrantRow {
  return {
    permission,
    mode: null,
    modes: ['off', 'ask', 'allow'],
    withheld: {},
    effective: { read: 'allow', write: null },
    ...overrides,
  };
}

const grant = {
  jti: 'jti-billing',
  name: 'Billing assistant',
  permissions: [
    row('tags:read'),
    row('tags:manage', { mode: 'ask', modes: ['off', 'ask'], effective: { read: null, write: 'ask' },
      withheld: { allow: { reason: 'always_asks', message: 'server english' } } }),
  ],
};

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
});

describe('Agent grant page', () => {
  it('shows each permission with its mode, and why Allowed is withheld', async () => {
    mockApiClient.mockReturnValue(json(200, { data: grant }));
    render(<AgentGrantPage />);
    expect(await screen.findByRole('heading', { name: 'Billing assistant' })).toBeInTheDocument();
    const read = screen.getByRole('radiogroup', { name: 'Tags · Read' });
    expect(within(read).getByRole('radio', { checked: true })).toHaveTextContent('Allowed');
    const manage = screen.getByRole('radiogroup', { name: 'Tags · Manage' });
    expect(within(manage).getByRole('radio', { name: 'Allowed' })).toBeDisabled();
    expect(screen.getByText(/can't be undone yet/)).toBeInTheDocument();
  });

  it('saves one permission at a time and shows what the server answered', async () => {
    mockApiClient.mockImplementation((_path: string, init?: RequestInit) => {
      if (init?.method === 'PATCH') {
        expect(JSON.parse(String(init.body))).toEqual({ 'tags:manage': 'off' });
        return json(200, { data: { ...grant, permissions: [grant.permissions[0], { ...grant.permissions[1], mode: 'off' }] } });
      }
      return json(200, { data: grant });
    });
    render(<AgentGrantPage />);
    const manage = await screen.findByRole('radiogroup', { name: 'Tags · Manage' });
    fireEvent.click(within(manage).getByRole('radio', { name: 'Off' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('tags:manage: Off', 'success'));
    expect(within(screen.getByRole('radiogroup', { name: 'Tags · Manage' })).getByRole('radio', { checked: true })).toHaveTextContent('Off');
  });

  it('tells someone who did not issue it that it is not theirs to set', async () => {
    mockApiClient.mockReturnValue(json(404, { error: 'Not found' }));
    render(<AgentGrantPage />);
    expect(await screen.findByText('Not yours to set')).toBeInTheDocument();
  });
});

describe('shownMode', () => {
  it('shows what the default amounts to, by its changes first', () => {
    expect(shownMode(row('a:b', { effective: { read: 'allow', write: 'ask' } }))).toBe('ask');
    expect(shownMode(row('a:b', { effective: { read: 'allow', write: null } }))).toBe('allow');
    expect(shownMode(row('a:b', { effective: { read: null, write: 'ask' } }))).toBe('ask');
    expect(shownMode(row('a:b', { effective: { read: null, write: null } }))).toBe('off');
    expect(shownMode(row('a:b', { mode: 'off' }))).toBe('off');
  });
});

describe('the two addresses', () => {
  it('points a credential and a workspace agent at their own grant', () => {
    expect(grantPath({ kind: 'credential', jti: 'a/b' })).toBe('/api/v1/mcp/tokens/a%2Fb/grant');
    expect(grantPath({ kind: 'agent', id: 7 })).toBe('/api/v1/me/agents/7/assignment/grant');
  });

  it('names a credential by itself, and an agent by its definition in the language shown', () => {
    expect(readGrant({ name: 'My bot', permissions: [] }, true).name).toBe('My bot');
    const agent = { agent: { name: 'Billing assistant', nameAr: 'مساعد الفواتير' }, permissions: [] };
    expect(readGrant(agent, false).name).toBe('Billing assistant');
    expect(readGrant(agent, true).name).toBe('مساعد الفواتير');
  });
});
