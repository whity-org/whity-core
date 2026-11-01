'use client';

/**
 * Approvals (screen A03; TplAgentApprovals, TplAgentApprovalsAr; WHIT-809).
 *
 * Where a person decides what agents asked to do with a permission they set
 * to "Ask first" (register 7.5a, 7.5b). Waiting proposals come one at a time,
 * oldest first, beside today's activity; Decided keeps the record; All
 * activity is the whole timeline, filterable by agent.
 *
 * Only the granting person decides (owner decision 10): the list is the
 * caller's own, from `GET /api/me/agent-proposals`, and approving runs the
 * operation as their delegate after the server checks everything again.
 * Nothing moves on until the server has answered, so a card never claims a
 * decision that did not land.
 */

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@whity/ui/tabs';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { ActionProposal, type ActionProposalLabels } from '@whity/ui/action-proposal';
import { ApprovalQueue, type ApprovalQueueItem } from '@whity/ui/approval-queue';
import { AgentActivity } from '@whity/ui/agent-activity';
import { UndoBar, type UndoBarLabels } from '@whity/ui/undo-bar';
import { EmptyState } from '@whity/ui/empty-state';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { AdminHeader } from '@/components/admin/admin-header';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import {
  actionActivity,
  agentName,
  isToday,
  newestFirst,
  oldestFirst,
  proposalActivity,
  proposalChanges,
  undoSecondsLeft,
  undoWindowWords,
  type AgentAction,
  type AgentProposal,
  type ProposalWords,
} from '@/components/agents/proposals';
import { EditProposalDialog } from './edit-proposal-dialog';

