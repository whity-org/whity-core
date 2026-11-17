/**
 * A01 Home: Waiting for you merges every inbox source and the person's
 * agent proposals, oldest first (H.2), and collapses to one line when empty.
 * From your apps and Finish setting up also carry what apps add (SDK 1.115, H.5).
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7, tenant_id: 1 }, memberships: [] }),
}));
// The typed client is adapted onto a URL router: the template, its path params
// and its query become the concrete URL the tests route by.
async function viaRouter(
  method: string,
  template: string,
  options?: { params?: { path?: Record<string, unknown>; query?: Record<string, unknown> }; body?: unknown }
) {
  let url = template.replace(/\{(\w+)\}/g, (_m, k: string) => String(options?.params?.path?.[k]));
  const query = Object.entries(options?.params?.query ?? {}).map(([k, v]) => `${k}=${v}`).join('&');
  if (query !== '') url += `?${query}`;
  const res = (await (method === 'GET' ? mockApiClient(url) : mockApiClient(url, { method, body: JSON.stringify(options?.body) }))) as { ok: boolean; status: number; json: () => Promise<unknown> };
  const body = await res.json();
  return {
    data: res.ok ? body : undefined,
    error: res.ok ? undefined : body,
    response: new Response(null, { status: res.status ?? (res.ok ? 200 : 500) }),
  };
}
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...a: [string, never]) => viaRouter('GET', ...a),
    POST: (...a: [string, never]) => viaRouter('POST', ...a),
    PUT: (...a: [string, never]) => viaRouter('PUT', ...a),
  },
  apiFetch: (...a: unknown[]) => mockApiClient(...a),
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/capabilities-context', () => ({ useCapabilitiesContext: () => ({ has: () => true }) }));
const mockPush = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: mockPush }) }));
jest.mock('@/components/plugin/blocks/block-renderer', () => ({
  BlockRenderer: ({ blocks }: { blocks: Array<{ type: string; value?: string }> }) => {
    if (blocks.some((b) => b.type === 'boom')) throw new Error('bad block');
    return <div data-testid="blocks">{blocks.map((b) => b.value ?? b.type).join(',')}</div>;
  },
}));
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

  it('opens a Needs verifying proposal on Approvals instead of approving it here (AG.1)', async () => {
    serve([{ key: 'proposal:1', kind: 'proposal', at: '2026-11-01 09:00:00', proposal: { ...proposal(1, '2026-11-01 09:00:00'), tier: 3 } }]);
    render(<WaitingForYou />);

    await userEvent.click(await screen.findByRole('button', { name: 'Open' }));
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(mockPush).toHaveBeenCalledWith('/admin/approvals');
    expect(mockApiClient).not.toHaveBeenCalledWith('/api/v1/me/agent-proposals/1/approve', expect.anything());
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

const calledUrls = () => mockApiClient.mock.calls.map((call) => call[0] as string);

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

const NO_APPS = { setup: [], widgets: [] };

const widget = (over: Record<string, unknown> = {}) => ({
  key: 'Salons:today',
  plugin: 'Salons',
  kind: 'list' as const,
  title: "Today's bookings",
  description: null,
  icon: 'calendar',
  href: null,
  source: '/api/v1/salons/today',
  sizes: ['medium' as const, 'large' as const],
  size: 'medium' as const,
  hidden: false,
  position: null,
  blocks: [{ type: 'dataList', source: '/api/v1/salons/today', itemField: 'line' }],
  ...over,
}) as never;

const SALON_SETUP = {
  plugin: 'Salons',
  status: '/api/v1/salons/setup-points',
  points: [
    { id: 'chairs', label: 'Add your chairs', why: 'Bookings need a chair', action: null, href: '/admin/setup/Salons?step=chairs', icon: null },
    { id: 'hours', label: 'Set your hours', why: null, action: 'Set', href: '/admin/setup/Salons?step=hours', icon: null },
  ],
};

describe('HomeApps', () => {
  it('shows nothing at all with no cards', async () => {
    mockApiClient.mockImplementation(() => json({ data: [] }));
    const { container } = render(<HomeApps dashboard={NO_APPS} onDashboardChange={jest.fn()} />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it("reads a card's number and lines from the app's own route", async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards'
        ? json({ data: [card()] })
        : json({ data: { value: '3', caption: 'items below reorder level', lines: [{ label: 'Flour', value: '4 / 6' }] } })
    );
    render(<HomeApps dashboard={NO_APPS} onDashboardChange={jest.fn()} />);

    expect(await screen.findByText('3')).toBeInTheDocument();
    expect(screen.getByText('items below reorder level')).toBeInTheDocument();
    expect(screen.getByText('Flour')).toBeInTheDocument();
    expect(calledUrls()).toContain('/api/v1/inventory/home-card');
  });

  it('leaves a card out when the app refuses this person', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({}, 403)
    );
    render(<HomeApps dashboard={NO_APPS} onDashboardChange={jest.fn()} />);

    await waitFor(() => expect(calledUrls()).toContain('/api/v1/inventory/home-card'));
    await waitFor(() => expect(screen.queryByRole('link', { name: 'Stock' })).not.toBeInTheDocument());
  });

  it('keeps a broken card to itself, with Try again', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({}, 500)
    );
    render(<HomeApps dashboard={NO_APPS} onDashboardChange={jest.fn()} />);

    expect(await screen.findByText('This card could not be loaded.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('lists a hidden card at the foot and Show brings it back', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card({ hidden: true })] }) : json({ data: { key: 'Inventory', hidden: false } })
    );
    render(<HomeApps dashboard={NO_APPS} onDashboardChange={jest.fn()} />);

    await userEvent.click(await screen.findByRole('button', { name: 'Show' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/me/dashboard/layout',
        expect.objectContaining({ method: 'PUT', body: JSON.stringify({ items: [{ key: 'Inventory', hidden: false, size: null }] }) })
      )
    );
  });

  it("draws an app's widget from its blocks after the card, in the apps' order", async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({ data: [] })
    );
    render(<HomeApps dashboard={{ setup: [], widgets: [widget({ plugin: 'Inventory', key: 'Inventory:low' })] }} onDashboardChange={jest.fn()} />);

    expect(await screen.findByTestId('blocks')).toHaveTextContent('dataList');
    const headings = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(headings).toEqual(['Stock', "Today's bookings"]);
  });

  it('shows one calm line when a widget cannot load, and leaves it out when refused', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [] }) : url === '/api/v1/salons/today' ? json({}, 500) : json({}, 403)
    );
    render(
      <HomeApps
        dashboard={{ setup: [], widgets: [widget(), widget({ key: 'Salons:money', title: 'Takings', source: '/api/v1/salons/money' })] }}
        onDashboardChange={jest.fn()}
      />
    );

    expect(await screen.findByText('Nothing to show here right now')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText('Takings')).not.toBeInTheDocument());
  });

  it('keeps a widget that breaks while drawing to itself', async () => {
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    mockApiClient.mockImplementation((url: string) => (url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({ data: [] })));
    render(<HomeApps dashboard={{ setup: [], widgets: [widget({ blocks: [{ type: 'boom' }] })] }} onDashboardChange={jest.fn()} />);

    expect(await screen.findByText('Nothing to show here right now')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Stock' })).toBeInTheDocument();
  });

  it('Customise moves a widget earlier and saves the whole order', async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/home-cards' ? json({ data: [card()] }) : json({ data: [] })
    );
    const changed = jest.fn();
    render(<HomeApps dashboard={{ setup: [], widgets: [widget()] }} onDashboardChange={changed} />);

    await userEvent.click(await screen.findByRole('button', { name: 'Customise' }));
    await userEvent.click(screen.getByRole('button', { name: "Move Today's bookings earlier" }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/me/dashboard/layout',
        expect.objectContaining({
          method: 'PUT',
          body: JSON.stringify({ items: [{ key: 'Salons:today', hidden: false, size: 'medium' }, { key: 'Inventory', hidden: false, size: null }] }),
        })
      )
    );
    expect(changed).toHaveBeenCalled();

    await userEvent.click(screen.getByRole('button', { name: 'Large' }));
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/dashboard/layout', expect.objectContaining({ body: expect.stringContaining('"size":"large"') })));
  });
});

describe('FinishSettingUp', () => {
  it('shows what is left and why it matters', async () => {
    mockApiClient.mockImplementation(() =>
      json({ data: { steps: [{ key: 'team', done: true }, { key: 'tax', done: false }], done: 1, total: 2 } })
    );
    render(<FinishSettingUp canSetUp apps={[]} />);

    expect(await screen.findByText('1 of 2 done')).toBeInTheDocument();
    expect(screen.getByText('Invoices can’t be issued without it')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Add' })).toHaveAttribute('href', '/admin/settings');
  });

  it('disappears when every step is done', async () => {
    mockApiClient.mockImplementation(() => json({ data: { steps: [{ key: 'team', done: true }], done: 1, total: 1 } }));
    const { container } = render(<FinishSettingUp canSetUp apps={[]} />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it("adds an app's points after core's, done by the app's own route", async () => {
    mockApiClient.mockImplementation((url: string) =>
      url === '/api/v1/me/setup-steps'
        ? json({ data: { steps: [{ key: 'team', done: true }], done: 1, total: 1 } })
        : json({ data: { points: { chairs: false, hours: true } } })
    );
    render(<FinishSettingUp canSetUp apps={[SALON_SETUP]} />);

    expect(await screen.findByText('2 of 3 done')).toBeInTheDocument();
    expect(screen.getByText('Bookings need a chair')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Set up' })).toHaveAttribute('href', '/admin/setup/Salons?step=chairs');
    expect(calledUrls()).toContain('/api/v1/salons/setup-points');
  });

  it("leaves out an app whose route fails, and shows only app points to people who can't change settings", async () => {
    mockApiClient.mockImplementation(() => json({}, 500));
    const { container } = render(<FinishSettingUp canSetUp={false} apps={[SALON_SETUP]} />);

    await waitFor(() => expect(calledUrls()).toContain('/api/v1/salons/setup-points'));
    expect(calledUrls()).not.toContain('/api/v1/me/setup-steps');
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
