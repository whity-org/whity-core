'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { CopyBlock } from '@whity/ui/copy-block';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Icon } from '@whity/ui/icon';
import { AreaError, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { useDateDisplay } from '@whity/features/datetime';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { AgentsTabs } from '@/components/agents/agents-tabs';
import { OnItsOwn } from '@/components/agents/agent-status';
import { localized, refusal, type MyAgent } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

/**
 * My agents (A20, TplAgents): the workspace's agents and which ones this
 * person uses (WHIT-820). Every member may use one for themselves, so
 * `/api/me/agents` has no permission gate. Stopping revokes its credentials and
 * can't be undone, so it asks first (tier 2). Each person sees their own use: how
 * much it does on its own under their grant, today's count, and when it was last
 * active. Only someone who manages agents can pause one, so a paused agent just
 * says so here, and can't be connected until it is resumed.
 */
export default function MyAgentsPage() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const dates = useDateDisplay();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [connecting, setConnecting] = useState<MyAgent | null>(null);
  const [stopping, setStopping] = useState<MyAgent | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const agents = useFetch<MyAgent[]>(async () => {
    const response = await apiClient('/api/v1/me/agents');
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: MyAgent[] }).data;
  }, [apiClient]);

  const act = async (agent: MyAgent, method: 'POST' | 'DELETE') => {
    setBusyId(agent.id);
    try {
      const response = await apiClient(`/api/v1/me/agents/${agent.id}/assignment`, { method });
      if (!response.ok) {
        addToast(await refusal(response, t('myAgents.error.save', 'That could not be done. Try again.')), 'error');
        return;
      }
      setStopping(null);
      agents.refetch();
    } catch {
      addToast(t('myAgents.error.save', 'That could not be done. Try again.'), 'error');
    } finally {
      setBusyId(null);
    }
  };

  const list = agents.data ?? [];
  const mine = list.filter((agent) => agent.assignment !== null);
  const rest = list.filter((agent) => agent.assignment === null);
  const nameOf = (agent: MyAgent) => localized(agent.name, agent.nameAr, arabic);

  const row = (agent: MyAgent) => (
    <li key={agent.id} className="flex flex-wrap items-center gap-4 border-t border-divider px-4 py-3 first:border-t-0">
      <Avatar kind="agent" name={nameOf(agent)} size={40} />
      <div className="min-w-0 flex-1">
        <p className="font-medium">{nameOf(agent)}</p>
        <p className="text-sm text-muted-foreground">{localized(agent.role, agent.roleAr, arabic)}</p>
        {agent.assignment !== null && (
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
            <OnItsOwn counts={agent.onItsOwn} />
            <span>{t('agentRecord.today', '{n} actions today', { n: agent.actionsToday })}</span>
            {agent.waiting > 0 && <span>{t('myAgents.waiting', '{n} waiting for you', { n: agent.waiting })}</span>}
            {dates.dateTime(agent.lastActiveAt) !== null && (
              <span>{t('myAgents.lastActive', 'Last active {when}', { when: dates.dateTime(agent.lastActiveAt) ?? '' })}</span>
            )}
          </p>
        )}
      </div>
      {agent.assignment === null ? (
        <Button variant="outline" disabled={busyId !== null} loading={busyId === agent.id} onClick={() => void act(agent, 'POST')}>
          {t('myAgents.start', 'Start using')}
        </Button>
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          {agent.paused ? (
            <Badge variant="outline">{t('agents.status.paused', 'Paused')}</Badge>
          ) : (
            <Badge variant="success">{t('myAgents.using', 'Working for you')}</Badge>
          )}
          <Button variant="outline" asChild>
            <Link href={`/admin/my-agents/${agent.id}`}>{t('myAgents.permissions', 'What it may do')}</Link>
          </Button>
          <Button variant="outline" disabled={agent.paused} onClick={() => setConnecting(agent)}>
            {t('myAgents.connect', 'Connect an app')}
          </Button>
          <Button variant="ghost" onClick={() => setStopping(agent)}>
            {t('myAgents.stop', 'Stop using')}
          </Button>
        </div>
      )}
    </li>
  );

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('agents.title', 'Agents')}
        description={t('myAgents.description', 'Use an agent and it acts for you, within what you allow. It never does more than you can.')}
        action={
          <Button variant="outline" asChild>
            <Link href="/admin/agent-activity">
              <Icon name="activity" size={16} />
              {t('myAgents.seeActivity', 'See activity')}
            </Link>
          </Button>
        }
      />
      <AgentsTabs active="mine" counts={{ mine: agents.loading ? undefined : mine.length }} />

      {agents.error !== null ? (
        <AreaError message={agents.error} retryLabel={t('agents.retry', 'Try again')} onRetry={agents.refetch} />
      ) : agents.loading ? (
        <LoadingRows headers={[t('agents.table.agent', 'Agent')]} label={t('agents.loading', 'Loading…')} />
      ) : list.length === 0 ? (
        <EmptyFirstTime
          icon="robot"
          title={t('myAgents.empty.title', 'No agents yet')}
          description={t('myAgents.empty.description', 'Someone who manages agents for this workspace adds them. Then you can start using one.')}
        />
      ) : (
        <div className="space-y-6">
          {mine.length > 0 && <ul className="overflow-hidden rounded-card border border-border bg-card">{mine.map(row)}</ul>}
          {rest.length > 0 && (
            <section className="space-y-3">
              <h2 className="text-sm font-semibold">{t('myAgents.available', 'Available to you')}</h2>
              <ul className="overflow-hidden rounded-card border border-border bg-card">{rest.map(row)}</ul>
            </section>
          )}
        </div>
      )}

      {connecting !== null && <ConnectDialog agent={connecting} onClose={() => setConnecting(null)} />}

      <ConfirmAction
        open={stopping !== null}
        onOpenChange={(open) => !open && setStopping(null)}
        title={t('myAgents.stopConfirm.title', 'Stop using {name}?', { name: stopping === null ? '' : nameOf(stopping) })}
        description={t(
          'myAgents.stopConfirm.description',
          'Every app connected as this agent loses access now, and what you allowed it is cleared. Anything it already did stays in the activity list.',
        )}
        confirmLabel={t('myAgents.stop', 'Stop using')}
        cancelLabel={t('myAgents.stopConfirm.cancel', 'Keep using')}
        onConfirm={async () => {
          if (stopping !== null) await act(stopping, 'DELETE');
        }}
      />
    </div>
  );
}

