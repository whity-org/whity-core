'use client';

import { useParams } from 'next/navigation';
import { useTranslation } from '@whity/features/i18n';
import { AgentGrantScreen } from '@/components/agents/agent-grant-screen';

/** What an agent may do for this person (WHIT-820): the same screen as an agent credential's. */
export default function MyAgentGrantPage() {
  const t = useTranslation('admin');
  const id = Number(useParams<{ id: string }>()?.id ?? 0);
  return <AgentGrantScreen subject={{ kind: 'agent', id }} backHref="/admin/my-agents" backLabel={t('myAgents.title', 'My agents')} />;
}
