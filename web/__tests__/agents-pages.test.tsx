/**
 * WHIT-820 — the agents pages over /api/agents, /api/agent-presets and /api/me/agents.
 *
 *  - admins create from a preset, and archive and restore without a confirm;
 *  - the persona editor saves both languages, and shows the server's refusal;
 *  - a starting grant never offers Allowed on a write;
 *  - a person starts and stops using an agent, stopping asks first, and a key is
 *    made only on request.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { isReadPermission, localized, templateGroups, toolsetsFromSplits, type WorkspaceAgent } from '@/components/agents/workspace-agents';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient: mockApiClient }) }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/hooks/useCapabilities', () => ({ useCapabilities: () => ({ hasPermission: () => true }) }));
const push = jest.fn();
jest.mock('next/navigation', () => ({ useParams: () => ({ id: '3' }), useRouter: () => ({ push }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import AgentsPage from '@/app/(protected)/admin/agents/page';
import MyAgentsPage from '@/app/(protected)/admin/my-agents/page';
import { PersonaEditor } from '@/components/agents/persona-editor';
import { AgentRecord } from '@/components/agents/agent-record';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

const clerk: WorkspaceAgent = {
  id: 3,
  slug: 'finance-clerk',
  name: 'Finance clerk',
  nameAr: 'موظف المالية',
  role: 'Prepares invoices.',
  roleAr: null,
  instructions: 'JOD, three decimals.',
  instructionsAr: null,
  tone: 'plain',
  language: 'auto',
  toolsets: ['core.billing'],
  grant: { 'billing:read': 'allow', 'documents:issue': 'ask' },
  preset: null,
  status: 'active',
  createdAt: '2026-03-12 09:00:00',
  updatedAt: '2026-10-03 09:00:00',
  paused: false,
  pausedAt: null,
  pausedBy: null,
  updatedBy: 'Rana Haddad',
  worksFor: [{ id: 10, name: 'Rana Haddad' }, { id: 11, name: 'Omar Khalil' }],
  actionsToday: 14,
  proposalsToday: 2,
  waiting: 2,
  lastActiveAt: '2026-11-03 09:12:00',
  onItsOwn: { allowed: 6, askFirst: 4, off: 1 },
  mayRead: [
    { id: 5, title: 'How we write to customers', kind: 'wiki', readable: true },
    { id: 6, title: null, kind: null, readable: false },
  ],
};

function route(routes: Record<string, () => unknown>) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    const key = `${init?.method ?? 'GET'} ${url}`;
    const answer = routes[key];
    return answer ? answer() : json(404, { error: `unexpected ${key}` });
  });
}

const sent = (key: string) => {
  const call = mockApiClient.mock.calls.find(([url, init]) => `${init?.method ?? 'GET'} ${url}` === key);
  return call ? JSON.parse(String(call[1].body ?? '{}')) : undefined;
};

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
  push.mockReset();
});

describe('workspace agent helpers', () => {
  it('falls back to English where there is no Arabic', () => {
    expect(localized('Clerk', 'كاتب', true)).toBe('كاتب');
    expect(localized('Clerk', '  ', true)).toBe('Clerk');
    expect(localized('Clerk', 'كاتب', false)).toBe('Clerk');
  });

  it('tells reads from writes as the server does', () => {
    expect(isReadPermission('documents:read')).toBe(true);
    expect(isReadPermission('reports:view')).toBe(true);
    expect(isReadPermission('documents:issue')).toBe(false);
  });

  it('never offers Allowed on a write in a starting grant', () => {
    const groups = templateGroups({ 'documents:issue': 'ask', 'documents:read': 'allow' }, 'why');
    const actions = groups.flatMap((group) => group.actions);
    expect(actions.find((a) => a.key === 'documents:issue')?.modes).toEqual(['off', 'ask']);
    expect(actions.find((a) => a.key === 'documents:issue')?.hint).toBe('why');
    expect(actions.find((a) => a.key === 'documents:read')?.modes).toEqual(['off', 'ask', 'allow']);
  });

  it('offers each toolset once, and keeps one chosen that no split names', () => {
    expect(toolsetsFromSplits([{ toolsets: ['b', 'a'] }, { toolsets: ['a'] }], ['z'])).toEqual(['a', 'b', 'z']);
  });
});

describe('Agents page', () => {
  it('creates from a preset and opens the new agent', async () => {
    route({
      'GET /api/v1/agents?archived=1': () => json(200, { data: [] }),
      'GET /api/v1/agent-presets': () =>
        json(200, { data: [{ ...clerk, key: 'core.finance-clerk', version: 1, does: ['Finds overdue invoices', 'Prepares reminders', 'Sums up each batch'], doesAr: [], icon: 'billing', needs: null, agentsFromIt: 2 }] }),
      'POST /api/v1/agents': () => json(201, { data: clerk }),
    });
    render(<AgentsPage />);

    fireEvent.click(await screen.findByRole('button', { name: 'New agent' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Use this agent' }));

    await waitFor(() => expect(push).toHaveBeenCalledWith('/admin/agents/3'));
    expect(screen.queryByText('Finds overdue invoices')).not.toBeNull();
    expect(screen.queryByText('Works with Whity alone')).not.toBeNull();
    expect(screen.queryByText('You have 2 from this')).not.toBeNull();
    expect(sent('POST /api/v1/agents')).toEqual({ presetKey: 'core.finance-clerk' });
  });

  it('archives without asking, and an archived agent offers Restore', async () => {
    route({
      'GET /api/v1/agents?archived=1': () => json(200, { data: [clerk, { ...clerk, id: 4, name: 'Old', status: 'archived' }] }),
      'POST /api/v1/agents/3/archive': () => json(200, { data: clerk }),
    });
    render(<AgentsPage />);

    const user = userEvent.setup();
    const menus = await screen.findAllByRole('button', { name: 'More actions' });
    await user.click(menus[0]);
    await user.click(await screen.findByRole('menuitem', { name: 'Archive' }));

    // Tier 1: it acts at once and the toast carries Undo.
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(expect.stringContaining('archived'), 'success', 8000, expect.objectContaining({ label: 'Undo' })),
    );
    await user.click((await screen.findAllByRole('button', { name: 'More actions' }))[1]);
    expect(await screen.findByRole('menuitem', { name: 'Restore' })).toBeInTheDocument();
  });
});

describe('Persona editor', () => {
  it('saves both languages together', async () => {
    route({
      'GET /api/v1/mcp/splits': () => json(200, { data: [{ toolsets: ['core.billing', 'core.documents'] }] }),
      'PATCH /api/v1/agents/3': () => json(200, { data: clerk }),
    });
    render(<PersonaEditor agent={clerk} />);

    expect(await screen.findByText('Documents')).toBeInTheDocument();
    expect(screen.queryByText('core.documents')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('What it does', { selector: '#agent-role-ar' }), { target: { value: 'يجهّز الفواتير.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(sent('PATCH /api/v1/agents/3')).toBeDefined());
    expect(sent('PATCH /api/v1/agents/3')).toMatchObject({
      name: 'Finance clerk',
      nameAr: 'موظف المالية',
      roleAr: 'يجهّز الفواتير.',
      toolsets: ['core.billing'],
      grant: { 'documents:issue': 'ask' },
    });
    expect(push).not.toHaveBeenCalled();
  });

  it('shows why the server refused', async () => {
    route({
      'GET /api/v1/mcp/splits': () => json(200, { data: [] }),
      'POST /api/v1/agents': () => json(422, { error: 'Invalid agent', errors: ['grant for documents:issue can start at off or ask'] }),
    });
    render(<PersonaEditor agent={null} />);

    fireEvent.change(screen.getByLabelText('Role name', { selector: '#agent-name' }), { target: { value: 'Clerk' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText(/can start at off or ask/)).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});

describe('Agent record', () => {
  it('collects edits in a save bar and saves them together', async () => {
    route({
      'GET /api/v1/mcp/splits': () => json(200, { data: [] }),
      'PATCH /api/v1/agents/3': () => json(200, { data: clerk }),
    });
    const user = userEvent.setup();
    render(<AgentRecord agent={clerk} onChanged={() => {}} />);

    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('tab', { name: 'Instructions' }));
    fireEvent.change(screen.getByLabelText('What it does', { selector: '#agent-role' }), { target: { value: 'Prepares and chases invoices.' } });

    expect(await screen.findByText('Name and job changed')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(sent('PATCH /api/v1/agents/3')).toMatchObject({ role: 'Prepares and chases invoices.', name: 'Finance clerk' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument());
  });
});

describe('Pausing and what the list shows', () => {
  it('shows who it works for, how much it does alone, and pauses at once with Undo', async () => {
    route({
      'GET /api/v1/agents?archived=1': () => json(200, { data: [clerk] }),
      'POST /api/v1/agents/3/pause': () => json(200, { data: { ...clerk, paused: true } }),
    });
    const user = userEvent.setup();
    render(<AgentsPage />);

    expect(await screen.findByText('Rana Haddad')).toBeInTheDocument();
    expect(screen.getByText('+1 more')).toBeInTheDocument();
    expect(screen.getByText('6 allowed')).toBeInTheDocument();
    expect(screen.getByText('4 ask first')).toBeInTheDocument();
    expect(screen.getAllByText('Working').length).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: 'More actions' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Pause' }));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith(expect.stringContaining('paused'), 'success', 8000, expect.objectContaining({ label: 'Undo' })));
  });

  it('a paused agent reads as paused and its record offers Resume', async () => {
    const resumed = jest.fn(() => json(200, { data: clerk }));
    route({
      'GET /api/v1/mcp/splits': () => json(200, { data: [] }),
      'POST /api/v1/agents/3/resume': resumed,
    });
    const user = userEvent.setup();
    render(<AgentRecord agent={{ ...clerk, paused: true, pausedBy: 'Rana Haddad' }} onChanged={() => {}} />);

    expect(screen.getByText('Paused')).toBeInTheDocument();
    expect(screen.getByText(/Rana Haddad paused this agent/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Resume' }));
    await waitFor(() => expect(resumed).toHaveBeenCalled());
  });

  it('names a document the viewer can read and hides one they can’t', async () => {
    route({ 'GET /api/v1/mcp/splits': () => json(200, { data: [] }) });
    const user = userEvent.setup();
    render(<AgentRecord agent={clerk} onChanged={() => {}} />);

    await user.click(screen.getByRole('tab', { name: 'Instructions' }));

    expect(await screen.findByText('How we write to customers')).toBeInTheDocument();
    expect(screen.getByText('A document you can’t open')).toBeInTheDocument();
  });

  it('a person sees a paused agent as paused, with no way to connect it', async () => {
    route({
      'GET /api/v1/me/agents': () =>
        json(200, { data: [{ id: 3, slug: 'a', name: 'Finance clerk', nameAr: null, role: null, roleAr: null, paused: true, actionsToday: 0, proposalsToday: 0, waiting: 0, lastActiveAt: null, onItsOwn: { allowed: 1, askFirst: 0, off: 0 }, mayRead: [], assignment: { id: 9, principalId: 'agent:9', createdAt: '2026-11-01' } }] }),
    });
    render(<MyAgentsPage />);

    expect(await screen.findByText('Paused')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Connect an app' })).toBeDisabled();
  });
});

describe('My agents page', () => {
  const mine = {
    id: 3, slug: 'finance-clerk', name: 'Finance clerk', nameAr: null, role: 'Invoices', roleAr: null,
    paused: false, actionsToday: 3, proposalsToday: 1, waiting: 1, lastActiveAt: null,
    onItsOwn: { allowed: 2, askFirst: 1, off: 0 }, mayRead: [],
  };
  const used = { ...mine, assignment: { id: 9, principalId: 'agent:9', createdAt: '2026-11-01' } };

  it('starts using an agent', async () => {
    route({
      'GET /api/v1/me/agents': () => json(200, { data: [{ ...mine, assignment: null }] }),
      'POST /api/v1/me/agents/3/assignment': () => json(201, { data: {} }),
    });
    render(<MyAgentsPage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Start using' }));

    await waitFor(() => expect(sent('POST /api/v1/me/agents/3/assignment')).toBeDefined());
  });

  it('asks before stopping, because that revokes its credentials', async () => {
    route({
      'GET /api/v1/me/agents': () => json(200, { data: [used] }),
      'DELETE /api/v1/me/agents/3/assignment': () => json(200, {}),
    });
    render(<MyAgentsPage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Stop using' }));
    expect(sent('DELETE /api/v1/me/agents/3/assignment')).toBeUndefined();
    expect(await screen.findByText('Stop using Finance clerk?')).toBeInTheDocument();

    const stops = screen.getAllByRole('button', { name: 'Stop using', hidden: true });
    fireEvent.click(stops[stops.length - 1]);

    await waitFor(() => expect(sent('DELETE /api/v1/me/agents/3/assignment')).toBeDefined());
  });

  it('gives the address first, and makes a key only when asked', async () => {
    route({
      'GET /api/v1/me/agents': () => json(200, { data: [used] }),
      'GET /api/v1/mcp/connect': () => json(200, { endpoint: 'https://app.example/mcp' }),
      'POST /api/v1/me/agents/3/credentials': () => json(201, { data: { token: 'secret-once', jti: 'j' } }),
    });
    render(<MyAgentsPage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Connect an app' }));

    expect(await screen.findByText('https://app.example/mcp/agents/finance-clerk')).toBeInTheDocument();
    expect(sent('POST /api/v1/me/agents/3/credentials')).toBeUndefined();

    fireEvent.click(screen.getByRole('button', { name: 'Make a key' }));

    expect(await screen.findByText('secret-once')).toBeInTheDocument();
  });
});
