'use client';

/**
 * The Tasks page while Whity's own task list and board wait for Taskaat (A10, WHIT-962).
 *
 * Tasks and boards live in Taskaat (T.3). This shows whether the workspace is connected, lets
 * someone with `tasks:manage` connect it with Taskaat's own sign-in or disconnect it, and opens
 * Taskaat. Once connected, agents reach the workspace's Taskaat through Whity's MCP.
 */

import Link from 'next/link';
import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { EmptyState } from '@whity/ui/empty-state';
import { Skeleton } from '@whity/ui/skeleton';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useActionPermission } from '@/hooks/useActionPermission';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';

interface Connection {
  connected: boolean;
  taskaat_url: string;
  connected_at?: string;
  needs_reconnect?: boolean;
}

export function TaskaatConnection() {
  const t = useTranslation('tasks');
  const dates = useDateDisplay();
  const manage = useActionPermission('tasks:manage');
  const loaded = useFetch<Connection>(async () => {
    const { data, error: loadError } = await api.GET('/api/v1/taskaat/connection');
    if (loadError || !data) {
      throw new Error(loadError?.error || t('error.load', 'Could not check the Taskaat connection. Try again.'));
    }
    return data.data;
  }, [t]);
  // After a disconnect, what the server answered, until the next load.
  const [changed, setChanged] = useState<Connection | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const connection = changed ?? loaded.data;
  const error = actionError ?? loaded.error;

  const connect = async () => {
    setBusy(true);
    const { data, error: connectError } = await api.POST('/api/v1/taskaat/connect');
    if (connectError || !data) {
      setBusy(false);
      setActionError(connectError?.error || t('error.connect', 'Taskaat did not answer. Try again in a minute.'));
      return;
    }
    window.location.assign(data.data.authorize_url);
  };

  const disconnect = async () => {
    const { data, error: disconnectError } = await api.DELETE('/api/v1/taskaat/connection');
    if (disconnectError || !data) {
      setActionError(disconnectError?.error || t('error.disconnect', 'Could not disconnect Taskaat. Try again.'));
      return;
    }
    setChanged(data.data);
  };

  const openUrl = connection?.taskaat_url ?? 'https://app.taskaat.dev';
  const connectedOn = dates.date(connection?.connected_at);
  const lapsed = connection?.connected === true && connection.needs_reconnect === true;

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={t('title', 'Tasks')}
        description={t('description', 'Tasks and boards live in Taskaat. Whity works with them through your workspace\'s Taskaat.')}
      />

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {connection === null && !error && (
        <div className="space-y-3" role="status" aria-live="polite">
          <Skeleton className="h-6 w-64" />
          <Skeleton className="h-10 w-48" />
        </div>
      )}

      {connection !== null && (!connection.connected || lapsed) && (
        <EmptyState
          title={lapsed ? t('lapsed.title', 'Connect Taskaat again') : t('connect.title', 'Connect your workspace to Taskaat')}
          description={
            lapsed
              ? t('lapsed.body', 'Taskaat no longer accepts this workspace\'s connection. Someone who manages tasks needs to connect it again.')
              : t('connect.body', 'Sign in to Taskaat and allow Whity. Your agents can then read and update the workspace\'s tasks, checks, evidence and decisions, and their changes wait for a person to accept them.')
          }
          action={
            manage.allowed ? (
              <Button type="button" onClick={connect} loading={busy}>
                {t('connect.action', 'Connect Taskaat')}
              </Button>
            ) : (
              <p className="text-sm text-muted-foreground">
                {t('connect.ask', 'Ask someone who manages tasks to connect Taskaat.')}
              </p>
            )
          }
        />
      )}

      {connection?.connected && !lapsed && (
        <section className="space-y-4 rounded-lg border border-border p-6" aria-labelledby="taskaat-connected">
          <h2 id="taskaat-connected" className="text-lg font-semibold text-foreground">
            {t('connected.title', 'Connected to Taskaat')}
          </h2>
          <p className="text-muted-foreground">
            {connectedOn
              ? t('connected.since', 'Connected on {date}. Your agents reach the workspace\'s Taskaat through Whity, and their changes wait for a person to accept them.', { date: connectedOn })
              : t('connected.body', 'Your agents reach the workspace\'s Taskaat through Whity, and their changes wait for a person to accept them.')}
          </p>
          <p className="text-muted-foreground">
            {t('connected.where', 'Your tasks are in Tasks and Boards, and always in Taskaat too.')}
          </p>
          <div className="flex flex-wrap gap-3">
            <Button asChild variant="outline">
              <Link href="/tasks">{t('connected.tasks', 'Go to Tasks')}</Link>
            </Button>
            <Button asChild variant="outline">
              <a href={openUrl} target="_blank" rel="noopener noreferrer">
                {t('connected.open', 'Open in Taskaat')}
              </a>
            </Button>
            {manage.allowed && (
              <Button type="button" variant="outline" onClick={() => setConfirming(true)}>
                {t('disconnect.action', 'Disconnect')}
              </Button>
            )}
          </div>
        </section>
      )}

      <ConfirmAction
        open={confirming}
        onOpenChange={setConfirming}
        title={t('disconnect.title', 'Disconnect Taskaat?')}
        description={t('disconnect.body', 'Agents stop reaching the workspace\'s Taskaat. Your tasks stay in Taskaat. To revoke Whity there too, remove it from Taskaat\'s connected apps.')}
        confirmLabel={t('disconnect.confirm', 'Disconnect Taskaat')}
        cancelLabel={t('action.cancel', 'Cancel')}
        onConfirm={disconnect}
      />
    </div>
  );
}
