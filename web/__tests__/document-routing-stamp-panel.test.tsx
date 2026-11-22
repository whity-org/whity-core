/**
 * Applying a company stamp on a `stamp` step (#1729).
 *
 * The act panel hands a stamp step to the stamp panel. A holder sees the
 * stamp's image and legal name, ticks the consent line and passes the second
 * check before Apply stamp does anything, and the body carries the content
 * hash it showed. Someone who is not a holder is told so plainly and can only
 * decline.
 */

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();

jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast: jest.fn() }),
}));

import { RouteActPanel } from '@/components/documents/route-act-panel';
import type { DocumentRoute, RouteRecipient, RouteStep } from '@/components/documents/routing-wire';

const VIEWER = 12;
const HASH = 'b'.repeat(64);
const IMAGE = 'data:image/png;base64,iVBORw0KGgo=';

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as unknown as Response;
}

function contextFor(isHolder: boolean, method: 'totp' | 'email_code' = 'totp') {
  return {
    route_id: 8,
    can_stamp: isHolder,
    is_holder: isHolder,
    step: { id: 10, position: 2, label: null, quorum: 'any' },
    stamp: { id: 3, name: 'Company seal', legal_name: 'Example Bakery LLC', image: IMAGE },
    subject: { type: 'document', id: 1, title: 'Supply agreement', version: 4, content_hash: HASH },
    consent_version: '2026-11-stamp-1',
    second_factor: { method, provider_key: null, email_hint: 'a***@example.com', signed_in_again: false },
  };
}

function renderPanel() {
  const stampStep = {
    id: 10,
    position: 2,
    rule_kind: 'explicit',
    rule_config: {},
    label: null,
    decision: false,
    decision_quorum: 'any',
    satisfied_by: 'act',
    rejection_count: 0,
    kind: 'stamp',
    stamp_id: 3,
  } as RouteStep;
  const route = {
    id: 8,
    document_id: 1,
    title: 'Supply agreement',
    created_by: 3,
    created_at: '',
    steps: [stampStep],
    edges: [],
  } as unknown as DocumentRoute;
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

function serve(context: ReturnType<typeof contextFor>) {
  mockApiClient.mockReset();
  mockApiClient.mockImplementation(async (url: string, options?: { method?: string }) => {
    if (url.endsWith('/stamping')) return json(200, { data: context });
    if (url.endsWith('/stamp') && options?.method === 'POST') return json(201, { data: {} });
    if (url.endsWith('/actions') && options?.method === 'POST') return json(201, { data: {} });
    return json(404, {});
  });
}

describe('the stamp panel', () => {
  it('shows the stamp and its legal name, and never calls it a certified seal', async () => {
    serve(contextFor(true));
    const { container } = renderPanel();

    expect(await screen.findByText('Company seal')).toBeInTheDocument();
    expect(screen.getByText('Stands for Example Bakery LLC')).toBeInTheDocument();
    const image = screen.getByRole('img', { name: 'The image of the stamp Company seal' });
    expect(image).toHaveAttribute('src', IMAGE);
    expect(container.querySelector('[data-slot="route-act-panel"]')).toBeNull();
    expect(container.querySelector('[data-slot="sign-panel"]')).toBeNull();
    expect(screen.getByText(/It is not a certified electronic seal/)).toBeInTheDocument();
    expect(container.querySelector('[data-slot="stamp-panel-not-holder"]')).toBeNull();
  });

  it('asks for consent and a code, then posts the content hash it showed', async () => {
    serve(contextFor(true));
    renderPanel();

    const apply = await screen.findByTestId('stamp-panel-apply');
    expect(apply).toBeDisabled();

    fireEvent.click(screen.getByRole('checkbox', { name: /I hold this company stamp/ }));
    expect(apply).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Code'), { target: { value: '654 321' } });
    expect(apply).toBeEnabled();

    fireEvent.click(apply);
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/documents/1/routes/8/stamp',
        expect.objectContaining({ method: 'POST' })
      )
    );
    const call = mockApiClient.mock.calls.find(([url]) => String(url).endsWith('/stamp'));
    const body = JSON.parse(String(call?.[1]?.body));
    expect(body).toEqual({
      content_hash: HASH,
      consent: true,
      consent_version: '2026-11-stamp-1',
      second_factor: { method: 'totp', code: '654321' },
    });
  });

  it('tells someone who does not hold the stamp, and lets them only decline', async () => {
    serve(contextFor(false));
    const { container } = renderPanel();

    expect(await screen.findByText(/Only a holder of this company stamp can apply it/)).toBeInTheDocument();
    expect(container.querySelector('[data-slot="stamp-panel"]')).toHaveAttribute('data-holder', 'false');
    expect(screen.queryByTestId('stamp-panel-apply')).toBeNull();
    expect(screen.queryByRole('checkbox', { name: /I hold this company stamp/ })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Decline to stamp' }));
    fireEvent.change(screen.getByLabelText(/Why are you declining/), { target: { value: 'Not my department' } });
    fireEvent.click(screen.getByRole('button', { name: 'Decline to stamp' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/documents/1/routes/8/actions',
        expect.objectContaining({ method: 'POST' })
      )
    );
    const call = mockApiClient.mock.calls.find(([url]) => String(url).endsWith('/actions'));
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ action: 'declined', note: 'Not my department' });
    expect(mockApiClient.mock.calls.some(([url]) => String(url).endsWith('/stamp'))).toBe(false);
  });

  it('sends the email code through the signing code endpoint', async () => {
    serve(contextFor(true, 'email_code'));
    mockApiClient.mockImplementation(async (url: string, options?: { method?: string }) => {
      if (url.endsWith('/stamping')) return json(200, { data: contextFor(true, 'email_code') });
      if (url.endsWith('/signing/code') && options?.method === 'POST') {
        return json(201, { data: { email_hint: 'a***@example.com' } });
      }
      return json(404, {});
    });
    renderPanel();

    fireEvent.click(await screen.findByRole('button', { name: 'Send me a code' }));
    expect(await screen.findByText(/We sent a 6-digit code to a\*\*\*@example.com/)).toBeInTheDocument();
  });
});
