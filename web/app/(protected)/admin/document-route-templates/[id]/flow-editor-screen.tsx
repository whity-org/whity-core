'use client';

/**
 * The node-based route-flow editor screen (#1027).
 *
 * The CANVAS is `@whity/ui/route-flow/editor` — a kit component, because a
 * graph editor is precisely what a second client wants and anything in
 * `web/components/` ships to one of three. This file is the HOST: it fetches,
 * it resolves audiences, it renders the inspector whose pickers are bound to
 * this app's APIs, and it saves.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE ONE THING THIS SCREEN EXISTS TO GET RIGHT
 * ─────────────────────────────────────────────────────────────────────────
 * A node is a TYPE. "Everyone holding Instructor" is one card, and it stays one
 * card whether it resolves to four people or four thousand. There is no code
 * path here that turns a rule into a list of people — not on the canvas, not in
 * what is saved.
 *
 * What the author gets instead is the COUNT, resolved live and shown on the card:
 * "reaches 1,043 people · all must approve". That single line is what makes the
 * safe quorum default survivable — an author who leaves `all` on a
 * 1,043-person node reads what it means while authoring, rather than finding out
 * in November when nothing has moved.
 *
 * The count comes from #1003's preview, which already answers exactly this
 * question with a count and a bounded sample. Only the count is used; the sample
 * is ignored on purpose, because a surface that rendered 1,043 names would have
 * rebuilt the problem the design exists to avoid.
 *
 * WHICH preview endpoint depends on the rule kind, and that is not an
 * optimisation — `POST /user-groups/preview` refuses `rule_kind: "group"`
 * outright, because a group cannot be DEFINED as another group. A group stage is
 * previewed through the stored group it names instead. See
 * `audiencePreviewRequest` in ../types.ts for the whole argument; getting this
 * wrong put a message about defining groups on the most important node type.
 *
 * A 403 is EXPECTED rather than exceptional — the draft preview is gated on
 * `groups:write` because it resolves an arbitrary composed rule, and somebody who
 * may design a flow does not necessarily hold it. The card then shows the reason
 * instead of a number. NEVER a zero: a zero reads as "this reaches nobody", which
 * is a very different and much more alarming statement than "you cannot see this".
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DIRECTION
 * ─────────────────────────────────────────────────────────────────────────
 * The canvas defaults to a VERTICAL flow, which reads identically in Arabic and
 * English and needs no mirroring — the argument is in the kit model's
 * `slotAt`. The orientation toggle is offered anyway, and when an author picks
 * horizontal the kit mirrors every horizontal axis (and the handles) from the
 * `direction` this screen passes down. Direction is read from the app's
 * language-derived `useDirection()` and never from a preference of its own.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useDirection } from '@/lib/direction-context';
import { useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Breadcrumb } from '@whity/ui/breadcrumb';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import { Switch } from '@whity/ui/switch';
import { RouteFlowEditor, appendStep } from '@whity/ui/route-flow/editor';
import {
  effectiveQuorum,
  resolveTransitions,
  type RouteFlowAudience,
  type RouteFlowGraph,
  type RouteFlowOrientation,
  type RouteFlowQuorum,
  type RouteFlowStep,
  type RouteFlowStepInput,
} from '@whity/ui/route-flow/model';
import { IconDeviceFloppy, IconFileCheck, IconPencil, IconPlus } from '@tabler/icons-react';
import { useLeaveGuard } from '@whity/ui/save-bar';
import { routeSentence } from '@/lib/documents/route-sentence';
import type { RoutingRule } from '@/components/documents/routing-wire';
import { AudienceGroupPicker, type AudienceGroupPreview } from '@whity/ui/audience-group-picker';
import {
  audiencePreviewRequest,
  toAudienceGroupPreview,
  toFlowGraph,
  toGraphRequest,
  type AudiencePreviewWire,
  type RouteTemplateGraphWire,
} from '../types';

interface RoleOption {
  id: number;
  name: string;
}

interface GroupOption {
  id: number;
  name: string;
}

/** WHIT-921: a published document template a final document stage may render with. */
interface FinalTemplateOption {
  id: number;
  title: string;
}

/** The value the template picker uses for "the form's final document template". */
const FORM_DEFAULT = 'form';

/** The two core kinds whose config is `{ role_id }`. */
const ROLE_KINDS = new Set(['role', 'role_below_actor']);
const GROUP_KIND = 'group';

/**
 * What a NEW stage names, in order of preference.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A LIST HERE AND NOT `rules[0]`
 * ─────────────────────────────────────────────────────────────────────────
 * It was `rules[0]`. `GET /api/v1/routing-rules` returns its catalogue sorted by
 * kind, so `rules[0]` is `explicit` — and every stage anybody added therefore
 * arrived as "Specific people, chosen by name", which is:
 *
 *  - unconfigurable HERE, because {@link StageInspector} has editors for role
 *    kinds and for groups and says so plainly for anything else, so the first
 *    thing an author did could not be finished; and
 *  - the enumerate-individuals shape this whole feature exists to replace. A
 *    node is a TYPE of person. Defaulting to the one kind that is a list of
 *    names taught the opposite in the first five seconds.
 *
 * The fix is NOT to reorder the server's response. A client that depends on the
 * order of a catalogue is the defect; the ordering is just what exposed it. So
 * the preference is stated here, and it is stated as the kinds this screen can
 * actually EDIT — which is the property that matters, and which changes when
 * this file changes rather than when a server sorts differently.
 *
 * Falls back to whatever the server does offer if none of these are installed,
 * because a stage has to name something.
 */
const NEW_STAGE_KIND_PREFERENCE = ['role', 'group', 'role_below_actor'];

