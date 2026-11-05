'use client';

/**
 * Agents today: the day's agent activity with a summary (done, waiting,
 * undone). Shown only to people whose agents did or asked something today, so
 * a member without agents never sees an empty card.
 */

import { useMemo } from 'react';
import Link from 'next/link';
import { Card } from '@whity/ui/card';
import { ActorChip } from '@whity/ui/actor-chip';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { isToday, type AgentAction, type AgentProposal } from '@/components/agents/proposals';

interface TodayRow {
  id: string;
  agent: string;
  text: string;
  at: string;
  dashed: boolean;
}

export function AgentsToday({ refreshKey }: { refreshKey: number }) {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const dates = useDateDisplay();
  const { apiClient } = useAuth();

  const activity = useFetch<{ actions: AgentAction[]; proposals: AgentProposal[] }>(async () => {
    const [actions, proposals] = await Promise.all([
      apiClient('/api/v1/me/agent-actions'),
      apiClient('/api/v1/me/agent-proposals'),
    ]);
    // A failed read leaves the card out; it is not worth an error on the home.
    return {
      actions: actions.ok ? (((await actions.json()) as { data?: AgentAction[] }).data ?? []) : [],
      proposals: proposals.ok ? (((await proposals.json()) as { data?: AgentProposal[] }).data ?? []) : [],
    };
  }, [apiClient, refreshKey]);

  const unknown = t('dashboard.agent.unknown', 'An agent');
  const name = (subject: { agent: { name: string | null; nameAr: string | null } | null }): string =>
    (locale === 'ar' && subject.agent?.nameAr) || subject.agent?.name || unknown;

  const { rows, done, waiting, undone } = useMemo(() => {
    const data = activity.data;
    if (data === null) return { rows: [] as TodayRow[], done: 0, waiting: 0, undone: 0 };
    const actions = data.actions.filter((a) => isToday(a.createdAt));
    const asked = data.proposals.filter((p) => p.status === 'waiting' && isToday(p.createdAt));
    const list: TodayRow[] = [
      ...actions.map((a) => ({
        id: `action-${a.id}`,
        agent: name(a),
        text: a.summary,
        at: a.createdAt,
        dashed: false,
      })),
      ...asked.map((p) => ({
        id: `proposal-${p.id}`,
        agent: name(p),
        text: p.summary,
        at: p.createdAt,
        dashed: true,
      })),
    ].sort((a, b) => (a.at < b.at ? 1 : -1));
    return {
      rows: list.slice(0, 5),
      done: actions.filter((a) => a.status !== 'undone').length,
      waiting: asked.length,
      undone: actions.filter((a) => a.status === 'undone').length,
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activity.data, locale]);

  if (rows.length === 0) return null;

  return (
    <Card className="gap-3 p-4">
      <div>
        <h2 className="text-lg font-semibold">{t('dashboard.agents.title', 'Agents today')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('dashboard.agents.summary', '{done} done · {waiting} waiting · {undone} undone')
            .replace('{done}', String(done))
            .replace('{waiting}', String(waiting))
            .replace('{undone}', String(undone))}
        </p>
      </div>
      <ul className="grid gap-3">
        {rows.map((row) => (
          <li key={row.id} className="grid gap-1">
            <ActorChip kind="agent" name={row.agent} onBehalfOf={t('dashboard.agents.you', 'you')} />
            <p className={row.dashed ? 'text-sm text-muted-foreground' : 'text-sm text-foreground'}>{row.text}</p>
            <p className="text-xs text-muted-foreground">{dates.time(row.at) ?? ''}</p>
          </li>
        ))}
      </ul>
      <Link href="/admin/approvals" className="text-sm font-medium text-primary underline-offset-2 hover:underline">
        {t('dashboard.agents.open', 'Open Approvals')}
      </Link>
    </Card>
  );
}
