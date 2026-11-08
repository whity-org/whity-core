/**
 * WHIT-1016: the host-drawn secret field. It shows whether a value is set and
 * never holds one it didn't just have typed into it.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const endpoint = '/api/v1/plugin-secrets/acme/api_key';
const block = { type: 'secretField', secret: 'api_key', label: 'API key', endpoint } as Block;

function stubResponse(status: number, body: unknown): Response {
  return { ok: status < 400, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => mockApiClient.mockReset());

test('starts empty and says when a value is set', async () => {
  mockApiClient.mockResolvedValue(stubResponse(200, { is_set: true, updated_at: '2026-11-08 10:00:00' }));

  render(<BlockRenderer blocks={[block]} />);

  expect(await screen.findByText('Set')).toBeInTheDocument();
  const input = screen.getByLabelText('API key') as HTMLInputElement;
  expect(input.type).toBe('password');
  expect(input.value).toBe('');
});

test('saves what was typed, then empties the field', async () => {
  mockApiClient.mockResolvedValueOnce(stubResponse(200, { is_set: false, updated_at: null }));
  render(<BlockRenderer blocks={[block]} />);
  const input = (await screen.findByLabelText('API key')) as HTMLInputElement;

  mockApiClient.mockResolvedValueOnce(stubResponse(200, { is_set: true, updated_at: '2026-11-08 10:00:00' }));
  await userEvent.type(input, 'sk-secret');
  await userEvent.click(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(screen.getByText('Set')).toBeInTheDocument());
  expect(mockApiClient).toHaveBeenLastCalledWith(endpoint, expect.objectContaining({
    method: 'PUT',
    body: JSON.stringify({ value: 'sk-secret' }),
  }));
  expect(input.value).toBe('');
});

test('without the permission there is no field', async () => {
  mockApiClient.mockResolvedValue(stubResponse(403, { error: 'Insufficient permissions' }));

  render(<BlockRenderer blocks={[block]} />);

  expect(await screen.findByText(/do not have permission/)).toBeInTheDocument();
  expect(screen.queryByLabelText('API key')).not.toBeInTheDocument();
});

test('a block with no endpoint from the host draws nothing usable', () => {
  render(<BlockRenderer blocks={[{ type: 'secretField', secret: 'api_key', label: 'API key' } as Block]} />);

  expect(screen.queryByLabelText('API key')).not.toBeInTheDocument();
  expect(mockApiClient).not.toHaveBeenCalled();
});
