'use client';

/**
 * Decided (`TplApprovalsDecided`, WHIT-828): what was approved, declined,
 * undone or let expire, grouped by day, newest first. Each row is the outcome
 * as an icon and a word, what the agent asked, the agent and any reason, who
 * decided and when; expired is dashed because it never happened. Filters for
 * outcome, agent and when; Export gives a CSV for the audit.
 *
 * The list is the caller's own proposals (only the granting person decides),
 * so "who decided" is the caller, nobody (expired) or whoever undid it; there
 * is no "Decided by" filter to choose from.
 */

import { useMemo, useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { EmptyState } from '@whity/ui/empty-state';
import { cn } from '@whity/ui/utils';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { FilterMenu } from '@/components/admin/filter-menu';
import { agentName, plainSummary, undoSecondsLeft, type AgentAction, type AgentProposal, type ProposalWords } from '@/components/agents/proposals';
import { decidedCsv, filterDecided, groupByDay, settledAt, type DecidedFilters, type DecidedOutcome } from './list-helpers';

export interface DecidedListProps {
  proposals: AgentProposal[];
  words: ProposalWords;
  /** The signed-in person's name, for what they decided. */
  me: string;
  now: number;
  undoingId: number | null;
  onUndo: (action: AgentAction) => void;
}

const OUTCOMES: DecidedOutcome[] = ['approved', 'declined', 'undone', 'expired'];

const ICON: Record<DecidedOutcome, IconName> = {
  approved: 'check',
  declined: 'close',
  undone: 'undo',
  expired: 'clock',
};

export function DecidedList({ proposals, words, me, now, undoingId, onUndo }: DecidedListProps) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const [filters, setFilters] = useState<DecidedFilters>({ outcome: [], agent: [], days: 30 });

  const outcomeWords: Record<DecidedOutcome, string> = {
    approved: t('approvals.status.approved', 'Approved'),
    declined: t('approvals.status.declined', 'Declined'),
    undone: t('approvals.status.undone', 'Undone'),
    expired: t('approvals.status.expired', 'Expired'),
  };
  const agentOf = (proposal: AgentProposal) => agentName(proposal, words);
  const agents = useMemo(
    () => [...new Set(proposals.filter((p) => p.status !== 'waiting').map((p) => agentName(p, words)))].sort(),
    [proposals, words],
  );
  const rows = filterDecided(proposals, filters, agentOf, now);
  const groups = groupByDay(rows, new Date(now));

  const who = (proposal: AgentProposal): string => {
    if (proposal.status === 'expired') return t('approvals.decided.nobody', 'Nobody');
    if (proposal.status === 'undone') return proposal.action?.undoneBy?.name ?? me;
    return me;
  };
  const detail = (proposal: AgentProposal): string => {
    if (proposal.status === 'expired') return t('approvals.decided.expiredDetail', 'nobody decided in time');
    if (proposal.status === 'undone') return t('approvals.decided.undoneDetail', 'undone after it ran');
    if (proposal.status === 'approved' && (proposal.result as { isError?: boolean } | null)?.isError) {
      return t('approvals.decided.failedDetail', 'approved, but it did not run');
    }
    return t('approvals.list.forYou', 'for you');
  };
  const when = (proposal: AgentProposal, today: boolean): string => {
    const value = settledAt(proposal);
    return (today ? dates.time(value) : dates.dateTime(value)) ?? '';
  };
  const heading = (key: string, sample: string): string =>
    key === 'today'
      ? t('approvals.decided.today', 'Today')
      : key === 'yesterday'
        ? t('approvals.decided.yesterday', 'Yesterday')
        : (dates.date(sample) ?? '');

  const exportCsv = () => {
    const csv = decidedCsv(
      [
        t('approvals.export.when', 'When'),
        t('approvals.export.outcome', 'Outcome'),
        t('approvals.export.request', 'Request'),
        t('approvals.export.agent', 'Agent'),
        t('approvals.export.decidedBy', 'Decided by'),
        t('approvals.export.permissions', 'Permissions'),
      ],
      rows.map((p) => [
        settledAt(p),
        outcomeWords[p.status as DecidedOutcome],
        plainSummary(p, words),
        agentOf(p),
        who(p),
        p.permissions.join(' '),
      ]),
    );
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'approvals-decided.csv';
    link.click();
    URL.revokeObjectURL(url);
  };

  const whenOptions = [
    { value: '7', label: t('approvals.decided.last7', 'Last 7 days') },
    { value: '30', label: t('approvals.decided.last30', 'Last 30 days') },
    { value: '90', label: t('approvals.decided.last90', 'Last 90 days') },
  ];

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <FilterMenu
          testId="decided-filter-outcome"
          label={t('approvals.decided.outcome', 'Outcome')}
          options={OUTCOMES.map((value) => ({ value, label: outcomeWords[value] }))}
          selected={filters.outcome}
          onChange={(outcome) => setFilters({ ...filters, outcome: outcome as DecidedOutcome[] })}
        />
        <FilterMenu
          testId="decided-filter-agent"
          label={t('approvals.decided.agent', 'Agent')}
          options={agents.map((name) => ({ value: name, label: name }))}
          selected={filters.agent}
          onChange={(agent) => setFilters({ ...filters, agent })}
        />
        <FilterMenu
          testId="decided-filter-when"
          single
          label={t('approvals.decided.when', 'When')}
          options={whenOptions}
          selected={filters.days === null ? [] : [String(filters.days)]}
          onChange={(days) => setFilters({ ...filters, days: days.length ? Number(days[0]) : null })}
        />
        <Button variant="outline" className="ms-auto" onClick={exportCsv} disabled={rows.length === 0}>
          <Icon name="download" size={16} aria-hidden />
          {t('approvals.decided.export', 'Export')}
        </Button>
      </div>

      {rows.length === 0 ? (
        <EmptyState
          title={t('approvals.decided.noneTitle', 'Nothing decided matches')}
          description={t('approvals.decided.noneDescription', 'Try another outcome, agent or time.')}
        />
      ) : (
        groups.map((group) => (
          <section key={group.key} className="grid gap-2" aria-label={heading(group.key, group.sample)}>
            <h3 className="text-sm text-muted-foreground">{heading(group.key, group.sample)}</h3>
            <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-card">
              {group.items.map((proposal) => {
                const outcome = proposal.status as DecidedOutcome;
                const action = proposal.action ?? null;
                const canUndo = outcome === 'approved' && action !== null && undoSecondsLeft(action, now) !== null;
                return (
                  <li
                    key={proposal.id}
                    data-testid={`decided-${proposal.id}`}
                    className="grid min-h-16 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-4 py-3 md:grid-cols-[auto_minmax(0,1fr)_12rem_10rem]"
                  >
                    <span
                      aria-hidden
                      className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-full',
                        outcome === 'approved' ? 'bg-success-tint text-success-text' : 'bg-muted text-muted-foreground',
                        outcome === 'expired' && 'border-[1.5px] border-dashed border-input bg-transparent',
                      )}
                    >
                      <Icon name={ICON[outcome]} size={16} />
                    </span>
                    <div className="min-w-0">
                      <div className="truncate">{plainSummary(proposal, words)}</div>
                      <div className="truncate text-sm text-muted-foreground">
                        {agentOf(proposal)} · {detail(proposal)}
                      </div>
                    </div>
                    <div className="col-start-2 flex flex-wrap items-center gap-2 md:col-start-auto">
                      <Badge
                        icon={ICON[outcome]}
                        variant={outcome === 'approved' ? 'success' : undefined}
                        className={outcome === 'approved' ? undefined : 'bg-muted text-muted-foreground'}
                      >
                        {outcomeWords[outcome]}
                      </Badge>
                      {canUndo && action !== null && (
                        <Button variant="outline" size="sm" disabled={undoingId === action.id} onClick={() => onUndo(action)}>
                          <Icon name="undo" size={16} aria-hidden />
                          {t('approvals.undo', 'Undo')}
                        </Button>
                      )}
                    </div>
                    <div className="col-start-2 text-sm md:col-start-auto">
                      <div className="truncate">{who(proposal)}</div>
                      <div className="text-muted-foreground tabular-nums">{when(proposal, group.key === 'today')}</div>
                    </div>
                  </li>
                );
              })}
            </ul>
          </section>
        ))
      )}
    </div>
  );
}
