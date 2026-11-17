'use client';

/**
 * Finish setting up (section 20): the steps that block real work, each with why it
 * matters, and gone once every step is done. Core's steps are for people who can
 * change the workspace; the server says which apply and which are done, and the
 * words and where each opens are here.
 *
 * Apps add their own points after core's (SDK 1.115, H.5), with their own words
 * and link, for people holding the app's permission. Each app's route says which
 * are done; an app whose route fails is left off, and the rest still shows.
 */

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { Lottie } from '@whity/ui/lottie';
import type { IconName } from '@whity/ui/icon-map';
import { useTranslation } from '@whity/features/i18n';
import { api, apiFetch } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { iconNames } from '@whity/ui/icon-map';
import type { DashboardSetup } from './use-dashboard';

interface SetupSteps {
  steps: Array<{ key: string; done: boolean }>;
  done: number;
  total: number;
}

/** Set while the checklist is unfinished, so its completion can be marked once. */
const SETUP_PENDING_KEY = 'whity.setup.pending';

/** A step on the card, core's or an app's, with its words already chosen. */
interface Row {
  key: string;
  done: boolean;
  icon: IconName;
  label: string;
  why?: string;
  action: string;
  href: string;
}

export function FinishSettingUp({ canSetUp, apps }: { canSetUp: boolean; apps: DashboardSetup[] }) {
  const t = useTranslation('common');

  const setup = useFetch<SetupSteps | null>(async () => {
    if (!canSetUp) return { steps: [], done: 0, total: 0 };
    const { data: body, response } = await api.GET('/api/v1/me/setup-steps');
    // Not worth an error on the home: the card just doesn't appear.
    if (!response.ok) return null;
    return ((body as { data?: SetupSteps } | undefined)?.data) ?? null;
  }, [canSetUp]);

  // Each app's own answer; an app whose route fails contributes nothing.
  const appStatus = useFetch<Record<string, Record<string, boolean>>>(async () => {
    const answers = await Promise.all(
      apps.map(async (app) => {
        try {
          const response = await apiFetch(app.status);
          if (!response.ok) return null;
          const body = (await response.json()) as { data?: { points?: Record<string, boolean> } };
          return [app.plugin, body.data?.points ?? {}] as const;
        } catch {
          return null;
        }
      }),
    );
    return Object.fromEntries(answers.filter((answer) => answer !== null));
  }, [apps]);

  const appRows: Row[] = [];
  for (const app of apps) {
    const done = appStatus.data?.[app.plugin];
    if (done === undefined) continue;
    for (const point of app.points) {
      appRows.push({
        key: `${app.plugin}:${point.id}`,
        done: done[point.id] === true,
        icon: iconNames.includes(point.icon as IconName) ? (point.icon as IconName) : 'plugin',
        label: point.label ?? point.id,
        why: point.why ?? undefined,
        action: point.action ?? t('dashboard.setup.appAction', 'Set up'),
        href: point.href,
      });
    }
  }

  const core = setup.data;
  // Undecided until every app has answered, so the card never says "ready" early.
  const data =
    (core === null && appRows.length === 0) || (apps.length > 0 && appStatus.data === null)
      ? null
      : {
          done: (core?.done ?? 0) + appRows.filter((row) => row.done).length,
          total: (core?.total ?? 0) + appRows.length,
        };
  const complete = data !== null && data.total > 0 && data.done === data.total;
  const pending = data !== null && data.total > 0 && data.done < data.total;

  // Company ready (LT.4, setup-done): shown once, on the first visit after the
  // last step is done, and only to someone who saw the checklist unfinished on
  // this device, so a workspace set up long ago gets no surprise.
  const [ready, setReady] = useState(false);
  useEffect(() => {
    try {
      if (pending) localStorage.setItem(SETUP_PENDING_KEY, '1');
      else if (complete && localStorage.getItem(SETUP_PENDING_KEY) === '1') {
        localStorage.removeItem(SETUP_PENDING_KEY);
        // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mounting
        setReady(true);
      }
    } catch {
      // No storage, no celebration; the home works the same.
    }
  }, [pending, complete]);

  if (ready) {
    return (
      <Card className="items-center gap-2 p-4 text-center" data-slot="setup-ready">
        <Lottie name="setup-done" className="h-[135px] w-[200px]" />
        <h2 className="text-lg font-semibold">{t('dashboard.setup.ready', 'Your workspace is ready')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('dashboard.setup.readyHelp', 'Every step is done. You can change any of it in Settings.')}
        </p>
        <Button variant="outline" size="sm" onClick={() => setReady(false)}>
          {t('dashboard.setup.readyDismiss', 'Close')}
        </Button>
      </Card>
    );
  }

  if (data === null || !pending) return null;

  const words: Record<string, Omit<Row, 'key' | 'done'>> = {
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

  const rows: Row[] = [
    ...(core?.steps ?? []).flatMap((step) => (words[step.key] ? [{ key: step.key, done: step.done, ...words[step.key] }] : [])),
    ...appRows,
  ];
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
        {rows.map((step) => {
          const word = step;
          return (
            <li key={step.key} className="flex items-center gap-3">
              <span className={step.done ? 'text-success-text' : 'text-muted-foreground'} aria-hidden="true">
                <Icon name={step.done ? 'success' : word.icon} size={16} />
              </span>
              <div className="min-w-0 flex-1">
                <p className={step.done ? 'text-sm text-muted-foreground' : 'text-sm font-medium text-foreground'}>{word.label}</p>
                {!step.done && word.why && <p className="text-sm text-muted-foreground">{word.why}</p>}
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
