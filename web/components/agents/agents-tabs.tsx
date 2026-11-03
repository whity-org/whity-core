'use client';

import Link from 'next/link';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useTranslation } from '@whity/features/i18n';
import { useCapabilities } from '@/hooks/useCapabilities';

/**
 * The two ways into the agents list (TplAgents): the agents this person uses,
 * and, for those who manage agents, every agent in the workspace. Two routes
 * stand in for the two tabs (owner answer Q6), so these are links.
 */
export function AgentsTabs({ active, counts }: { active: 'mine' | 'everyone'; counts?: { mine?: number; everyone?: number } }) {
  const t = useTranslation('admin');
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission('agents:manage');

  return (
    <Tabs value={active} className="mb-6">
      <TabsList aria-label={t('agents.tabs.label', 'Agents')}>
        <TabsTrigger value="mine" asChild>
          <Link href="/admin/my-agents">
            {t('agents.tabs.mine', 'My agents')}
            {counts?.mine !== undefined && <span className="rounded-full bg-muted px-2 text-xs">{counts.mine}</span>}
          </Link>
        </TabsTrigger>
        {canManage && (
          <TabsTrigger value="everyone" asChild>
            <Link href="/admin/agents">
              {t('agents.tabs.everyone', 'Everyone’s agents')}
              {counts?.everyone !== undefined && <span className="rounded-full bg-muted px-2 text-xs">{counts.everyone}</span>}
            </Link>
          </TabsTrigger>
        )}
      </TabsList>
    </Tabs>
  );
}
