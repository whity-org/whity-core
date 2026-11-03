'use client';

import { Suspense, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@whity/features/i18n';
import { PluginsTabs } from '@/components/plugins/plugins-tabs';
import { InstallPluginDialog } from '@/components/plugins/install-plugin-dialog';
import { PermissionButton } from '@/components/rbac/permission-button';
import { Badge } from '@whity/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { AccessDeniedPanel, AreaError, EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { IconArrowLeft, IconDownload, IconPuzzle } from '@tabler/icons-react';

const PLUGINS_READ = 'plugins:read';
const PLUGINS_UPLOAD = 'plugins:upload';

type CataloguePlugin = components['schemas']['StoreCataloguePlugin'];

function PluginDetail() {
  const t = useTranslation('admin');
  const params = useParams<{ plugin: string }>();
  const searchParams = useSearchParams();
  const slug = decodeURIComponent(params.plugin);
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const hasAccess = hasPermission(PLUGINS_READ);

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
      if (!store) {
        const { data } = await api.GET('/api/v1/plugins/store/allowed');
        const host = data?.data.hosts[0];
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
      <AccessDeniedPanel
        title={t('pluginStore.accessDenied.title', 'You cannot browse the plugin store')}
        description={t(
          'pluginStore.accessDenied',
          'You do not have the required permission (`plugins:read`) to browse the Plugin Store.',
        )}
      />
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
              {plugin.description ? <p className="text-sm text-foreground">{plugin.description}</p> : null}
            </div>
            <PermissionButton permission={PLUGINS_UPLOAD} disabled={!plugin.latest_version} onClick={() => setInstallOpen(true)} className="gap-2">
              <IconDownload />
              {t('pluginStore.action.install', 'Install')}
            </PermissionButton>
          </header>

          <div className="grid gap-6 md:grid-cols-[1fr_20rem]">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">{t('pluginDetail.overview', 'Overview')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
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
              </CardContent>
            </Card>

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
                      {t('pluginDetail.runsFrame', 'In an isolated frame, installed turned off')}
                    </dd>
                  </div>
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
