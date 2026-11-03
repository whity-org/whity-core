'use client';

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { AgentActivityPanel } from '@/components/agents/agent-activity-panel';

/** Agent activity (A23): what agents did for this person and asked, with Undo while it lasts. */
export default function AgentActivityPage() {
  const t = useTranslation('admin');
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
      <AgentActivityPanel />
    </div>
  );
}
