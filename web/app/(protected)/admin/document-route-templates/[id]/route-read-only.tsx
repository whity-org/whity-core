'use client';

/**
 * A route template on a phone (`PwBuilders`, PW.9): numbered sentences, read-only.
 *
 * The flow canvas needs room for nodes, arrows and an inspector, and the owner
 * answered that reading is enough under 640px: so there is no canvas here, just
 * the stages in order as "who does what", with where a rejection goes. Built
 * from the graph the page already loaded; it changes nothing and fetches
 * nothing. Editing stays on a computer (the bar offers the link).
 */

import Link from 'next/link';
import { IconChevronLeft } from '@tabler/icons-react';
import { OpenOnComputerBar, PhoneReadOnlyNote } from '@whity/ui/open-on-computer';
import {
  effectiveQuorum,
  resolveTransitions,
  stepsInOrder,
  type RouteFlowGraph,
  type RouteFlowStep,
} from '@whity/ui/route-flow/model';
import { roleLabel } from '@whity/features/roles';
import { useTranslation } from '@whity/features/i18n';
import type { RoutingRule } from '@/components/documents/routing-wire';

interface Named {
  id: number;
  name: string;
}

export interface RouteReadOnlyProps {
  name: string;
  graph: RouteFlowGraph;
  rules: RoutingRule[];
  roles: Named[];
  groups: Named[];
}

const ROLE_KINDS = new Set(['role', 'role_below_actor']);

export function RouteReadOnly({ name, graph, rules, roles, groups }: RouteReadOnlyProps) {
  const t = useTranslation('admin');
  const ordered = stepsInOrder(graph.steps);
  const { transitions } = resolveTransitions(graph);
  const numberOf = new Map(ordered.map((step, index) => [step.position, index + 1]));

  /** Who the stage is for, in words: the author's own name for it, else the rule's. */
  const who = (step: RouteFlowStep): string => {
    const label = step.label?.trim() ?? '';
    if (label !== '') return label;
    if (ROLE_KINDS.has(step.ruleKind)) {
      const role = roles.find((r) => r.id === step.ruleConfig.role_id);
      if (role !== undefined) return roleLabel(role.name);
    } else if (step.ruleKind === 'group') {
      const group = groups.find((g) => g.id === step.ruleConfig.group_id);
      if (group !== undefined) return group.name;
    } else {
      const rule = rules.find((r) => r.kind === step.ruleKind);
      if (rule !== undefined) return rule.label;
    }
    return t('routeTemplates.sentence.unnamed', 'A stage');
  };

  const sentence = (step: RouteFlowStep): string => {
    // An author's own name for a stage is already a phrase ("The dean decides"):
    // add no verb to it. Roles, groups and rules are names, so they get one.
    const label = step.label?.trim() ?? '';
    if (label !== '' && step.kind !== 'finalise') return label;
    if (step.kind === 'finalise') return t('routeTemplates.phone.final', 'The final PDF is made');
    if (step.satisfiedBy === 'delivery') {
      return t('routeTemplates.phone.told', '{who} is told', { who: who(step) });
    }
    if (step.decision) return t('routeTemplates.phone.approves', '{who} approves', { who: who(step) });
    return t('routeTemplates.phone.handles', '{who} handles it', { who: who(step) });
  };

  const quorumWords = (step: RouteFlowStep): string | null => {
    if (!step.decision || step.satisfiedBy === 'delivery') return null;
    const quorum = effectiveQuorum(step, graph);
    if (quorum === 'any') return t('routeTemplates.quorum.any', 'any one may approve');
    if (quorum === 'majority') return t('routeTemplates.quorum.majority', 'a majority must approve');
    if (quorum === 'first') return t('routeTemplates.quorum.first', 'the first answer decides');
    return t('routeTemplates.quorum.all', 'all must approve');
  };

  /** Where a verdict leads, only when it is not simply the next step. */
  const detail = (step: RouteFlowStep, index: number): string[] => {
    const lines: string[] = [];
    const quorum = quorumWords(step);
    if (quorum !== null) lines.push(quorum);
    if (!step.decision) return lines;
    const approved = transitions.find((x) => x.from === step.position && x.on === 'approved');
    const next = ordered[index + 1];
    if (approved !== undefined && approved.to !== next?.position) {
      lines.push(
        t('routeTemplates.phone.approvedTo', 'If approved, it goes to step {n}', {
          n: numberOf.get(approved.to) ?? approved.to,
        })
      );
    }
    const rejected = transitions.find((x) => x.from === step.position && x.on === 'rejected');
    lines.push(
      rejected !== undefined
        ? t('routeTemplates.phone.rejectedTo', 'If rejected, it goes to step {n}', {
            n: numberOf.get(rejected.to) ?? rejected.to,
          })
        : t('routeTemplates.phone.rejectedEnds', 'If rejected, it stops here')
    );
    return lines;
  };

  return (
    <div className="space-y-4" data-testid="route-read-only">
      <Link
        href="/admin/document-route-templates"
        className="flex min-h-11 items-center gap-1 text-base text-primary"
      >
        <IconChevronLeft aria-hidden="true" className="size-5 rtl:rotate-180" />
        {t('routeTemplates.title', 'Routes')}
      </Link>
      <h1 className="text-xl font-semibold" dir="auto">
        {name}
      </h1>
      <PhoneReadOnlyNote>
        {t('routeTemplates.phone.note', 'You can read this route here. To change it, open it on a computer.')}
      </PhoneReadOnlyNote>

      {ordered.length === 0 ? (
        <p className="text-base text-muted-foreground">{t('routeTemplates.sentence.empty', 'No stages drawn yet')}</p>
      ) : (
        <ol className="space-y-5" aria-label={t('routeTemplates.phone.steps', 'The steps of this route')}>
          {ordered.map((step, index) => (
            <li key={step.position} className="flex items-start gap-3" data-testid="route-read-only-step">
              <span
                aria-hidden="true"
                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-semibold text-primary-tint-foreground"
              >
                {index + 1}
              </span>
              <div className="min-w-0 pt-0.5">
                <p className="text-base text-foreground" dir="auto">
                  {sentence(step)}
                </p>
                {detail(step, index).map((line) => (
                  <p key={line} className="text-sm text-muted-foreground">
                    {line}
                  </p>
                ))}
              </div>
            </li>
          ))}
        </ol>
      )}

      <OpenOnComputerBar
        labels={{
          open: t('routeTemplates.phone.open', 'Open on a computer'),
          description: t(
            'routeTemplates.phone.sheet',
            'Open this route on a computer to change the steps. Copy the link, or email it to yourself.'
          ),
          copy: t('routeTemplates.phone.copy', 'Copy link'),
          copied: t('routeTemplates.phone.copied', 'Link copied'),
          email: t('routeTemplates.phone.email', 'Email me the link'),
          notNow: t('routeTemplates.phone.notNow', 'Not now'),
          close: t('routeTemplates.phone.close', 'Close'),
          emailSubject: t('routeTemplates.phone.emailSubject', 'A Whity route to change on a computer'),
        }}
      />
    </div>
  );
}
