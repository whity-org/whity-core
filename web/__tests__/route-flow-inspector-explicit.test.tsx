/**
 * #1034 — the stage inspector for the two rule kinds that had no editor.
 *
 * - `explicit` gets #1015's people picker, a sentence saying it is the kind that
 *   names individuals (and so goes stale), and stores `{ profile_ids }`.
 * - A plugin's kind is shown read only: which plugin it comes from and what is
 *   saved, because rule contributions declare no config schema yet.
 *
 * The canvas is stubbed for the reason `route-flow-inspector-merge.test.tsx`
 * gives: the host decides what a selected stage means, and that is under test.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => mockApiClient(...args) }),
}));
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

// The canvas, reduced to the one thing the host consumes from it: which stage is
// selected. `appendStep` is passed through unchanged — the screen imports it
// from the same module and this file never adds a stage.
jest.mock('@whity/ui/route-flow/editor', () => ({
  __esModule: true,
  RouteFlowEditor: ({
    graph,
    onSelectStep,
  }: {
    graph: { steps: Array<{ position: number }> };
    onSelectStep?: (position: number | null) => void;
  }) => (
    <div>
      {graph.steps.map((step) => (
        <button key={step.position} onClick={() => onSelectStep?.(step.position)}>
          {`select stage ${step.position}`}
        </button>
      ))}
    </div>
  ),
  appendStep: (graph: unknown) => graph,
}));

import { RouteFlowEditorScreen } from '@/app/(protected)/admin/document-route-templates/[id]/flow-editor-screen';

function step(position: number, rule_kind: string, rule_config: Record<string, unknown>) {
  return {
    position,
    rule_kind,
    rule_config,
    label: null,
    decision: false,
    decision_quorum: null,
    canvas_x: 0,
    canvas_y: position * 160,
  };
}

const TEMPLATE = {
  id: 1,
  name: 'Tender committee',
  description: null,
  step_count: 3,
  created_by: null,
  created_at: '2026-01-01T00:00:00+00:00',
  updated_at: '2026-01-01T00:00:00+00:00',
  default_quorum: 'all' as const,
  max_steps: 20,
  steps: [
    step(1, 'explicit', { profile_ids: [11] }),
    step(2, 'shift_lead', { shift: 'night', sites: [3, 4] }),
    step(3, 'gone_kind', {}),
  ],
  edges: [],
};

const RULES = [
  { kind: 'role', label: 'Everyone holding a role', source: 'core' },
  { kind: 'explicit', label: 'Exactly these people', source: 'core' },
  { kind: 'shift_lead', label: 'Whoever leads the shift', source: 'rota' },
];

const PEOPLE = [
  { id: 11, name: 'Amro Saleh', email: 'amro@example.com' },
  { id: 12, name: 'Awss Haddad', email: 'awss@example.com' },
];

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

let usersStatus = 200;

beforeEach(() => {
  usersStatus = 200;
  mockApiClient.mockReset();
  mockApiClient.mockImplementation((url: string, init?: { method?: string }) => {
    if (url.startsWith('/api/v1/document-route-templates/')) return jsonResponse(200, { data: TEMPLATE });
    if (url.startsWith('/api/v1/routing-rules')) return jsonResponse(200, { data: RULES });
    if (url.startsWith('/api/v1/roles')) return jsonResponse(200, { data: [] });
    if (url.startsWith('/api/v1/user-groups/preview') && init?.method === 'POST') {
      return jsonResponse(200, { data: { total: 1, truncated: false, sample_size: 0, sample: [] } });
    }
    if (url.startsWith('/api/v1/user-groups')) return jsonResponse(200, { data: [] });
    if (url.startsWith('/api/v1/users')) {
      return usersStatus === 200 ? jsonResponse(200, { data: PEOPLE }) : jsonResponse(usersStatus, { error: 'Forbidden' });
    }
    throw new Error(`unexpected request: ${url}`);
  });
});

describe('the stage inspector for explicit and plugin rule kinds (#1034)', () => {
  it('names people on an explicit stage with the people picker', async () => {
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await user.click(await screen.findByRole('button', { name: 'select stage 1' }));

    expect(screen.getByText(/means exactly these people and nobody else/)).toBeInTheDocument();
    // The saved person is a chip, by name.
    expect(await screen.findByRole('button', { name: 'Remove Amro Saleh' })).toBeInTheDocument();

    await user.type(screen.getByRole('searchbox', { name: 'Search people by name' }), 'Awss');
    await user.click(screen.getByRole('button', { name: /Awss Haddad/ }));
    expect(screen.getByRole('button', { name: 'Remove Awss Haddad' })).toBeInTheDocument();

    expect(screen.queryByText(/has no editor here yet/)).not.toBeInTheDocument();
  });

  it('says why when people cannot be listed', async () => {
    usersStatus = 403;
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await user.click(await screen.findByRole('button', { name: 'select stage 1' }));

    expect(await screen.findByText(/You cannot list people here/)).toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('shows a plugin kind read only, naming the plugin and its saved settings', async () => {
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await user.click(await screen.findByRole('button', { name: 'select stage 2' }));

    expect(screen.getByText(/comes from the rota plugin/)).toBeInTheDocument();
    expect(screen.getByText('shift')).toBeInTheDocument();
    expect(screen.getByText('night')).toBeInTheDocument();
    expect(screen.getByText('3, 4')).toBeInTheDocument();
    // Nothing to type into: the config is the plugin's.
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    // People are not fetched for a stage that does not name any.
    expect(mockApiClient.mock.calls.some(([u]) => String(u).startsWith('/api/v1/users'))).toBe(false);
  });

  it('says so when a stage names a kind the workspace no longer offers', async () => {
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await user.click(await screen.findByRole('button', { name: 'select stage 3' }));

    expect(screen.getByText(/not one this workspace offers any more/)).toBeInTheDocument();
    expect(screen.getByText(/needs no settings, or none have been saved/)).toBeInTheDocument();
  });
});