export default function ApprovalsPage() {
  const t = useTranslation('admin');
  const language = useFormattingLocale();
  const dates = useDateDisplay();
  // Times through the shared date display: local, and nothing at all when the
  // workspace hides dates.
  const clock = (value: string): string => dates.time(value) ?? '';
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [tab, setTab] = useState('waiting');
  const [currentId, setCurrentId] = useState<string | undefined>(undefined);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [editing, setEditing] = useState<AgentProposal | null>(null);
  const [undoingId, setUndoingId] = useState<number | null>(null);

  // The undo meters count down without a request: the window's end is known.
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(timer);
  }, []);

  const proposals = useFetch<AgentProposal[]>(async () => {
    const response = await apiClient('/api/v1/me/agent-proposals');
    if (!response.ok) {
      throw new Error(t('approvals.error.list', 'Your approvals could not be loaded.'));
    }
    const body = (await response.json()) as { data?: AgentProposal[] };
    return body.data ?? [];
  }, [apiClient]);

  // What agents did, Allowed calls included (WHIT-812). A failed read leaves the
  // timeline to the proposals rather than failing the page.
  const actions = useFetch<AgentAction[]>(async () => {
    const response = await apiClient('/api/v1/me/agent-actions');
    if (!response.ok) return [];
    const body = (await response.json()) as { data?: AgentAction[] };
    return body.data ?? [];
  }, [apiClient]);

  const all = useMemo(() => proposals.data ?? [], [proposals.data]);
  const waiting = useMemo(() => oldestFirst(all.filter((p) => p.status === 'waiting')), [all]);
  const decided = useMemo(() => all.filter((p) => p.status !== 'waiting'), [all]);

  const you = t('approvals.you', 'you');
  const words: ProposalWords = {
    arabic: language === 'ar',
    unknownAgent: t('approvals.agent.unknown', 'An agent'),
    minute: t('approvals.undo.minute', '1 minute'),
    minutes: t('approvals.undo.minutes', '{n} minutes'),
    hour: t('approvals.undo.hour', '1 hour'),
    hours: t('approvals.undo.hours', '{n} hours'),
    day: t('approvals.undo.day', '1 day'),
    days: t('approvals.undo.days', '{n} days'),
    acted: t('approvals.activity.acted', '{summary}'),
    asked: t('approvals.activity.asked', 'Asked: {summary}'),
    approved: t('approvals.activity.approved', 'Approved: {summary}'),
    declined: t('approvals.activity.declined', 'Declined: {summary}'),
  };
  const labels: ActionProposalLabels = {
    for: t('approvals.for', 'for'),
    waiting: t('approvals.status.waiting', 'Waiting for your approval'),
    approved: t('approvals.status.approved', 'Approved'),
    declined: t('approvals.status.declined', 'Declined'),
    expired: t('approvals.status.expired', 'Expired'),
    undone: t('approvals.status.undone', 'Undone'),
    undoneBy: t('approvals.status.undoneBy', 'Undone by {name}'),
    approve: t('approvals.approve', 'Approve'),
    decline: t('approvals.decline', 'Decline'),
    edit: t('approvals.edit', 'Edit'),
    undo: t('approvals.undo', 'Undo'),
    askAgain: t('approvals.askAgain', 'Ask again'),
    uses: t('approvals.uses', 'Uses'),
    reversible: t('approvals.reversible', 'Can be undone'),
    reversibleFor: t('approvals.reversibleFor', 'Can be undone for {window}'),
    irreversible: t('approvals.irreversible', "Can't be undone"),
    before: t('approvals.before', 'Now'),
    after: t('approvals.after', 'After'),
    showAll: t('approvals.showAll', 'Show all {count} changes'),
    showLess: t('approvals.showLess', 'Show less'),
  };

  const undoBarLabels: UndoBarLabels = {
    undo: t('approvals.undo', 'Undo'),
    left: t('approvals.undoBar.left', '{time} left to undo'),
    expired: t('approvals.undoBar.expired', 'Can no longer be undone'),
    undone: t('approvals.status.undone', 'Undone'),
    undoneBy: t('approvals.status.undoneBy', 'Undone by {name}'),
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
        addToast(body?.error ?? t('approvals.error.undo', 'That could not be undone.'), 'error', 8000);
      } else {
        addToast(t('approvals.toast.undone', 'Undone: {summary}').replace('{summary}', action.summary), 'info');
      }
      proposals.refetch();
      actions.refetch();
    } catch {
      addToast(t('approvals.error.undo', 'That could not be undone.'), 'error', 8000);
    } finally {
      setUndoingId(null);
    }
  };

  const cardProps = (proposal: AgentProposal) => {
    const action = proposal.action ?? null;
    const canUndo = action !== null && undoSecondsLeft(action, now) !== null;
    return {
    agent: agentName(proposal, words),
    onBehalfOf: you,
    title: proposal.summary,
    changes: proposalChanges(proposal),
    scope: proposal.permissions,
    // No undo window means the server will not undo it. Once run, only while
    // its window is open: past that, the card says it can't be undone.
    reversible: action === null ? Boolean(proposal.undoWindowSeconds) : canUndo || action.status === 'undone',
    // The window means something only while it can still be used.
    undoWindow: action === null || canUndo ? undoWindowWords(proposal.undoWindowSeconds, words) : undefined,
    status: proposal.status,
    decidedAt: proposal.decidedAt ? clock(proposal.decidedAt) : undefined,
    undoneBy: action?.undoneBy?.name ?? undefined,
    busy: action !== null && undoingId === action.id,
    onUndo: canUndo && action !== null ? () => void undo(action) : undefined,
    labels,
    };
  };

  /** After a decision, show the next one in the queue, never jump back to the start. */
  const advanceFrom = (id: number) => {
    const index = waiting.findIndex((p) => p.id === id);
    const next = waiting[index + 1] ?? waiting[index - 1];
    setCurrentId(next ? String(next.id) : undefined);
  };

  const decide = async (proposal: AgentProposal, verdict: 'approve' | 'decline', args?: Record<string, unknown>) => {
    setBusyId(proposal.id);
    try {
      const response = await apiClient(`/api/v1/me/agent-proposals/${proposal.id}/${verdict}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(args ? { arguments: args } : {}),
      });
      const body = (await response.json().catch(() => null)) as
        | { data?: AgentProposal | null; error?: string }
        | null;
      if (!response.ok) {
        addToast(
          body?.error ?? t('approvals.error.decide', 'That decision could not be saved. Try again.'),
          'error',
          6000,
        );
        return;
      }
      const result = body?.data?.result as { isError?: boolean } | null | undefined;
      if (verdict === 'approve' && result?.isError) {
        addToast(
          t('approvals.toast.failed', 'Approved, but it did not run: {summary}').replace('{summary}', proposal.summary),
          'error',
          8000,
        );
      } else {
        // 7.5c: ten seconds with Undo on the toast, then Undo stays on the card
        // and the activity row for the rest of the window.
        const ran = verdict === 'approve' ? (body?.data?.action ?? null) : null;
        addToast(
          (verdict === 'approve'
            ? t('approvals.toast.approved', 'Approved: {summary}')
            : t('approvals.toast.declined', 'Declined: {summary}')
          ).replace('{summary}', proposal.summary),
          verdict === 'approve' ? 'success' : 'info',
          10000,
          ran !== null && ran.undoableUntil
            ? { label: t('approvals.undo', 'Undo'), onClick: () => void undo(ran) }
            : undefined,
        );
      }
      advanceFrom(proposal.id);
      setEditing(null);
      proposals.refetch();
      actions.refetch();
      window.dispatchEvent(new Event(WAITING_CHANGED_EVENT));
    } catch {
      addToast(t('approvals.error.decide', 'That decision could not be saved. Try again.'), 'error', 6000);
    } finally {
      setBusyId(null);
    }
  };

  const queueItems: ApprovalQueueItem[] = waiting.map((proposal) => ({
    id: String(proposal.id),
    ...cardProps(proposal),
    busy: busyId === proposal.id,
    onApprove: () => void decide(proposal, 'approve'),
    onDecline: () => void decide(proposal, 'decline'),
    onEdit: () => setEditing(proposal),
  }));

  // Derived each render: a few dozen rows at most (the list is capped at 50).
  const actionExtra = (action: AgentAction) => {
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
  const activity = newestFirst([
    ...proposalActivity(all, { words, you, time: clock }),
    ...actionActivity(actions.data ?? [], { words, you, time: clock, extra: actionExtra }),
  ]);
  const today = activity.filter((item) => item.dateTime !== undefined && isToday(item.dateTime));

  return (
    <div>
      <AdminHeader
        title={t('approvals.title', 'Approvals')}
        description={t('approvals.description', 'Agents ask here before they do anything you set to “Ask first”.')}
        action={
          <Button variant="outline" asChild>
            <Link href="/admin/ai-principals">
              <Icon name="scope" size={16} />
              {t('approvals.scopes', 'Agent scopes')}
            </Link>
          </Button>
        }
      />

      {proposals.error ? (
        <p className="rounded-md border border-border bg-muted p-4 text-sm text-muted-foreground">{proposals.error}</p>
      ) : (
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList variant="line">
            <TabsTrigger value="waiting">
              {t('approvals.tab.waiting', 'Waiting')}
              {waiting.length > 0 && (
                <span className="ms-1.5 text-xs text-muted-foreground tabular-nums">{waiting.length}</span>
              )}
            </TabsTrigger>
            <TabsTrigger value="decided">{t('approvals.tab.decided', 'Decided')}</TabsTrigger>
            <TabsTrigger value="activity">{t('approvals.tab.activity', 'All activity')}</TabsTrigger>
          </TabsList>

          <TabsContent value="waiting" className="mt-6">
            {proposals.loading && !proposals.data ? (
              <p className="text-sm text-muted-foreground">{t('approvals.loading', 'Loading…')}</p>
            ) : (
              <div className="grid gap-6 lg:grid-cols-[minmax(0,560px)_minmax(0,1fr)]">
                <ApprovalQueue
                  items={queueItems}
                  currentId={currentId}
                  onCurrentChange={setCurrentId}
                  label={t('approvals.title', 'Approvals')}
                  labels={{
                    position: t('approvals.queue.position', '{index} of {count} waiting'),
                    order: t('approvals.queue.order', 'oldest first'),
                    previous: t('approvals.queue.previous', 'Previous'),
                    next: t('approvals.queue.next', 'Next'),
                    doneTitle: t('approvals.queue.doneTitle', 'All caught up'),
                    doneDescription: t(
                      'approvals.queue.doneDescription',
                      'Nothing is waiting for you. New requests from agents appear here.',
                    ),
                  }}
                />
                <Card className="h-fit gap-3 p-4">
                  <div>
                    <h2 className="text-lg font-semibold">{t('approvals.today.title', 'Today')}</h2>
                    <p className="text-sm text-muted-foreground">
                      {t('approvals.today.description', 'What agents asked, and what you decided')}
                    </p>
                  </div>
                  {today.length > 0 ? (
                    <AgentActivity items={today} label={t('approvals.today.title', 'Today')} labels={{ for: labels.for }} />
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      {t('approvals.today.empty', 'Nothing from agents yet today.')}
                    </p>
                  )}
                </Card>
              </div>
            )}
          </TabsContent>

          <TabsContent value="decided" className="mt-6">
            {decided.length === 0 ? (
              <EmptyState
                title={t('approvals.decided.emptyTitle', 'Nothing decided yet')}
                description={t('approvals.decided.emptyDescription', 'Proposals you approve or decline stay here.')}
              />
            ) : (
              <div className="grid max-w-[560px] gap-4">
                {decided.map((proposal) => (
                  <ActionProposal key={proposal.id} {...cardProps(proposal)} />
                ))}
              </div>
            )}
          </TabsContent>

          <TabsContent value="activity" className="mt-6">
            {activity.length === 0 ? (
              <EmptyState
                title={t('approvals.activity.emptyTitle', 'No agent activity')}
                description={t(
                  'approvals.activity.emptyDescription',
                  'When an agent asks for something, it shows here.',
                )}
              />
            ) : (
              <div className="max-w-[720px]">
                <AgentActivity
                  items={activity}
                  filter
                  label={t('approvals.tab.activity', 'All activity')}
                  labels={{ all: t('approvals.activity.all', 'All'), filter: t('approvals.activity.filter', 'Filter by agent'), for: labels.for }}
                />
              </div>
            )}
          </TabsContent>
        </Tabs>
      )}

      {editing && (
        <EditProposalDialog
          proposal={editing}
          busy={busyId === editing.id}
          onCancel={() => setEditing(null)}
          onApprove={(args) => void decide(editing, 'approve', args)}
        />
      )}
    </div>
  );
}
