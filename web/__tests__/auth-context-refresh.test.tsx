/**
 * Opening the app with an expired access token goes through the shared refresh, so
 * it can't race the screen's own requests, or itself under React's strict mode.
 *
 * The refresh lives in the typed client now (it renews once, through
 * `refreshSession`, and replays the request), so the provider's part is to ask
 * `/api/v1/me` through that client and never refresh on its own.
 */

import React, { StrictMode } from 'react';
import { render, waitFor } from '@testing-library/react';
import { AuthProvider } from '@/lib/auth-context';
import { refreshSession } from '@/lib/session-refresh';

jest.mock('@/lib/session-refresh', () => ({ refreshSession: jest.fn() }));

const mockGet = jest.fn();
const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  SESSION_EXPIRED_EVENT: 'whity:session-expired',
  api: {
    GET: (...a: unknown[]) => mockGet(...a),
    POST: (...a: unknown[]) => mockPost(...a),
  },
}));

const refresh = refreshSession as jest.MockedFunction<typeof refreshSession>;

const reply = (status: number, body?: unknown) => ({
  data: status < 400 ? body : undefined,
  error: status < 400 ? undefined : body,
  response: { ok: status < 400, status },
});

describe('AuthProvider at mount', () => {
  afterEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    refresh.mockReset();
  });

  it('renews through refreshSession, and never posts the refresh itself', async () => {
    // The client renews and replays: the provider sees the replayed answer.
    mockGet.mockResolvedValue(reply(200, { user: { id: 1 } }));

    render(
      <StrictMode>
        <AuthProvider>
          <span />
        </AuthProvider>
      </StrictMode>
    );

    await waitFor(() => expect(mockGet).toHaveBeenCalled());
    expect(mockGet.mock.calls.every(([path]) => path === '/api/v1/me')).toBe(true);
    expect(refresh).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('does not retry when the refresh was refused', async () => {
    mockGet.mockResolvedValue(reply(401));

    render(
      <AuthProvider>
        <span />
      </AuthProvider>
    );

    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1));
    expect(refresh).not.toHaveBeenCalled();
    expect(mockGet).toHaveBeenCalledTimes(1);
  });
});
