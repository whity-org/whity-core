'use client';

import { useMemo, useState } from 'react';
import { AgentActivity, type AgentActivityItem } from '@whity/ui/agent-activity';
import { Button } from '@whity/ui/button';
import { useDisplayPreferences } from '@whity/ui/display-preferences';
import { AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { type WorkspaceAgent } from './workspace-agents';
import { localizedText } from '@whity/ui/localized-text';

type Item = components['schemas']['AgentActivityItem'];
type Status = Item['status'];

const PAGE_SIZE = 50;
const ALL = 'all';

const KIND: Record<Status, AgentActivityItem['kind']> = {
  done: 'act',
  undone: 'undo',
  waiting: 'propose',
  declined: 'undo',
  expired: 'propose',
  failed: 'error',
};

/**
 * What the workspace's agents did and proposed (A23 for those who manage agents):
 * `GET /api/agent-activity`. Undo stays with the person an action was for, so no
 * row offers it here. With `agentId` it is one agent's page and has no agent
 * filter; `since` narrows it to today on the agent's Overview.
 */
export function WorkspaceActivityPanel({
  agentId,
  initialAgent,
  since,
  compact = false,
}: {
  agentId?: number;
  initialAgent?: number;
  since?: string;
  compact?: boolean;
}) {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const dates = useDateDisplay();
  const { showTechnicalDetails } = useDisplayPreferences();
  const [agent, setAgent] = useState(initialAgent !== undefined ? String(initialAgent) : ALL);
  const [status, setStatus] = useState<string>(ALL);
  const [pages, setPages] = useState(1);
  const [offset, setOffset] = useState(0);
  const [rows, setRows] = useState<Item[]>([]);

  const shownAgent = agentId !== undefined ? String(agentId) : agent;

  const page = useFetch<{ data: Item[]; hasMore: boolean }>(async () => {
    const query: Record<string, string | number> = { limit: PAGE_SIZE, offset };
    if (shownAgent !== ALL) query.agent = shownAgent;
    if (status !== ALL) query.status = status;
    if (since !== undefined) query.since = since;
    const { data, response } = await api.GET('/api/v1/agent-activity', { params: { query: query as never } });
    if (!response.ok) throw new Error(t('agentActivity.workspace.error', 'The workspace’s agent activity could not be loaded.'));
    const body = data as unknown as { data: Item[]; hasMore: boolean };
    // Each further page adds to the list; a new filter starts it again.
    setRows((current) => (offset === 0 ? body.data : [...current, ...body.data]));
    return body;
  }, [shownAgent, status, since, offset]);

  const restart = (apply: () => void) => {
    apply();
    setOffset(0);
    setPages(1);
  };

  const agents = useFetch<WorkspaceAgent[]>(async () => {
    if (agentId !== undefined) return [];
    const { data, response } = await api.GET('/api/v1/agents', { params: { query: { archived: 1 } as never } });
    return response.ok ? (data as unknown as { data: WorkspaceAgent[] }).data : [];
  }, [agentId]);

  const statusWords: Record<Status, string> = {
    done: t('agentActivity.status.done', 'Done'),
    undone: t('agentActivity.status.undone', 'Undone'),
    waiting: t('agentActivity.status.waiting', 'Waiting for a person'),
    declined: t('agentActivity.status.declined', 'Declined'),
    expired: t('agentActivity.status.expired', 'Expired'),
    failed: t('agentActivity.status.failed', 'Didn’t run'),
  };

  const items = useMemo<AgentActivityItem[]>(
    () =>
      rows.map((row) => {
        const what = localizedText({ en: row.toolInfo?.plainName ?? row.summary, ar: row.toolInfo?.plainNameAr ?? null }, arabic ? 'ar' : 'en');
        return {
          id: row.id,
          kind: KIND[row.status],
          actor: localizedText({ en: row.agent.name, ar: row.agent.nameAr }, arabic ? 'ar' : 'en') || t('agentActivity.agent.unknown', 'An agent'),
          onBehalfOf: row.person.name ?? undefined,
          text: row.status === 'done' ? what : t('agentActivity.workspace.row', '{status}: {what}', { status: statusWords[row.status], what }),
          timeLabel: dates.dateTime(row.createdAt) ?? '',
          dateTime: row.createdAt,
          tool: showTechnicalDetails ? row.tool : undefined,
        };
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [rows, arabic, showTechnicalDetails],
  );

  const filtered = shownAgent !== ALL && agentId === undefined ? true : status !== ALL;
  const loadingFirst = page.loading && offset === 0;

  const filters = !compact && (
    <div className="flex flex-wrap items-center gap-3">
      {agentId === undefined && (
        <Select value={agent} onValueChange={(value) => restart(() => setAgent(value))}>
          <SelectTrigger aria-label={t('agentActivity.filter', 'Filter by agent')} className="w-64">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>{t('agentActivity.workspace.allAgents', 'All agents')}</SelectItem>
            {(agents.data ?? []).map((option) => (
              <SelectItem key={option.id} value={String(option.id)}>
                {localizedText({ en: option.name, ar: option.nameAr }, arabic ? 'ar' : 'en')}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
      <Select value={status} onValueChange={(value) => restart(() => setStatus(value))}>
        <SelectTrigger aria-label={t('agentActivity.workspace.filterStatus', 'Filter by what happened')} className="w-64">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL}>{t('agentActivity.workspace.allStatuses', 'Everything')}</SelectItem>
          {(Object.keys(statusWords) as Status[]).map((id) => (
            <SelectItem key={id} value={id}>
              {statusWords[id]}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );

  return (
    <div className="space-y-4">
      {filters}
      {page.error !== null ? (
        <AreaError message={page.error} retryLabel={t('agents.retry', 'Try again')} onRetry={page.refetch} />
      ) : loadingFirst ? (
        <LoadingRows headers={[t('agentActivity.table.what', 'What happened')]} label={t('agents.loading', 'Loading…')} />
      ) : items.length === 0 ? (
        filtered ? (
          <EmptyFiltered
            title={t('agentActivity.workspace.filtered.title', 'Nothing matches')}
            description={t('agentActivity.workspace.filtered.description', 'Try another agent or show everything.')}
            clearLabel={t('agents.filtered.clear', 'Clear filters')}
            onClear={() =>
              restart(() => {
                setAgent(ALL);
                setStatus(ALL);
              })
            }
          />
        ) : compact ? (
          <p className="text-sm text-muted-foreground">{t('agentActivity.workspace.nothingToday', 'Nothing yet today.')}</p>
        ) : (
          <EmptyFirstTime
            icon="activity"
            title={t('agentActivity.empty.title', 'No agent activity yet')}
            description={t('agentActivity.workspace.empty', 'When an agent does something for someone, or asks, it shows here.')}
          />
        )
      ) : (
        <div className="max-w-[720px] space-y-4">
          <AgentActivity
            items={items}
            label={t('agentActivity.title', 'Agent activity')}
            labels={{ all: t('agentActivity.all', 'All'), filter: t('agentActivity.filter', 'Filter by agent'), for: t('agentActivity.for', 'for') }}
          />
          {page.data?.hasMore === true && !compact && (
            <Button
              variant="outline"
              loading={page.loading}
              onClick={() => {
                setOffset(pages * PAGE_SIZE);
                setPages(pages + 1);
              }}
            >
              {t('agentActivity.workspace.more', 'Show more')}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
