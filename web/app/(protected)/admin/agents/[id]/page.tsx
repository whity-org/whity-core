'use client';

import { useParams } from 'next/navigation';
import { EmptyState } from '@whity/ui/empty-state';
import { useTranslation } from '@whity/features/i18n';
import { PersonaEditor } from '@/components/agents/persona-editor';
import type { WorkspaceAgent } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';

/** One workspace agent's persona (WHIT-820). */
export default function AgentPage() {
  const t = useTranslation('admin');
  const id = useParams<{ id: string }>()?.id ?? '';
  const { apiClient } = useAuth();

  const agent = useFetch<WorkspaceAgent>(async () => {
    const response = await apiClient(`/api/v1/agents/${encodeURIComponent(id)}`);
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: WorkspaceAgent }).data;
  }, [apiClient, id]);

  if (agent.error !== null) {
    return <EmptyState title={t('agents.error.title', 'Could not load')} description={agent.error} />;
  }
  if (agent.data === null) {
    return <p className="text-sm text-muted-foreground">{t('agents.loading', 'Loading…')}</p>;
  }
  return <PersonaEditor key={agent.data.id} agent={agent.data} />;
}
