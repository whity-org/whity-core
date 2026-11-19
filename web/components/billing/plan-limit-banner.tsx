'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useAuth } from '@/lib/auth-context';
import { api } from '@/lib/api/client';
import {
  BANNER_HIDE_DAYS,
  bannerHidden,
  bannerHideKey,
  shownRows,
  type PlanLimit,
} from '@/lib/billing/plan-limits';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';

/** The permission of those who can change the plan: they see the banner, members never do. */
const PLAN_PERMISSION = 'billing:pay';

/**
 * A thin amber line across the app while the workspace is past a limit of its
 * plan (design 3.4 `TplPlanLimit`, BM.11, WHIT-1039). Only for people who can
 * change the plan; "Hide for 7 days" puts it away on this device. Near a limit
 * shows nothing here (Billing says it). A warning, never a block: every word
 * says everything keeps working.
 */
export function PlanLimitBanner() {
  const t = useTranslation('admin');
  const { user, memberships } = useAuth();
  const { hasPermission, loading } = useCapabilities();
  const allowed = !loading && hasPermission(PLAN_PERMISSION);
  const tenantId = user?.tenant_id ?? null;
  const hideKey = bannerHideKey(tenantId);
  const [over, setOver] = useState<PlanLimit[] | null>(null);
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    let stored: string | null = null;
    try {
      stored = window.localStorage.getItem(hideKey);
    } catch {
      stored = null;
    }
    const timer = setTimeout(() => setHidden(bannerHidden(stored)), 0);
    return () => clearTimeout(timer);
  }, [hideKey]);

  useEffect(() => {
    if (!allowed) return;
    let live = true;
    api
      .GET('/api/v1/billing/limits')
      .then(({ data, response }) => (response.ok ? ((data as { data?: PlanLimit[] } | undefined)?.data ?? []) : []))
      .then((rows) => {
        if (live) setOver(shownRows(rows).filter((row) => row.over));
      })
      .catch(() => {
        if (live) setOver([]);
      });
    return () => {
      live = false;
    };
  }, [allowed, tenantId]);

  if (!allowed || hidden || !over || over.length === 0) return null;

  const membership = memberships.find((m) => m.tenant_id === tenantId);
  const workspace = membership?.tenant_name || t('billing.banner.yourWorkspace', 'Your workspace');
  const short = (key: string): string => {
    switch (key) {
      case 'members.max':
        return t('billing.banner.staff', 'staff');
      case 'customers.per_month':
        return t('billing.banner.customers', 'customers');
      case 'agents.runs.per_month':
        return t('billing.banner.agentWork', 'agent work');
      case 'messages.outbound.per_month':
        return t('billing.banner.messages', 'messages');
      case 'documents.render.per_day':
      case 'documents.render.per_month':
        return t('billing.banner.documents', 'documents');
      case 'inventory.items.max':
        return t('billing.banner.items', 'items');
      default:
        return t('billing.banner.ownWebAddress', 'own web address');
    }
  };

  const hide = () => {
    try {
      window.localStorage.setItem(hideKey, new Date(Date.now() + BANNER_HIDE_DAYS * 86_400_000).toISOString());
    } catch {
      // Storage refused: it hides for this visit only.
    }
    setHidden(true);
  };

  return (
    <div
      role="status"
      data-testid="plan-limit-banner"
      className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-warning-tint px-6 py-2 text-sm text-foreground md:px-8"
    >
      <Icon name="alert" size={18} className="text-warning-text" />
      <span className="min-w-0 flex-1">
        <span className="font-semibold">
          {over.length === 1
            ? t('billing.banner.one', '{workspace} has gone past its plan’s {limit} limit.', { workspace, limit: short(over[0].key) })
            : t('billing.banner.many', '{workspace} has gone past {n} of its plan’s limits.', { workspace, n: over.length })}
        </span>{' '}
        {t('billing.banner.keepsWorking', 'Everything keeps working and nothing is removed.')}
      </span>
      <Button asChild size="sm" variant="outline">
        <Link href="/billing">{t('billing.banner.seePlan', 'See plan')}</Link>
      </Button>
      <Button size="sm" variant="ghost" onClick={hide}>
        {t('billing.banner.hide', 'Hide for 7 days')}
      </Button>
    </div>
  );
}
