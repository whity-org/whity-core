'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { IconAlertTriangle } from '@tabler/icons-react';

/** What the dialog is for: fetching a newer version, or reviewing one that waits (S.6). */
export type PluginUpdateTarget =
  | { kind: 'fetch'; plugin: string; version: string }
  | { kind: 'review'; plugin: string; version: string; newPermissions: string[]; newDomains: string[] };

export interface PluginUpdateDialogProps {
  target: PluginUpdateTarget | null;
  onClose: () => void;
  /** Called after anything changed on the server. */
  onChanged: () => void;
}

/**
 * Update a plugin. A version that asks for no new access applies straight away;
 * one that does waits, and this dialog then shows only what's new. The new
 * version lands turned off either way: it is new code on the server.
 */
export function PluginUpdateDialog({ target, onClose, onChanged }: PluginUpdateDialogProps) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [token, setToken] = useState('');
  const [busy, setBusy] = useState(false);
  const [waiting, setWaiting] = useState<PluginUpdateTarget | null>(null);
  const shown = waiting ?? target;

  const close = () => {
    setToken('');
    setWaiting(null);
    onClose();
  };

  const finished = () => {
    addToast(t('pluginUpdate.done', 'Updated. The new version is turned off until you turn it on.'), 'success');
    onChanged();
    close();
  };

  const fetchUpdate = async () => {
    if (!shown) return;
    setBusy(true);
    try {
      const { data, error } = await api.POST('/api/v1/plugins/{name}/update', {
        params: { path: { name: shown.plugin } },
        body: { token: token || undefined },
      });
      if (error || !data) {
        addToast(error?.error || t('pluginUpdate.error', 'The update could not be installed.'), 'error');
        return;
      }
      if (data.data.status === 'waiting_approval') {
        setWaiting({
          kind: 'review',
          plugin: shown.plugin,
          version: data.data.version ?? shown.version,
          newPermissions: data.data.new_permissions ?? [],
          newDomains: data.data.new_domains ?? [],
        });
        onChanged();
        return;
      }
      finished();
    } finally {
      setBusy(false);
    }
  };

  const decide = async (approve: boolean) => {
    if (!shown) return;
    setBusy(true);
    try {
      const { error } = approve
        ? await api.POST('/api/v1/plugins/{name}/update/approve', { params: { path: { name: shown.plugin } } })
        : await api.POST('/api/v1/plugins/{name}/update/reject', { params: { path: { name: shown.plugin } } });
      if (error) {
        addToast(error.error || t('pluginUpdate.error', 'The update could not be installed.'), 'error');
        return;
      }
      if (approve) {
        finished();
      } else {
        onChanged();
        close();
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={shown !== null} onOpenChange={(open) => !open && close()}>
      <DialogContent className="sm:max-w-lg">
        {shown ? (
          <>
            <DialogHeader>
              <DialogTitle>
                {shown.kind === 'review'
                  ? t('pluginUpdate.reviewTitle', '{name} {version} asks for more access', { name: shown.plugin, version: shown.version })
                  : t('pluginUpdate.fetchTitle', 'Update {name} to {version}', { name: shown.plugin, version: shown.version })}
              </DialogTitle>
              <DialogDescription>
                {shown.kind === 'review'
                  ? t('pluginUpdate.reviewHint', 'The current version stays until you decide. Only what is new is listed.')
                  : t('pluginUpdate.fetchHint', 'It is turned off when the update lands, and you turn it back on.')}
              </DialogDescription>
            </DialogHeader>

            {shown.kind === 'review' ? (
              <div className="space-y-3 text-sm">
                {shown.newPermissions.length > 0 ? (
                  <div>
                    <p className="font-medium">
                      {t('pluginUpdate.newPermissions', 'New permissions it asks for: {count}', { count: shown.newPermissions.length })}
                    </p>
                    <TechnicalDetails>
                      <ul className="mt-1 space-y-0.5 font-mono text-xs text-muted-foreground">
                        {shown.newPermissions.map((slug) => (
                          <li key={slug}>{slug}</li>
                        ))}
                      </ul>
                    </TechnicalDetails>
                  </div>
                ) : null}
                {shown.newDomains.map((domain) => (
                  <p key={domain} className="flex items-start gap-2 rounded-md border border-warning/30 bg-warning-tint p-3 text-warning-text">
                    <IconAlertTriangle className="mt-0.5 size-4 shrink-0" />
                    {t('pluginUpdate.newDomain', 'The store says it sends data to {domain}', { domain })}
                  </p>
                ))}
              </div>
            ) : (
              <div className="space-y-1.5 text-sm">
                <label htmlFor="plugin-update-token" className="font-medium">
                  {t('pluginStore.field.token', 'Access token')}
                </label>
                <input
                  id="plugin-update-token"
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                  type="password"
                  name="plugin-update-access-token"
                  autoComplete="new-password"
                  className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                />
              </div>
            )}

            <DialogFooter className="gap-2">
              {shown.kind === 'review' ? (
                <>
                  <Button variant="outline" onClick={() => void decide(false)} disabled={busy}>
                    {t('pluginUpdate.reject', 'Stay on this version')}
                  </Button>
                  <Button onClick={() => void decide(true)} loading={busy} disabled={busy}>
                    {t('pluginUpdate.approve', 'Allow and update')}
                  </Button>
                </>
              ) : (
                <>
                  <Button variant="outline" onClick={close} disabled={busy}>
                    {t('pluginInstall.cancel', 'Cancel')}
                  </Button>
                  <Button onClick={() => void fetchUpdate()} loading={busy} disabled={busy}>
                    {t('pluginUpdate.fetch', 'Update')}
                  </Button>
                </>
              )}
            </DialogFooter>
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  );
}
