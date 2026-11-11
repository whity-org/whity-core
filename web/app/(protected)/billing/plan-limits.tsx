'use client';

import { IconAlertTriangle } from '@tabler/icons-react';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';

/**
 * WHERE THIS WORKSPACE STANDS AGAINST ITS PLAN (WHIT-932).
 *
 * The business model promises that passing a limit warns first and nothing is
 * locked or deleted. The server keeps the second half: the work goes ahead and
 * is counted. This is the first half, the warning, shown to whoever can see
 * Billing, because they are the people who can change the plan.
 *
 * A row per limit the plan sets a number for. An unlimited limit is left out:
 * "12 used, no limit" tells nobody anything they need. A brand feature appears
 * only when it is in use without the plan including it.
 *
 * Numbers stay Western digits and left to right in both languages.
 */

interface PlanLimit {
  key: string;
  kind: 'count' | 'feature';
  used: number | null;
  limit: number | null;
  over: boolean;
  resets_at: string | null;
}

const NUMBER = new Intl.NumberFormat('en-US');

export function PlanLimits() {
  const { apiClient } = useAuth();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  // Quiet on failure: this section is advice beside the bill, and a workspace
  // that cannot read it has lost nothing it needs to pay or work.
  const { data } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/limits');
    if (!res.ok) {
      return [] as PlanLimit[];
    }
    return ((await res.json()).data ?? []) as PlanLimit[];
  }, [apiClient]);

  const label = (key: string): string | null => {
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
      case 'brand.custom_domain':
        return t('billing.limits.ownWebAddress', 'Your own web address');
      default:
        return null;
    }
  };

  const rows = (data ?? []).filter(
    (row) => label(row.key) !== null && (row.kind === 'feature' || row.limit !== null)
  );
  if (rows.length === 0) {
    return null;
  }

  const passed = rows.some((row) => row.over);

  return (
    <section className="space-y-3" data-testid="plan-limits">
      <h2 className="text-base font-medium">{t('billing.limits.title', 'Your plan’s limits')}</h2>

      {passed && (
        <Alert variant="warning" data-testid="plan-limits-passed">
          <IconAlertTriangle aria-hidden="true" />
          <AlertTitle>
            {t('billing.limits.passed.title', 'Your workspace has gone past a limit of its plan')}
          </AlertTitle>
          <AlertDescription>
            {t(
              'billing.limits.passed.description',
              'Everything keeps working and nothing is removed. To raise the limit, change your plan or add more.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <ul className="divide-y rounded-lg border text-sm">
        {rows.map((row) => {
          const resets = dates.date(row.resets_at);
          return (
            <li
              key={row.key}
              className="flex min-h-12 flex-wrap items-center justify-between gap-2 p-3"
              data-testid={`plan-limit-${row.key}`}
            >
              <div className="space-y-0.5">
                <div className="font-medium">{label(row.key)}</div>
                {row.kind === 'feature' ? (
                  <div className="text-muted-foreground">
                    {t('billing.limits.featureNotIncluded', 'In use, but not part of your plan. It keeps working.')}
                  </div>
                ) : (
                  resets !== null && (
                    <div className="text-muted-foreground">
                      {t('billing.limits.resets', 'Starts again on {date}', { date: resets })}
                    </div>
                  )
                )}
              </div>
              <div className="flex items-center gap-2">
                {row.kind === 'count' && row.used !== null && row.limit !== null && (
                  <span dir="ltr" className="font-medium tabular-nums">
                    {NUMBER.format(row.used)} / {NUMBER.format(row.limit)}
                  </span>
                )}
                {row.over && (
                  <Badge variant="warning">{t('billing.limits.over', 'Over the limit')}</Badge>
                )}
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
