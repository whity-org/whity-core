/**
 * Signing on a `sign` step (WHIT-922, #1726).
 *
 * The act panel hands a signing step to the sign panel, which asks for the
 * consent line, a drawn signature and the account's second check before the
 * Sign button does anything, and sends the content hash it showed.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

import { RouteActPanel } from '@/components/documents/route-act-panel';
import type { DocumentRoute, RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

const VIEWER = 12;
const HASH = 'a'.repeat(64);

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as unknown as Response;
}

const context = {
  route_id: 8,
  can_sign: true,
  step: { id: 10, position: 1, label: null, capacity: 'Director', on_behalf_of: 'Example Bakery', quorum: 'all' },
  subject: { type: 'document', id: 1, title: 'Supply agreement', version: 1, content_hash: HASH },
  consent_version: '2026-11-1',
  second_factor: { method: 'totp', provider_key: null, email_hint: null, signed_in_again: false },
  saved_signature: {
    width: 480,
    height: 160,
    strokes: [[[10, 50], [40, 20], [70, 60], [100, 30], [130, 55]]],
  },
};

function renderPanel() {
  const signStep = {
    id: 10,
    position: 1,
    rule_kind: 'explicit',
    rule_config: {},
    label: null,
    decision: false,
    decision_quorum: 'all',
    satisfied_by: 'act',
    rejection_count: 0,
    kind: 'sign',
    sign_capacity: 'Director',
    sign_on_behalf_of: 'Example Bakery',
  } as RouteStep;
  const route = { id: 8, document_id: 1, title: 'Supply agreement', created_by: 3, created_at: '', steps: [signStep], edges: [] } as unknown as DocumentRoute;
  const item = {
    id: 14,
    document_id: 1,
    route_id: 8,
    step_id: 10,
    profile_id: VIEWER,
    ou_id: null,
    parent_recipient_id: null,
    created_by_event_id: 15,
    closed_by_event_id: null,
    open: true,
    closed_by_delivery: false,
    created_at: '',
  } as RouteRecipient;

  return render(
    <RouteActPanel documentId={1} route={route} recipients={[item]} viewerProfileId={VIEWER} onActed={() => {}} />
  );
}

describe('the sign panel', () => {
  beforeEach(() => {
    mockApiClient.mockReset();
    mockApiClient.mockImplementation(async (url: string, options?: { method?: string }) => {
      if (url.endsWith('/signing')) return json(200, { data: context });
      if (url.endsWith('/sign') && options?.method === 'POST') return json(201, { data: {} });
      return json(404, {});
    });
  });

  it('shows the capacity and asks for consent and a code before signing', async () => {
    const { container } = renderPanel();

    await screen.findByText('Director');
    expect(screen.getByText('Example Bakery')).toBeInTheDocument();
    expect(container.querySelector('[data-slot="route-act-panel"]')).toBeNull();

    const sign = screen.getByTestId('sign-panel-sign');
    expect(sign).toBeDisabled();

    fireEvent.click(screen.getByRole('checkbox', { name: /I have read this document/ }));
    expect(sign).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Code'), { target: { value: '123456' } });
    expect(sign).toBeEnabled();

    fireEvent.click(sign);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/documents/1/routes/8/sign',
      expect.objectContaining({ method: 'POST' })
    ));
    const call = mockApiClient.mock.calls.find(([url]) => String(url).endsWith('/sign'));
    const body = JSON.parse(String(call?.[1]?.body));
    expect(body).toMatchObject({
      content_hash: HASH,
      consent: true,
      consent_version: '2026-11-1',
      use_saved_signature: true,
      second_factor: { method: 'totp', code: '123456' },
    });
  });
});
