/**
 * A01 Home: Waiting for you merges every inbox source and the person's
 * agent proposals, oldest first (H.2), and collapses to one line when empty.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7, tenant_id: 1 }, memberships: [] }),
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/capabilities-context', () => ({ useCapabilitiesContext: () => ({ has: () => true }) }));
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));

import { WaitingForYou } from '@/app/(protected)/dashboard/waiting-for-you';
import { PlatformCards } from '@/app/(protected)/dashboard/platform-cards';
import { HomeApps } from '@/app/(protected)/dashboard/home-apps';
import { FinishSettingUp } from '@/app/(protected)/dashboard/finish-setting-up';
import { RecentDocuments } from '@/app/(protected)/dashboard/recent-documents';

const json = (body: unknown, status = 200) =>
  Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

/** The server's merged answer: oldest first, as `GET /api/me/waiting` gives it. */
function serve(rows: unknown[], counts = { inbox: 0, proposals: 0, total: rows.length }, unavailable: string[] = []) {
  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/me/waiting?limit=6') return json({ data: rows, counts, unavailable });
    return json({ data: {} });
  });
}

const item = (id: string, timestamp: string) => ({
  id,
  title: `Document ${id}`,
  subtitle: 'Omar Khalil',
  timestamp,
  status: null,
  resource_type: null,
  resource_id: null,
  meta: { route_id: 1, step_id: 1, document_id: Number(id), open: true, arrived_by: 'forwarded' },
});

const proposal = (id: number, createdAt: string) => ({
  id,
  status: 'waiting',
  summary: `Reorder ${id}`,
  agent: { name: 'Stock assistant', nameAr: null },
  createdAt,
});

beforeEach(() => jest.clearAllMocks());

