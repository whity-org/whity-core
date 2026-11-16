/**
 * Settings › Chat (design 3.5, UC.4, UC.12, WHIT-1097): the workspace's bots
 * and who it blocked.
 *
 * What would go wrong unnoticed: a bot switch that doesn't save its rule, and
 * Unblock that leaves the row (so nobody knows it worked).
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@testing-library/react';

const mockApiFetch = jest.fn();
jest.mock('@/lib/api/client', () => ({ apiFetch: (...a: unknown[]) => mockApiFetch(...a) }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_k: string, f: string, v?: Record<string, string>) =>
      Object.entries(v ?? {}).reduce((s, [k, x]) => s.replace(`{${k}}`, x), f),
}));
jest.mock('@whity/features/datetime', () => ({ useDateDisplay: () => ({ date: (v: string | null) => v }) }));

import { BlockedSendersCard, ChatBotsCard } from '@/components/chat/chat-settings';

function respond(body: unknown) {
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: body }) });
}

const bots = [
  { kind: 'away', enabled: false, settings: { text: 'Closed', days: [1], from: '09:00', to: '18:00', timezone: 'UTC' } },
  { kind: 'welcome', enabled: true, settings: { text: 'Hello' } },
  { kind: 'handover', enabled: false, settings: { people: [] } },
];

beforeEach(() => {
  mockApiFetch.mockReset();
  addToast.mockReset();
});

test('turning a bot on saves it with its rule', async () => {
  mockApiFetch.mockImplementation((path: string, init?: RequestInit) => {
    if (path === '/api/v1/chat/bots') return respond(bots);
    if (path.startsWith('/api/v1/chat/directory')) return respond({ people: [], agents: [] });
    if (path === '/api/v1/chat/bots/away') return respond({ ...bots[0], ...JSON.parse(String(init?.body)) });
    return respond(null);
  });
  render(<ChatBotsCard />);

  await userEvent.click(await screen.findByRole('switch', { name: 'Turn Away reply on' }));
  await waitFor(() =>
    expect(mockApiFetch).toHaveBeenCalledWith('/api/v1/chat/bots/away', expect.objectContaining({ method: 'PUT', body: JSON.stringify({ enabled: true, settings: bots[0].settings }) }))
  );
});

test('Unblock removes the row and says what happens next', async () => {
  mockApiFetch.mockImplementation((path: string) =>
    path === '/api/v1/chat/blocked'
      ? respond([{ id: 3, channel: 'email', address: 'spam@example.test', name: null, reported_spam: false, blocked_by: 'Amro Saleh', created_at: '2026-11-14' }])
      : respond({ ok: true })
  );
  render(<BlockedSendersCard />);

  await userEvent.click(await screen.findByRole('button', { name: 'Unblock' }));
  await waitFor(() => expect(screen.queryByText('spam@example.test')).not.toBeInTheDocument());
  expect(mockApiFetch).toHaveBeenCalledWith('/api/v1/chat/blocked/3', { method: 'DELETE' });
  expect(addToast).toHaveBeenCalledWith('Unblocked. Their next message reaches the team.', 'success');
});
