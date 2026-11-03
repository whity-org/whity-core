'use client';

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { AgentPresetGallery } from '@/components/agents/agent-preset-gallery';
import { AgentsTabs } from '@/components/agents/agents-tabs';
import { localized, refusal, type WorkspaceAgent } from '@/components/agents/workspace-agents';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

type Filter = 'all' | 'active' | 'archived';

/**
 * Everyone's agents (A20, TplAgents): the workspace's agents, new ones from a
 * preset gallery, and archive with an Undo toast (tier 1) or restore. The
 * holder of `agents:manage` sees this tab. "Works for", what an agent does on
 * its own, last active and Pause need server data that doesn't exist yet (S6),
 * so the table shows only what `/api/agents` returns.
 */
export default function AgentsPage() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const router = useRouter();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [choosing, setChoosing] = useState(false);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<Filter>('all');

  const agents = useFetch<WorkspaceAgent[]>(async () => {
    const response = await apiClient('/api/v1/agents?archived=1');
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: WorkspaceAgent[] }).data;
  }, [apiClient]);

  const setArchived = async (agent: WorkspaceAgent, archived: boolean) => {
    const response = await apiClient(`/api/v1/agents/${agent.id}/${archived ? 'archive' : 'restore'}`, { method: 'POST' });
    if (!response.ok) {
      addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
      return;
    }
    const name = localized(agent.name, agent.nameAr, arabic);
    addToast(
      archived
        ? t('agents.archived', '{name} archived. Everyone using it has stopped.', { name })
        : t('agents.restored', '{name} restored.', { name }),
      'success',
      8000,
      archived ? { label: t('agents.undo', 'Undo'), onClick: () => void setArchived(agent, false) } : undefined,
    );
    agents.refetch();
  };

  const list = useMemo(() => agents.data ?? [], [agents.data]);
  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return list.filter((agent) => {
      if (filter !== 'all' && agent.status !== filter) return false;
      if (needle === '') return true;
      return [agent.name, agent.nameAr, agent.role, agent.roleAr].some((text) => (text ?? '').toLowerCase().includes(needle));
    });
  }, [list, query, filter]);

  const columns: DataTableColumn<WorkspaceAgent>[] = [
    {
      accessorKey: 'name',
      header: t('agents.table.agent', 'Agent'),
      enableSorting: true,
      cell: (agent: WorkspaceAgent) => (
        <span className="flex items-center gap-3">
          <Avatar kind="agent" name={localized(agent.name, agent.nameAr, arabic)} size={36} />
          <span className="min-w-0">
            <span className="block font-medium">{localized(agent.name, agent.nameAr, arabic)}</span>
            <span className="block text-sm text-muted-foreground">{localized(agent.role, agent.roleAr, arabic)}</span>
          </span>
        </span>
      ),
    },
    {
      accessorKey: 'status',
      header: t('agents.table.status', 'Status'),
      cell: (agent: WorkspaceAgent) =>
        agent.status === 'archived' ? (
          <Badge variant="outline">{t('agents.status.archived', 'Archived')}</Badge>
        ) : (
          <Badge variant="success">{t('agents.status.active', 'Available')}</Badge>
        ),
    },
  ];

  const empty = !agents.loading && agents.error === null && list.length === 0;

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('agents.title', 'Agents')}
        description={t('agents.description', 'Agents work for the person using them, never with more access than that person has. They ask first for anything you choose.')}
        action={
          <Button onClick={() => setChoosing(true)}>
            <Icon name="plus" size={16} />
            {t('agents.new.action', 'New agent')}
          </Button>
        }
      />
      <AgentsTabs active="everyone" counts={{ everyone: agents.loading ? undefined : list.filter((a) => a.status === 'active').length }} />

      {agents.error !== null ? (
        <AreaError message={agents.error} retryLabel={t('agents.retry', 'Try again')} onRetry={agents.refetch} />
      ) : agents.loading ? (
        <LoadingRows headers={[t('agents.table.agent', 'Agent'), t('agents.table.status', 'Status')]} label={t('agents.loading', 'Loading…')} />
      ) : empty ? (
        <EmptyFirstTime
          icon="robot"
          title={t('agents.empty.title', 'No agents yet')}
          description={t('agents.empty.description', 'Start from a ready-made agent. It asks you before doing anything that matters.')}
          action={<Button onClick={() => setChoosing(true)}>{t('agents.new.action', 'New agent')}</Button>}
        />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <SearchField
              className="w-full max-w-sm"
              value={query}
              onValueChange={setQuery}
              label={t('agents.search', 'Search agents')}
              placeholder={t('agents.search', 'Search agents')}
              clearLabel={t('agents.search.clear', 'Clear search')}
            />
            <ToggleGroup
              label={t('agents.filter.label', 'Show')}
              value={filter}
              required
              onChange={(value) => typeof value === 'string' && setFilter(value as Filter)}
              options={[
                { value: 'all', label: t('agents.filter.all', 'All') },
                { value: 'active', label: t('agents.filter.active', 'Available') },
                { value: 'archived', label: t('agents.filter.archived', 'Archived') },
              ]}
            />
          </div>
          {shown.length === 0 ? (
            <EmptyFiltered
              title={t('agents.filtered.title', 'No agents match')}
              description={t('agents.filtered.description', 'Try another search or show all agents.')}
              clearLabel={t('agents.filtered.clear', 'Clear filters')}
              onClear={() => {
                setQuery('');
                setFilter('all');
              }}
            />
          ) : (
            <DataTable
              columns={columns}
              data={shown}
              getRowId={(agent) => String(agent.id)}
              onRowClick={(agent) => router.push(`/admin/agents/${agent.id}`)}
              rowActions={(agent) => (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={t('agents.row.menu', 'More actions')}>
                      <Icon name="more" size={16} />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onClick={() => router.push(`/admin/agents/${agent.id}`)}>{t('agents.action.open', 'Open')}</DropdownMenuItem>
                    <DropdownMenuItem onClick={() => void setArchived(agent, agent.status !== 'archived')}>
                      {agent.status === 'archived' ? t('agents.action.restore', 'Restore') : t('agents.action.archive', 'Archive')}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
              pagination={{ pageSize: 25 }}
            />
          )}
        </>
      )}

      <AgentPresetGallery open={choosing} onOpenChange={setChoosing} />
    </div>
  );
}
