/**
 * #1019 — a step that names a role reads with the role's NAME for a viewer who
 * cannot list roles.
 *
 * The routing panel used to name roles only from `GET /api/v1/roles`, which needs
 * `roles:read`, so a recipient or a sender without it read "A role". The roles
 * the steps name now come from `POST /api/v1/labels/resolve`, asked as the
 * routing surface (`documents:route`) by somebody who may route, and as the
 * document surface (`documents:read`) otherwise.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient, user: { id: 7 } }),
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

let mayRoute = true;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    has: () => mayRoute,
    hasPermission: () => mayRoute,
    loading: false,
    permissions: [],
  }),
}));

jest.mock('next/navigation', () => ({
  useParams: () => ({ documentId: '1' }),
  useRouter: () => ({ push: jest.fn() }),
}));

import { DocumentRoutingPanel } from '@/components/documents/document-routing-panel';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

const ROUTE = {
  id: 8,
  document_id: 1,
  title: 'To the heads',
  created_by: 3,
  created_at: '2026-08-25T00:26:43Z',
  steps: [
    {
      id: 10,
      position: 1,
      rule_kind: 'role',
      rule_config: { role_id: 5 },
      label: null,
      decision: false,
      decision_quorum: null,
    },
  ],
  edges: [],
  default_quorum: 'all',
};

function wire(labels: { status: number; body: unknown }) {
  mockApiClient.mockImplementation(async (url: string) => {
    if (url === '/api/v1/labels/resolve') return json(labels.status, labels.body);
    if (url.includes('/recipients')) return json(200, { data: [] });
    if (url.includes('/routes')) return json(200, { data: [ROUTE] });
    if (url.startsWith('/api/v1/roles')) return json(403, { error: 'Insufficient permissions', required: 'roles:read' });
    if (url === '/api/v1/routing-rules') return json(200, { data: [] });
    return json(200, { data: [], pagination: { page: 1, perPage: 100, total: 0, totalPages: 0 } });
  });
}

function labelBodies(): { surface: string; roles: number[] }[] {
  return mockApiClient.mock.calls
    .filter((call) => call[0] === '/api/v1/labels/resolve')
    .map((call) => JSON.parse(String((call[1] as RequestInit).body)));
}

beforeEach(() => {
  jest.clearAllMocks();
  mayRoute = true;
});

it('names the role a step resolves through without roles:read', async () => {
  wire({ status: 200, body: { data: { ous: {}, roles: { 5: 'Department head' } } } });

  render(<DocumentRoutingPanel documentId={1} documentTitle="A circular" />);

  await waitFor(() => expect(screen.getByText(/Everyone holding Department head/)).toBeInTheDocument());
  expect(labelBodies().at(-1)).toEqual({ surface: 'documents.route', ous: [], roles: [5] });
});

it('asks as the document surface for a viewer who may not route', async () => {
  mayRoute = false;
  wire({ status: 200, body: { data: { ous: {}, roles: { 5: 'Department head' } } } });

  render(<DocumentRoutingPanel documentId={1} documentTitle="A circular" />);

  await waitFor(() => expect(screen.getByText(/Everyone holding Department head/)).toBeInTheDocument());
  expect(labelBodies().at(-1)?.surface).toBe('documents');
});

it('keeps the honest fallback when the names are refused', async () => {
  wire({ status: 403, body: { error: 'Insufficient permissions', required: 'documents:route' } });

  render(<DocumentRoutingPanel documentId={1} documentTitle="A circular" />);

  await waitFor(() => expect(document.querySelector('[data-slot="routing-route"]')).not.toBeNull());
  await waitFor(() => expect(labelBodies().length).toBeGreaterThan(0));
  expect(screen.queryByText(/Department head/)).not.toBeInTheDocument();
});
