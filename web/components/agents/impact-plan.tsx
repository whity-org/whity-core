'use client';

/**
 * A Whity Builder publish or roll back on its approval card (TplImpactPlan;
 * register SC.6; WHIT-1042). Each change is a row with its kind in words and an
 * icon, never colour alone: Safe, Changes data (with the records it touches),
 * Deletes data (solid red). The version's tests sit under the changes; failed
 * tests turn Approve off and offer to ask the agent to fix them. A destructive
 * plan needs a tick naming what is lost before Approve turns on. The server
 * holds the same two rules (`tests_failed`, `acknowledge_destructive`).
 */

import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon, type IconName } from '@whity/ui/icon';
import { cn } from '@whity/ui/utils';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { impactPlanBlocks, recordsLost, type ImpactChange, type ImpactPlan } from './proposals';

export interface ImpactPlanCardState {
  /** The person ticked that they understand what a destructive plan loses. */
  acknowledged: boolean;
  onAcknowledge: (value: boolean) => void;
  /** Decline with the failing tests named, for the agent to fix. */
  onAskToFix: (reason: string) => void;
  busy: boolean;
  /** The agent's name, for "Ask Whity Builder to fix it". */
  agent: string;
  /** Still waiting for a decision: only then is there a tick to give. */
  waiting?: boolean;
}

const KIND: Record<ImpactChange['effect'], { icon: IconName; text: string; row: string }> = {
  safe: { icon: 'check', text: 'text-success-text', row: 'border border-border' },
  data: { icon: 'field', text: 'text-warning-text', row: 'border border-warning' },
  destructive: { icon: 'delete', text: 'text-destructive', row: 'border-[1.5px] border-destructive' },
};

/** What the approvals card shows for a proposal with an impact plan, merged over its usual props. */
export function useImpactPlanCard() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const dates = useDateDisplay();

  const kindWords: Record<ImpactChange['effect'], string> = {
    safe: t('impactPlan.kind.safe', 'Safe'),
    data: t('impactPlan.kind.data', 'Changes data'),
    destructive: t('impactPlan.kind.destructive', 'Deletes data'),
  };

  return (plan: ImpactPlan, state: ImpactPlanCardState) => {
    const name = (arabic ? plan.label?.ar : plan.label?.en) || plan.plugin;
    const title =
      plan.kind === 'rollback'
        ? t('impactPlan.title.rollback', 'Roll back “{name}” to version {version}', { name, version: plan.to })
        : t('impactPlan.title.publish', 'Publish “{name}” version {version}', { name, version: plan.to });
    const tests = plan.tests;
    const total = tests ? tests.passed + tests.failed : 0;
    const summary =
      plan.changes.length === 1
        ? t('impactPlan.summary.one', '1 change.')
        : t('impactPlan.summary.many', '{n} changes.', { n: plan.changes.length });
    const lost = recordsLost(plan);
    const failed = (tests?.failed ?? 0) > 0;
    const failureNames = (tests?.failures ?? []).map((f) => (arabic ? f.ar : f.en));
    const ranAt = tests?.ranAt ? dates.dateTime(tests.ranAt) : null;

    const fixReason = `Fix the failing ${tests?.failed === 1 ? 'test' : 'tests'}: ${(tests?.failures ?? []).map((f) => `"${f.en}"`).join(', ')}${
      tests && tests.failed > tests.failures.length ? ` and ${tests.failed - tests.failures.length} more` : ''
    }.`;

    const body = (
      <div className="grid gap-2.5">
        {plan.changes.map((change, index) => (
          <div
            key={`${change.path}-${index}`}
            className={cn(
              'grid grid-cols-1 items-start gap-x-3 gap-y-1 rounded-card bg-card px-3.5 py-3 text-sm sm:grid-cols-[124px_minmax(0,1fr)_auto]',
              KIND[change.effect].row,
            )}
          >
            <span className={cn('inline-flex items-center gap-1.5 text-sm font-semibold', KIND[change.effect].text)}>
              <Icon name={KIND[change.effect].icon} size={14} />
              {kindWords[change.effect]}
            </span>
            <span className="font-medium">{arabic ? change.messageAr : change.message}</span>
            <span className="text-sm text-muted-foreground tabular-nums">
              {change.records !== undefined && change.records > 0
                ? change.records === 1
                  ? t('impactPlan.records.one', '1 record')
                  : t('impactPlan.records.many', '{n} records', { n: change.records })
                : ''}
            </span>
          </div>
        ))}
        {tests && total > 0 ? (
          <div
            className={cn(
              'flex flex-wrap items-center gap-3.5 rounded-card px-3.5 py-3 text-sm',
              failed ? 'bg-error-tint' : 'bg-muted',
            )}
          >
            {failed ? (
              <Badge variant="destructive" icon="error">
                {t('impactPlan.tests.failed', '{failed} of {total} tests failed', { failed: tests.failed, total })}
              </Badge>
            ) : (
              <Badge variant="success" icon="success">
                {total === 1
                  ? t('impactPlan.tests.passedOne', '1 test passed')
                  : t('impactPlan.tests.passed', '{n} tests passed', { n: total })}
              </Badge>
            )}
            {failed && failureNames.length > 0 ? (
              <span>
                {tests.failed > 1
                  ? t('impactPlan.tests.failedNames', '“{first}” and {n} more.', { first: failureNames[0], n: tests.failed - 1 })
                  : t('impactPlan.tests.failedName', '“{first}”.', { first: failureNames[0] })}
              </span>
            ) : (
              ranAt && (
                <span className="text-muted-foreground">
                  {t('impactPlan.tests.ranAt', 'Ran on your data and rolled back, {when}', { when: ranAt })}
                </span>
              )
            )}
          </div>
        ) : (
          <p className="m-0 text-sm text-muted-foreground">{t('impactPlan.tests.none', 'This version has no tests of its own.')}</p>
        )}
        {plan.level === 'destructive' && !failed && state.waiting !== false && (
          <label className="flex items-start gap-2.5 rounded-card border-[1.5px] border-destructive px-3.5 py-3 text-sm">
            <Checkbox
              className="mt-0.5"
              checked={state.acknowledged}
              disabled={state.busy}
              onCheckedChange={(value) => state.onAcknowledge(value === true)}
            />
            <span>
              <span className="font-semibold">
                {lost === 1
                  ? t('impactPlan.ack.one', 'I understand 1 record stops showing what was entered in the parts marked Deletes data.')
                  : t('impactPlan.ack.many', 'I understand {n} records stop showing what was entered in the parts marked Deletes data.', { n: lost })}
              </span>{' '}
              {t('impactPlan.ack.help', 'Approve stays off until this is ticked.')}
            </span>
          </label>
        )}
      </div>
    );

    return {
      title,
      summary,
      changes: [],
      children: body,
      // The agent's manifest isn't edited here: the agent changes its draft.
      onEdit: undefined,
      approveDisabled: impactPlanBlocks(plan, state.acknowledged),
      actions: failed ? (
        <Button variant="outline" size="sm" disabled={state.busy} onClick={() => state.onAskToFix(fixReason)}>
          {t('impactPlan.askToFix', 'Ask {agent} to fix it', { agent: state.agent })}
        </Button>
      ) : undefined,
      undoText: plan.from
        ? t('impactPlan.undo.rollback', 'Can be rolled back to version {version}', { version: plan.from })
        : t('impactPlan.undo.new', 'A new plugin: it can be turned off again'),
    };
  };
}
