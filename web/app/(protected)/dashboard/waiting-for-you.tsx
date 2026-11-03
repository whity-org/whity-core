'use client';

/**
 * Waiting for you (H.2): inbox documents and agent proposals in one list,
 * oldest first, with the decision inline. Until a merged route exists (S2) the
 * list is merged here from each inbox source and the person's proposals.
 *
 * Proposals are dashed until a person decides. The home has no filled main
 * button, so every decision is an outline button.
 */

import { useMemo } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { parseWireDate } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import type { AgentAction, AgentProposal } from '@/components/agents/proposals';
import {
  routingMeta,
  type InboxItem,
  type InboxListResponse,
  type InboxSourcesResponse,
} from '@/components/documents/routing-wire';
import { ago } from './relative-time';

/** At most this many rows show; the rest are "and N more". */
const MAX_ROWS = 6;

interface WaitingRow {
  key: string;
  at: number;
  kind: 'proposal' | 'document';
  title: string;
  from: string;
  when: string | null;
  proposal?: AgentProposal;
  href?: string;
}

export function WaitingForYou({ onChange }: { onChange?: () => void }) {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const router = useRouter();

  const failed = t('dashboard.waiting.error', 'What is waiting for you could not be loaded.');

  const waiting = useFetch<{ items: InboxItem[]; proposals: AgentProposal[]; inboxCount: number }>(async () => {
    const [sourcesResponse, proposalsResponse] = await Promise.all([
      apiClient('/api/v1/me/inbox/sources'),
      apiClient('/api/v1/me/agent-proposals'),
    ]);
    if (!sourcesResponse.ok || !proposalsResponse.ok) throw new Error(failed);
    const sources = ((await sourcesResponse.json()) as InboxSourcesResponse).data ?? [];
    const proposals = ((await proposalsResponse.json()) as { data?: AgentProposal[] }).data ?? [];
    const lists = await Promise.all(
      sources.map(async (source) => {
        const response = await apiClient(`/api/v1/me/inbox?source=${encodeURIComponent(source.key)}`);
        if (!response.ok) throw new Error(failed);
        return ((await response.json()) as InboxListResponse).data ?? [];
      })
    );
    return {
      items: lists.flat(),
      proposals: proposals.filter((p) => p.status === 'waiting'),
      inboxCount: sources.reduce((sum, s) => sum + s.open_count, 0),
    };
  }, [apiClient]);

  const unknownAgent = t('dashboard.agent.unknown', 'An agent');
  const rows = useMemo<WaitingRow[]>(() => {
    const data = waiting.data;
    if (data === null) return [];
    const documents: WaitingRow[] = data.items.map((item) => {
      const meta = routingMeta(item);
      return {
        key: `document-${item.id}`,
        at: parseWireDate(item.timestamp)?.getTime() ?? 0,
        kind: 'document',
        title: item.title,
        from: item.subtitle ?? '',
        when: item.timestamp,
        href: meta === null ? undefined : `/admin/document-routing/${meta.document_id}`,
      };
    });
    const agents: WaitingRow[] = data.proposals.map((proposal) => ({
      key: `proposal-${proposal.id}`,
      at: parseWireDate(proposal.createdAt)?.getTime() ?? 0,
      kind: 'proposal',
      title: proposal.summary,
      from: (locale === 'ar' && proposal.agent?.nameAr) || proposal.agent?.name || unknownAgent,
      when: proposal.createdAt,
      proposal,
    }));
    return [...documents, ...agents].sort((a, b) => a.at - b.at);
  }, [waiting.data, locale, unknownAgent]);

  const undo = async (action: AgentAction) => {
    const response = await apiClient(`/api/v1/me/agent-actions/${action.id}/undo`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    addToast(
      response.ok
        ? t('dashboard.waiting.undone', 'Undone: {summary}').replace('{summary}', action.summary)
        : t('dashboard.waiting.undoFailed', 'That could not be undone.'),
      response.ok ? 'info' : 'error',
      response.ok ? 3000 : 8000
    );
    waiting.refetch();
    onChange?.();
  };

  const decide = async (proposal: AgentProposal, verdict: 'approve' | 'decline') => {
    const decideFailed = t('dashboard.waiting.decideFailed', 'That decision could not be saved. Try again.');
    try {
      const response = await apiClient(`/api/v1/me/agent-proposals/${proposal.id}/${verdict}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{}',
      });
      const body = (await response.json().catch(() => null)) as
        | { data?: (AgentProposal & { action?: AgentAction | null }) | null; error?: string }
        | null;
      if (!response.ok) {
        addToast(body?.error ?? decideFailed, 'error', 6000);
        return;
      }
      // Approving shows the Undo toast, as the Approvals page does.
      const ran = verdict === 'approve' ? (body?.data?.action ?? null) : null;
      addToast(
        (verdict === 'approve'
          ? t('dashboard.waiting.approved', 'Approved: {summary}')
          : t('dashboard.waiting.declined', 'Declined: {summary}')
        ).replace('{summary}', proposal.summary),
        verdict === 'approve' ? 'success' : 'info',
        10000,
        ran !== null && ran.undoableUntil
          ? { label: t('dashboard.waiting.undo', 'Undo'), onClick: () => void undo(ran) }
          : undefined
      );
      window.dispatchEvent(new Event(WAITING_CHANGED_EVENT));
      waiting.refetch();
      onChange?.();
    } catch {
      addToast(decideFailed, 'error', 6000);
    }
  };

  if (waiting.error !== null) {
    return (
      <Card className="p-4">
        <AreaError
          message={waiting.error}
          retryLabel={t('dashboard.tryAgain', 'Try again')}
          onRetry={() => waiting.refetch()}
        />
      </Card>
    );
  }
  if (waiting.loading && waiting.data === null) {
    return (
      <LoadingRows
        label={t('dashboard.waiting.loading', 'Loading what is waiting for you')}
        headers={[t('dashboard.waiting.title', 'Waiting for you')]}
        rows={3}
      />
    );
  }

  const proposalCount = waiting.data?.proposals.length ?? 0;
  const inboxCount = waiting.data?.inboxCount ?? 0;

  // Nothing waiting: one line, never an empty box (H.2).
  if (rows.length === 0) {
    return (
      <Card className="flex-row items-center gap-3 p-4">
        <span className="grid size-9 place-items-center rounded-full bg-success-tint text-success-text">
          <Icon name="check" size={16} />
        </span>
        <p className="text-base font-medium text-foreground">
          {t('dashboard.waiting.nothing', 'Nothing is waiting for you.')}
        </p>
      </Card>
    );
  }

  const shown = rows.slice(0, MAX_ROWS);
  const more = rows.length - shown.length;

  return (
    <Card className="gap-4 p-4">
      <div>
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          {t('dashboard.waiting.title', 'Waiting for you')}
          <span className="rounded-full bg-muted px-2 text-sm font-medium text-muted-foreground tabular-nums">
            {rows.length}
          </span>
        </h2>
        <p className="text-sm text-muted-foreground">
          {t('dashboard.waiting.hint', 'Oldest first. Agent proposals are dashed until you decide.')}
        </p>
      </div>
      <ul className="grid gap-3">
        {shown.map((row) => (
          <li
            key={row.key}
            className={
              row.kind === 'proposal'
                ? 'flex flex-wrap items-center gap-3 rounded-card border border-dashed border-input p-3'
                : 'flex flex-wrap items-center gap-3 rounded-card border border-border p-3'
            }
          >
            <span
              aria-hidden="true"
              className={
                row.kind === 'proposal'
                  ? 'grid size-9 shrink-0 place-items-center rounded-card bg-primary-tint text-primary-tint-foreground'
                  : 'grid size-9 shrink-0 place-items-center rounded-card bg-muted text-muted-foreground'
              }
            >
              <Icon name={row.kind === 'proposal' ? 'robot' : 'document'} size={16} />
            </span>
            <div className="min-w-0 flex-1">
              <p className="font-medium text-foreground">{row.title}</p>
              <p className="text-sm text-muted-foreground">
                {row.from !== '' && (
                  <span>
                    {row.from}
                    {row.kind === 'proposal' ? ` ${t('dashboard.waiting.forYou', 'for you')}` : ''} ·{' '}
                  </span>
                )}
                {ago(row.when, locale ?? 'en')}
              </p>
            </div>
            {row.kind === 'proposal' && row.proposal !== undefined ? (
              <div className="flex items-center gap-2">
                <Button variant="ghost" onClick={() => void decide(row.proposal as AgentProposal, 'decline')}>
                  {t('dashboard.waiting.decline', 'Decline')}
                </Button>
                <Button variant="outline" onClick={() => void decide(row.proposal as AgentProposal, 'approve')}>
                  {t('dashboard.waiting.approve', 'Approve')}
                </Button>
              </div>
            ) : (
              row.href !== undefined && (
                <Button variant="outline" onClick={() => router.push(row.href ?? '/admin/inbox')}>
                  {t('dashboard.waiting.open', 'Open')}
                </Button>
              )
            )}
          </li>
        ))}
      </ul>
      {more > 0 && (
        <p className="text-sm text-muted-foreground">
          {t('dashboard.waiting.more', 'and {count} more').replace('{count}', String(more))}
        </p>
      )}
      <div className="flex flex-wrap gap-4 text-sm font-medium">
        <Link href="/admin/inbox" className="text-primary underline-offset-2 hover:underline">
          {t('dashboard.waiting.openInbox', 'Open Inbox ({count})').replace('{count}', String(inboxCount))}
        </Link>
        <Link href="/admin/approvals" className="text-primary underline-offset-2 hover:underline">
          {t('dashboard.waiting.openApprovals', 'Open Approvals ({count})').replace('{count}', String(proposalCount))}
        </Link>
      </div>
    </Card>
  );
}
