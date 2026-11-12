'use client';

/**
 * Approvals as a list (AQ.1, `TplApprovalsList`).
 *
 * Reviewing one at a time stays the default; this view lets Ordinary (tier-1)
 * proposals be approved together. Check first (tier 2) and Needs verifying
 * (tier 3) rows can't be ticked: they show Open, which takes the person to that
 * proposal on its own. The server enforces the same rule
 * (`POST /api/me/agent-proposals/bulk-approve` approves only tier 1), so a
 * stale screen can't approve more than it should. Each approved proposal keeps
 * its own Undo, on its card in Decided and in the activity.
 */

import { useMemo, useState } from 'react';
import { DataTable, type DataTableColumn } from '@whity/ui/data-table';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { EmptyState } from '@whity/ui/empty-state';
import { useTranslation } from '@whity/features/i18n';
import { agentName, type AgentProposal, type ProposalWords } from '@/components/agents/proposals';
import { proposalAmount, waitedWords, type WaitedWords } from './list-helpers';

export interface ApprovalsListProps {
  proposals: AgentProposal[];
  words: ProposalWords;
  /** Opens one proposal on its own (the one-at-a-time view). */
  onOpen: (proposal: AgentProposal) => void;
  /** Approves the ticked Ordinary proposals together. Resolves when the server has answered. */
  onApproveTogether: (proposals: AgentProposal[]) => Promise<void>;
  /** Declines the ticked proposals, one request each. */
  onDeclineTogether: (proposals: AgentProposal[]) => Promise<void>;
  busy: boolean;
  now: number;
}

export function ApprovalsList({ proposals, words, onOpen, onApproveTogether, onDeclineTogether, busy, now }: ApprovalsListProps) {
  const t = useTranslation('admin');
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const byId = useMemo(() => new Map(proposals.map((p) => [String(p.id), p])), [proposals]);
  const chosen = [...selected].map((id) => byId.get(id)).filter((p): p is AgentProposal => p !== undefined && p.tier === 1);

  const waited: WaitedWords = {
    minutes: t('approvals.list.waited.minutes', '{n} min'),
    hours: t('approvals.list.waited.hours', '{n} h'),
    days: t('approvals.list.waited.days', '{n} d'),
  };

  const kind = (proposal: AgentProposal) => {
    if (proposal.tier === 3) {
      return (
        <Badge variant="destructive">
          <Icon name="lock" size={12} aria-hidden />
          {t('approvals.kind.verify', 'Needs verifying')}
        </Badge>
      );
    }
    if (proposal.tier === 2) {
      return <Badge variant="warning">{t('approvals.kind.check', 'Check first')}</Badge>;
    }
    return <Badge variant="outline">{t('approvals.kind.ordinary', 'Ordinary')}</Badge>;
  };

  const columns: DataTableColumn<AgentProposal>[] = [
    {
      id: 'proposal',
      header: t('approvals.list.proposal', 'Proposal'),
      enableHiding: false,
      cell: (proposal) => (
        <div className="flex items-center gap-3">
          <span aria-hidden className="flex size-8 shrink-0 items-center justify-center rounded-full bg-info-tint text-info-text">
            <Icon name="robot" size={16} />
          </span>
          <div className="min-w-0">
            <div className="truncate">{proposal.summary}</div>
            <div className="truncate text-sm text-muted-foreground">
              {agentName(proposal, words)} · {t('approvals.list.forYou', 'for you')}
            </div>
          </div>
        </div>
      ),
    },
    {
      id: 'amount',
      header: t('approvals.list.amount', 'Amount'),
      className: 'text-end',
      cell: (proposal) => {
        const amount = proposalAmount(proposal);
        return <span className="font-mono tabular-nums" dir="ltr">{amount ?? '–'}</span>;
      },
    },
    { id: 'kind', header: t('approvals.list.kind', 'Kind'), cell: kind },
    {
      id: 'waiting',
      header: t('approvals.list.waiting', 'Waiting'),
      className: 'text-end',
      cell: (proposal) => <span className="tabular-nums">{waitedWords(proposal.createdAt, now, waited)}</span>,
    },
    {
      id: 'open',
      header: <span className="sr-only">{t('approvals.list.open', 'Open')}</span>,
      enableHiding: false,
      cell: (proposal) =>
        proposal.tier === 1 ? null : (
          <Button variant="outline" size="sm" onClick={() => onOpen(proposal)}>
            {t('approvals.list.open', 'Open')}
          </Button>
        ),
    },
  ];

  if (proposals.length === 0) {
    return (
      <EmptyState
        moment="all-caught-up"
        title={t('approvals.queue.doneTitle', 'All caught up')}
        description={t('approvals.queue.doneDescription', 'Nothing is waiting for you. New requests from agents appear here.')}
      />
    );
  }

  const total = chosen.reduce<{ currency: string | null; minor: number; mixed: boolean }>(
    (sum, proposal) => {
      const amount = proposalAmount(proposal, true);
      if (amount === null) return sum;
      const [currency, value] = amount.split(' ');
      if (sum.currency !== null && sum.currency !== currency) return { ...sum, mixed: true };
      return { currency, minor: sum.minor + Number(value) * 1000, mixed: sum.mixed };
    },
    { currency: null, minor: 0, mixed: false },
  );

  return (
    <div className="grid gap-4">
      <Alert>
        <Icon name="info" size={16} aria-hidden />
        <AlertTitle>{t('approvals.list.onlyOrdinary', 'Only ordinary items can be approved together')}</AlertTitle>
        <AlertDescription>
          {t(
            'approvals.list.onlyOrdinaryDescription',
            'Anything to check first, or that needs verifying, opens on its own so you see its details.',
          )}
        </AlertDescription>
      </Alert>

      <DataTable
        columns={columns}
        data={proposals}
        getRowId={(proposal) => String(proposal.id)}
        selection={{
          selected,
          onSelectedChange: setSelected,
          canSelect: (proposal) => proposal.tier === 1,
          summary: () =>
            total.currency !== null && !total.mixed ? (
              <span dir="ltr" className="font-mono">
                · {total.currency} {(total.minor / 1000).toFixed(3)}
              </span>
            ) : null,
          labels: {
            count: (count) => t('approvals.list.selected', '{count} selected').replace('{count}', String(count)),
            clear: t('approvals.list.clear', 'Clear'),
            selectAll: t('approvals.list.selectAll', 'Select all ordinary items'),
            selectRow: t('approvals.list.selectRow', 'Select'),
          },
          actions: (
            <>
              <Button
                variant="outline"
                size="sm"
                disabled={busy || chosen.length === 0}
                onClick={() => void onDeclineTogether(chosen).then(() => setSelected(new Set()))}
              >
                {t('approvals.decline', 'Decline')}
              </Button>
              <Button
                size="sm"
                disabled={busy || chosen.length === 0}
                onClick={() => void onApproveTogether(chosen).then(() => setSelected(new Set()))}
              >
                <Icon name="check" size={16} aria-hidden />
                {t('approvals.list.approveN', 'Approve {count}').replace('{count}', String(chosen.length))}
              </Button>
            </>
          ),
        }}
      />
    </div>
  );
}