describe('WaitingForYou', () => {
  it('shows the merged rows in the order the server gives', async () => {
    serve(
      [
        { key: 'proposal:1', kind: 'proposal', at: '2026-11-01 09:00:00', proposal: proposal(1, '2026-11-01 09:00:00') },
        { key: 'document_routing:2', kind: 'inbox', at: '2026-11-02 09:00:00', item: item('2', '2026-11-02 09:00:00') },
        { key: 'proposal:2', kind: 'proposal', at: '2026-11-03 09:00:00', proposal: proposal(2, '2026-11-03 09:00:00') },
      ],
      { inbox: 1, proposals: 2, total: 3 }
    );
    render(<WaitingForYou />);

    await screen.findByText('Reorder 1');
    const order = screen.getAllByRole('listitem').map((li) => li.textContent ?? '');
    expect(order[0]).toContain('Reorder 1');
    expect(order[1]).toContain('Document 2');
    expect(order[2]).toContain('Reorder 2');
    expect(screen.getByText('Open Inbox (1)')).toBeInTheDocument();
    expect(screen.getByText('Open Approvals (2)')).toBeInTheDocument();
  });

  it('says how many more there are from the real total', async () => {
    serve(
      [{ key: 'proposal:1', kind: 'proposal', at: '2026-11-01 09:00:00', proposal: proposal(1, '2026-11-01 09:00:00') }],
      { inbox: 4, proposals: 5, total: 9 }
    );
    render(<WaitingForYou />);

    expect(await screen.findByText('and 8 more')).toBeInTheDocument();
  });

  it('approves a proposal through the server', async () => {
    serve([{ key: 'proposal:1', kind: 'proposal', at: '2026-11-01 09:00:00', proposal: proposal(1, '2026-11-01 09:00:00') }]);
    render(<WaitingForYou />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/agent-proposals/1/approve', expect.objectContaining({ method: 'POST' })),
    );
  });

  it('does not say nothing is waiting when a source failed', async () => {
    serve([], { inbox: 0, proposals: 0, total: 0 }, ['document_routing']);
    render(<WaitingForYou />);

    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
    expect(screen.queryByText('Nothing is waiting for you.')).not.toBeInTheDocument();
  });

  it('keeps what it has and says the list is incomplete', async () => {
    serve(
      [{ key: 'proposal:1', kind: 'proposal', at: '2026-11-01 09:00:00', proposal: proposal(1, '2026-11-01 09:00:00') }],
      { inbox: 0, proposals: 1, total: 1 },
      ['document_routing']
    );
    render(<WaitingForYou />);

    expect(await screen.findByText('Reorder 1')).toBeInTheDocument();
    expect(screen.getByText('Some of what is waiting could not be loaded.')).toBeInTheDocument();
  });

  it('collapses to one line when nothing waits', async () => {
    serve([]);
    render(<WaitingForYou />);
    expect(await screen.findByText('Nothing is waiting for you.')).toBeInTheDocument();
  });

  it('offers Try again when it cannot load', async () => {
    mockApiClient.mockImplementation(() => json({}, 500));
    render(<WaitingForYou />);
    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});

describe('PlatformCards', () => {
  it('reads the totals from the stats envelope the endpoint answers with', async () => {
    mockApiClient.mockImplementation(() =>
      json({ status: 'success', stats: { totals: { users: 12, tenants: 3, roles: 5, permissions: 60 } } })
    );
    render(<PlatformCards />);
    expect(await screen.findByText('12')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.queryByText('The platform numbers could not be loaded.')).not.toBeInTheDocument();
  });
});

const card = (over: Record<string, unknown> = {}) => ({
  key: 'Inventory',
  label: 'Stock',
  labelAr: 'المخزون',
  caption: null,
  captionAr: null,
  icon: 'package',
  href: '/admin/x/inventory',
  source: '/api/v1/inventory/home-card',
  value: null,
  hidden: false,
  ...over,
});

describe('HomeApps', () => {
  it('shows nothing at all with no cards', async () => {
    mockApiClient.mockImplementation(() => json({ data: [] }));
    const { container } = render(<HomeApps />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it("reads a card's number and lines from the app's own route", async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards'
        ? json({ data: [card()] })
        : json({ data: { value: '3', caption: 'items below reorder level', lines: [{ label: 'Flour', value: '4 / 6' }] } })
    );
    render(<HomeApps />);

    expect(await screen.findByText('3')).toBeInTheDocument();
    expect(screen.getByText('items below reorder level')).toBeInTheDocument();
    expect(screen.getByText('Flour')).toBeInTheDocument();
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/inventory/home-card');
  });

  it('leaves a card out when the app refuses this person', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({}, 403)
    );
    render(<HomeApps />);

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/inventory/home-card'));
    await waitFor(() => expect(screen.queryByRole('link', { name: 'Stock' })).not.toBeInTheDocument());
  });

  it('keeps a broken card to itself, with Try again', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({}, 500)
    );
    render(<HomeApps />);

    expect(await screen.findByText('This card could not be loaded.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('lists a hidden card at the foot and Show brings it back', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card({ hidden: true })] }) : json({ data: { key: 'Inventory', hidden: false } })
    );
    render(<HomeApps />);

    await userEvent.click(await screen.findByRole('button', { name: 'Show' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/me/home-cards/Inventory/hidden',
        expect.objectContaining({ method: 'PUT', body: JSON.stringify({ hidden: false }) })
      )
    );
    expect(mockApiClient).not.toHaveBeenCalledWith('/api/v1/inventory/home-card');
  });
});

describe('FinishSettingUp', () => {
  it('shows what is left and why it matters', async () => {
    mockApiClient.mockImplementation(() =>
      json({ data: { steps: [{ key: 'team', done: true }, { key: 'tax', done: false }], done: 1, total: 2 } })
    );
    render(<FinishSettingUp />);

    expect(await screen.findByText('1 of 2 done')).toBeInTheDocument();
    expect(screen.getByText('Invoices can’t be issued without it')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Add' })).toHaveAttribute('href', '/admin/settings');
  });

  it('disappears when every step is done', async () => {
    mockApiClient.mockImplementation(() => json({ data: { steps: [{ key: 'team', done: true }], done: 1, total: 1 } }));
    const { container } = render(<FinishSettingUp />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });
});

describe('RecentDocuments', () => {
  it('shows each document with its status', async () => {
    mockApiClient.mockImplementation(() =>
      json({
        data: [
          { kind: 'document', id: 1, title: 'Purchase order', status: 'with', waitingWith: 'Rana Haddad', at: '2026-11-02 09:00:00' },
          { kind: 'draft', id: 2, title: 'Stock count', status: 'draft', waitingWith: null, at: '2026-11-01 09:00:00' },
        ],
      })
    );
    render(<RecentDocuments />);

    expect(await screen.findByText('With Rana Haddad')).toBeInTheDocument();
    expect(screen.getByText('Draft')).toBeInTheDocument();
  });

  it('leaves the card out with nothing to show', async () => {
    mockApiClient.mockImplementation(() => json({ data: [] }));
    const { container } = render(<RecentDocuments />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });
});
