/**
 * WHIT-809 — the Approvals screen over `/api/me/agent-proposals`.
 *
 * What the tests hold, from the register and owner decision 10:
 *  - one proposal at a time, oldest first, with "1 of N waiting" (7.5b);
 *  - the card names the agent by its credential and shows the permissions it
 *    uses, and says it can't be undone when the server gives no window (7.5a);
 *  - Approve and Decline call the server and move on only after it answers;
 *  - Edit sends the edited arguments with the approval, keeping their types.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { proposalChanges, undoWindowWords, type ProposalWords } from '@/components/agents/proposals';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import ApprovalsPage from '@/app/(protected)/admin/approvals/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function proposal(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    agent: { name: 'Billing assistant' },
    status: 'waiting',
    tool: 'post_api_forms',
    summary: `Create form ${id}`,
    arguments: { name: `Leave request ${id}`, days: 2 },
    diff: null,
    permissions: ['forms:manage'],
    undoWindowSeconds: null,
    result: null,
    decidedAt: null,
    expiresAt: '2099-01-01 00:00:00',
    createdAt: `2026-10-31 0${id}:00:00`,
    ...overrides,
  };
}

let mockActions: unknown[] = [];

function action(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    status: 'done',
    tool: 'post_api_tags',
    summary: `Create tag ${id}`,
    agent: { name: 'Billing assistant', nameAr: null },
    permissions: ['tags:manage'],
    arguments: {},
    proposalId: null,
    undoWindowSeconds: 1800,
    undoableUntil: new Date(Date.now() + 1200_000).toISOString(),
    undoneBy: null,
    undoneAt: null,
    createdAt: '2026-10-31T09:00:00Z',
    ...overrides,
  };
}

function serve(list: unknown[], decide?: (url: string, init: RequestInit) => Promise<unknown>) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/agent-proposals') return jsonResponse(200, { data: list });
    if (url === '/api/v1/me/agent-actions') return jsonResponse(200, { data: mockActions });
    if (decide) return decide(url, init ?? {});
    return jsonResponse(404, { error: 'not found' });
  });
}

beforeEach(() => {
  mockActions = [];
  mockApiClient.mockReset();
  addToast.mockReset();
});

describe('Approvals page', () => {
  it('shows the oldest waiting proposal first, one at a time', async () => {
    serve([proposal(3), proposal(1), proposal(2), proposal(4, { status: 'approved', decidedAt: '2026-10-31 05:00:00' })]);
    render(<ApprovalsPage />);

    expect(await screen.findByText('1 of 3 waiting · oldest first')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Create form 1' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Create form 2' })).not.toBeInTheDocument();
    expect(screen.getAllByText('Billing assistant').length).toBeGreaterThan(0);
    // Permission codes show only with Show technical details.
    expect(screen.queryByText('forms:manage')).not.toBeInTheDocument();
    expect(screen.getByText("Can't be undone")).toBeInTheDocument();
  });

  it('approves through the server, then shows the next one', async () => {
    let list = [proposal(1), proposal(2)];
    serve(list, (url) => {
      expect(url).toBe('/api/v1/me/agent-proposals/1/approve');
      list = [proposal(1, { status: 'approved' }), proposal(2)];
      serve(list);
      return jsonResponse(200, { data: list[0] });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Approved: Create form 1', 'success', 10000, undefined));
    expect(await screen.findByRole('heading', { name: 'Create form 2' })).toBeInTheDocument();
    expect(screen.getByText('1 of 1 waiting · oldest first')).toBeInTheDocument();
  });

  it('asks a Needs verifying proposal to confirm it is them, and sends the code with the approval (AG.1)', async () => {
    const calls: Array<{ url: string; body: unknown }> = [];
    let tries = 0;
    serve([proposal(1, { tier: 3, permissions: ['roles:manage'] })], (url, init) => {
      calls.push({ url, body: init.body ? JSON.parse(String(init.body)) : null });
      if (url === '/api/v1/me/agent-proposals/1/identity-check') {
        return jsonResponse(200, { data: { method: 'email_code', email_hint: 'a•••@examplebakery.test' } });
      }
      tries += 1;
      return tries === 1
        ? jsonResponse(403, { error: 'Confirm it is you first: that code is not correct or has expired.', reason: 'identity_check_failed' })
        : jsonResponse(200, { data: proposal(1, { tier: 3, status: 'approved' }) });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/We sent a code to a•••@examplebakery.test/)).toBeInTheDocument();
    expect(calls.map((c) => c.url)).toEqual(['/api/v1/me/agent-proposals/1/identity-check']);

    const boxes = within(dialog).getAllByRole('textbox');
    fireEvent.change(boxes[boxes.length - 1], { target: { value: '111111' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    expect(await within(dialog).findByText('That code is not right or has expired.')).toBeInTheDocument();

    const again = within(dialog).getAllByRole('textbox');
    fireEvent.change(again[0], { target: { value: '123456' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Approved: Create form 1', 'success', 10000, undefined));
    expect(calls.filter((c) => c.url === '/api/v1/me/agent-proposals/1/approve').map((c) => c.body)).toEqual([
      { code: '111111' },
      { code: '123456' },
    ]);
  });

  it('approves an ordinary proposal with no check', async () => {
    const urls: string[] = [];
    serve([proposal(1, { tier: 1, undoWindowSeconds: 1800 })], (url) => {
      urls.push(url);
      return jsonResponse(200, { data: proposal(1, { tier: 1, status: 'approved' }) });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Approved: Create form 1', 'success', 10000, undefined));
    expect(urls).toEqual(['/api/v1/me/agent-proposals/1/approve']);
  });

  it('keeps the card when the server refuses, and says why', async () => {
    serve([proposal(1)], () => jsonResponse(409, { error: 'Already decided' }));
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Decline' }));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Already decided', 'error', 6000));
    expect(screen.getByRole('heading', { name: 'Create form 1' })).toBeInTheDocument();
  });

  it('sends edited arguments with the approval, keeping a number a number', async () => {
    let sent: unknown = null;
    serve([proposal(1)], (url, init) => {
      sent = JSON.parse(String(init.body));
      return jsonResponse(200, { data: proposal(1, { status: 'approved' }) });
    });
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Days'), { target: { value: '3' } });
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve with changes' }));

    await waitFor(() => expect(sent).toEqual({ arguments: { name: 'Leave request 1', days: 3 } }));
  });

  it('offers Undo on the toast when what it ran can be undone, and undoes through the server', async () => {
    const ran = action(9, { proposalId: 1 });
    const urls: string[] = [];
    serve([proposal(1, { undoWindowSeconds: 1800 })], (url) => {
      urls.push(url);
      if (url.endsWith('/approve')) return jsonResponse(200, { data: proposal(1, { status: 'approved', action: ran }) });
      return jsonResponse(200, { data: { ...ran, status: 'undone' } });
    });
    render(<ApprovalsPage />);

    expect(await screen.findByText('Can be undone for 30 minutes')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalled());
    const [message, type, duration, toastAction] = addToast.mock.calls[0];
    expect([message, type, duration]).toEqual(['Approved: Create form 1', 'success', 10000]);
    expect(toastAction.label).toBe('Undo');

    toastAction.onClick();
    await waitFor(() => expect(urls).toContain('/api/v1/me/agent-actions/9/undo'));
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Undone: Create form 1'.replace('form 1', 'tag 9'), 'info'));
  });

  it('offers no Undo on the toast when it cannot be undone', async () => {
    serve([proposal(1)], () => jsonResponse(200, { data: proposal(1, { status: 'approved', action: action(9, { undoableUntil: null, undoWindowSeconds: null }) }) }));
    render(<ApprovalsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(addToast).toHaveBeenCalled());
    expect(addToast.mock.calls[0][3]).toBeUndefined();
  });

  it('shows why an undo was refused', async () => {
    mockActions = [action(9)];
    serve([], () => jsonResponse(409, { error: 'It has changed since, so undoing it would overwrite a later change' }));
    render(<ApprovalsPage />);
    await userEvent.click(await screen.findByRole('tab', { name: 'All activity' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Undo' }));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('It has changed since, so undoing it would overwrite a later change', 'error', 8000),
    );
  });

  it('shows what agents did by themselves, with how long is left to undo', async () => {
    mockActions = [action(9), action(10, { undoableUntil: new Date(Date.now() - 1000).toISOString() }), action(11, { status: 'undone', undoneBy: { id: 3, name: 'Rana Haddad' } })];
    serve([]);
    render(<ApprovalsPage />);
    await userEvent.click(await screen.findByRole('tab', { name: 'All activity' }));
    expect(await screen.findByText('Create tag 9')).toBeInTheDocument();
    expect(screen.getByText(/20 minutes left to undo/)).toBeInTheDocument();
    expect(screen.getByText(/Can no longer be undone/)).toBeInTheDocument();
    expect(screen.getByText(/Undone by Rana Haddad/)).toBeInTheDocument();
  });

  it('lists what was decided by day, with the outcome, the agent and who decided (TplApprovalsDecided)', async () => {
    const now = new Date();
    const iso = (minutesAgo: number) => new Date(now.getTime() - minutesAgo * 60_000).toISOString();
    const lastWeek = iso(60 * 24 * 6);
    serve([
      proposal(1, { status: 'approved', decidedAt: iso(5) }),
      proposal(2, { status: 'declined', decidedAt: iso(10), agent: { name: 'Purchasing assistant' } }),
      proposal(3, { status: 'expired', expiresAt: lastWeek }),
      proposal(4, { status: 'approved', decidedAt: iso(60 * 24 * 60) }),
    ]);
    render(<ApprovalsPage />);
    await userEvent.click(await screen.findByRole('tab', { name: 'Decided' }));

    const today = await screen.findByRole('region', { name: 'Today' });
    expect(within(today).getByText('Create form 1')).toBeInTheDocument();
    expect(within(today).getByText('Create form 2')).toBeInTheDocument();
    expect(within(screen.getByTestId('decided-3')).getByText('Nobody')).toBeInTheDocument();
    expect(within(screen.getByTestId('decided-3')).getByText(/nobody decided in time/)).toBeInTheDocument();
    expect(within(screen.getByTestId('decided-1')).getByText('You')).toBeInTheDocument();
    // The last 30 days by default.
    expect(screen.queryByText('Create form 4')).not.toBeInTheDocument();

    await userEvent.click(screen.getByTestId('decided-filter-agent'));
    await userEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Purchasing assistant' }));
    await userEvent.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByText('Create form 1')).not.toBeInTheDocument());
    expect(screen.getByText('Create form 2')).toBeInTheDocument();
  });

  it('holds Approve with changes until what was typed is valid', async () => {
    serve([proposal(1)]);
    render(<ApprovalsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/Change what Billing assistant will send/)).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText('Days'), { target: { value: 'two' } });
    expect(within(dialog).getByText('This needs to be a number.')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Approve with changes' })).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText('Days'), { target: { value: '2' } });
    expect(within(dialog).getByRole('button', { name: 'Approve with changes' })).toBeEnabled();
  });

  it('says so when nothing waits', async () => {
    serve([]);
    render(<ApprovalsPage />);
    expect(await screen.findByText('All caught up')).toBeInTheDocument();
  });
});

describe('proposal helpers', () => {
  const words: ProposalWords = {
    unknownAgent: 'An agent', minute: '1 minute', minutes: '{n} minutes', hour: '1 hour', hours: '{n} hours',
    day: '1 day', days: '{n} days', acted: '{summary}', asked: 'Asked: {summary}', approved: 'Approved: {summary}', declined: 'Declined: {summary}',
  };

  it('words the undo window', () => {
    expect(undoWindowWords(null, words)).toBeUndefined();
    expect(undoWindowWords(1800, words)).toBe('30 minutes');
    expect(undoWindowWords(7200, words)).toBe('2 hours');
    expect(undoWindowWords(86400, words)).toBe('1 day');
  });

  it('prefers a previewed diff over the arguments', () => {
    const base = proposal(1) as never;
    expect(proposalChanges(base)).toEqual([
      { label: 'Name', after: 'Leave request 1' },
      { label: 'Days', after: '2' },
    ]);
    expect(
      proposalChanges({ ...(proposal(1) as object), diff: [{ field: 'status', before: 'Unpaid', after: 'Paid' }] } as never),
    ).toEqual([{ label: 'Status', before: 'Unpaid', after: 'Paid' }]);
  });
});
