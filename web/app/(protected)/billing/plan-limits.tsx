'use client';

import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { meterPercent, overBy, shownRows, stateOf, type PlanLimit } from '@/lib/billing/plan-limits';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { cn } from '@whity/ui/utils';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';

/**
 * WHERE THIS WORKSPACE STANDS AGAINST ITS PLAN (WHIT-932; design 3.4
 * `TplPlanLimit`, BM.11, WHIT-1039).
 *
 * The business model promises that passing a limit warns first and nothing is
 * locked or deleted. The server keeps the second half: the work goes ahead and
 * is counted. This is the first half, the warning, shown to whoever can see
 * Billing.
 *
 * A row per limit the plan sets a number for: what counts, a meter, "7 of 5"
 * and when it starts again. Over is amber with stripes and a word, never red
 * and never colour alone; near (80%) is a quiet blue note here only. An
 * unlimited limit is left out: "12 used, no limit" tells nobody anything. A
 * brand feature appears only when it is in use without the plan including it.
 *
 * Numbers stay Western digits and left to right in both languages.
 */

const NUMBER = new Intl.NumberFormat('en-US');

export function PlanLimits() {
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  // Quiet on failure: this section is advice beside the bill, and a workspace
  // that cannot read it has lost nothing it needs to pay or work.
  const { data } = useFetch(async () => {
    const { data: body, response } = await api.GET('/api/v1/billing/limits');
    if (!response.ok) {
      return [] as PlanLimit[];
    }
    return ((body as { data?: PlanLimit[] } | undefined)?.data ?? []) as PlanLimit[];
  }, []);

  const label = (key: string): string => {
    switch (key) {
      case 'members.max':
        return t('billing.limits.staff', 'Staff');
      case 'customers.per_month':
        return t('billing.limits.customers', 'Customers this month');
      case 'agents.runs.per_month':
        return t('billing.limits.agentWork', 'Agent work this month');
      case 'messages.outbound.per_month':
        return t('billing.limits.messagesMonth', 'Messages sent this month');
      case 'documents.render.per_day':
        return t('billing.limits.documentsToday', 'Documents made today');
      case 'documents.render.per_month':
        return t('billing.limits.documentsMonth', 'Documents made this month');
      case 'inventory.items.max':
        return t('billing.limits.items', 'Items in inventory');
      default:
        return t('billing.limits.ownWebAddress', 'Your own web address');
    }
  };

  const help = (row: PlanLimit): string | null => {
    if (row.kind === 'feature') {
      return t('billing.limits.featureNotIncluded', 'In use, but not part of your plan. It keeps working.');
    }
    if (row.key === 'members.max') {
      return t('billing.limits.staffHelp', 'People who work in the workspace. Agents don’t count.');
    }
    if (row.key === 'inventory.items.max') {
      return t('billing.limits.itemsHelp', 'Each size or colour counts. Archived items don’t.');
    }
    if (row.key === 'documents.render.per_day') {
      return t('billing.limits.resetsTomorrow', 'Starts again tomorrow');
    }
    const resets = dates.date(row.resets_at);
    return resets !== null ? t('billing.limits.resets', 'Starts again on {date}', { date: resets }) : null;
  };

  const rows = shownRows(data ?? []);
  if (rows.length === 0) {
    return null;
  }

  const passed = rows.some((row) => row.over);
  const near = !passed && rows.some((row) => stateOf(row) === 'near');

  return (
    <section className="space-y-3" data-testid="plan-limits">
      <h2 className="text-base font-medium">{t('billing.limits.title', 'Your plan’s limits')}</h2>

      {passed && (
        <Alert variant="warning" data-testid="plan-limits-passed">
          <AlertTitle>{t('billing.limits.passed.title', 'Your workspace has gone past a limit of its plan')}</AlertTitle>
          <AlertDescription>
            {t(
              'billing.limits.passed.description',
              'Everything keeps working and nothing is removed. To raise the limit, change your plan or add more.'
            )}
          </AlertDescription>
        </Alert>
      )}
      {near && (
        <Alert variant="info" data-testid="plan-limits-near">
          <AlertTitle>{t('billing.limits.near.title', 'Your workspace is close to a limit of its plan')}</AlertTitle>
          <AlertDescription>
            {t('billing.limits.near.description', 'Nothing changes when you pass it: everything keeps working and you’ll be told.')}
          </AlertDescription>
        </Alert>
      )}

      <ul className="divide-y divide-border rounded-card border border-border bg-card text-sm">
        {rows.map((row) => {
          const state = stateOf(row);
          const name = label(row.key);
          const note = help(row);
          return (
            <li
              key={row.key}
              className="grid min-h-12 items-center gap-x-6 gap-y-2 p-4 sm:grid-cols-[minmax(0,1fr)_200px_auto]"
              data-testid={`plan-limit-${row.key}`}
            >
              <div className="grid gap-0.5">
                <span className="font-medium text-foreground">{name}</span>
                {note && <span className="text-muted-foreground">{note}</span>}
              </div>
              {row.kind === 'count' && row.used !== null && row.limit !== null ? (
                <div className="grid gap-1.5">
                  <div
                    role="meter"
                    aria-label={name}
                    aria-valuemin={0}
                    aria-valuemax={row.limit}
                    aria-valuenow={row.used}
                    className="h-2 overflow-hidden rounded-full bg-muted"
                  >
                    <i
                      className={cn(
                        'block h-full rounded-full',
                        state === 'over'
                          ? // Amber with stripes: never colour alone (the badge says it in words too).
                            'bg-warning bg-[repeating-linear-gradient(135deg,transparent_0_4px,color-mix(in_srgb,var(--card)_45%,transparent)_4px_8px)]'
                          : 'bg-primary'
                      )}
                      style={{ width: `${meterPercent(row)}%` }}
                    />
                  </div>
                  <span dir="ltr" className="justify-self-start text-xs text-muted-foreground tabular-nums">
                    {t('billing.limits.usedOf', '{used} of {limit}', {
                      used: NUMBER.format(row.used),
                      limit: NUMBER.format(row.limit),
                    })}
                  </span>
                </div>
              ) : (
                <span />
              )}
              <div className="justify-self-start sm:justify-self-end">
                {state === 'over' &&
                  (row.kind === 'count' ? (
                    <Badge variant="warning" icon="alert">
                      {t('billing.limits.overBy', '{n} over', { n: NUMBER.format(overBy(row)) })}
                    </Badge>
                  ) : (
                    <Badge variant="warning">{t('billing.limits.notInPlan', 'Not in plan')}</Badge>
                  ))}
                {state === 'near' && <Badge variant="outline">{t('billing.limits.nearBadge', 'Near')}</Badge>}
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
