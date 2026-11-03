'use client';

import { useParams } from 'next/navigation';
import { AccessDeniedPanel, AreaError, LoadingRows } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { AgentRecord } from '@/components/agents/agent-record';
import type { WorkspaceAgent } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';

const DENIED = 'agents:denied';

/** One workspace agent's record page (A21, WHIT-820). */
export default function AgentPage() {
  const t = useTranslation('admin');
  const id = useParams<{ id: string }>()?.id ?? '';
  const { apiClient } = useAuth();

  const agent = useFetch<WorkspaceAgent>(async () => {
    const response = await apiClient(`/api/v1/agents/${encodeURIComponent(id)}`);
    if (response.status === 403) throw new Error(DENIED);
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: WorkspaceAgent }).data;
  }, [apiClient, id]);

  if (agent.error === DENIED) {
    return (
      <AccessDeniedPanel
        title={t('agents.denied.title', 'You can’t open this agent')}
        description={t('agents.denied.description', 'Only people who manage agents for this workspace can. Ask them for access.')}
      />
    );
  }
  if (agent.error !== null) {
    return <AreaError message={agent.error} retryLabel={t('agents.retry', 'Try again')} onRetry={agent.refetch} />;
  }
  if (agent.data === null) {
    return <LoadingRows headers={[t('agents.table.agent', 'Agent')]} rows={4} label={t('agents.loading', 'Loading…')} />;
  }
  return <AgentRecord key={agent.data.id} agent={agent.data} onChanged={agent.refetch} />;
}
