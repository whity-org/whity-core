'use client';

import { useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useNavigation } from '@/lib/navigation-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Stepper } from '@whity/ui/stepper';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { IconAlertTriangle, IconKey } from '@tabler/icons-react';

type CataloguePlugin = components['schemas']['StoreCataloguePlugin'];
type PluginEntry = components['schemas']['PluginEntry'];

const PLUGINS_ENABLE = 'plugins:enable';

export interface InstallPluginDialogProps {
  plugin: CataloguePlugin | null;
  storeUrl: string;
  onOpenChange: (open: boolean) => void;
}

/**
 * Install from a store in up to four steps (TplPluginInstall). Plan and Set up
 * need price and settings data no API returns yet, so they are left out, and
 * the Stepper counts only the steps shown: Access, then Done.
 */
export function InstallPluginDialog({ plugin, storeUrl, onOpenChange }: InstallPluginDialogProps) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const { refresh: refreshNavigation } = useNavigation();

  const [step, setStep] = useState(0);
  const [token, setToken] = useState('');
  const [minting, setMinting] = useState(false);
  const [installing, setInstalling] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [installed, setInstalled] = useState<PluginEntry | null>(null);
  const [installedSlug, setInstalledSlug] = useState<string | null>(null);
  const declarative = plugin?.kind === 'declarative';
  const [turningOn, setTurningOn] = useState(false);
  const [turnedOn, setTurnedOn] = useState(false);

  const close = (open: boolean) => {
    if (!open) {
      setStep(0);
      setToken('');
      setError(null);
      setInstalled(null);
      setInstalledSlug(null);
      setTurnedOn(false);
    }
    onOpenChange(open);
  };

  const mintToken = async () => {
    setMinting(true);
    try {
      // The store is this instance's own deployment in the common case; its
      // token route is plugin-declared, so it is called directly.
      const res = await fetch(`${storeUrl}/api/v1/plugin-store/tokens`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ label: 'admin-store-page' }),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok || !body?.data?.token) {
        addToast(body?.error || t('pluginStore.error.mint', 'Could not mint a store token for this store.'), 'error');
        return;
      }
      setToken(body.data.token);
      addToast(t('pluginStore.toast.minted', 'Store token minted and filled in below.'), 'success');
    } catch {
      addToast(t('pluginStore.error.mintUnreachable', 'Could not reach the store to mint a token.'), 'error');
    } finally {
      setMinting(false);
    }
  };

  const install = async () => {
    if (!plugin?.latest_version) {
      setError(
        t('pluginStore.error.noVersion', '"{name}" has no published version to install.', { name: plugin?.name ?? '' }),
      );
      return;
    }
    setInstalling(true);
    setError(null);
    try {
      if (declarative) {
        const { data, error: installError } = await api.POST('/api/v1/workspace-plugins/install-from-store', {
          body: { store_url: storeUrl, slug: plugin.slug, version: plugin.latest_version, token: token || undefined },
        });
        if (installError) {
          setError(
            installError.error ||
              t('pluginStore.error.install', 'Failed to install "{name}".', { name: plugin.name }),
          );
          return;
        }
        setInstalledSlug(data?.data.slug ?? null);
        setStep(1);
        return;
      }
      const { data, error: installError } = await api.POST('/api/v1/plugins/install-from-store', {
        body: { store_url: storeUrl, slug: plugin.slug, version: plugin.latest_version, token: token || undefined },
      });
      if (installError) {
        // The backend's own reason: no token, already installed, allowlist.
        setError(
          installError.error ||
            t('pluginStore.error.install', 'Failed to install "{name}".', { name: plugin.name }),
        );
        return;
      }
      setInstalled(data?.data ?? null);
      setStep(1);
    } finally {
      setInstalling(false);
    }
  };

  const turnOn = async () => {
    if (!installed && !installedSlug) return;
    setTurningOn(true);
    try {
      const { error: enableError } = installedSlug
        ? await api.POST('/api/v1/workspace-plugins/{plugin}/enable', { params: { path: { plugin: installedSlug } } })
        : await api.POST('/api/v1/plugins/{name}/enable', { params: { path: { name: installed?.id ?? '' } } });
      if (enableError) {
        addToast(enableError.error || t('plugins.error.enable', 'Failed to enable plugin'), 'error');
        return;
      }
      setTurnedOn(true);
      void refreshNavigation();
    } finally {
      setTurningOn(false);
    }
  };

  const mintLabel = t(
    'pluginStore.field.mintTitle',
    'Mint a download token from this store (requires an admin session on it)',
  );

  return (
    <Dialog open={plugin !== null} onOpenChange={close}>
      <DialogContent className="sm:max-w-lg">
        {plugin ? (
          <>
            <DialogHeader>
              <DialogTitle>
                {step === 0
                  ? t('pluginInstall.title', 'Install {name}', { name: plugin.name })
                  : t('pluginInstall.doneTitle', '{name} is installed', { name: plugin.name })}
              </DialogTitle>
              <DialogDescription>
                {plugin.author
                  ? t('pluginInstall.from', 'From {publisher}', { publisher: plugin.author })
                  : t('pluginInstall.fromStore', 'From the store')}
              </DialogDescription>
            </DialogHeader>

            <Stepper
              label={t('pluginInstall.steps', 'Install steps')}
              stepOfLabel={(n, total) => t('pluginInstall.stepOf', 'Step {n} of {total}', { n, total })}
              current={step}
              steps={[
                { label: t('pluginInstall.step.access', 'Access') },
                { label: t('pluginInstall.step.done', 'Done') },
              ]}
            />

            {step === 0 ? (
              <div className="space-y-4 text-sm">
                <ul className="list-disc space-y-1.5 ps-5 text-muted-foreground">
                  {declarative ? (
                    <>
                      <li>
                        {t(
                          'pluginInstall.access.declarative',
                          'It goes into this workspace only. Whity draws its screens, and none of the publisher\'s code runs. Whity has not reviewed it.',
                        )}
                      </li>
                      <li>
                        {t('pluginInstall.access.declarativeOff', 'It is installed turned off, and you decide when to turn it on.')}
                      </li>
                    </>
                  ) : (
                    <>
                      <li>
                        {t(
                          'pluginInstall.access.server',
                          'Once turned on, it runs on this server for every workspace, with full access to the data here. Whity has not reviewed it. Only install it if you trust who made it.',
                        )}
                      </li>
                      <li>
                        {t(
                          'pluginInstall.access.turnedOff',
                          'It is installed turned off, and you decide when to turn it on. A small part of it does run once now, to read its name and version.',
                        )}
                      </li>
                    </>
                  )}
                  {(plugin.permissions ?? []).length > 0 ? (
                    <li>
                      {t('pluginInstall.access.permissions', 'Permissions it asks for: {count}', {
                        count: (plugin.permissions ?? []).length,
                      })}
                    </li>
                  ) : null}
                </ul>
                {(plugin.outside_domains ?? []).map((domain) => (
                  <p key={domain} className="flex items-start gap-2 rounded-md border border-warning/30 bg-warning-tint p-3 text-warning-text">
                    <IconAlertTriangle className="mt-0.5 size-4 shrink-0" />
                    {t('pluginInstall.access.domain', 'The store says it sends data to {domain}', { domain })}
                  </p>
                ))}
                <TechnicalDetails>
                  <p className="font-mono text-xs text-muted-foreground">
                    {plugin.slug} v{plugin.latest_version ?? '-'} · {storeUrl}
                  </p>
                  {(plugin.permissions ?? []).length > 0 ? (
                    <ul className="mt-1 space-y-0.5 font-mono text-xs text-muted-foreground">
                      {(plugin.permissions ?? []).map((slug) => (
                        <li key={slug}>{slug}</li>
                      ))}
                    </ul>
                  ) : null}
                </TechnicalDetails>

                <div className="space-y-1.5">
                  <label htmlFor="plugin-store-token" className="font-medium">
                    {t('pluginStore.field.token', 'Access token')}
                  </label>
                  <div className="flex items-center gap-2">
                    <input
                      id="plugin-store-token"
                      value={token}
                      onChange={(e) => setToken(e.target.value)}
                      type="password"
                      name="plugin-store-access-token"
                      autoComplete="new-password"
                      placeholder={t('pluginStore.field.tokenPlaceholder', 'required to install')}
                      className="h-10 flex-1 rounded-md border border-input bg-background px-3 text-sm"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      onClick={mintToken}
                      disabled={minting}
                      aria-label={mintLabel}
                      title={mintLabel}
                    >
                      <IconKey className={minting ? 'animate-pulse' : undefined} />
                    </Button>
                  </div>
                </div>

                {error ? (
                  <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-destructive">
                    {error}
                  </p>
                ) : null}
              </div>
            ) : (
              <div className="space-y-3 text-sm">
                <p>
                  {turnedOn
                    ? t('pluginInstall.done.on', '{name} is turned on.', { name: plugin.name })
                    : t('pluginInstall.done.off', '{name} is installed and turned off.', { name: plugin.name })}
                </p>
                {!turnedOn ? (
                  <Badge variant="secondary">{t('pluginInstall.done.badgeOff', 'Turned off')}</Badge>
                ) : null}
              </div>
            )}

            <DialogFooter className="gap-2">
              {step === 0 ? (
                <>
                  <Button variant="outline" onClick={() => close(false)} disabled={installing}>
                    {t('pluginInstall.cancel', 'Cancel')}
                  </Button>
                  <Button onClick={install} loading={installing} disabled={installing}>
                    {t('pluginInstall.allow', 'Allow and continue')}
                  </Button>
                </>
              ) : (
                <>
                  <Button variant="outline" asChild>
                    <Link href="/admin/plugins" onClick={() => close(false)}>
                      {t('pluginInstall.goInstalled', 'Go to Installed')}
                    </Link>
                  </Button>
                  {!turnedOn && (installed || installedSlug) && hasPermission(PLUGINS_ENABLE) ? (
                    <Button onClick={turnOn} loading={turningOn} disabled={turningOn}>
                      {t('pluginInstall.turnOn', 'Turn on')}
                    </Button>
                  ) : (
                    <Button onClick={() => close(false)}>{t('pluginInstall.close', 'Close')}</Button>
                  )}
                </>
              )}
            </DialogFooter>
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  );
}
