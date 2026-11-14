import {
  toFlowGraph,
  toGraphRequest,
  type RouteTemplateGraphWire,
  type RouteTemplateStepWire,
} from '@/app/(protected)/admin/document-route-templates/types';

/**
 * A graph must survive read -> edit -> save unchanged (#1054/#1064).
 *
 * THE BUG THIS EXISTS FOR. `toGraphRequest()` did not send `satisfied_by`, and
 * the server reads a MISSING `satisfied_by` as `act`. So opening a template
 * that contained a delivery stage in the flow editor and moving a single node
 * turned "everybody at this stage is told" into "everybody at this stage must
 * answer" — for every recipient of that stage, silently, with the trail showing
 * an ordinary graph save.
 *
 * `RouteTemplatePresenter` publishes the field with a comment naming exactly
 * this outcome: "a canvas that could not show it would let an author 'correct'
 * it away by saving a graph that never carried it." The server did its half.
 *
 * NEITHER MAPPING FUNCTION HAD A TEST, which is why a dropped field could go
 * unnoticed. So the first test below is deliberately not about `satisfied_by`
 * at all: it is a WHOLE-OBJECT round trip, and it fails for ANY field added to
 * the wire type and forgotten in `toGraphRequest`. The specific regression is
 * pinned separately underneath, because a general test that breaks tells you
 * something is wrong and a named one tells you what.
 */
describe('the route-template graph round trip', () => {
  const step = (overrides: Partial<RouteTemplateStepWire> = {}): RouteTemplateStepWire => ({
    position: 1,
    rule_kind: 'role',
    rule_config: { role_id: 7 },
    label: 'Registry',
    decision: false,
    decision_quorum: null,
    satisfied_by: 'act',
    allow_send_back: true,
    inputs: [],
    canvas_x: 120,
    canvas_y: 40,
    ...overrides,
  });

  const graph = (steps: RouteTemplateStepWire[]): RouteTemplateGraphWire =>
    ({
      default_quorum: 'all',
      max_steps: 20,
      steps,
      edges: [{ from: 1, to: 2, verdict: 'approved' as const }],
    }) as RouteTemplateGraphWire;

  it('returns every step field it was given, for every field', () => {
    // The general guard. Each value is distinct from every default so a mapping
    // that dropped one and let the server re-supply it would still fail here.
    const steps = [
      step({ position: 1, satisfied_by: 'delivery', label: 'Circulate' }),
      step({
        position: 2,
        rule_kind: 'role_below_actor',
        rule_config: { role_id: 3 },
        label: null,
        decision: true,
        decision_quorum: 'majority',
        satisfied_by: 'act',
        allow_send_back: false,
        inputs: [
          { key: 'meeting_no', label: 'Committee meeting number', label_ar: 'رقم جلسة اللجنة', required: true, max_length: 20 },
          { key: 'file_no', label: 'File number', label_ar: null, required: false, max_length: 100 },
        ],
        canvas_x: 400,
        canvas_y: 220,
      }),
    ];

    const saved = toGraphRequest(toFlowGraph(graph(steps)));

    expect(saved.steps).toEqual(steps);
  });

  it('keeps a delivery stage a delivery stage', () => {
    // The named regression. A round trip that silently returned `act` here is
    // the destructive save: the stage still exists, still renders, and now asks
    // its recipients for something nobody meant to ask them for.
    const saved = toGraphRequest(toFlowGraph(graph([step({ satisfied_by: 'delivery' })])));

    expect(saved.steps[0].satisfied_by).toBe('delivery');
  });

  it('keeps a stage that cannot be sent back that way (WHIT-920)', () => {
    // The server reads a missing `allow_send_back` as on, so a save that dropped
    // it would quietly let reviewers send back at a stage the author closed.
    const saved = toGraphRequest(toFlowGraph(graph([step({ allow_send_back: false })])));

    expect(saved.steps[0].allow_send_back).toBe(false);
  });

  it('reads a stage from a server that sends no send-back flag as one that can be sent back', () => {
    const legacy = step();
    delete (legacy as Partial<RouteTemplateStepWire>).allow_send_back;

    const flow = toFlowGraph(graph([legacy]));

    expect(flow.steps[0].allowSendBack).toBe(true);
    expect(toGraphRequest(flow).steps[0].allow_send_back).toBe(true);
  });

  it('reads a stage from a server that predates the field as an act stage', () => {
    // `satisfied_by` arrives absent from an older backend. `act` is the right
    // reading — it is what the server itself defaults a missing value to — and
    // the point is that it must be an EXPLICIT value in the model rather than
    // `undefined`, which would serialise back as a missing field and re-enter
    // the same defaulting loop from the other side.
    const legacy = step();
    delete (legacy as Partial<RouteTemplateStepWire>).satisfied_by;

    const flow = toFlowGraph(graph([legacy]));

    expect(flow.steps[0].satisfiedBy).toBe('act');
    expect(toGraphRequest(flow).steps[0].satisfied_by).toBe('act');
  });

  it('keeps what a stage asks the approver to fill in (WHIT-1016)', () => {
    // The server reads a missing list as none, so a save that dropped it
    // would quietly stop asking for the committee meeting number.
    const asked = [{ key: 'meeting_no', label: 'Meeting number', label_ar: null, required: true, max_length: 100 }];
    const saved = toGraphRequest(toFlowGraph(graph([step({ inputs: asked })])));

    expect(saved.steps[0].inputs).toEqual(asked);
  });

  it('reads a stage from a server that predates values as one asking for none, and says so on save', () => {
    const legacy = step();
    delete (legacy as Partial<RouteTemplateStepWire>).inputs;

    const flow = toFlowGraph(graph([legacy]));

    expect(flow.steps[0].inputs).toEqual([]);
    expect(toGraphRequest(flow).steps[0].inputs).toEqual([]);
  });

  it('does not send back the two facts the tenant owns', () => {
    // `default_quorum` and `max_steps` are the server's, re-derived on every
    // read. A client that echoed them would be offering to overwrite a tenant
    // setting from a canvas — the mirror image of the bug above, and worth
    // pinning while the round trip is under test at all.
    const saved = toGraphRequest(toFlowGraph(graph([step()])));

    expect(saved).not.toHaveProperty('default_quorum');
    expect(saved).not.toHaveProperty('max_steps');
  });

  it('carries the edges through untouched', () => {
    const saved = toGraphRequest(toFlowGraph(graph([step(), step({ position: 2 })])));

    expect(saved.edges).toEqual([{ from: 1, to: 2, verdict: 'approved' }]);
  });
});
