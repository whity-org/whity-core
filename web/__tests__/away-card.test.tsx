/**
 * OR.1 — "I'm away": the person hands over from their own account, and can
 * come back early.
 */
import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockGet = jest.fn();
const mockDelete = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...a: unknown[]) => mockGet(...a), DELETE: (...a: unknown[]) => mockDelete(...a) },
}));
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ user: { id: 1 } }) }));
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

function result(status: number, body: unknown) {
  return Promise.resolve({ data: body, error: undefined, response: new Response(null, { status }) });
}

beforeEach(() => {
  mockGet.mockReset();
  mockDelete.mockReset();
  mockAddToast.mockReset();
});

it('shows who decides while away, and comes back early', async () => {
  mockGet.mockImplementation(() => result(200, { data: PERIOD }));
  mockDelete.mockImplementation(() => result(200, { data: null }));
  const onChanged = jest.fn();
  render(<AwayCard people={[{ id: 1, name: 'Amro Saleh' }, { id: 2, name: 'Emad Nasser' }]} onChanged={onChanged} />);

  expect(await screen.findByText('Emad Nasser')).toBeInTheDocument();
  expect(screen.getByText('Starting')).toBeInTheDocument();
  expect(screen.getByText('Emad is deciding for me.')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'I’m back' }));
  await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/v1/me/away'));
  await waitFor(() => expect(onChanged).toHaveBeenCalled());
  expect(await screen.findByTestId('away-card')).toBeInTheDocument();
});

it('needs someone to hand over to', async () => {
  mockGet.mockImplementation(() => result(200, { data: null }));
  render(<AwayCard people={[{ id: 1, name: 'Amro Saleh' }, { id: 2, name: 'Emad Nasser' }]} />);

  const handOver = await screen.findByRole('button', { name: /Hand over from/ });
  expect(handOver).toBeDisabled();
  expect(screen.getByRole('switch', { name: 'Approvals in flows' })).toBeChecked();
  expect(screen.getByRole('switch', { name: 'Documents to sign' })).not.toBeChecked();
  expect(screen.getByRole('switch', { name: 'Approvals in plugins' })).not.toBeChecked();
});
