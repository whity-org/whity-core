'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { PluginsTabs } from '@/components/plugins/plugins-tabs';
import { InstallPluginDialog } from '@/components/plugins/install-plugin-dialog';
import { InstallButton, type CataloguePlugin, usePluginAddsLabels, usePriceLabel, useUsageLabel } from '@/components/plugins/plugin-facts';
import { Button } from '@whity/ui/button';
import { PluginTile } from '@whity/ui/plugin-card';
import { SearchField } from '@whity/ui/search-field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';

// Read access to the store matches the Installed tab (plugins:read). Install is
// plugins:install, and only for operators: a code plugin is install-wide (Q4).
const PLUGINS_READ = 'plugins:read';

export default function PluginStorePage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const addsLabels = usePluginAddsLabels();
  const priceLabel = usePriceLabel();
  const usageLabel = useUsageLabel();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const hasAccess = hasPermission(PLUGINS_READ);

  const [allowedHosts, setAllowedHosts] = useState<string[]>([]);
  const [featureEnabled, setFeatureEnabled] = useState<boolean | null>(null);
  const [canInstall, setCanInstall] = useState(false);
  const [canInstallDeclarative, setCanInstallDeclarative] = useState(false);
  const [storeUrl, setStoreUrl] = useState('');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState<string | null>(null);

  const [plugins, setPlugins] = useState<CataloguePlugin[]>([]);
  const [browsing, setBrowsing] = useState(false);
  const [hasBrowsed, setHasBrowsed] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [installing, setInstalling] = useState<CataloguePlugin | null>(null);

  // Load the operator's trusted store hosts once.
  const loadHosts = useCallback(async () => {
    const { data, error } = await api.GET('/api/v1/plugins/store/allowed');
    if (error || !data) {
      setLoadError(error?.error || t('pluginStore.error.loadStores', 'Could not load the configured stores.'));
      return;
    }
    setLoadError(null);
    setAllowedHosts(data.data.hosts);
    setFeatureEnabled(data.data.enabled);
    setCanInstall(data.data.can_install);
    setCanInstallDeclarative(data.data.can_install_declarative);
    // Preselect the first store so a one-store deployment needs no click.
    if (data.data.hosts.length > 0) {
      setStoreUrl(`https://${data.data.hosts[0]}`);
    }
  }, [t]);

  useEffect(() => {
    if (!hasAccess) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads once on mount
    void loadHosts();
  }, [hasAccess, loadHosts]);

  const browse = useCallback(
    async (term: string) => {
      if (!storeUrl) return;
      setBrowsing(true);
      try {
        const { data, error } = await api.GET('/api/v1/plugins/store/catalog', {
          params: { query: { store_url: storeUrl, q: term || undefined } },
        });
        if (error || !data) {
          setLoadError(error?.error || t('pluginStore.error.catalogue', 'The store catalogue could not be loaded.'));
          return;
        }
        setLoadError(null);
        setPlugins(data.data);
        setHasBrowsed(true);
      } finally {
        setBrowsing(false);
      }
    },
    [storeUrl, t],
  );

  // The store shows its plugins as soon as it is chosen.
  useEffect(() => {
    if (!storeUrl || featureEnabled === false) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads when the store changes
    void browse('');
  }, [storeUrl, featureEnabled, browse]);

  const categories = useMemo(() => {
    const tags = new Set<string>();
    plugins.forEach((p) => (p.tags ?? []).forEach((tag) => tags.add(tag)));
    return [...tags].sort();
  }, [plugins]);

  const visible = category ? plugins.filter((p) => (p.tags ?? []).includes(category)) : plugins;

  const openPlugin = (plugin: CataloguePlugin) => {
    router.push(
      `/admin/plugins/store/${encodeURIComponent(plugin.slug)}?store=${encodeURIComponent(storeUrl)}`,
    );
  };

  if (isCapabilitiesLoading) {
    return <Skeleton className="mx-auto h-64 max-w-7xl" />;
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

  return (
    <div className="mx-auto max-w-7xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={t('plugins.title', 'Plugins')}
        description={t('pluginStore.description', 'Browse a trusted store. A plugin installed here runs for every workspace on this deployment.')}
      />
      <PluginsTabs />

      {loadError ? (
        <AreaError
          message={loadError}
          retryLabel={t('plugins.tryAgain', 'Try again')}
          onRetry={() => void (featureEnabled === null ? loadHosts() : browse(search))}
        />
      ) : featureEnabled === false ? (
        <EmptyFirstTime
          icon="plugin"
          title={t('pluginStore.disabled.title', 'Plugin store disabled')}
          description={t(
            'pluginStore.disabled.description',
            'Installing from a store is turned off on this instance. It needs the plugin marketplace switched on in Feature flags and at least one trusted store in the global settings.',
          )}
        />
      ) : (
        <>
          <div className="flex flex-col gap-3 md:flex-row md:items-center">
            <SearchField
              className="flex-1"
              value={search}
              onValueChange={setSearch}
              onSearch={(value) => void browse(value)}
              label={t('pluginStore.field.search', 'Search')}
              clearLabel={t('plugins.clearSearch', 'Clear search')}
              placeholder={t('pluginStore.field.searchPlaceholder', 'name, slug, tag…')}
              name="plugin-store-search"
              autoComplete="off"
            />
            {allowedHosts.length > 1 ? (
              <Select value={storeUrl} onValueChange={setStoreUrl}>
                <SelectTrigger aria-label={t('pluginStore.field.store', 'Store')} className="md:w-64">
                  <SelectValue placeholder={t('pluginStore.field.storePlaceholder', 'Choose a store…')} />
                </SelectTrigger>
                <SelectContent>
                  {allowedHosts.map((h) => (
                    <SelectItem key={h} value={`https://${h}`}>
                      {h}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : null}
          </div>

          {categories.length > 0 ? (
            <div className="flex flex-wrap gap-2" role="group" aria-label={t('pluginStore.categories', 'Categories')}>
              <Button
                size="sm"
                variant={category === null ? 'default' : 'outline'}
                aria-pressed={category === null}
                onClick={() => setCategory(null)}
              >
                {t('pluginStore.category.all', 'All')}
              </Button>
              {categories.map((tag) => (
                <Button
                  key={tag}
                  size="sm"
                  variant={category === tag ? 'default' : 'outline'}
                  aria-pressed={category === tag}
                  onClick={() => setCategory(tag)}
                >
                  {tag}
                </Button>
              ))}
            </div>
          ) : null}

          {browsing && !hasBrowsed ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3" aria-busy="true">
              {[1, 2, 3].map((n) => (
                <Skeleton key={n} className="h-40" />
              ))}
            </div>
          ) : visible.length === 0 ? (
            search || category ? (
              <EmptyFiltered
                title={t('pluginStore.empty.noMatches', 'No plugins matched your search.')}
                clearLabel={t('plugins.clearFilters', 'Clear filters')}
                onClear={() => {
                  setSearch('');
                  setCategory(null);
                  void browse('');
                }}
              />
            ) : (
              <EmptyFirstTime
                icon="plugin"
                title={t('pluginStore.empty.none', 'This store has no plugins yet.')}
              />
            )
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              {visible.map((plugin) => (
                <PluginTile
                  key={plugin.slug}
                  name={plugin.name}
                  publisher={plugin.author}
                  summary={plugin.description}
                  version={plugin.latest_version}
                  tags={plugin.tags}
                  adds={addsLabels(plugin)}
                  trustLabel={t('pluginStore.notVerified', 'Not verified')}
                  facts={[priceLabel(plugin), usageLabel(plugin)].filter((fact): fact is string => fact !== null)}
                  onOpen={() => openPlugin(plugin)}
                  action={
                    <InstallButton
                      canInstall={plugin.kind === 'declarative' ? canInstallDeclarative : canInstall}
                      disabled={!plugin.latest_version}
                      onClick={() => setInstalling(plugin)}
                    />
                  }
                />
              ))}
            </div>
          )}
        </>
      )}

      <InstallPluginDialog
        plugin={installing}
        storeUrl={storeUrl}
        onOpenChange={(open) => {
          if (!open) setInstalling(null);
        }}
      />
    </div>
  );
}
