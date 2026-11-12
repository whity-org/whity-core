/**
 * OR.1 — "I'm away": the person hands over from their own account, and can
 * come back early.
 */
import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ apiClient: mockApiClient, user: { id: 1 } }) }));
const mockAddToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: mockAddToast }) }));

import { AwayCard } from '@/app/(protected)/admin/delegations/away-card';

const PERIOD = {
  id: 5,
  profile: { id: 1, name: 'Amro Saleh' },
  delegate: { id: 2, name: 'Emad Nasser' },
  startsOn: '2026-11-15',
  backOn: '2026-11-20',
  covers: ['flows', 'proposals'],
  message: 'Emad is deciding for me.',
  status: 'starting',
  endedAt: null,
  createdAt: '2026-11-12 08:00:00',
};

function json(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockAddToast.mockReset();
});

it('shows who decides while away, and comes back early', async () => {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (init?.method === 'DELETE') return json(200, { data: null });
    return json(200, { data: PERIOD });
  });
  const onChanged = jest.fn();
  render(<AwayCard people={[{ id: 1, name: 'Amro Saleh' }, { id: 2, name: 'Emad Nasser' }]} onChanged={onChanged} />);

  expect(await screen.findByText('Emad Nasser')).toBeInTheDocument();
  expect(screen.getByText('Starting')).toBeInTheDocument();
  expect(screen.getByText('Emad is deciding for me.')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'I’m back' }));
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/me/away', { method: 'DELETE' }));
  await waitFor(() => expect(onChanged).toHaveBeenCalled());
  expect(await screen.findByTestId('away-card')).toBeInTheDocument();
});

it('needs someone to hand over to', async () => {
  mockApiClient.mockImplementation(() => json(200, { data: null }));
  render(<AwayCard people={[{ id: 1, name: 'Amro Saleh' }, { id: 2, name: 'Emad Nasser' }]} />);

  const handOver = await screen.findByRole('button', { name: /Hand over from/ });
  expect(handOver).toBeDisabled();
  expect(screen.getByRole('switch', { name: 'Approvals in flows' })).toBeChecked();
  expect(screen.getByRole('switch', { name: 'Documents to sign' })).not.toBeChecked();
});