const QUORUMS: RouteFlowQuorum[] = ['all', 'any', 'majority'];

export function RouteFlowEditorScreen({
  templateId,
  canWrite,
}: {
  templateId: number;
  canWrite: boolean;
}) {
  const { apiClient } = useAuth();
  const t = useTranslation('admin');

  const templateResult = useFetch(async () => {
    const response = await apiClient(`/api/v1/document-route-templates/${templateId}`);
    if (!response.ok) {
      throw new Error(t('routeTemplates.error.loadOne', 'This route template could not be loaded'));
    }
    const body = (await response.json()) as { data: RouteTemplateGraphWire };
    return body.data;
  }, [apiClient, templateId]);

  /** The kinds a stage may name, from the server's own registry. */
  const rulesResult = useFetch(async () => {
    const response = await apiClient('/api/v1/routing-rules');
    if (!response.ok) return [] as RoutingRule[];
    const body = await response.json();
    return (body.data ?? []) as RoutingRule[];
  }, [apiClient]);

  /**
   * Roles and groups for the two core config shapes.
   *
   * A 403 on either is expected rather than exceptional — designing a flow does
   * not imply `roles:read` or `groups:read` — so both fail SOFT into an empty
   * list with a reason, instead of throwing and taking the canvas down with the
   * picker.
   */
  const rolesResult = useFetch(async () => {
    const response = await apiClient('/api/v1/roles?per_page=100');
    if (!response.ok) return [] as RoleOption[];
    const body = await response.json();
    return ((body.data ?? []) as RoleOption[]).map((r) => ({ id: r.id, name: r.name }));
  }, [apiClient]);

  const groupsResult = useFetch(async () => {
    const response = await apiClient('/api/v1/user-groups?per_page=100');
    if (!response.ok) return [] as GroupOption[];
    const body = await response.json();
    return ((body.data ?? []) as GroupOption[]).map((g) => ({ id: g.id, name: g.name }));
  }, [apiClient]);

  // WHIT-921: the published templates a final document stage may render with.
  // Soft on a 403 like the pickers above: the stage can still use the form's.
  const finalTemplatesResult = useFetch(async () => {
    const response = await apiClient('/api/v1/document-sources?kind=template');
    if (!response.ok) return [] as FinalTemplateOption[];
    const body = await response.json();
    return ((body.data ?? []) as Array<{ id: number; title: string; published_version: number | null }>)
      .filter((row) => row.published_version !== null)
      .map((row) => ({ id: row.id, title: row.title }));
  }, [apiClient]);

  if (templateResult.error !== null) {
    return <p className="text-sm text-destructive">{templateResult.error}</p>;
  }
  if (templateResult.data === null) {
    return <p className="text-sm text-muted-foreground">{t('routeTemplates.loading', 'Loading…')}</p>;
  }

  // The editing body mounts only once the design has arrived, so its state can be
  // INITIALISED from it rather than synced into it by an effect. That is not a
  // lint workaround: an effect that copies fetched data into state renders once
  // with an empty canvas and once with the real one, and anything that ran in
  // between — an audience lookup, a fit-to-view — did so against a graph with no
  // nodes in it. Keyed by the template id so navigating between designs starts
  // clean instead of showing the previous one's unsaved edits.
  return (
    <RouteFlowBody
      key={templateId}
      templateId={templateId}
      template={templateResult.data}
      canWrite={canWrite}
      rules={rulesResult.data ?? []}
      roles={rolesResult.data ?? []}
      groups={groupsResult.data ?? []}
      finalTemplates={finalTemplatesResult.data ?? []}
    />
  );
}

