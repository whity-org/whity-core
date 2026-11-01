'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { CopyBlock } from '@whity/ui/copy-block';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { EmptyState } from '@whity/ui/empty-state';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { localized, refusal, type MyAgent } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

/**
 * The workspace's agents and which ones this person uses (WHIT-820). Every
 * member may use one for themselves, so `/api/me/agents` has no permission gate.
 * Starting one gives it a grant that begins clamped to what the person holds.
 * Stopping revokes its credentials and can't be undone, so that asks first.
 * Built from existing components until the design cards land (WHIT-821).
 */
export default function MyAgentsPage() {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [connecting, setConnecting] = useState<MyAgent | null>(null);
  const [stopping, setStopping] = useState<MyAgent | null>(null);
  const [busy, setBusy] = useState(false);

  const agents = useFetch<MyAgent[]>(async () => {
    const response = await apiClient('/api/v1/me/agents');
    if (!response.ok) throw new Error(t('agents.error.load', 'The agents could not be loaded.'));
    return ((await response.json()) as { data: MyAgent[] }).data;
  }, [apiClient]);

  const act = async (agent: MyAgent, method: 'POST' | 'DELETE') => {
    setBusy(true);
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
      setBusy(false);
    }
  };

  const list = agents.data ?? [];

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('myAgents.title', 'My agents')}
        description={t('myAgents.description', 'Use an agent and it acts for you, within what you allow. It never does more than you can.')}
      />

      {agents.error !== null ? (
        <EmptyState title={t('agents.error.title', 'Could not load')} description={agents.error} />
      ) : !agents.loading && list.length === 0 ? (
        <EmptyState title={t('myAgents.empty.title', 'No agents yet')} description={t('myAgents.empty.description', 'An admin of this workspace adds them.')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {list.map((agent) => (
            <li key={agent.id} className="flex flex-col gap-3 rounded-card border border-border bg-card p-4">
              <div className="flex-1 space-y-1">
                <p className="font-medium">{localized(agent.name, agent.nameAr, arabic)}</p>
                <p className="text-sm text-muted-foreground">{localized(agent.role, agent.roleAr, arabic)}</p>
              </div>
              {agent.assignment === null ? (
                <div>
                  <Button variant="outline" disabled={busy} onClick={() => void act(agent, 'POST')}>
                    {t('myAgents.start', 'Start using')}
                  </Button>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" asChild>
                    <Link href={`/admin/my-agents/${agent.id}`}>{t('myAgents.permissions', 'What it may do')}</Link>
                  </Button>
                  <Button variant="outline" onClick={() => setConnecting(agent)}>
                    {t('myAgents.connect', 'Connect an app')}
                  </Button>
                  <Button variant="ghost" onClick={() => setStopping(agent)}>
                    {t('myAgents.stop', 'Stop using')}
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {connecting !== null && <ConnectDialog agent={connecting} onClose={() => setConnecting(null)} />}

      <Dialog open={stopping !== null} onOpenChange={(open) => !open && setStopping(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('myAgents.stopConfirm.title', 'Stop using {name}?', { name: stopping === null ? '' : localized(stopping.name, stopping.nameAr, arabic) })}</DialogTitle>
            <DialogDescription>
              {t('myAgents.stopConfirm.description', 'Every app connected as this agent loses access now, and what you allowed it is cleared. Anything it already did stays in the activity list.')}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" disabled={busy} onClick={() => setStopping(null)}>
              {t('myAgents.stopConfirm.cancel', 'Keep using')}
            </Button>
            <Button variant="destructive" disabled={busy} onClick={() => stopping !== null && void act(stopping, 'DELETE')}>
              {t('myAgents.stop', 'Stop using')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
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
          <DialogDescription>{t('myAgents.connect.description', 'Most apps sign in themselves: add this address, and the app asks you to sign in. Whity Agent lists this agent under your workspace.')}</DialogDescription>
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
              <AlertDescription>{t('myAgents.connect.credentialWarning', 'This key is shown only now. Anyone who has it can act as this agent for you, so don’t share it.')}</AlertDescription>
            </Alert>
            <CopyBlock value={token} {...copy} />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
