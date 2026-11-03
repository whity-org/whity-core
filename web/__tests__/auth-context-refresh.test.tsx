/**
 * Opening the app with an expired access token goes through the shared refresh, so
 * it can't race the screen's own requests, or itself under React's strict mode.
 */

import React, { StrictMode } from 'react';
import { render, waitFor } from '@testing-library/react';
import { AuthProvider } from '@/lib/auth-context';
import { refreshSession } from '@/lib/session-refresh';

jest.mock('@/lib/session-refresh', () => ({ refreshSession: jest.fn() }));

const refresh = refreshSession as jest.MockedFunction<typeof refreshSession>;

const reply = (status: number, body: unknown = {}) => ({ ok: status < 400, status, json: async () => body });

describe('AuthProvider at mount', () => {
  const realFetch = global.fetch;

  afterEach(() => {
    global.fetch = realFetch;
    refresh.mockReset();
  });

  it('renews through refreshSession, and never posts the refresh itself', async () => {
    let renewed = false;
    const fetchMock = jest.fn(async () => reply(renewed ? 200 : 401, { user: { id: 1 } }));
    global.fetch = fetchMock as unknown as typeof fetch;
    refresh.mockImplementation(async () => {
      renewed = true;

      return 'renewed';
    });

    render(
      <StrictMode>
        <AuthProvider>
          <span />
        </AuthProvider>
      </StrictMode>
    );

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThan(1));
    const urls = fetchMock.mock.calls.map((call) => String((call as unknown[])[0]));
    expect(urls.some((url) => url.includes('/auth/refresh'))).toBe(false);
  });

  it('does not retry when the refresh was refused', async () => {
    const fetchMock = jest.fn(async () => reply(401));
    global.fetch = fetchMock as unknown as typeof fetch;
    refresh.mockResolvedValue('refused');

    render(
      <AuthProvider>
        <span />
      </AuthProvider>
    );

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});