/**
 * Connect an app as this agent. An app that signs in itself needs only the URL,
 * which holds no secret. Any other app needs a credential, made only on request
 * and shown once.
 */
function ConnectDialog({ agent, onClose }: { agent: MyAgent; onClose: () => void }) {
  const t = useTranslation('admin');
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [token, setToken] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const info = useFetch<{ endpoint: string | null }>(async () => {
    const response = await apiClient('/api/v1/mcp/connect');
    return response.ok ? ((await response.json()) as { endpoint: string | null }) : { endpoint: null };
  }, [apiClient]);
  const base = info.data?.endpoint ?? `${window.location.origin}/mcp`;

  const issue = async () => {
    setBusy(true);
    try {
      const response = await apiClient(`/api/v1/me/agents/${agent.id}/credentials`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
      });
      if (!response.ok) {
        addToast(await refusal(response, t('myAgents.error.save', 'That could not be done. Try again.')), 'error');
        return;
      }
      setToken(((await response.json()) as { data: { token: string } }).data.token);
    } catch {
      addToast(t('myAgents.error.save', 'That could not be done. Try again.'), 'error');
    } finally {
      setBusy(false);
    }
  };

  const copy = {
    copyLabel: t('aiPrincipals.connect.copy', 'Copy'),
    copiedLabel: t('aiPrincipals.connect.copied', 'Copied'),
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('myAgents.connect', 'Connect an app')}</DialogTitle>
          <DialogDescription>
            {t('myAgents.connect.description', 'Most apps sign in themselves: add this address, and the app asks you to sign in. Whity Agent lists this agent under your workspace.')}
          </DialogDescription>
        </DialogHeader>
        <CopyBlock value={`${base}/agents/${agent.slug}`} {...copy} />
        {token === null ? (
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">{t('myAgents.connect.credentialHelp', 'For a script or an app that can’t sign in, make a key instead.')}</p>
            <Button variant="outline" disabled={busy} onClick={() => void issue()}>
              {t('myAgents.connect.credential', 'Make a key')}
            </Button>
          </div>
        ) : (
          <div className="space-y-2">
            <Alert variant="warning">
              <AlertDescription>
                {t('myAgents.connect.credentialWarning', 'This key is shown only now. Anyone who has it can act as this agent for you, so don’t share it.')}
              </AlertDescription>
            </Alert>
            <CopyBlock value={token} {...copy} />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
