'use client';

import { Suspense, useCallback, useEffect, useState } from 'react';
import { NoAccess } from '@/components/no-access';
import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@whity/features/i18n';
import { PluginsTabs } from '@/components/plugins/plugins-tabs';
import { InstallPluginDialog } from '@/components/plugins/install-plugin-dialog';
import { InstallButton, type CataloguePlugin, usePluginAddsLabels, usePriceLabel, useUsageLabel } from '@/components/plugins/plugin-facts';
import { Badge } from '@whity/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { AreaError, EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useDateDisplay } from '@whity/features/datetime';
import { IconAlertTriangle, IconArrowLeft, IconPuzzle } from '@tabler/icons-react';

const PLUGINS_READ = 'plugins:read';

function PluginDetail() {
  const t = useTranslation('admin');
  const params = useParams<{ plugin: string }>();
  const searchParams = useSearchParams();
  const slug = decodeURIComponent(params.plugin);
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const hasAccess = hasPermission(PLUGINS_READ);
  const addsLabels = usePluginAddsLabels();
  const priceLabel = usePriceLabel();
  const usageLabel = useUsageLabel();
  const dates = useDateDisplay();
  const [canInstall, setCanInstall] = useState(false);
  const [canInstallDeclarative, setCanInstallDeclarative] = useState(false);

  const [storeUrl, setStoreUrl] = useState(searchParams.get('store') ?? '');
  const [plugin, setPlugin] = useState<CataloguePlugin | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [installOpen, setInstallOpen] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      let store = storeUrl;
      const { data: allowed } = await api.GET('/api/v1/plugins/store/allowed');
      setCanInstall(allowed?.data.can_install ?? false);
      setCanInstallDeclarative(allowed?.data.can_install_declarative ?? false);
      if (!store) {
        const host = allowed?.data.hosts[0];
        if (!host) {
          setPlugin(null);
          return;
        }
        store = `https://${host}`;
        setStoreUrl(store);
      }
      const { data, error: catalogueError } = await api.GET('/api/v1/plugins/store/catalog', {
        params: { query: { store_url: store, q: slug } },
      });
      if (catalogueError || !data) {
        setError(catalogueError?.error || t('pluginStore.error.catalogue', 'The store catalogue could not be loaded.'));
        return;
      }
      setPlugin(data.data.find((p) => p.slug === slug) ?? null);
    } finally {
      setLoading(false);
    }
  }, [storeUrl, slug, t]);

  useEffect(() => {
    if (!hasAccess) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads once on mount
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasAccess, slug]);

  if (isCapabilitiesLoading) {
    return <Skeleton className="mx-auto h-64 max-w-5xl" />;
  }
  if (!hasAccess) {
    return (
      <NoAccess area={t('access.area.pluginStore.accessDenied', 'the plugin store')} permission="plugins:read" />
    );
  }

  const back = (
    <Link
      href="/admin/plugins/store"
      className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
    >
      <IconArrowLeft className="size-4 rtl:-scale-x-100" />
      {t('pluginDetail.back', 'Back to the store')}
    </Link>
  );

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 pb-16 md:px-0">
      <div className="space-y-3">
        <h1 className="text-3xl font-semibold text-foreground">{t('plugins.title', 'Plugins')}</h1>
        <PluginsTabs />
        {back}
      </div>

      {loading ? (
        <Skeleton className="h-48" aria-busy="true" />
      ) : error ? (
        <AreaError message={error} retryLabel={t('plugins.tryAgain', 'Try again')} onRetry={() => void load()} />
      ) : plugin === null ? (
        <EmptyFirstTime
          icon="plugin"
          title={t('pluginDetail.notFound', 'This plugin is not in the store.')}
          description={t('pluginDetail.notFoundHint', 'It may have been removed, or the store is not available.')}
        />
      ) : (
        <>
          <header className="flex flex-wrap items-start gap-4">
            <div className="grid size-16 shrink-0 place-items-center rounded-card bg-muted text-primary [&_svg]:size-8">
              <IconPuzzle />
            </div>
            <div className="min-w-0 flex-1 space-y-1">
              <h2 className="text-2xl font-semibold text-foreground">{plugin.name}</h2>
              {plugin.author ? <p className="text-sm text-muted-foreground">{plugin.author}</p> : null}
              <Badge variant="outline">{t('pluginStore.notVerified', 'Not verified')}</Badge>
              {plugin.description ? <p className="text-sm text-foreground">{plugin.description}</p> : null}
              {usageLabel(plugin) ? <p className="text-sm text-muted-foreground">{usageLabel(plugin)}</p> : null}
              {addsLabels(plugin).length > 0 ? (
                <div className="flex flex-wrap gap-1.5 pt-1">
                  {addsLabels(plugin).map((label) => (
                    <Badge key={label} variant="secondary">
                      {label}
                    </Badge>
                  ))}
                </div>
              ) : null}
            </div>
            <div className="flex flex-col items-end gap-1">
              <InstallButton canInstall={plugin.kind === 'declarative' ? canInstallDeclarative : canInstall} disabled={!plugin.latest_version} onClick={() => setInstallOpen(true)} />
              {priceLabel(plugin) ? <p className="text-sm text-muted-foreground">{priceLabel(plugin)}</p> : null}
            </div>
          </header>

          <div className="grid gap-6 md:grid-cols-[1fr_20rem]">
            <Tabs defaultValue="overview">
              <TabsList variant="line">
                <TabsTrigger value="overview">{t('pluginDetail.overview', 'Overview')}</TabsTrigger>
                <TabsTrigger value="permissions">
                  {t('pluginDetail.permissions', 'Permissions ({count})', { count: (plugin.permissions ?? []).length })}
                </TabsTrigger>
                <TabsTrigger value="changelog">{t('pluginDetail.changelog', 'Changelog')}</TabsTrigger>
                <TabsTrigger value="support">{t('pluginDetail.support', 'Support')}</TabsTrigger>
              </TabsList>
              <TabsContent value="overview" className="space-y-3 pt-4 text-sm">
                <p className="text-muted-foreground">
                  {plugin.description || t('pluginDetail.noDescription', 'The publisher has not added a description.')}
                </p>
                {(plugin.tags ?? []).length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {(plugin.tags ?? []).map((tag) => (
                      <Badge key={tag} variant="outline">
                        {tag}
                      </Badge>
                    ))}
                  </div>
                ) : null}
              </TabsContent>
              <TabsContent value="permissions" className="space-y-2 pt-4 text-sm">
                <p className="text-muted-foreground">
                  {t(
                    'pluginDetail.permissionsNote',
                    'What the store lists. Once turned on, a plugin runs on the server, so this is what it asks for, not a limit on what it can do.',
                  )}
                </p>
                {(plugin.permissions ?? []).length > 0 ? (
                  <ul className="space-y-1 font-mono text-xs">
                    {(plugin.permissions ?? []).map((slug) => (
                      <li key={slug}>{slug}</li>
                    ))}
                  </ul>
                ) : null}
              </TabsContent>
              <TabsContent value="changelog" className="space-y-3 pt-4 text-sm">
                {(plugin.changelog ?? []).length === 0 ? (
                  <p className="text-muted-foreground">{t('pluginDetail.noChangelog', 'The publisher has not added a changelog.')}</p>
                ) : (
                  (plugin.changelog ?? []).map((entry) => (
                    <div key={entry.version}>
                      <p className="font-medium">
                        {entry.version}
                        {entry.date && dates.date(entry.date) ? ` · ${dates.date(entry.date)}` : ''}
                      </p>
                      <p className="text-muted-foreground">{entry.notes}</p>
                    </div>
                  ))
                )}
              </TabsContent>
              <TabsContent value="support" className="pt-4 text-sm">
                {plugin.support_url ? (
                  <a href={plugin.support_url} target="_blank" rel="noopener noreferrer" className="break-all text-primary underline">
                    {plugin.support_url}
                  </a>
                ) : (
                  <p className="text-muted-foreground">{t('pluginDetail.noSupport', 'The publisher has not added a support link.')}</p>
                )}
              </TabsContent>
            </Tabs>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">{t('pluginDetail.facts', 'Facts')}</CardTitle>
              </CardHeader>
              <CardContent>
                <dl className="space-y-3 text-sm">
                  {plugin.author ? (
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.publisher', 'Publisher')}</dt>
                      <dd className="font-medium">{plugin.author}</dd>
                    </div>
                  ) : null}
                  {plugin.latest_version ? (
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.version', 'Version')}</dt>
                      <dd className="font-medium">{plugin.latest_version}</dd>
                    </div>
                  ) : null}
                  <div>
                    <dt className="text-muted-foreground">{t('pluginDetail.runs', 'How it runs')}</dt>
                    <dd className="font-medium">
                      {plugin.kind === 'declarative'
                        ? t(
                            'pluginDetail.runsDeclarative',
                            'In this workspace only, drawn by Whity. None of the publisher\'s code runs. Whity has not reviewed it.',
                          )
                        : t(
                            'pluginDetail.runsServer',
                            'On this server, for every workspace, once it is turned on. Whity has not reviewed it.',
                          )}
                    </dd>
                  </div>
                  {plugin.updated_at && dates.date(plugin.updated_at) ? (
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.updated', 'Last update')}</dt>
                      <dd className="font-medium">{dates.date(plugin.updated_at)}</dd>
                    </div>
                  ) : null}
                  {(plugin.languages ?? []).length > 0 ? (
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.languages', 'Languages')}</dt>
                      <dd className="font-medium">{(plugin.languages ?? []).join(', ')}</dd>
                    </div>
                  ) : null}
                  {(plugin.outside_domains ?? []).length > 0 ? (
                    <div className="rounded-md border border-warning/30 bg-warning-tint p-3 text-warning-text">
                      <dt className="flex items-center gap-1.5 font-medium">
                        <IconAlertTriangle className="size-4 shrink-0" />
                        {t('pluginDetail.outside', 'Sends data outside')}
                      </dt>
                      <dd className="mt-1 break-all">{(plugin.outside_domains ?? []).join(', ')}</dd>
                    </div>
                  ) : null}
                  <TechnicalDetails>
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.source', 'Store')}</dt>
                      <dd className="break-all font-mono text-xs">{storeUrl}</dd>
                    </div>
                    <div>
                      <dt className="text-muted-foreground">{t('pluginDetail.slug', 'Plugin id')}</dt>
                      <dd className="font-mono text-xs">{plugin.slug}</dd>
                    </div>
                  </TechnicalDetails>
                </dl>
              </CardContent>
            </Card>
          </div>
        </>
      )}

      <InstallPluginDialog
        plugin={installOpen ? plugin : null}
        storeUrl={storeUrl}
        onOpenChange={setInstallOpen}
      />
    </div>
  );
}

export default function PluginDetailPage() {
  return (
    <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-5xl" />}>
      <PluginDetail />
    </Suspense>
  );
}
