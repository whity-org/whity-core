import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { AuthProvider, useAuth } from '@/lib/auth-context';

// The typed client, over a fetch-shaped fake: each test queues the old-style
// `{ ok, status, json }` answers on `mockFetch`, and this maps one to the
// `{ data, error, response }` the client resolves to.
const mockFetch = jest.fn();
async function mockTyped(method: string, path: string, init?: { body?: unknown }) {
  const res = (await mockFetch(path, { method, body: init?.body })) as {
    ok: boolean;
    status?: number;
    json?: () => Promise<unknown>;
  };
  const parsed: unknown = res.json ? await res.json().catch(() => undefined) : undefined;
  return {
    data: res.ok ? parsed : undefined,
    error: res.ok ? undefined : parsed,
    response: { ok: res.ok, status: res.status ?? (res.ok ? 200 : 500) },
  };
}
jest.mock('@/lib/api/client', () => ({
  SESSION_EXPIRED_EVENT: 'whity:session-expired',
  api: {
    GET: (path: string, init?: object) => mockTyped('GET', path, init),
    POST: (path: string, init?: object) => mockTyped('POST', path, init),
  },
}));

const mockUser = {
  id: 1,
  email: 'test@example.com',
  role: 'admin',
};

// Test component that uses auth context
function TestComponent() {
  const auth = useAuth();
  return (
    <div>
      <div data-testid="user">{auth.user ? auth.user.email : 'No user'}</div>
      <div data-testid="loading">{auth.isLoading ? 'Loading' : 'Ready'}</div>
      <div data-testid="error">{auth.error || 'No error'}</div>
      <div data-testid="authenticated">{auth.isAuthenticated() ? 'Authenticated' : 'Not authenticated'}</div>
      <button onClick={() => auth.login('test@example.com', 'password')}>Login</button>
      <button onClick={() => auth.logout()}>Logout</button>
    </div>
  );
}

