'use client';

/**
 * Finish setting up (section 20): the steps that block real work, each with why it
 * matters. For people who can change the workspace, and gone once every step is
 * done. The server says which steps apply and which are done; the words and where
 * each opens are here.
 */

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import type { IconName } from '@whity/ui/icon-map';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';

interface SetupSteps {
  steps: Array<{ key: string; done: boolean }>;
  done: number;
  total: number;
}

export function FinishSettingUp() {
  const t = useTranslation('common');
  const { apiClient } = useAuth();

  const setup = useFetch<SetupSteps | null>(async () => {
    const response = await apiClient('/api/v1/me/setup-steps');
    // Not worth an error on the home: the card just doesn't appear.
    if (!response.ok) return null;
    return ((await response.json()) as { data?: SetupSteps }).data ?? null;
  }, [apiClient]);

  const data = setup.data;
  if (data === null || data.total === 0 || data.done === data.total) return null;

  const words: Record<string, { icon: IconName; label: string; why: string; action: string; href: string }> = {
    branding: {
      icon: 'branding',
      label: t('dashboard.setup.branding', 'Add your logo and colour'),
      why: t('dashboard.setup.brandingWhy', 'Your documents and emails carry them'),
      action: t('dashboard.setup.brandingAction', 'Add'),
      href: '/admin/settings/branding',
    },
    team: {
      icon: 'users',
      label: t('dashboard.setup.team', 'Invite your team'),
      why: t('dashboard.setup.teamWhy', 'Documents can only go to people who are here'),
      action: t('dashboard.setup.teamAction', 'Invite'),
      href: '/admin/users',
    },
    agent: {
      icon: 'robot',
      label: t('dashboard.setup.agent', 'Connect an agent'),
      why: t('dashboard.setup.agentWhy', 'It proposes the routine work, and you decide'),
      action: t('dashboard.setup.agentAction', 'Connect'),
      href: '/admin/agents',
    },
    tax: {
      icon: 'receipt',
      label: t('dashboard.setup.tax', 'Add your tax number'),
      why: t('dashboard.setup.taxWhy', 'Invoices can’t be issued without it'),
      action: t('dashboard.setup.taxAction', 'Add'),
      href: '/admin/settings',
    },
    plan: {
      icon: 'billing',
      label: t('dashboard.setup.plan', 'Choose a plan'),
      why: t('dashboard.setup.planWhy', 'Your workspace needs one to keep running'),
      action: t('dashboard.setup.planAction', 'Choose'),
      href: '/billing',
    },
  };

  const summary = t('dashboard.setup.summary', '{done} of {total} done', { done: data.done, total: data.total });

  return (
    <Card className="gap-3 p-4">
      <div>
        <h2 className="text-lg font-semibold">{t('dashboard.setup.title', 'Finish setting up')}</h2>
        <p className="text-sm text-muted-foreground">{summary}</p>
      </div>
      <div role="img" aria-label={summary} className="h-2 overflow-hidden rounded-full bg-muted">
        <div className="h-full rounded-full bg-primary" style={{ width: `${Math.round((data.done / data.total) * 100)}%` }} />
      </div>
      <ul className="grid gap-3">
        {data.steps.map((step) => {
          const word = words[step.key];
          if (word === undefined) return null;
          return (
            <li key={step.key} className="flex items-center gap-3">
              <span className={step.done ? 'text-success-text' : 'text-muted-foreground'} aria-hidden="true">
                <Icon name={step.done ? 'success' : word.icon} size={16} />
              </span>
              <div className="min-w-0 flex-1">
                <p className={step.done ? 'text-sm text-muted-foreground' : 'text-sm font-medium text-foreground'}>{word.label}</p>
                {!step.done && <p className="text-sm text-muted-foreground">{word.why}</p>}
              </div>
              {!step.done && (
                <Button variant="outline" size="sm" asChild>
                  <Link href={word.href}>{word.action}</Link>
                </Button>
              )}
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
