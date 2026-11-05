'use client';

import { useEffect, useMemo, useState } from 'react';
import { AgentActivity } from '@whity/ui/agent-activity';
import { AreaError, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { UndoBar, type UndoBarLabels } from '@whity/ui/undo-bar';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  actionActivity,
  newestFirst,
  proposalActivity,
  undoSecondsLeft,
  undoWindowWords,
  type AgentAction,
  type AgentProposal,
  type ProposalWords,
} from '@/components/agents/proposals';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

/**
 * What agents did for this person and what they asked (A23, AgentActivity,
 * Timeline, UndoBar): a timeline of `/api/me/agent-proposals` and
 * `/api/me/agent-actions`, with Undo while an action's window is open. It is
 * the viewer's own activity only; whoever manages agents has everyone's in
 * WorkspaceActivityPanel. Reads as each action's plain name in the viewer's
 * language.
 */
export function AgentActivityPanel() {
  const t = useTranslation('admin');
  const language = useFormattingLocale();
  const dates = useDateDisplay();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [undoingId, setUndoingId] = useState<number | null>(null);

  // The undo meters count down without a request: the window's end is known.
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(timer);
  }, []);

  const proposals = useFetch<AgentProposal[]>(async () => {
    const response = await apiClient('/api/v1/me/agent-proposals');
    if (!response.ok) throw new Error(t('agentActivity.error', 'Your agents’ activity could not be loaded.'));
    return ((await response.json()) as { data?: AgentProposal[] }).data ?? [];
  }, [apiClient]);

  const actions = useFetch<AgentAction[]>(async () => {
    const response = await apiClient('/api/v1/me/agent-actions');
    if (!response.ok) throw new Error(t('agentActivity.error', 'Your agents’ activity could not be loaded.'));
    return ((await response.json()) as { data?: AgentAction[] }).data ?? [];
  }, [apiClient]);

  const words: ProposalWords = {
    arabic: language === 'ar',
    unknownAgent: t('agentActivity.agent.unknown', 'An agent'),
    minute: t('agentActivity.undo.minute', '1 minute'),
    minutes: t('agentActivity.undo.minutes', '{n} minutes'),
    hour: t('agentActivity.undo.hour', '1 hour'),
    hours: t('agentActivity.undo.hours', '{n} hours'),
    day: t('agentActivity.undo.day', '1 day'),
    days: t('agentActivity.undo.days', '{n} days'),
    acted: t('agentActivity.acted', '{summary}'),
    asked: t('agentActivity.asked', 'Asked: {summary}'),
    approved: t('agentActivity.approved', 'Approved: {summary}'),
    declined: t('agentActivity.declined', 'Declined: {summary}'),
  };
  const you = t('agentActivity.you', 'you');
  const undoBarLabels: UndoBarLabels = {
    undo: t('agentActivity.undo', 'Undo'),
    left: t('agentActivity.undoBar.left', '{time} left to undo'),
    expired: t('agentActivity.undoBar.expired', 'Can no longer be undone'),
    undone: t('agentActivity.undone', 'Undone'),
    undoneBy: t('agentActivity.undoneBy', 'Undone by {name}'),
  };

  /** Undo runs on the server, re-checked; it can refuse, and says why. */
  const undo = async (action: AgentAction) => {
    setUndoingId(action.id);
    try {
      const response = await apiClient(`/api/v1/me/agent-actions/${action.id}/undo`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      });
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      if (!response.ok) {
        addToast(body?.error ?? t('agentActivity.error.undo', 'That could not be undone.'), 'error', 8000);
      } else {
        addToast(t('agentActivity.toast.undone', 'Undone: {summary}', { summary: action.summary }), 'info');
      }
      proposals.refetch();
      actions.refetch();
    } catch {
      addToast(t('agentActivity.error.undo', 'That could not be undone.'), 'error', 8000);
    } finally {
      setUndoingId(null);
    }
  };

  const extra = (action: AgentAction) => {
    if (!action.undoWindowSeconds) return undefined;
    const left = undoSecondsLeft(action, now);
    return (
      <UndoBar
        state={action.status === 'undone' ? 'undone' : left !== null ? 'open' : 'expired'}
        remainingLabel={undoWindowWords(left, words)}
        remaining={left ?? 0}
        total={action.undoWindowSeconds}
        undoneBy={action.undoneBy?.name ?? undefined}
        busy={undoingId === action.id}
        onUndo={() => void undo(action)}
        labels={undoBarLabels}
      />
    );
  };

  const clock = (value: string): string => dates.time(value) ?? '';
  const items = useMemo(
    () =>
      newestFirst([
        ...proposalActivity(proposals.data ?? [], { words, you, time: clock }),
        ...actionActivity(actions.data ?? [], { words, you, time: clock, extra }),
      ]),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [proposals.data, actions.data, now, undoingId, language],
  );

  const error = proposals.error ?? actions.error;
  if (error !== null) {
    return (
      <AreaError
        message={error}
        retryLabel={t('agents.retry', 'Try again')}
        onRetry={() => {
          proposals.refetch();
          actions.refetch();
        }}
      />
    );
  }
  if (proposals.loading || actions.loading) {
    return <LoadingRows headers={[t('agentActivity.table.what', 'What happened')]} label={t('agents.loading', 'Loading…')} />;
  }
  if (items.length === 0) {
    return (
      <EmptyFirstTime
        icon="activity"
        title={t('agentActivity.empty.title', 'No agent activity yet')}
        description={t('agentActivity.empty.description', 'When an agent does something for you, or asks, it shows here.')}
      />
    );
  }
  return (
    <div className="max-w-[720px]">
      <AgentActivity
        items={items}
        filter
        label={t('agentActivity.title', 'Agent activity')}
        labels={{
          all: t('agentActivity.all', 'All'),
          filter: t('agentActivity.filter', 'Filter by agent'),
          for: t('agentActivity.for', 'for'),
        }}
      />
    </div>
  );
}
