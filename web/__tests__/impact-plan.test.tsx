/**
 * WHIT-1042 (TplImpactPlan): a Whity Builder publish or roll back on the
 * Approvals card.
 *
 *  - each change is a row with its kind in words, and the records it touches;
 *  - tests that passed say so; failed tests turn Approve off and offer to ask
 *    the agent to fix them, which declines with the failing tests named;
 *  - a destructive plan needs the tick before Approve turns on, and sends it;
 *  - the manifest is never shown as text, and it can't be edited here.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { impactPlanBlocks, impactPlanOf, proposalChanges, recordsLost } from '@/components/agents/proposals';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ user: { id: 7 } }) }));
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import ApprovalsPage from '@/app/(protected)/admin/approvals/page';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

const PUBLISH = 'post_api_workspace_plugins';

function plan(overrides: Record<string, unknown> = {}) {
  return {
    kind: 'publish',
    label: { en: 'Bakery orders', ar: 'طلبات المخبز' },
    plugin: 'BakeryOrders',
    from: '1.3.0',
    to: '1.4.0',
    level: 'data',
    changes: [
      { effect: 'safe', change: 'field_added', path: 'records.order.fields.pickup_time', message: 'New field "pickup_time" on "order"', messageAr: 'حقل جديد' },
      { effect: 'data', change: 'field_required', path: 'records.order.fields.pickup_time', records: 312, message: '312 orders need a pickup time', messageAr: '312 طلباً' },
    ],
    tests: { passed: 24, failed: 0, failures: [], ranAt: '2026-11-13T10:20:00Z' },
    ...overrides,
  };
}

function proposal(id: number, diff: unknown, overrides: Record<string, unknown> = {}) {
  return {
    id,
    agent: { name: 'Whity Builder', nameAr: null },
    status: 'waiting',
    tool: PUBLISH,
    tier: 2,
    summary: 'Publish a workspace plugin',
    arguments: { manifest: { plugin: { name: 'BakeryOrders' } }, confirmDestructive: false },
    diff,
    permissions: ['plugins:publish'],
    undoWindowSeconds: null,
    result: null,
    decidedAt: null,
    expiresAt: '2099-01-01 00:00:00',
    createdAt: '2026-11-13 10:20:00',
    action: null,
    declineReason: null,
    ...overrides,
  };
}

function serve(list: unknown[], decide?: (url: string, init: RequestInit) => Promise<unknown>) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/agent-proposals') return json(200, { data: list });
    if (url === '/api/v1/me/agent-actions') return json(200, { data: [] });
    if (decide) return decide(url, init ?? {});
    return json(404, { error: 'not found' });
  });
}

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
});

describe('impact plan helpers', () => {
  it('reads a plan only from the publish and activate tools', () => {
    expect(impactPlanOf(proposal(1, plan()) as never)?.to).toBe('1.4.0');
    expect(impactPlanOf(proposal(1, plan(), { tool: 'post_api_forms' }) as never)).toBeNull();
    expect(impactPlanOf(proposal(1, [{ label: 'x', after: 'y' }]) as never)).toBeNull();
    expect(proposalChanges(proposal(1, plan()) as never)).toEqual([]);
  });

  it('blocks on failed tests, and on a destructive plan until ticked', () => {
    const destructive = impactPlanOf(proposal(1, plan({ level: 'destructive', changes: [
      { effect: 'destructive', change: 'field_removed', path: 'p', records: 88, message: 'm', messageAr: 'm' },
    ] })) as never)!;
    expect(recordsLost(destructive)).toBe(88);
    expect(impactPlanBlocks(destructive, false)).toBe(true);
    expect(impactPlanBlocks(destructive, true)).toBe(false);
    const failed = impactPlanOf(proposal(1, plan({ tests: { passed: 22, failed: 2, failures: [{ en: 'A', ar: 'أ' }], ranAt: null } })) as never)!;
    expect(impactPlanBlocks(failed, true)).toBe(true);
  });
});

describe('An impact plan on the Approvals card', () => {
  it('shows each change with its kind and records, and the tests that passed', async () => {
    serve([proposal(1, plan())]);
    render(<ApprovalsPage />);
    expect(await screen.findByText('Publish “Bakery orders” version 1.4.0')).toBeInTheDocument();
    expect(screen.getByText('Safe')).toBeInTheDocument();
    expect(screen.getByText('Changes data')).toBeInTheDocument();
    expect(screen.getByText('312 records')).toBeInTheDocument();
    expect(screen.getByText('24 tests passed')).toBeInTheDocument();
    expect(screen.getByText('Can be rolled back to version 1.3.0')).toBeInTheDocument();
    expect(screen.queryByText(/BakeryOrders/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve' })).toBeEnabled();
  });

  it('turns Approve off when tests failed, and asks the agent to fix them by declining with the names', async () => {
    const failing = plan({ tests: { passed: 22, failed: 2, failures: [{ en: 'Orders with no notes keep an empty pickup time', ar: 'x' }, { en: 'Second', ar: 'y' }], ranAt: null } });
    let declined: unknown = null;
    serve([proposal(1, failing)], (url, init) => {
      if (url === '/api/v1/me/agent-proposals/1/decline') {
        declined = JSON.parse(String(init.body));
        return json(200, { data: proposal(1, failing, { status: 'declined' }) });
      }
      return json(404, {});
    });
    render(<ApprovalsPage />);
    expect(await screen.findByText('2 of 24 tests failed')).toBeInTheDocument();
    expect(screen.getByText('“Orders with no notes keep an empty pickup time” and 1 more.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Ask Whity Builder to fix it' }));
    await waitFor(() =>
      expect(declined).toEqual({ reason: 'Fix the failing tests: "Orders with no notes keep an empty pickup time", "Second".' }),
    );
  });

  it('needs the tick before a destructive plan can be approved, and sends it', async () => {
    const destructive = plan({
      kind: 'rollback',
      to: '1.2.0',
      level: 'destructive',
      changes: [{ effect: 'destructive', change: 'field_removed', path: 'p', records: 88, message: 'Field "allergens" goes', messageAr: 'م' }],
    });
    let approved: unknown = null;
    serve([proposal(1, destructive)], (url, init) => {
      if (url === '/api/v1/me/agent-proposals/1/approve') {
        approved = JSON.parse(String(init.body));
        return json(200, { data: proposal(1, destructive, { status: 'approved' }) });
      }
      return json(404, {});
    });
    render(<ApprovalsPage />);
    expect(await screen.findByText('Roll back “Bakery orders” to version 1.2.0')).toBeInTheDocument();
    expect(screen.getByText('Deletes data')).toBeInTheDocument();
    const approve = screen.getByRole('button', { name: 'Approve' });
    expect(approve).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox'));
    expect(approve).toBeEnabled();
    fireEvent.click(approve);
    await waitFor(() => expect(approved).toEqual({ acknowledgedDestructive: true }));
  });
});