function RouteFlowBody({
  templateId,
  template,
  canWrite,
  rules,
  roles,
  groups,
  finalTemplates,
}: {
  templateId: number;
  template: RouteTemplateGraphWire;
  canWrite: boolean;
  rules: RoutingRule[];
  roles: RoleOption[];
  groups: GroupOption[];
  finalTemplates: FinalTemplateOption[];
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { dir } = useDirection();
  const t = useTranslation('admin');

  const [graph, setGraph] = useState<RouteFlowGraph>(() => toFlowGraph(template));
  const [selected, setSelected] = useState<number | null>(null);
  // Top to bottom only (D.2): a route reads like a sentence, so the editor no
  // longer offers the along-the-text layout.
  const orientation: RouteFlowOrientation = 'vertical';
  const [name, setName] = useState(template.name);
  const [renaming, setRenaming] = useState(false);
  const [savedName, setSavedName] = useState(template.name);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  useLeaveGuard(dirty);

  const maxSteps = template.max_steps;
  // WHIT-920: forms that send anonymous requests through this design.
  const anonymousForms = template.anonymous_public_forms ?? [];

  // ── audience resolution ───────────────────────────────────────────────────
  //
  // Keyed by the rule ITSELF (kind + config), not by the step's position. Two
  // stages naming the same rule are one question, and a position-keyed cache
  // would re-ask it on every renumber — which happens on every delete.
  // The count feeds the CANVAS (one number per node, never a roster) and the full
  // preview feeds the INSPECTOR's group picker, which draws the snapshot itself.
  // One request answers both, so they are cached together under one key rather
  // than fetched twice.
  const [audiences, setAudiences] = useState<Record<string, RouteFlowAudience>>({});
  const [previews, setPreviews] = useState<Record<string, AudienceGroupPreview>>({});
  const inFlight = useRef<Set<string>>(new Set());

  const audienceKey = useCallback(
    (step: RouteFlowStep) => `${step.ruleKind}::${JSON.stringify(step.ruleConfig)}`,
    []
  );

  useEffect(() => {
    for (const step of graph.steps) {
      const key = audienceKey(step);
      if (key in audiences || inFlight.current.has(key)) continue;

      // A rule with no configuration yet is not a question worth asking — the
      // server would refuse it, and the refusal would be shown on the card as
      // though the rule were broken rather than merely unfinished. Which
      // endpoint answers depends on the KIND; see `audiencePreviewRequest`.
      const request = audiencePreviewRequest(step.ruleKind, step.ruleConfig);
      if (request === null) continue;

      inFlight.current.add(key);
      void (async () => {
        try {
          const response = await apiClient(request.url, request.init);

          if (!response.ok) {
            const body = (await response.json().catch(() => null)) as { error?: string } | null;
            setAudiences((prev) => ({
              ...prev,
              [key]: {
                count: null,
                unavailableReason:
                  response.status === 403
                    ? t(
                        'routeTemplates.audience.forbidden',
                        'You cannot preview audiences here, so the size of this stage is not shown.'
                      )
                    : (body?.error ??
                      t('routeTemplates.audience.error', 'Audience size could not be resolved.')),
              },
            }));
            return;
          }

          // `total`, not `count` — the field #1003's presenter actually emits.
          const body = (await response.json()) as AudiencePreviewWire;
          setAudiences((prev) => ({ ...prev, [key]: { count: body.data.total } }));
          setPreviews((prev) => ({ ...prev, [key]: toAudienceGroupPreview(body) }));
        } finally {
          inFlight.current.delete(key);
        }
      })();
    }
  }, [apiClient, audienceKey, audiences, graph, t]);

  const audienceFor = useCallback(
    (step: RouteFlowStep): RouteFlowAudience | undefined => audiences[audienceKey(step)],
    [audienceKey, audiences]
  );

  const ruleLabelFor = useCallback(
    (step: RouteFlowStep): string => {
      // WHIT-921: nobody is reached; the card says what happens instead.
      if (step.kind === 'finalise') {
        return t('routeTemplates.final.rule', 'Whity makes the final PDF');
      }
      const rule = rules.find((r) => r.kind === step.ruleKind);
      const base = rule?.label ?? step.ruleKind;

      // The card shows WHICH role or group, not just "everyone holding a role" —
      // otherwise every node in a four-stage approval reads identically.
      if (ROLE_KINDS.has(step.ruleKind)) {
        const roleId = step.ruleConfig.role_id;
        const role = roles.find((r) => r.id === roleId);
        return role !== undefined ? `${base}: ${roleLabel(role.name)}` : base;
      }
      if (step.ruleKind === GROUP_KIND) {
        const groupId = step.ruleConfig.group_id;
        const group = groups.find((g) => g.id === groupId);
        return group !== undefined ? `${base}: ${group.name}` : base;
      }

      return base;
    },
    [groups, roles, rules, t]
  );

  // ── mutation ──────────────────────────────────────────────────────────────

  const change = useCallback((next: RouteFlowGraph) => {
    setGraph(next);
    setDirty(true);
  }, []);

  const addStage = useCallback(() => {
    const offered = new Set(rules.map((r) => r.kind));
    const kind =
      NEW_STAGE_KIND_PREFERENCE.find((k) => offered.has(k)) ??
      rules[0]?.kind ??
      NEW_STAGE_KIND_PREFERENCE[0];

    const next = appendStep(
      graph,
      {
        ruleKind: kind,
        ruleConfig: {},
        label: null,
        decision: false,
        decisionQuorum: null,
        // Explicit rather than left to a default further down. A new stage asks
        // its people to act; that is the ordinary case and the one an author
        // means when they drop a node without touching anything.
        satisfiedBy: 'act',
        // WHIT-920: a new stage can send a request back for changes.
        allowSendBack: true,
      },
      orientation,
      dir
    );
    change(next);
    // The new stage, wherever it went: before the final document stage when
    // there is one.
    const final = graph.steps.find((s) => s.kind === 'finalise');
    setSelected(final !== undefined ? final.position : next.steps[next.steps.length - 1].position);
  }, [change, dir, graph, orientation, rules]);

  // WHIT-921: the final document stage. One per route, always last, and only
  // where PDFs can be made.
  const canFinalise = template.can_finalise === true;
  const hasFinalStage = graph.steps.some((s) => s.kind === 'finalise');
  const addFinalStage = useCallback(() => {
    const next = appendStep(
      graph,
      {
        ruleKind: 'finalise',
        ruleConfig: {},
        label: null,
        decision: false,
        decisionQuorum: null,
        satisfiedBy: 'act',
        allowSendBack: false,
        kind: 'finalise',
        finalTemplateId: null,
      },
      orientation,
      dir
    );
    change(next);
    setSelected(next.steps[next.steps.length - 1].position);
  }, [change, dir, graph, orientation]);

  const updateSelected = useCallback(
    (patch: Partial<RouteFlowStep>) => {
      if (selected === null) return;
      change({
        ...graph,
        steps: graph.steps.map((s) => (s.position === selected ? { ...s, ...patch } : s)),
      });
    },
    [change, graph, selected]
  );

  const save = useCallback(async () => {
    setSaving(true);
    try {
      if (name.trim() !== '' && name !== savedName) {
        const renamed = await apiClient(`/api/v1/document-route-templates/${templateId}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name: name.trim() }),
        });
        if (!renamed.ok) {
          const body = (await renamed.json().catch(() => null)) as { error?: string } | null;
          addToast(body?.error ?? t('routeTemplates.error.rename', 'The route could not be renamed'), 'error');
          return;
        }
        setSavedName(name);
      }
      const response = await apiClient(`/api/v1/document-route-templates/${templateId}/graph`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(toGraphRequest(graph)),
      });

      if (!response.ok) {
        // The server's 422 names the stage and the reason ("Step 3: the 'group'
        // rule needs a 'group_id' ..."), which is exactly what an author needs
        // to act. Shown verbatim rather than replaced.
        const body = (await response.json().catch(() => null)) as { error?: string } | null;
        addToast(
          body?.error ?? t('routeTemplates.error.save', 'The flow could not be saved'),
          'error'
        );
        return;
      }

      const body = (await response.json()) as { data: RouteTemplateGraphWire };
      setGraph(toFlowGraph(body.data));
      setDirty(false);
      addToast(t('routeTemplates.saved', 'Route saved'), 'success');
    } finally {
      setSaving(false);
    }
  }, [addToast, apiClient, graph, name, savedName, t, templateId]);

  const selectedStep = useMemo(
    () => graph.steps.find((s) => s.position === selected) ?? null,
    [graph, selected]
  );

  const atCeiling = graph.steps.length >= maxSteps;
  const sentence = routeSentence(
    graph.steps,
    (step) => {
      if (ROLE_KINDS.has(step.ruleKind)) {
        const role = roles.find((r) => r.id === step.ruleConfig.role_id);
        return role !== undefined ? roleLabel(role.name) : null;
      }
      if (step.ruleKind === GROUP_KIND) return groups.find((g) => g.id === step.ruleConfig.group_id)?.name ?? null;
      if (step.ruleKind === 'finalise') return t('routeTemplates.final.sentence', 'the final PDF');
      return rules.find((r) => r.kind === step.ruleKind)?.label ?? null;
    },
    {
      unnamed: t('routeTemplates.sentence.unnamed', 'A stage'),
      empty: t('routeTemplates.sentence.empty', 'No stages drawn yet'),
      join: (before, next) => t('routeTemplates.sentence.then', '{before}, then {next}', { before, next }),
    }
  );

  return (
    <div className="space-y-4">
      <Breadcrumb
        items={[
          { label: t('routeTemplates.title', 'Routes'), href: '/admin/document-route-templates' },
          { label: name },
        ]}
      />
      <div className="flex flex-wrap items-center gap-2">
        <div className="me-auto min-w-0">
          {renaming ? (
            <Input
              autoFocus
              dir="auto"
              value={name}
              maxLength={160}
              aria-label={t('routeTemplates.rename.label', 'Name of the route')}
              onChange={(e) => {
                setName(e.target.value);
                setDirty(true);
              }}
              onBlur={() => name.trim() !== '' && setRenaming(false)}
              onKeyDown={(e) => e.key === 'Enter' && name.trim() !== '' && setRenaming(false)}
              className="max-w-sm"
            />
          ) : (
            <h1 className="truncate text-lg font-semibold" dir="auto">
              {name}
            </h1>
          )}
          <p className="text-sm text-muted-foreground" data-testid="route-sentence">
            {sentence}
          </p>
        </div>

        {canWrite && (
          <>
            <Button variant="outline" onClick={() => setRenaming(true)} disabled={renaming}>
              <IconPencil className="me-1 size-4" />
              {t('routeTemplates.action.rename', 'Rename')}
            </Button>
            <Button variant="outline" onClick={addStage} disabled={atCeiling}>
              <IconPlus className="me-1 size-4" />
              {t('routeTemplates.action.addStage', 'Add a stage')}
            </Button>
            {canFinalise && !hasFinalStage && (
              <Button variant="outline" onClick={addFinalStage} disabled={atCeiling}>
                <IconFileCheck className="me-1 size-4" />
                {t('routeTemplates.action.addFinal', 'Add the final document')}
              </Button>
            )}
            <Button onClick={save} disabled={!dirty || saving}>
              <IconDeviceFloppy className="me-1 size-4" />
              {t('routeTemplates.action.save', 'Save route')}
            </Button>
          </>
        )}
      </div>

      {anonymousForms.length > 0 &&
        graph.steps.some((step) => step.allowSendBack !== false && step.satisfiedBy !== 'delivery') && (
        <Alert>
          <AlertDescription>
            {t(
              'routeTemplates.anonymousSendBack',
              'Some stages can send a request back for changes, but {forms} takes requests from visitors who do not sign in. Nobody can send those back. Ask visitors to sign in on that form to fix this.',
              { forms: anonymousForms.map((form) => form.name).join(', ') }
            )}
          </AlertDescription>
        </Alert>
      )}

      {atCeiling && (
        <Alert>
          <AlertDescription>
            {t(
              'routeTemplates.atCeiling',
              'This flow has as many stages as this tenant allows ({max}). Raise documents.routing_max_steps to add more.',
              { max: String(maxSteps) }
            )}
          </AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <RouteFlowEditor
          graph={graph}
          onGraphChange={change}
          selectedPosition={selected}
          onSelectStep={setSelected}
          orientation={orientation}
          direction={dir}
          // The tenant's own ceiling, not the kit's readability default: the
          // server is the authority on how many stages may be saved.
          maxNodes={maxSteps}
          readOnly={!canWrite}
          audienceFor={audienceFor}
          ruleLabelFor={ruleLabelFor}
          labels={{
            empty: t('routeTemplates.canvas.empty', 'No stages yet. Add the first one to start the flow.'),
            decision: t('routeTemplates.canvas.decision', 'Decision'),
            delivery: t('routeTemplates.canvas.delivery', 'Sent, not asked'),
            finalise: t('routeTemplates.canvas.finalise', 'Final PDF'),
            reaches: t('routeTemplates.canvas.reaches', 'Reaches'),
            people: t('routeTemplates.canvas.people.other', 'people'),
            person: t('routeTemplates.canvas.people.one', 'person'),
            audienceUnavailable: t('routeTemplates.canvas.audienceUnavailable', 'Audience size unavailable'),
            approved: t('routeTemplates.canvas.approved', 'Approved'),
            rejected: t('routeTemplates.canvas.rejected', 'Rejected'),
            continues: t('routeTemplates.canvas.continues', 'Continues'),
            implicit: t('routeTemplates.canvas.implicit', 'implicit'),
            ends: t('routeTemplates.canvas.ends', 'Ends here'),
            start: t('routeTemplates.canvas.start', 'A document is sent'),
            arrivalsMerge: t(
              'routeTemplates.canvas.arrivalsMerge',
              'Paths merge — 1 item per person'
            ),
            inCycle: t('routeTemplates.canvas.inCycle', 'Can come back round — loops'),
            deleteStep: t('routeTemplates.canvas.deleteStep', 'Delete stage'),
            quorumAll: t('routeTemplates.quorum.all', 'all must approve'),
            quorumAny: t('routeTemplates.quorum.any', 'any one may approve'),
            quorumMajority: t('routeTemplates.quorum.majority', 'a majority must approve'),
            tooManyNodes: t(
              'routeTemplates.canvas.tooMany',
              'This flow is larger than the canvas will draw. Showing the first stages only.'
            ),
          }}
        />

        <aside className="space-y-3 rounded-lg border border-border bg-card p-4">
          {selectedStep === null ? (
            <p className="text-sm text-muted-foreground">
              {t(
                'routeTemplates.inspector.none',
                'Select a stage to edit who it reaches and what it asks of them.'
              )}
            </p>
          ) : selectedStep.kind === 'finalise' ? (
            <FinalStageInspector
              step={selectedStep}
              templates={finalTemplates}
              canFinalise={canFinalise}
              readOnly={!canWrite}
              onChange={updateSelected}
              t={t}
            />
          ) : (
            <StageInspector
              step={selectedStep}
              graph={graph}
              rules={rules}
              roles={roles}
              groups={groups}
              audience={audienceFor(selectedStep)}
              preview={previews[audienceKey(selectedStep)] ?? null}
              readOnly={!canWrite}
              onChange={updateSelected}
              t={t}
            />
          )}
        </aside>
      </div>
    </div>
  );
}

/**
 * "Reaches N people right now", in the form the count calls for.
 *
 * One function because the sentence is rendered in two places — under the group
 * picker and under the stage heading — and a count of one used to read "Reaches
 * 1 people right now" in both (#1042). Singular and plural are separate KEYS,
 * following `tenants.delete.userCount.one` / `.other`: a suffix rule applied to
 * a translated string is an English rule, and the two forms differ by more than
 * an "s" in most languages.
 *
 * This is a one/other split and Arabic has six categories, so "reaches 2" still
 * takes the plural where the dual would be right. That is a limitation of the
 * translator, not of these call sites; a plural-aware `t()` would change this
 * function and nothing else.
 */
function reachesRightNow(t: ReturnType<typeof useTranslation>, count: number): string {
  return count === 1
    ? t('routeTemplates.inspector.reaches.one', 'Reaches 1 person right now')
    : t('routeTemplates.inspector.reaches.other', 'Reaches {count} people right now', {
        count: count.toLocaleString(),
      });
}

/**
 * The panel for the final document stage (WHIT-921). Nobody is reached and
 * nobody acts, so there is no rule, quorum or send-back to set: only its name
 * and the template the PDF is made with.
 */
function FinalStageInspector({
  step,
  templates,
  canFinalise,
  readOnly,
  onChange,
  t,
}: {
  step: RouteFlowStep;
  templates: FinalTemplateOption[];
  canFinalise: boolean;
  readOnly: boolean;
  onChange: (patch: Partial<RouteFlowStep>) => void;
  t: ReturnType<typeof useTranslation>;
}) {
  const chosen = step.finalTemplateId ?? null;
  const missing = chosen !== null && !templates.some((tpl) => tpl.id === chosen);

  return (
    <div className="space-y-3">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">
        {t('routeTemplates.final.heading', 'Final document')}
      </p>

      {!canFinalise && (
        <Alert>
          <AlertDescription className="text-xs">
            {t(
              'routeTemplates.final.unavailable',
              'PDFs can\'t be made on this install, so this route can\'t be saved with a final document. Remove this stage, or ask your administrator to set up the render service.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.inspector.label', 'Label')}</span>
        <Input
          value={step.label ?? ''}
          disabled={readOnly}
          maxLength={160}
          placeholder={t('routeTemplates.final.labelPlaceholder', 'Approval letter')}
          onChange={(e) => onChange({ label: e.target.value === '' ? null : e.target.value })}
        />
      </label>

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.final.template', 'Document template')}</span>
        <Select
          value={chosen === null ? FORM_DEFAULT : String(chosen)}
          disabled={readOnly}
          onValueChange={(v) => onChange({ finalTemplateId: v === FORM_DEFAULT ? null : Number(v) })}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={FORM_DEFAULT}>
              {t('routeTemplates.final.formDefault', 'The form\'s final document template')}
            </SelectItem>
            {missing && (
              <SelectItem value={String(chosen)}>
                {t('routeTemplates.final.unknownTemplate', 'A template you can\'t see')}
              </SelectItem>
            )}
            {templates.map((tpl) => (
              <SelectItem key={tpl.id} value={String(tpl.id)}>
                {tpl.title}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </label>

      <p className="text-xs text-muted-foreground">
        {t(
          'routeTemplates.final.explained',
          'Nobody acts here. When everything before it is done and approved, Whity makes the final PDF from the approved version with this template, gives it its number and verification code, and keeps it on the document. If the PDF can\'t be made right away, the route still finishes and Whity tries again.'
        )}
      </p>
    </div>
  );
}

/**
 * The panel that edits one stage.
 *
 * Deliberately in the HOST and not in the kit: choosing a rule means pickers
 * bound to this app's `/api/v1/roles` and `/api/v1/user-groups`, and the kit
 * cannot fetch. The canvas reports which stage is selected; this decides what
 * that means.
 */
/** WHIT-1016: the most values one stage may ask for; the server refuses more. */
const MAX_STAGE_VALUES = 5;

/**
 * A key for a new value, from its English label: small letters, digits and _,
 * starting with a letter, unique on this stage. Templates print it as
 * `route.value.<key>`, so it never changes once made, even if the label does.
 */
export function stageValueKey(label: string, taken: readonly string[]): string {
  const slug = label
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^[^a-z]+/, '')
    .replace(/_+$/, '')
    .slice(0, 32);
  const base = slug === '' ? 'value' : slug;
  if (!taken.includes(base)) return base;
  for (let n = 2; ; n++) {
    const key = `${base}_${n}`;
    if (!taken.includes(key)) return key;
  }
}

/**
 * The values a stage asks the person letting it through to fill in
 * (WHIT-1016), in English and Arabic. The key is made from the English label
 * when the value is added and is not shown: templates offer the label.
 */
function StageValuesEditor({
  inputs,
  readOnly,
  onChange,
  t,
}: {
  inputs: RouteFlowStepInput[];
  readOnly: boolean;
  onChange: (inputs: RouteFlowStepInput[]) => void;
  t: ReturnType<typeof useTranslation>;
}) {
  const [draft, setDraft] = useState('');
  const update = (index: number, patch: Partial<RouteFlowStepInput>) =>
    onChange(inputs.map((input, i) => (i === index ? { ...input, ...patch } : input)));
  const add = () => {
    const label = draft.trim();
    if (label === '') return;
    onChange([
      ...inputs,
      { key: stageValueKey(label, inputs.map((input) => input.key)), label, labelAr: null, required: true, maxLength: 100 },
    ]);
    setDraft('');
  };

  return (
    <section className="space-y-2" aria-labelledby="stage-values-heading">
      <h3 id="stage-values-heading" className="text-sm font-medium">
        {t('routeTemplates.inspector.values', 'Values to fill in')}
      </h3>
      <p className="text-xs text-muted-foreground">
        {t(
          'routeTemplates.inspector.valuesHelp',
          'The person approving here fills these in, such as a meeting number. Documents and reference numbers can print them.'
        )}
      </p>
      {inputs.map((input, index) => (
        <div key={input.key} className="space-y-2 rounded-control border border-border p-2">
          <label className="block space-y-1">
            <span className="text-xs font-medium">{t('routeTemplates.inspector.valueLabel', 'Name')}</span>
            <Input
              value={input.label}
              maxLength={80}
              disabled={readOnly}
              onChange={(e) => update(index, { label: e.target.value })}
            />
          </label>
          <label className="block space-y-1">
            <span className="text-xs font-medium">{t('routeTemplates.inspector.valueLabelAr', 'Name in Arabic')}</span>
            <Input
              dir="rtl"
              value={input.labelAr ?? ''}
              maxLength={80}
              disabled={readOnly}
              onChange={(e) => update(index, { labelAr: e.target.value === '' ? null : e.target.value })}
            />
          </label>
          <div className="flex items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-sm">
              <Switch
                checked={input.required}
                disabled={readOnly}
                onCheckedChange={(checked) => update(index, { required: checked })}
              />
              {t('routeTemplates.inspector.valueRequired', 'Needed to approve')}
            </label>
            {!readOnly && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onChange(inputs.filter((_, i) => i !== index))}
              >
                {t('routeTemplates.inspector.valueRemove', 'Remove')}
              </Button>
            )}
          </div>
        </div>
      ))}
      {!readOnly && inputs.length < MAX_STAGE_VALUES && (
        <div className="flex items-end gap-2">
          <label className="block flex-1 space-y-1">
            <span className="text-xs font-medium">{t('routeTemplates.inspector.valueNew', 'New value')}</span>
            <Input
              value={draft}
              maxLength={80}
              placeholder={t('routeTemplates.inspector.valueNewPlaceholder', 'For example: Meeting number')}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  add();
                }
              }}
            />
          </label>
          <Button type="button" variant="outline" disabled={draft.trim() === ''} onClick={add}>
            {t('routeTemplates.inspector.valueAdd', 'Add')}
          </Button>
        </div>
      )}
    </section>
  );
}

function StageInspector({
  step,
  graph,
  rules,
  roles,
  groups,
  audience,
  preview,
  readOnly,
  onChange,
  t,
}: {
  step: RouteFlowStep;
  graph: RouteFlowGraph;
  rules: RoutingRule[];
  roles: RoleOption[];
  groups: GroupOption[];
  audience: RouteFlowAudience | undefined;
  preview: AudienceGroupPreview | null;
  readOnly: boolean;
  onChange: (patch: Partial<RouteFlowStep>) => void;
  t: ReturnType<typeof useTranslation>;
}) {
  const isRoleKind = ROLE_KINDS.has(step.ruleKind);
  const isGroupKind = step.ruleKind === GROUP_KIND;

  // THE MERGE, SAID IN FULL — the half of #1058 the card cannot hold.
  //
  // The canvas note on a converging stage is "Paths merge — 1 item per person":
  // true in every case, and it has to be, because notes are joined into one span
  // clamped to `ROUTE_FLOW_MAX_NOTES` lines and a longer one pushes a later note
  // off the card (#1042). What it cannot do in thirty-one characters is tell an
  // author WHICH case they are in — which is the actionable half, and the half
  // #1058 asks for.
  //
  // This panel has room, and it is open at exactly the moment the question is
  // live: the author has selected this stage and is choosing its rule. So the
  // marker stays on the card and the CONSEQUENCE is stated here, next to the
  // picker that decides it.
  //
  // Derived from the same `resolveTransitions` the canvas draws, over the LOCAL
  // graph, so an edge drawn a second ago is already counted. A server-side
  // annotation on the loaded steps would have gone stale the moment the author
  // drew anything, which is precisely when they are reading this.
  const mergesHere = useMemo(
    () => resolveTransitions(graph).merges.includes(step.position),
    [graph, step.position]
  );

  return (
    <div className="space-y-3">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">
        {t('routeTemplates.inspector.stage', 'Stage {n}', { n: String(step.position) })}
      </p>

      {mergesHere && (
        <Alert>
          <AlertDescription className="text-xs">
            {t(
              'routeTemplates.inspector.merges',
              'More than one path reaches this stage. The engine keeps one open item per person, so a second arrival is absorbed only where it reaches people who still hold an open item here; anyone else gets their own item, and the stage settles and continues a second time. A rule resolved against the acting person reaches different people on each path, which is the usual reason that happens.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.inspector.label', 'Label')}</span>
        <Input
          value={step.label ?? ''}
          disabled={readOnly}
          maxLength={160}
          placeholder={t('routeTemplates.inspector.labelPlaceholder', 'Head of department')}
          onChange={(e) => onChange({ label: e.target.value === '' ? null : e.target.value })}
        />
      </label>

      <label className="block space-y-1">
        <span className="text-sm font-medium">{t('routeTemplates.inspector.rule', 'Who it reaches')}</span>
        <Select
          value={step.ruleKind}
          disabled={readOnly}
          onValueChange={(kind) =>
            // The config belongs to the KIND. Carrying it across a kind change
            // would store a `{role_id}` under a rule that expects a `{group_id}`
            // — the exact mismatch #999 refuses on user groups for the same
            // reason, and it would only surface when the flow was finally run.
            onChange({ ruleKind: kind, ruleConfig: {} })
          }
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {rules.map((rule) => (
              <SelectItem key={rule.kind} value={rule.kind}>
                {rule.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </label>

      {isRoleKind && (
        <label className="block space-y-1">
          <span className="text-sm font-medium">{t('routeTemplates.inspector.role', 'Role')}</span>
          {roles.length === 0 ? (
            <p className="text-xs text-muted-foreground">
              {t(
                'routeTemplates.inspector.noRoles',
                'No roles are available to name. You may not have permission to list them.'
              )}
            </p>
          ) : (
            <Select
              value={typeof step.ruleConfig.role_id === 'number' ? String(step.ruleConfig.role_id) : ''}
              disabled={readOnly}
              onValueChange={(v) => onChange({ ruleConfig: { role_id: Number(v) } })}
            >
              <SelectTrigger>
                <SelectValue placeholder={t('routeTemplates.inspector.pickRole', 'Choose a role')} />
              </SelectTrigger>
              <SelectContent>
                {roles.map((role) => (
                  <SelectItem key={role.id} value={String(role.id)}>
                    {roleLabel(role.name)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </label>
      )}

      {/* #1015's kit primitive, composed rather than duplicated.

          It is the same control the linear route composer uses, which is the
          whole reason it is a kit primitive: a route step saying "everyone in
          Instructors" is authored in two places and a second copy is where the
          two drift.

          It also does the thing the provisional <Select> here could not — it
          draws the membership SNAPSHOT beside the choice, in the present tense
          ("reaches N people right now"), labels a sample as a sample from the
          server's own `truncated` flag, and states outright that the rule is
          re-resolved every time it is reached. A group node is exactly where
          that caveat has to be visible: the whole point of naming a group rather
          than people is that the answer changes after you author it. */}
      {isGroupKind && (
        <div className="space-y-1">
          <label className="text-sm font-medium" htmlFor="route-flow-group">
            {t('routeTemplates.inspector.group', 'User group')}
          </label>
          <AudienceGroupPicker
            id="route-flow-group"
            groups={groups}
            value={typeof step.ruleConfig.group_id === 'number' ? step.ruleConfig.group_id : null}
            onChange={(groupId) =>
              onChange({ ruleConfig: groupId === null ? {} : { group_id: groupId } })
            }
            disabled={readOnly}
            unavailableReason={
              groups.length === 0
                ? t(
                    'routeTemplates.inspector.noGroups',
                    'No user groups are available to name. You may not have permission to list them.'
                  )
                : null
            }
            preview={preview}
            // `audience` is the SAME request's answer, so the two cannot disagree:
            // a resolved count means the preview arrived, a null count with a
            // reason means it did not.
            previewStatus={
              step.ruleConfig.group_id === undefined
                ? 'idle'
                : preview !== null
                  ? 'ready'
                  : audience?.count === null
                    ? 'error'
                    : 'loading'
            }
            previewError={audience?.count === null ? (audience.unavailableReason ?? null) : null}
            placeholder={t('routeTemplates.inspector.pickGroup', 'Choose a group')}
            previewCountLabel={(total) => reachesRightNow(t, total)}
            previewDynamicNote={t(
              'routeTemplates.inspector.groupDynamic',
              'This is who the group means right now. It is re-resolved every time the stage is reached, so a document routed next month reaches whoever matches then.'
            )}
          />
        </div>
      )}

      {!isRoleKind && !isGroupKind && (
        <Alert>
          <AlertDescription className="text-xs">
            {t(
              'routeTemplates.inspector.unsupportedKind',
              'This rule kind has no editor here yet. Its configuration is kept exactly as it was saved.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* THE COUNT, BESIDE THE QUORUM. This pairing is the point: an author
          setting "all must approve" on a node that reaches 1,043 people reads
          both facts in one glance, at the moment the decision is made.

          NOT for a group stage. `AudienceGroupPicker` already draws this exact
          sentence, from this exact number, a few rows above — so a group stage
          said "Reaches 2 people right now" twice, once under the picker and once
          under this heading (#1042). The picker's copy is the one to keep: it
          sits beside the sample and the "re-resolved every time" caveat, which
          is what makes the number mean something. */}
      {!isGroupKind && audience !== undefined && audience.count !== null && (
        <p className="rounded bg-muted px-2 py-1 text-xs text-muted-foreground">
          {reachesRightNow(t, audience.count)}
        </p>
      )}

      {/* WHAT THIS STAGE DOES TO THE PEOPLE IT REACHES (#1054/#1064).
          Two switches, and they are mutually exclusive at the CONTROL rather
          than at save: the server refuses the pair at three layers, and an
          affordance whose only outcome is a refusal is worse than no affordance
          — the same argument the canvas already makes for not drawing verdict
          handles on a non-decision step. */}
      <label className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {t('routeTemplates.inspector.decision', 'Requires a decision')}
        </span>
        <Switch
          checked={step.decision}
          disabled={readOnly || step.satisfiedBy === 'delivery'}
          onCheckedChange={(checked) =>
            // Dropping the quorum when a stage stops being a gate: a quorum on a
            // stage that asks for no verdict can never be consulted, and the
            // server refuses to store one. Clearing it here means the refusal is
            // never reached rather than reported.
            onChange({ decision: checked, decisionQuorum: checked ? step.decisionQuorum : null })
          }
        />
      </label>

      <label className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {t('routeTemplates.inspector.delivery', 'Sends without asking')}
        </span>
        <Switch
          checked={step.satisfiedBy === 'delivery'}
          disabled={readOnly || step.decision}
          onCheckedChange={(checked) =>
            onChange({ satisfiedBy: checked ? 'delivery' : 'act' })
          }
        />
      </label>

      {/* WHIT-920: whether a person here may send it back to the applicant for
          changes. Meaningless on a stage that asks nobody anything. */}
      <label className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {t('routeTemplates.inspector.allowSendBack', 'Can be sent back to the applicant')}
        </span>
        <Switch
          checked={step.allowSendBack !== false}
          disabled={readOnly || step.satisfiedBy === 'delivery'}
          onCheckedChange={(checked) => onChange({ allowSendBack: checked })}
        />
      </label>

      {/* WHIT-1016: values the person letting it through fills in, such as a
          committee meeting number. Nobody acts on a stage that only tells. */}
      {step.satisfiedBy !== 'delivery' && (
        <StageValuesEditor
          inputs={step.inputs ?? []}
          readOnly={readOnly}
          onChange={(inputs) => onChange({ inputs })}
          t={t}
        />
      )}

      {/* Said once, under whichever switch is on, rather than as a warning that
          appears after a refusal. An author turning one on needs to know why the
          other went grey, and an author who has turned neither on needs neither
          sentence. */}
      {step.satisfiedBy === 'delivery' && (
        <p className="text-xs text-muted-foreground">
          {t(
            'routeTemplates.inspector.deliveryExplained',
            'Everybody this stage reaches is sent the document and asked for nothing. Their item closes the moment it is sent, so the flow continues immediately and this stage cannot also be a decision.'
          )}
        </p>
      )}
      {step.decision && (
        <p className="text-xs text-muted-foreground">
          {t(
            'routeTemplates.inspector.decisionExcludesDelivery',
            'A decision needs somebody holding the item to answer it, so this stage cannot also send without asking.'
          )}
        </p>
      )}

      {step.decision && (
        <label className="block space-y-1">
          <span className="text-sm font-medium">
            {t('routeTemplates.inspector.quorum', 'What counts as approved')}
          </span>
          <Select
            value={step.decisionQuorum ?? 'inherit'}
            disabled={readOnly}
            onValueChange={(v) =>
              onChange({ decisionQuorum: v === 'inherit' ? null : (v as RouteFlowQuorum) })
            }
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {/* "Inherit" is a real, distinct choice and not a blank: it means
                  "follow the tenant setting, whatever it becomes", which is
                  different from freezing today's answer onto the stage. */}
              <SelectItem value="inherit">
                {t('routeTemplates.quorum.inherit', 'Follow the tenant default ({value})', {
                  value: graph.defaultQuorum,
                })}
              </SelectItem>
              {QUORUMS.map((q) => (
                <SelectItem key={q} value={q}>
                  {q === 'all'
                    ? t('routeTemplates.quorum.all', 'all must approve')
                    : q === 'any'
                      ? t('routeTemplates.quorum.any', 'any one may approve')
                      : t('routeTemplates.quorum.majority', 'a majority must approve')}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {t(
              'routeTemplates.inspector.quorumEffective',
              'Right now this stage is satisfied when: {value}',
              { value: effectiveQuorum(step, graph) }
            )}
          </p>
        </label>
      )}

      {step.decision && (
        <p className="text-xs text-muted-foreground">
          {t(
            'routeTemplates.inspector.edgeHint',
            'Drag from the green handle to draw where an approval goes, and from the red one for a rejection. With no approval edge the flow continues to the next stage; with no rejection edge it stops.'
          )}
        </p>
      )}
    </div>
  );
}
