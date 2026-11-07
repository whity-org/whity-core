'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';

export interface StoreConnection {
  connected: boolean;
  store_host?: string;
  issuer?: string;
  account?: { sub?: string | null; name?: string | null; email?: string | null } | null;
  connected_at?: string;
  needs_reconnect?: boolean;
}

interface StoreConnectionCardProps {
  /** The store to connect to, a bare https origin. */
  storeUrl: string;
  connection: StoreConnection;
  /** Only an operator connects or disconnects; everyone else just sees the state. */
  canManage: boolean;
  onChanged: () => void;
}

/**
 * "Connect to Whity store" (WHIT-1003). An operator signs in to Whity ID once, and from then
 * on this installation installs and updates plugins from the store with no token to paste.
 * The sign-in leaves the page; Whity ID returns to /admin/plugins/store/connect.
 */
export function StoreConnectionCard({ storeUrl, connection, canManage, onChanged }: StoreConnectionCardProps) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { date } = useDateDisplay();
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const host = connection.store_host ?? storeUrl.replace(/^https:\/\//, '');
  const connectedHere = connection.connected && connection.store_host === storeUrl.replace(/^https:\/\//, '');

  const connect = async () => {
    setBusy(true);
    try {
      const { data, error } = await api.POST('/api/v1/plugins/store/connect', { body: { store_url: storeUrl } });
      if (error || !data) {
        addToast(error?.error || t('pluginStore.connect.error', 'Could not start connecting to the store.'), 'error');
        return;
      }
      window.location.assign(data.data.authorize_url);
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async () => {
    const { error } = await api.DELETE('/api/v1/plugins/store/connection');
    if (error) {
      addToast(error.error || t('pluginStore.connect.disconnectError', 'Could not disconnect from the store.'), 'error');
      return;
    }
    addToast(t('pluginStore.connect.disconnected', 'Disconnected from {host}.', { host }), 'success');
    onChanged();
  };

  const account = connection.account?.name || connection.account?.email || null;
  const since = date(connection.connected_at);

  return (
    <section
      aria-labelledby="store-connection-title"
      className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 md:flex-row md:items-center md:justify-between"
    >
      <div className="space-y-1">
        <h2 id="store-connection-title" className="font-semibold">
          {t('pluginStore.connect.title', 'Whity store')}
        </h2>
        {connectedHere && !connection.needs_reconnect ? (
          <p className="text-sm text-muted-foreground">
            {account && since
              ? t('pluginStore.connect.connectedAs', 'Connected to {host} as {account}, since {date}.', {
                  host,
                  account,
                  date: since,
                })
              : account
                ? t('pluginStore.connect.connectedAsNoDate', 'Connected to {host} as {account}.', { host, account })
                : t('pluginStore.connect.connected', 'Connected to {host}.', { host })}
            {connection.account?.name && connection.account?.email ? (
              <span className="block" dir="ltr">
                {connection.account.email}
              </span>
            ) : null}
          </p>
        ) : connectedHere && connection.needs_reconnect ? (
          <p role="status" className="text-sm text-destructive">
            {t(
              'pluginStore.connect.needsReconnect',
              'The connection to {host} has ended. Connect again to install and update plugins from it.',
              { host },
            )}
          </p>
        ) : (
          <p className="text-sm text-muted-foreground">
            {t(
              'pluginStore.connect.description',
              'Sign in with Whity ID once to install and update plugins from {host} without an access token.',
              { host },
            )}
          </p>
        )}
      </div>

      {canManage ? (
        <div className="flex gap-2">
          {connectedHere && !connection.needs_reconnect ? (
            <Button variant="outline" onClick={() => setConfirming(true)}>
              {t('pluginStore.connect.disconnect', 'Disconnect')}
            </Button>
          ) : (
            <Button variant="outline" onClick={() => void connect()} disabled={busy}>
              {connection.needs_reconnect
                ? t('pluginStore.connect.reconnect', 'Connect again')
                : t('pluginStore.connect.action', 'Connect to Whity store')}
            </Button>
          )}
        </div>
      ) : null}

      <ConfirmAction
        open={confirming}
        onOpenChange={setConfirming}
        title={t('pluginStore.connect.confirmTitle', 'Disconnect from {host}?', { host })}
        description={t(
          'pluginStore.connect.confirmDescription',
          'Installed plugins keep working. Installing and updating from this store will need an access token until someone connects again.',
        )}
        confirmLabel={t('pluginStore.connect.disconnect', 'Disconnect')}
        cancelLabel={t('common.cancel', 'Cancel')}
        onConfirm={disconnect}
      />
    </section>
  );
}
