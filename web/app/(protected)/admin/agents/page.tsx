'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { localized, refusal, type AgentPreset, type WorkspaceAgent } from '@/components/agents/workspace-agents';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

/**
 * The workspace's agents (WHIT-820): list, create from a preset or from scratch,
 * archive and restore. `agents:manage` holds every route behind this page.
 * Archiving has Restore, so it asks nothing first. Built from existing
 * components until the design cards land (DESIGN-ASKS.md, WHIT-821).
 */
export default function AgentsPage() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const router = useRouter();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [choosing, setChoosing] = useState(false);
  const [busy, setBusy] = useState(false);

  const agents = useFetch<WorkspaceAgent[]>(async () => {
    const response = await apiClient('/api/v1/agents?archived=1');
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: WorkspaceAgent[] }).data;
  }, [apiClient]);

  const presets = useFetch<AgentPreset[]>(async () => {
    if (!choosing) return [];
    const response = await apiClient('/api/v1/agent-presets');
    if (!response.ok) throw new Error(t('agents.error.presets', 'The presets could not be loaded.'));
    return ((await response.json()) as { data: AgentPreset[] }).data;
  }, [apiClient, choosing]);

  useEffect(() => {
    if (agents.error) addToast(agents.error, 'error');
  }, [agents.error, addToast]);

  const fromPreset = async (preset: AgentPreset) => {
    setBusy(true);
    try {
      const response = await apiClient('/api/v1/agents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ presetKey: preset.key }),
      });
      if (!response.ok) {
        addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
        return;
      }
      const body = (await response.json()) as { data: WorkspaceAgent };
      router.push(`/admin/agents/${body.data.id}`);
    } finally {
      setBusy(false);
    }
  };

  const setArchived = async (agent: WorkspaceAgent, archived: boolean) => {
    const response = await apiClient(`/api/v1/agents/${agent.id}/${archived ? 'archive' : 'restore'}`, { method: 'POST' });
    if (!response.ok) {
      addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
      return;
    }
    addToast(
      archived
        ? t('agents.archived', '{name} archived. Everyone using it has stopped.', { name: agent.name })
        : t('agents.restored', '{name} restored.', { name: agent.name }),
      'success',
    );
    agents.refetch();
  };

  const columns: DataTableColumn<WorkspaceAgent>[] = [
    {
      accessorKey: 'name',
      header: t('agents.table.name', 'Role name'),
      enableSorting: true,
      cell: (agent: WorkspaceAgent) => (
        <Link href={`/admin/agents/${agent.id}`} className="font-medium text-primary hover:underline">
          {localized(agent.name, agent.nameAr, arabic)}
        </Link>
      ),
    },
    {
      accessorKey: 'role',
      header: t('agents.table.role', 'What it does'),
      cell: (agent: WorkspaceAgent) => localized(agent.role, agent.roleAr, arabic),
    },
    {
      accessorKey: 'status',
      header: t('agents.table.status', 'Status'),
      cell: (agent: WorkspaceAgent) =>
        agent.status === 'archived' ? <Badge variant="secondary">{t('agents.status.archived', 'Archived')}</Badge> : t('agents.status.active', 'Active'),
    },
  ];

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('agents.title', 'Agents')}
        description={t('agents.description', 'Assistants people in this workspace can use. An agent acts for the person using it, and never does more than they can.')}
        action={<Button onClick={() => setChoosing(true)}>{t('agents.new.action', 'New agent')}</Button>}
      />

      <DataTable
        columns={columns}
        data={agents.data ?? []}
        getRowId={(agent) => String(agent.id)}
        isLoading={agents.loading}
        rowActions={(agent) => (
          <Button variant="outline" size="sm" onClick={() => void setArchived(agent, agent.status !== 'archived')}>
            {agent.status === 'archived' ? t('agents.action.restore', 'Restore') : t('agents.action.archive', 'Archive')}
          </Button>
        )}
        pagination={{ pageSize: 25 }}
        emptyState={{
          title: t('agents.empty.title', 'No agents yet'),
          description: t('agents.empty.description', 'Start from a preset such as Finance clerk, or write your own.'),
        }}
      />

      <Dialog open={choosing} onOpenChange={setChoosing}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{t('agents.new.title', 'New agent')}</DialogTitle>
            <DialogDescription>{t('agents.new.description', 'A preset fills in the persona, and you can change anything before anyone uses it.')}</DialogDescription>
          </DialogHeader>
          <ul className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-2">
            {(presets.data ?? []).map((preset) => (
              <li key={preset.key} className="flex flex-col gap-2 rounded-card border border-border p-4">
                <span className="font-medium">{localized(preset.name, preset.nameAr, arabic)}</span>
                <span className="flex-1 text-sm text-muted-foreground">{localized(preset.role, preset.roleAr, arabic)}</span>
                <Button variant="outline" size="sm" disabled={busy} onClick={() => void fromPreset(preset)}>
                  {t('agents.new.usePreset', 'Use this preset')}
                </Button>
              </li>
            ))}
          </ul>
          <Button variant="outline" asChild>
            <Link href="/admin/agents/new">{t('agents.new.scratch', 'Start from scratch')}</Link>
          </Button>
        </DialogContent>
      </Dialog>
    </div>
  );
}