describe('AuthContext', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    // Default: fetch returns 401 so any unexpected call (e.g. the silent-refresh
    // attempt when /api/v1/me returns 401) fails gracefully without throwing.
    mockFetch.mockResolvedValue({ ok: false, status: 401 });
  });

  // Test 1: Initialize with /api/me on mount
  test('testAuthProviderInitializesWithApiMe', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(mockFetch).toHaveBeenCalledWith(
      '/api/v1/me',
      expect.objectContaining({ method: 'GET' })
    );
  });

  // Test 2: Set user from /api/me response
  test('testSetUserFromMeResponse', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });
  });

  // Test 3: Set user to null if /api/me returns 401
  test('testSetUserNullIf401OnInit', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(screen.getByTestId('user')).toHaveTextContent('No user');
    expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
  });

  // Test 4: Login updates user from response
  test('testLoginUpdatesUserFromResponse', async () => {
    // Mock initial /api/me call
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    // Mock login response
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });
  });

  // Test 5: Login does NOT store token in context
  test('testLoginStoresNoToken', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        user: mockUser,
        token: 'fake-jwt-token', // Server includes token, but it's not stored
      }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Verify token is NOT in the context value
    const contextValue: Record<string, unknown> = {
      user: mockUser,
      isLoading: false,
      error: null,
    };
    expect(contextValue.token).toBeUndefined();
  });

  // Test 6: Logout calls /api/auth/logout endpoint
  test('testLogoutCallsLogoutEndpoint', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Mock logout response
    mockFetch.mockResolvedValueOnce({
      ok: true,
    });

    const logoutButton = screen.getByText('Logout');
    logoutButton.click();

    await waitFor(() => {
      expect(mockFetch).toHaveBeenCalledWith(
        '/api/v1/auth/logout',
        expect.objectContaining({ method: 'POST' })
      );
    });
  });

  // Test 7: Logout clears user state
  test('testLogoutClearsUserState', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent(mockUser.email);
    });

    // Mock logout response
    mockFetch.mockResolvedValueOnce({
      ok: true,
    });

    const logoutButton = screen.getByText('Logout');
    logoutButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('user')).toHaveTextContent('No user');
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
    });
  });

  // Test 8: Verify token field does NOT exist in context
  test('testNoTokenFieldInContext', () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const captured: { value: ReturnType<typeof useAuth> | null } = { value: null };

    function CaptureContext() {
      const auth = useAuth();
      React.useEffect(() => {
        captured.value = auth;
      }, [auth]);
      return null;
    }

    render(
      <AuthProvider>
        <CaptureContext />
      </AuthProvider>
    );

    waitFor(() => {
      expect(captured.value).not.toHaveProperty('token');
    });
  });

  // Test 10: isAuthenticated depends only on user
  test('testIsAuthenticatedDependsOnlyOnUser', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
    });

    // User null = not authenticated
    expect(screen.getByTestId('user')).toHaveTextContent('No user');

    // Mock successful login to set user
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(screen.getByTestId('authenticated')).toHaveTextContent('Authenticated');
    });
  });

  // Test 11: Boot sequence handles network errors gracefully
  test('testBootSequenceHandlesNetworkErrors', async () => {
    mockFetch.mockRejectedValueOnce(new Error('Network error'));

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    expect(screen.getByTestId('user')).toHaveTextContent('No user');
    expect(screen.getByTestId('authenticated')).toHaveTextContent('Not authenticated');
  });

  // ── Canonical JWT decode (token-fallback login path), ADR 0005 §5 ──────────

  /** Build an unsigned JWT-shaped string with the given payload claims. */
  function fakeJwt(payload: Record<string, unknown>): string {
    const encode = (obj: Record<string, unknown>) =>
      Buffer.from(JSON.stringify(obj)).toString('base64');
    return `${encode({ alg: 'HS256', typ: 'JWT' })}.${encode(payload)}.signature`;
  }

  function UserDetails() {
    const auth = useAuth();
    return (
      <div>
        <div data-testid="user-id">{auth.user ? String(auth.user.id) : 'none'}</div>
        <div data-testid="tenant-id">{auth.user ? String(auth.user.tenant_id) : 'none'}</div>
        <button onClick={() => auth.login('test@example.com', 'password')}>Login</button>
      </div>
    );
  }

  async function loginWithTokenOnly(token: string) {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <UserDetails />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('none');
    });

    // Login response carries only a token (no user object) so the decode
    // fallback populates the profile state.
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ token }),
    });

    screen.getByText('Login').click();
  }

  test('testTokenDecodePrefersNewClaims', async () => {
    await loginWithTokenOnly(
      fakeJwt({
        profile_id: 42,
        active_tenant_id: 7,
        user_id: 5,
        tenant_id: 3,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('42');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('7');
    });
  });

  test('testTokenDecodeIgnoresLegacyOnlyClaims', async () => {
    // Post-cutover (WC-idcut-E/F): a token carrying ONLY the retired legacy
    // {user_id, tenant_id} claims (no profile_id) is not a valid identity, so
    // the decode fallback populates NO user — it fails closed rather than
    // resurrecting the dropped claim shape.
    await loginWithTokenOnly(
      fakeJwt({
        user_id: 5,
        tenant_id: 3,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('none');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('none');
    });
  });

  test('testTokenDecodeNewClaimsOnlyShape', async () => {
    await loginWithTokenOnly(
      fakeJwt({
        profile_id: 42,
        active_tenant_id: 0,
        email: 'test@example.com',
        role: 'admin',
      })
    );

    await waitFor(() => {
      expect(screen.getByTestId('user-id')).toHaveTextContent('42');
      expect(screen.getByTestId('tenant-id')).toHaveTextContent('0');
    });
  });

  // Test 12: Login goes through the typed client, which sends the session cookie
  test('testLoginIncludesCredentialsForCookies', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 401,
    });

    render(
      <AuthProvider>
        <TestComponent />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('Ready');
    });

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({ user: mockUser }),
    });

    const loginButton = screen.getByText('Login');
    loginButton.click();

    await waitFor(() => {
      expect(mockFetch).toHaveBeenCalledWith(
        '/api/v1/login',
        expect.objectContaining({ method: 'POST' })
      );
    });
  });
});
