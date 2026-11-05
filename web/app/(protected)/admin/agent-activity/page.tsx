'use client';

import { Suspense } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { AgentActivityPanel } from '@/components/agents/agent-activity-panel';
import { WorkspaceActivityPanel } from '@/components/agents/workspace-activity-panel';
import { useCapabilities } from '@/hooks/useCapabilities';

/**
 * Agent activity (A23): what agents did for this person and asked, with Undo
 * while it lasts. Whoever manages agents also has everyone's, with a filter by
 * agent; `?agent=` opens that tab on one agent.
 */
export default function AgentActivityPage() {
  return (
    <Suspense>
      <ActivityPage />
    </Suspense>
  );
}

function ActivityPage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const { hasPermission } = useCapabilities();
  const canManage = hasPermission('agents:manage');
  const params = useSearchParams();
  const agent = Number(params?.get('agent') ?? 0);
  const everyone = params?.get('tab') === 'everyone';
  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('agentActivity.title', 'Agent activity')}
        description={t('agentActivity.description', 'Everything your agents did and asked for, newest first. You can undo an action while its window is open.')}
        action={
          <Button variant="outline" asChild>
            <Link href="/admin/my-agents">
              <Icon name="chevron-left" size={16} className="rtl:-scale-x-100" />
              {t('agentActivity.back', 'Agents')}
            </Link>
          </Button>
        }
      />
      {canManage ? (
        <Tabs
          defaultValue={agent > 0 || everyone ? 'everyone' : 'mine'}
          onValueChange={(value) => router.replace(value === 'everyone' ? '/admin/agent-activity?tab=everyone' : '/admin/agent-activity')}
        >
          <TabsList aria-label={t('agentActivity.title', 'Agent activity')}>
            <TabsTrigger value="mine">{t('agentActivity.tab.mine', 'Mine')}</TabsTrigger>
            <TabsTrigger value="everyone">{t('agentActivity.tab.everyone', 'Everyone’s')}</TabsTrigger>
          </TabsList>
          <TabsContent value="mine" className="mt-6">
            <AgentActivityPanel />
          </TabsContent>
          <TabsContent value="everyone" className="mt-6">
            <WorkspaceActivityPanel initialAgent={agent > 0 ? agent : undefined} />
          </TabsContent>
        </Tabs>
      ) : (
        <AgentActivityPanel />
      )}
    </div>
  );
}
