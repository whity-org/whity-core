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
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: jest.fn() }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));

import { WaitingForYou } from '@/app/(protected)/dashboard/waiting-for-you';

const json = (body: unknown, status = 200) =>
  Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

function serve(items: unknown[], proposals: unknown[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/me/inbox/sources') return json({ data: [{ key: 'document_routing', label: 'Docs', open_count: items.length }] });
    if (url.startsWith('/api/v1/me/inbox?')) return json({ data: items });
    if (url === '/api/v1/me/agent-proposals') return json({ data: proposals });
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
  it('merges documents and proposals, oldest first', async () => {
    serve([item('2', '2026-11-02 09:00:00')], [proposal(1, '2026-11-01 09:00:00'), proposal(2, '2026-11-03 09:00:00')]);
    render(<WaitingForYou />);

    await screen.findByText('Reorder 1');
    const order = screen.getAllByRole('listitem').map((li) => li.textContent ?? '');
    expect(order[0]).toContain('Reorder 1');
    expect(order[1]).toContain('Document 2');
    expect(order[2]).toContain('Reorder 2');
    expect(screen.getByText('Open Inbox (1)')).toBeInTheDocument();
    expect(screen.getByText('Open Approvals (2)')).toBeInTheDocument();
  });

  it('approves a proposal through the server', async () => {
    serve([], [proposal(1, '2026-11-01 09:00:00')]);
    render(<WaitingForYou />);

    await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/agent-proposals/1/approve', expect.objectContaining({ method: 'POST' })),
    );
  });

  it('collapses to one line when nothing waits', async () => {
    serve([], []);
    render(<WaitingForYou />);
    expect(await screen.findByText('Nothing is waiting for you.')).toBeInTheDocument();
  });

  it('offers Try again when it cannot load', async () => {
    mockApiClient.mockImplementation(() => json({}, 500));
    render(<WaitingForYou />);
    expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
