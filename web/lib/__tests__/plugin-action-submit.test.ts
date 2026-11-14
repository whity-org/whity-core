/**
 * WC-235: unit tests for `submitPluginAction`.
 *
 * Mocks `apiFetch` directly. Asserts:
 *   - 2xx response → { ok: true }
 *   - 422 with `{ issues }` → { ok: false, issues }
 *   - other 4xx with `{ error }` → { ok: false, error }
 *   - thrown error → { ok: false, error: message }
 *   - sends the correct HTTP method + JSON-encoded payload to the endpoint
 */

import { submitPluginAction } from '@/lib/plugin-action-submit';

// The endpoint is a run-time path, so the action goes through `apiFetch`.
const mockApiClient = jest.fn<Promise<Response>, [string, RequestInit?]>();
jest.mock('@/lib/api/client', () => ({
  apiFetch: (...a: [string, RequestInit?]) => mockApiClient(...a),
}));

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

beforeEach(() => {
  mockApiClient.mockReset();
});

describe('submitPluginAction', () => {
  it('returns { ok: true } on a 200 response', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, { data: {} }));

    const result = await submitPluginAction('/api/v1/x/save', 'POST', { key: 'value' });

    expect(result).toEqual({ ok: true });
  });

  it('returns { ok: true } on a 201 response', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 201, {}));

    const result = await submitPluginAction('/api/v1/x/save', 'PUT', {});

    expect(result).toEqual({ ok: true });
  });

  it('returns { ok: false, issues } on a 422 response with issues array', async () => {
    const issues = [
      { severity: 'error', message: 'Name is required', column: 'name' },
    ];
    mockApiClient.mockResolvedValue(stubResponse(false, 422, { issues }));

    const result = await submitPluginAction('/api/v1/x/save', 'POST', {});

    expect(result).toEqual({ ok: false, issues });
  });

  it('returns { ok: false, error } on a 4xx response with error string', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 403, { error: 'Forbidden' })
    );

    const result = await submitPluginAction('/api/v1/x/save', 'POST', {});

    expect(result).toEqual({ ok: false, error: 'Forbidden' });
  });

  it('returns { ok: false, error } with status message when body has neither issues nor error', async () => {
    mockApiClient.mockResolvedValue(stubResponse(false, 400, {}));

    const result = await submitPluginAction('/api/v1/x/save', 'POST', {});

    expect(result.ok).toBe(false);
    expect((result as { ok: false; error?: string }).error).toContain('400');
  });

  it('returns { ok: false, error } when apiFetch throws', async () => {
    mockApiClient.mockRejectedValue(new Error('Network failure'));

    const result = await submitPluginAction('/api/v1/x/save', 'POST', {});

    expect(result).toEqual({ ok: false, error: 'Network failure' });
  });

  it('returns { ok: false, error } with fallback when thrown value is not an Error', async () => {
    mockApiClient.mockRejectedValue('something unexpected');

    const result = await submitPluginAction('/api/v1/x/save', 'POST', {});

    expect(result.ok).toBe(false);
  });

  it('sends the method and JSON-encoded payload to the endpoint', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    await submitPluginAction('/api/v1/x/save', 'PUT', { name: 'Alice', age: 30 });

    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/x/save', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'Alice', age: 30 }),
    });
  });

  it('sends POST method when specified', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    await submitPluginAction('/api/v1/x/action', 'POST', {});

    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/x/action', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });
  });
});
