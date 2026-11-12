'use client';

import { useMemo, useState } from 'react';
import { LoadProblem } from '@/components/load-problem';
import { throwUnlessOk } from '@/lib/forbidden';
import { useRouter } from 'next/navigation';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { EmptyFiltered, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { AgentPresetGallery } from '@/components/agents/agent-preset-gallery';
import { AgentStatusBadge, OnItsOwn, WorksFor } from '@/components/agents/agent-status';
import { AgentsTabs } from '@/components/agents/agents-tabs';
import { localized, refusal, type WorkspaceAgent } from '@/components/agents/workspace-agents';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

type Filter = 'all' | 'working' | 'paused' | 'archived';

const ANYONE = 'anyone';

/**
 * Everyone's agents (A20, TplAgents): the workspace's agents, new ones from a
 * preset gallery, who each works for, how much it does on its own, when it was
 * last active, and Pause or archive with an Undo toast (tier 1). The holder of
 * `agents:manage` sees this tab.
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
  const [person, setPerson] = useState(ANYONE);
  const dates = useDateDisplay();

  const agents = useFetch<WorkspaceAgent[]>(async () => {
    const response = await apiClient('/api/v1/agents?archived=1');
    throwUnlessOk(response, t('agents.error.load', 'The agents could not be loaded.'));
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

  const setPaused = async (agent: WorkspaceAgent, paused: boolean) => {
    const response = await apiClient(`/api/v1/agents/${agent.id}/${paused ? 'pause' : 'resume'}`, { method: 'POST' });
    if (!response.ok) {
      addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
      return;
    }
    const name = localized(agent.name, agent.nameAr, arabic);
    addToast(
      paused
        ? t('agents.paused', '{name} is paused. It can’t do anything until it is resumed.', { name })
        : t('agents.resumed', '{name} is working again.', { name }),
      'success',
      8000,
      paused ? { label: t('agents.undo', 'Undo'), onClick: () => void setPaused(agent, false) } : undefined,
    );
    agents.refetch();
  };

  const list = useMemo(() => agents.data ?? [], [agents.data]);
  const people = useMemo(() => {
    const byId = new Map<number, string>();
    for (const agent of list) for (const who of agent.worksFor) if (who.name !== null) byId.set(who.id, who.name);
    return [...byId.entries()].sort((a, b) => a[1].localeCompare(b[1]));
  }, [list]);
  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return list.filter((agent) => {
      if (filter === 'archived' && agent.status !== 'archived') return false;
      if (filter === 'working' && (agent.status !== 'active' || agent.paused)) return false;
      if (filter === 'paused' && (agent.status !== 'active' || !agent.paused)) return false;
      if (person !== ANYONE && !agent.worksFor.some((who) => String(who.id) === person)) return false;
      if (needle === '') return true;
      return [agent.name, agent.nameAr, agent.role, agent.roleAr].some((text) => (text ?? '').toLowerCase().includes(needle));
    });
  }, [list, query, filter, person]);

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
            <span className="block max-w-xs truncate text-sm text-muted-foreground">{localized(agent.role, agent.roleAr, arabic)}</span>
          </span>
        </span>
      ),
    },
    {
      id: 'worksFor',
      header: t('agents.table.worksFor', 'Works for'),
      cell: (agent: WorkspaceAgent) => <WorksFor people={agent.worksFor} />,
    },
    {
      id: 'onItsOwn',
      header: t('agents.table.onItsOwn', 'On its own'),
      cell: (agent: WorkspaceAgent) => <OnItsOwn counts={agent.onItsOwn} />,
    },
    ...dates.dateColumns<WorkspaceAgent>([
      {
        id: 'lastActiveAt',
        header: t('agents.table.lastActive', 'Last active'),
        value: (agent) => agent.lastActiveAt,
        withTime: true,
        empty: t('agents.lastActive.never', 'Not yet'),
        enableSorting: true,
      },
    ]),
    {
      accessorKey: 'status',
      header: t('agents.table.status', 'Status'),
      cell: (agent: WorkspaceAgent) => <AgentStatusBadge status={agent.status} paused={agent.paused} />,
    },
  ];

  const empty = !agents.loading && agents.error === null && list.length === 0;

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('agents.title', 'Agents')}
        description={t('agents.description', 'Agents work for the person using them, never with more access than that person has. They ask first for anything you choose.')}
        action={
          agents.forbidden ? undefined : (
            <Button onClick={() => setChoosing(true)}>
              <Icon name="plus" size={16} />
              {t('agents.new.action', 'New agent')}
            </Button>
          )
        }
      />
      <AgentsTabs active="everyone" counts={{ everyone: agents.loading ? undefined : list.filter((a) => a.status === 'active').length }} />

      {agents.error !== null ? (
        <LoadProblem forbidden={agents.forbidden} message={agents.error} onRetry={agents.refetch} area={t('agents.area', 'agents')} />
      ) : agents.loading ? (
        <LoadingRows
          headers={[t('agents.table.agent', 'Agent'), t('agents.table.worksFor', 'Works for'), t('agents.table.onItsOwn', 'On its own'), t('agents.table.status', 'Status')]}
          label={t('agents.loading', 'Loading…')}
        />
      ) : empty ? (
        <EmptyFirstTime
          icon="robot"
          moment="agents-propose"
          onceEver="agents-first"
          title={t('agents.empty.title', 'No agents yet')}
          description={t('agents.empty.description', 'Start from a ready-made agent. It asks you before doing anything that matters.')}
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
            <Select value={person} onValueChange={setPerson}>
              <SelectTrigger aria-label={t('agents.table.worksFor', 'Works for')} className="w-56">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ANYONE}>{t('agents.filter.anyone', 'Works for anyone')}</SelectItem>
                {people.map(([id, name]) => (
                  <SelectItem key={id} value={String(id)}>
                    {name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <ToggleGroup
              label={t('agents.filter.label', 'Show')}
              value={filter}
              required
              onChange={(value) => typeof value === 'string' && setFilter(value as Filter)}
              options={[
                { value: 'all', label: t('agents.filter.all', 'All') },
                { value: 'working', label: t('agents.status.working', 'Working') },
                { value: 'paused', label: t('agents.status.paused', 'Paused') },
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
                setPerson(ANYONE);
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
                    {agent.status === 'active' && (
                      <DropdownMenuItem onClick={() => void setPaused(agent, !agent.paused)}>
                        {agent.paused ? t('agents.action.resume', 'Resume') : t('agents.action.pause', 'Pause')}
                      </DropdownMenuItem>
                    )}
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
