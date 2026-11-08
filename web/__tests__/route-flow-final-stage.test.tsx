/**
 * The final document stage in the route editor (WHIT-921, #1725).
 *
 * Three things are pinned: the stage survives a read -> save round trip with
 * its template (a field the canvas drops is one a save destroys); a stage added
 * after it goes before it, because the final document is always last; and the
 * editor offers the stage only where PDFs can be made, with an inspector that
 * asks for a template rather than for people.
 *
 * The canvas is stubbed for the reason `route-flow-inspector-merge.test.tsx`
 * gives: `@xyflow/react` needs a layout engine jsdom does not have.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  toFlowGraph,
  toGraphRequest,
  type RouteTemplateGraphWire,
} from '@/app/(protected)/admin/document-route-templates/types';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => mockApiClient(...args) }),
}));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/lib/direction-context', () => ({ useDirection: () => ({ dir: 'ltr' }) }));

jest.mock('@whity/ui/route-flow/editor', () => {
  const actual = jest.requireActual('@whity/ui/route-flow/editor');
  return {
    __esModule: true,
    appendStep: actual.appendStep,
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
  };
});

import { appendStep } from '@whity/ui/route-flow/editor';
import { RouteFlowEditorScreen } from '@/app/(protected)/admin/document-route-templates/[id]/flow-editor-screen';

function step(position: number, overrides: Record<string, unknown> = {}) {
  return {
    position,
    rule_kind: 'role',
    rule_config: {},
    label: null,
    decision: false,
    decision_quorum: null,
    satisfied_by: 'act' as const,
    allow_send_back: true,
    canvas_x: 0,
    canvas_y: position * 160,
    ...overrides,
  };
}

const FINAL = step(2, {
  rule_kind: 'finalise',
  rule_config: {},
  label: 'Approval letter',
  allow_send_back: false,
  kind: 'finalise',
  final_template_id: 42,
});

function wire(canFinalise: boolean, steps = [step(1, { decision: true }), FINAL]): RouteTemplateGraphWire {
  return {
    id: 1,
    name: 'Supplier approval',
    description: null,
    step_count: steps.length,
    usage_count: 0,
    created_by: null,
    created_at: '2026-11-08T00:00:00+00:00',
    updated_at: '2026-11-08T00:00:00+00:00',
    default_quorum: 'all',
    max_steps: 20,
    steps,
    edges: [{ from: 1, to: 2, verdict: 'approved' }],
    can_finalise: canFinalise,
  } as RouteTemplateGraphWire;
}

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function serve(template: RouteTemplateGraphWire) {
  mockApiClient.mockReset();
  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/document-route-templates/')) return jsonResponse(200, { data: template });
    if (url.startsWith('/api/v1/routing-rules')) return jsonResponse(200, { data: [{ kind: 'role', label: 'Everyone holding a role', source: 'core' }] });
    if (url.startsWith('/api/v1/roles')) return jsonResponse(200, { data: [] });
    if (url.startsWith('/api/v1/user-groups')) return jsonResponse(200, { data: [] });
    if (url.startsWith('/api/v1/document-sources')) {
      return jsonResponse(200, {
        data: [
          { id: 42, title: 'Supplier approval letter', published_version: 1 },
          { id: 43, title: 'Unpublished draft', published_version: null },
        ],
      });
    }
    throw new Error(`unexpected request: ${url}`);
  });
}

describe('the final document stage', () => {
  it('survives a round trip with its template, and other stages carry no kind', () => {
    const saved = toGraphRequest(toFlowGraph(wire(true)));

    expect(saved.steps[1]).toMatchObject({ kind: 'finalise', final_template_id: 42, rule_kind: 'finalise' });
    expect(saved.steps[0]).not.toHaveProperty('kind');
  });

  it('keeps a stage that uses the form\'s template as one with none', () => {
    const saved = toGraphRequest(toFlowGraph(wire(true, [step(1), { ...FINAL, final_template_id: null }])));

    expect(saved.steps[1]).toMatchObject({ kind: 'finalise', final_template_id: null });
  });

  it('puts a stage added after the final document before it, and moves the arrows that reach it', () => {
    const graph = toFlowGraph(wire(true));
    const next = appendStep(graph, {
      ruleKind: 'role',
      ruleConfig: {},
      label: 'Finance',
      decision: false,
      decisionQuorum: null,
      satisfiedBy: 'act',
    });

    const byPosition = [...next.steps].sort((a, b) => a.position - b.position);
    expect(byPosition.map((s) => s.label)).toEqual([null, 'Finance', 'Approval letter']);
    expect(byPosition[2].kind).toBe('finalise');
    expect(next.edges).toEqual([{ from: 1, to: 3, verdict: 'approved' }]);
  });

  it('asks for a template, not for people, and lists only published templates', async () => {
    serve(wire(true));
    const user = userEvent.setup();
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await user.click(await screen.findByRole('button', { name: 'select stage 2' }));

    expect(await screen.findByText('Document template')).toBeInTheDocument();
    expect(screen.queryByText('Who it reaches')).not.toBeInTheDocument();
    expect(screen.queryByText('Requires a decision')).not.toBeInTheDocument();
    // The route already has its final document stage, so none is offered.
    expect(screen.queryByRole('button', { name: 'Add the final document' })).not.toBeInTheDocument();
  });

  it('offers the stage only where PDFs can be made', async () => {
    serve(wire(true, [step(1)]));
    const first = render(<RouteFlowEditorScreen templateId={1} canWrite />);
    expect(await screen.findByRole('button', { name: 'Add the final document' })).toBeInTheDocument();
    first.unmount();

    serve(wire(false, [step(1)]));
    render(<RouteFlowEditorScreen templateId={1} canWrite />);
    await screen.findByRole('button', { name: 'select stage 1' });
    expect(screen.queryByRole('button', { name: 'Add the final document' })).not.toBeInTheDocument();
  });
});
