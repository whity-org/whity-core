/**
 * AQ.1 — the Approvals list: only Ordinary (tier-1) proposals can be ticked and
 * approved together; the others show Open and go one by one.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { proposalAmount, waitedWords } from '@/app/(protected)/admin/approvals/list-helpers';
import type { AgentProposal } from '@/components/agents/proposals';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import ApprovalsPage from '@/app/(protected)/admin/approvals/page';

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function proposal(id: number, tier: 1 | 2 | 3, overrides: Record<string, unknown> = {}) {
  return {
    id,
    tier,
    agent: { name: 'Billing assistant', nameAr: null },
    status: 'waiting',
    tool: 'post_api_reminders',
    summary: `Payment reminder ${id}`,
    arguments: { amount: 312, currency: 'JOD' },
    diff: null,
    permissions: ['reminders:send'],
    undoWindowSeconds: tier === 1 ? 600 : null,
    result: null,
    decidedAt: null,
    expiresAt: '2099-01-01 00:00:00',
    createdAt: '2026-11-10T08:00:00Z',
    action: null,
    ...overrides,
  };
}

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
});

it('says each kind and lets only ordinary items be ticked', async () => {
  const list = [proposal(1, 1), proposal(2, 2), proposal(3, 3)];
  const sent: unknown[] = [];
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/me/agent-proposals') return jsonResponse(200, { data: list });
    if (url === '/api/v1/me/agent-actions') return jsonResponse(200, { data: [] });
    if (url === '/api/v1/me/agent-proposals/bulk-approve') {
      sent.push(JSON.parse(String(init?.body)));
      return jsonResponse(200, { data: { approved: [{ ...list[0], status: 'approved' }], refused: [] } });
    }
    return jsonResponse(404, {});
  });

  render(<ApprovalsPage />);
  fireEvent.click(await screen.findByRole('button', { name: 'List' }));

  expect(await screen.findByText('Ordinary')).toBeInTheDocument();
  expect(screen.getByText('Check first')).toBeInTheDocument();
  expect(screen.getByText('Needs verifying')).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: 'Open' })).toHaveLength(2);

  const boxes = screen.getAllByRole('checkbox', { name: 'Select' });
  expect(boxes.map((box) => box.hasAttribute('disabled') || box.getAttribute('aria-disabled') === 'true')).toEqual([false, true, true]);

  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all ordinary items' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Approve 1' }));

  await waitFor(() => expect(sent).toEqual([{ ids: [1] }]));
});

it('helpers: amount in normal units, and how long it waited', () => {
  expect(proposalAmount({ arguments: { amount: 312, currency: 'JOD' } } as unknown as AgentProposal)).toBe('JOD 312.000');
  expect(proposalAmount({ arguments: { name: 'x' } } as unknown as AgentProposal)).toBeNull();
  const words = { minutes: '{n} min', hours: '{n} h', days: '{n} d' };
  const now = Date.parse('2026-11-12T10:00:00Z');
  expect(waitedWords('2026-11-12T09:40:00Z', now, words)).toBe('20 min');
  expect(waitedWords('2026-11-12T05:00:00Z', now, words)).toBe('5 h');
  expect(waitedWords('2026-11-10T09:00:00Z', now, words)).toBe('2 d');
});
