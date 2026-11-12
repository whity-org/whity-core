'use client';

import { useEffect, useRef, useState } from 'react';
import { NoAccess } from '@/components/no-access';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { uploadPluginPackage } from '@/lib/api/plugin-upload';
import { Lottie } from '@whity/ui/lottie';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useNavigation } from '@/lib/navigation-context';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import { classifyPluginVersion, type PluginVersionTier } from '@/lib/plugin-version-badge';
import { useRichTranslation, useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { PluginsTabs } from '@/components/plugins/plugins-tabs';
import { PluginUpdateDialog, type PluginUpdateTarget } from '@/components/plugins/plugin-update-dialog';
import { useDateDisplay } from '@whity/features/datetime';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { DataTable, type DataTableColumn } from '@whity/ui/data-table';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { AreaError, EmptyFirstTime, LoadingRows, ReadOnlyNotice } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { IconReload, IconAlertCircle, IconAlertTriangle, IconDots, IconUpload } from '@tabler/icons-react';

// Core permission required to view the plugins console (WC-218). Read access is
// gated on plugins:read; per-action gating uses the other five perms via
// <PermissionButton> (WC-221).
const PLUGINS_READ = 'plugins:read';
const PLUGINS_RELOAD = 'plugins:reload';
const PLUGINS_ENABLE = 'plugins:enable';
const PLUGINS_DISABLE = 'plugins:disable';
const PLUGINS_UNINSTALL = 'plugins:uninstall';
const PLUGINS_UPLOAD = 'plugins:upload';
const PLUGINS_INSTALL = 'plugins:install';

// Reject client-side anything above this cap before hitting the network. The
// backend has its own limit; this is a fast-fail UX guard, not the authority.
const MAX_UPLOAD_BYTES = 32 * 1024 * 1024; // 32 MiB

type PluginEntry = components['schemas']['PluginEntry'];

// Extended type for backend plugin failure details
interface ExtendedPluginEntry extends PluginEntry {
  consecutive_errors?: number;
  last_error?: {
    message?: string;
    type?: string;
    trace?: string;
  };
}

// Map a version tier to a Badge variant (distinct styling per tier).
const VERSION_BADGE_VARIANT: Record<
  PluginVersionTier,
  React.ComponentProps<typeof Badge>['variant']
> = {
  alpha: 'destructive',
  beta: 'secondary',
  prerelease: 'outline',
  stable: 'default',
};

export default function PluginsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');
  const rt = useRichTranslation('admin');
  const hasAccess = hasPermission(PLUGINS_READ);

  // Sidebar nav + plugin feature descriptors: used to optimistically drop a
  // disabled/uninstalled plugin's contributed nav links and then reconcile
  // with the server, all without a full page reload (WC-221).
  const { refresh: refreshNavigation, removeItemsByHref } = useNavigation();
  // `dropped` is the answer to "why is that screen not in my navigation?"
  // (#953). It arrives on the SAME response the navigation is built from, and
  // the server sends it only to a plugins:read caller — which this page has
  // already gated on above, so nothing further is needed here.
  const { features, dropped } = usePluginFeatures();

  /** The refused descriptors belonging to one plugin, by its declared name. */
  const droppedFor = (pluginName: string) =>
    dropped.filter((entry) => entry.plugin === pluginName);

  // Fetch actual backend plugins using useFetch to avoid set-state-in-effect issues
  const { data, loading: loadingPlugins, error, refetch: fetchPlugins } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/plugins');
    if (responseData === undefined) {
      throw new Error(t('plugins.error.fetch', 'Failed to fetch plugins'));
    }
    return { plugins: responseData.data, canOperate: responseData.meta.can_operate };
  }, []);

  const plugins = data?.plugins ?? [];
  // A code plugin is install-wide, so changing one is the operator's (Q4). The
  // server decides; this only stops offering what it would refuse.
  const isOperator = data?.canOperate ?? false;
  const dates = useDateDisplay();

  // What the operator sees beyond the list: kept plugins (S.7) and updates (S.6).
  const { data: removed, refetch: refetchRemoved } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/plugins/removed');
    return responseData?.data ?? [];
  }, []);
  const { data: updates, refetch: refetchUpdates } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/plugins/updates');
    return responseData?.data ?? [];
  }, []);
  // Declarative plugins in this workspace (decision 2). The system tenant has none.
  const { data: workspacePlugins, refetch: refetchWorkspacePlugins } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/workspace-plugins');
    return responseData?.data ?? [];
  }, []);
  const switchWorkspacePlugin = async (slug: string, on: boolean) => {
    const { error: switchError } = on
      ? await api.POST('/api/v1/workspace-plugins/{plugin}/enable', { params: { path: { plugin: slug } } })
      : await api.POST('/api/v1/workspace-plugins/{plugin}/disable', { params: { path: { plugin: slug } } });
    if (switchError) {
      addToast(
        switchError.error ||
          (on ? t('plugins.error.enable', 'Failed to enable plugin') : t('plugins.error.disable', 'Failed to disable plugin')),
        'error',
      );
      return;
    }
    refetchWorkspacePlugins();
    void refreshNavigation();
  };
  const { data: trustStatus } = useFetch(async () => {
    const { data: responseData } = await api.GET('/api/v1/plugins/trust-status');
    return responseData?.data ?? null;
  }, []);
  const [updateTarget, setUpdateTarget] = useState<PluginUpdateTarget | null>(null);
  const waitingUpdates = plugins.filter((plugin) => plugin.install?.update_waiting);
  const availableUpdateFor = (plugin: PluginEntry) =>
    (updates ?? []).find((update) => update.plugin === plugin.name || update.plugin === plugin.id);

  const refetchAll = () => {
    fetchPlugins();
    refetchRemoved();
    refetchUpdates();
  };

  const reinstall = async (name: string) => {
    const { error: reinstallError } = await api.POST('/api/v1/plugins/{name}/reinstall', {
      params: { path: { name } },
    });
    if (reinstallError) {
      addToast(reinstallError.error || t('plugins.error.reinstall', 'Failed to reinstall plugin'), 'error');
      return;
    }
    addToast(t('plugins.toast.reinstalled', '{name} is back, with everything it had.', { name }), 'success');
    refetchAll();
    void refreshNavigation();
  };

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  // Modals & Dialogs
  const [detailPlugin, setDetailPlugin] = useState<PluginEntry | null>(null);
  const [uninstallTarget, setUninstallTarget] = useState<PluginEntry | null>(null);
  const [, setUninstalling] = useState(false);
  const [reloadPending, setReloadPending] = useState(false);

  // Upload dialog state
  const [uploadOpen, setUploadOpen] = useState(false);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (isCapabilitiesLoading) {
    return <Skeleton className="mx-auto h-64 max-w-7xl" />;
  }

  // RBAC gate: Access Denied if user lacks plugins:read
  if (!hasAccess) {
    return (
      <NoAccess area={t('access.area.plugins.accessDenied', 'plugins')} permission="plugins:read" />
    );
  }

  // Compute the sidebar nav hrefs contributed by a given plugin. Plugin
  // features render at /admin/x/{featureId} and the feature descriptor names
  // the plugin that provides it, so we can attribute nav links to a plugin
  // entirely client-side (no backend nav-shape change needed). The plugin
  // entry's `name` matches the feature's `plugin` field.
  const pluginNavHrefs = (plugin: PluginEntry): string[] =>
    features
      .filter((f) => f.plugin === plugin.name)
      .map((f) => `/admin/x/${f.id}`);

  // After a state change that may add/remove a plugin's nav links, reflect it
  // in the sidebar immediately: optimistically drop the plugin's hrefs (only
  // meaningful for disable/uninstall; a no-op set on enable), then refresh the
  // authoritative server-filtered nav. Both are additive — no page reload.
  const syncSidebar = (hrefs: string[]): void => {
    if (hrefs.length > 0) {
      removeItemsByHref(hrefs);
    }
    void refreshNavigation();
  };

  // Enable/Disable a backend plugin. The action is derived from the plugin's
  // lifecycle `status` (the same field that drives the button label/badge), NOT
  // from `enabled`: a plugin can be present-on-disk (`enabled: true`) yet have a
  // per-worker lifecycle `status` of `disabled`, so keying off `enabled` would
  // make the button do the opposite of its label.
  const togglePluginState = async (plugin: PluginEntry) => {
    const isCurrentlyActive = plugin.status === 'active';
    const action = isCurrentlyActive ? 'disable' : 'enable';
    // Capture the contributed nav hrefs BEFORE the await so an optimistic
    // removal on disable can fire the instant the request succeeds.
    const hrefs = pluginNavHrefs(plugin);

    addToast(
      isCurrentlyActive
        ? t('plugins.toast.disabling', 'Disabling plugin {name}...', { name: plugin.name })
        : t('plugins.toast.enabling', 'Enabling plugin {name}...', { name: plugin.name }),
      'info'
    );

    try {
      let response;
      if (action === 'enable') {
        response = await api.POST('/api/v1/plugins/{name}/enable', {
          params: { path: { name: plugin.id } },
        });
      } else {
        response = await api.POST('/api/v1/plugins/{name}/disable', {
          params: { path: { name: plugin.id } },
        });
      }

      if (response.error) {
        throw new Error(
          response.error.error ||
            (isCurrentlyActive
              ? t('plugins.error.disable', 'Failed to disable plugin')
              : t('plugins.error.enable', 'Failed to enable plugin'))
        );
      }

      addToast(
        isCurrentlyActive
          ? t('plugins.toast.disabled', 'Plugin {name} successfully disabled.', {
              name: plugin.name,
            })
          : t('plugins.toast.enabled', 'Plugin {name} successfully enabled.', {
              name: plugin.name,
            }),
        'success'
      );
      fetchPlugins();
      // Disabling drops the plugin's nav links optimistically; enabling has no
      // hrefs to remove yet, so the refresh alone restores them.
      syncSidebar(isCurrentlyActive ? hrefs : []);
    } catch (err) {
      const msg =
        err instanceof Error
          ? err.message
          : t('plugins.error.toggle', 'Error triggering plugin state');
      addToast(msg, 'error');
    }
  };

  // Re-enable a failed plugin
  const handleReEnable = async (pluginId: string) => {
    addToast(
      t('plugins.toast.reEnabling', 'Clearing failed state and re-enabling plugin...'),
      'info'
    );
    try {
      const { error: reEnableError } = await api.POST('/api/v1/plugins/{id}/re-enable', {
        params: { path: { id: pluginId } },
      });
      if (reEnableError) {
        throw new Error(
          reEnableError.error || t('plugins.error.reEnable', 'Failed to re-enable plugin')
        );
      }
      addToast(t('plugins.toast.reEnabled', 'Plugin re-enabled successfully.'), 'success');
      if (detailPlugin) {
        setDetailPlugin(null);
      }
      fetchPlugins();
      // Re-enabling can restore contributed nav links — refresh the sidebar.
      void refreshNavigation();
    } catch (err) {
      const msg =
        err instanceof Error
          ? err.message
          : t('plugins.error.reEnableUnknown', 'Error re-enabling plugin');
      addToast(msg, 'error');
    }
  };

  // Reload the plugin directory
  const handleReload = async () => {
    setReloadPending(true);
    addToast(t('plugins.toast.reloading', 'Scanning plugins directory...'), 'info');
    try {
      const { data: reloadData, error: reloadError } = await api.POST('/api/v1/plugins/reload');
      if (reloadError) {
        throw new Error(reloadError.error || t('plugins.error.reload', 'Failed to reload plugins'));
      }

      const reloadBody = reloadData as unknown as {
        message?: string;
        data?: { message?: string; worker_restart_required?: boolean };
        worker_restart_required?: boolean;
      };
      const msg =
        reloadBody?.data?.message ||
        reloadBody?.message ||
        t('plugins.toast.reloaded', 'Plugins scanned');
      addToast(msg, 'success');

      if (reloadBody?.data?.worker_restart_required || reloadBody?.worker_restart_required) {
        addToast(
          t(
            'plugins.toast.restartRequired',
            'Warning: A worker restart is required to load modified plugins.'
          ),
          'warning'
        );
      }

      fetchPlugins();
    } catch (err) {
      const msg =
        err instanceof Error ? err.message : t('plugins.error.reloadUnknown', 'Error reloading plugins');
      addToast(msg, 'error');
    } finally {
      setReloadPending(false);
    }
  };

  // Upload a plugin package (.zip or single .php) for staged install.
  const handleUpload = async () => {
    if (!uploadFile) {
      addToast(
        t('plugins.upload.noFile', 'Choose a .zip or .php plugin package to upload.'),
        'error'
      );
      return;
    }
    if (uploadFile.size > MAX_UPLOAD_BYTES) {
      addToast(
        t('plugins.upload.tooLarge', 'Package is too large (max {max} MiB).', {
          max: MAX_UPLOAD_BYTES / (1024 * 1024),
        }),
        'error'
      );
      return;
    }

    setUploading(true);
    addToast(
      t('plugins.upload.uploading', 'Uploading {name}...', { name: uploadFile.name }),
      'info'
    );
    try {
      const { error: uploadError } = await uploadPluginPackage(uploadFile);
      if (uploadError) {
        throw new Error(uploadError.error || t('plugins.error.upload', 'Failed to upload plugin'));
      }
      addToast(
        t('plugins.upload.staged', 'Plugin staged successfully. Review it, then Enable.'),
        'success'
      );
      closeUploadDialog();
      // The staged plugin appears with status `disabled` for review.
      fetchPlugins();
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('plugins.error.uploadUnknown', 'Upload failed');
      addToast(msg, 'error');
    } finally {
      setUploading(false);
    }
  };

  const closeUploadDialog = () => {
    setUploadOpen(false);
    setUploadFile(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  // Trigger Uninstall Dialog
  const confirmUninstall = (target: PluginEntry) => {
    setUninstallTarget(target);
  };

  // Execute Uninstall
  const handleUninstall = async () => {
    if (!uninstallTarget) return;
    setUninstalling(true);
    const hrefs = pluginNavHrefs(uninstallTarget);

    try {
      addToast(
        t('plugins.toast.uninstalling', 'Uninstalling plugin {name}...', {
          name: uninstallTarget.name,
        }),
        'info'
      );
      const { error: uninstallError } = await api.POST('/api/v1/plugins/{id}/uninstall', {
        params: { path: { id: uninstallTarget.id } },
      });

      if (uninstallError) {
        throw new Error(
          ('error' in uninstallError ? uninstallError.error : uninstallError.message) ||
            t('plugins.error.uninstall', 'Failed to uninstall plugin')
        );
      }

      addToast(
        t('plugins.toast.uninstalledKept', '{name} is uninstalled. You can reinstall it for 30 days.', {
          name: uninstallTarget.name,
        }),
        'success'
      );
      setUninstallTarget(null);
      if (detailPlugin) setDetailPlugin(null);
      refetchAll();
      // Uninstalling removes the plugin's nav links — drop them optimistically
      // then refresh the authoritative server-filtered nav.
      syncSidebar(hrefs);
    } catch (err) {
      const msg =
        err instanceof Error ? err.message : t('plugins.error.uninstallUnknown', 'Uninstall failed');
      addToast(msg, 'error');
    } finally {
      setUninstalling(false);
    }
  };

  // Literal keys, so i18n:extract finds them (the classifier's labels are English).
  const versionTierLabel: Record<PluginVersionTier, string> = {
    alpha: t('plugins.version.alpha', 'Alpha'),
    beta: t('plugins.version.beta', 'Beta'),
    prerelease: t('plugins.version.prerelease', 'Pre-release'),
    stable: t('plugins.version.stable', 'Stable'),
  };

  const stateOf = (plugin: PluginEntry): { label: string; variant: React.ComponentProps<typeof Badge>['variant'] } => {
    switch (plugin.status) {
      case 'active':
        return { label: t('plugins.state.active', 'Active'), variant: 'success' };
      case 'failed':
        return { label: t('plugins.state.failed', 'Failed'), variant: 'destructive' };
      case 'disabled':
        return { label: t('plugins.state.off', 'Turned off'), variant: 'secondary' };
      default:
        return { label: t('plugins.state.waiting', 'Waiting'), variant: 'outline' };
    }
  };

  const canOperate = isOperator && (hasPermission(PLUGINS_UPLOAD) || hasPermission(PLUGINS_RELOAD));

  const columns: DataTableColumn<PluginEntry>[] = [
    {
      id: 'name',
      header: t('plugins.col.name', 'Plugin'),
      cell: (plugin) => (
        <div className="space-y-1">
          <p className="font-medium text-foreground">{plugin.name}</p>
          {plugin.trust === 'verified' ? (
            <Badge variant="default" className="w-fit">{t('pluginStore.verified', 'Verified')}</Badge>
          ) : (
            <Badge variant="outline" className="w-fit">{t('pluginStore.notVerified', 'Not verified')}</Badge>
          )}
          <TechnicalDetails>
            <p className="font-mono text-xs text-muted-foreground">{plugin.id}</p>
          </TechnicalDetails>
          {droppedFor(plugin.name).length > 0 && (
            <Badge variant="destructive" className="w-fit gap-1">
              <IconAlertTriangle size={12} />
              {t('plugins.card.droppedCount', '{count} rejected screen(s)', {
                count: droppedFor(plugin.name).length,
              })}
            </Badge>
          )}
        </div>
      ),
    },
    {
      id: 'state',
      header: t('plugins.col.state', 'State'),
      cell: (plugin) => {
        const state = stateOf(plugin);
        return <Badge variant={state.variant}>{state.label}</Badge>;
      },
    },
    {
      id: 'version',
      header: t('plugins.col.version', 'Version'),
      cell: (plugin) => {
        const versionBadge = classifyPluginVersion(plugin.version);
        return (
          <span className="flex items-center gap-1.5">
            <span>{plugin.version || t('plugins.version.unknown', 'Not known')}</span>
            {versionBadge && (
              <Badge
                variant={VERSION_BADGE_VARIANT[versionBadge.tier]}
                data-testid={`version-badge-${plugin.id}`}
              >
                {versionTierLabel[versionBadge.tier]}
              </Badge>
            )}
            {plugin.install?.update_waiting ? (
              <Badge variant="warning">{t('plugins.state.updateWaiting', 'Update waiting')}</Badge>
            ) : null}
          </span>
        );
      },
    },
  ];

  const rowActions = (plugin: PluginEntry) => {
    const isActive = plugin.status === 'active';
    const toggleAllowed = isOperator && hasPermission(isActive ? PLUGINS_DISABLE : PLUGINS_ENABLE);
    const available = availableUpdateFor(plugin);
    const waiting = plugin.install?.update_waiting;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon-sm" aria-label={t('plugins.row.menu', 'More actions for {name}', { name: plugin.name })}>
            <IconDots size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => setDetailPlugin(plugin)}>
            {t('plugins.card.details', 'Details')}
          </DropdownMenuItem>
          {isOperator && hasPermission(PLUGINS_INSTALL) && available && !waiting && (
            <DropdownMenuItem
              onClick={() => setUpdateTarget({ kind: 'fetch', plugin: plugin.name, version: available.latest_version })}
            >
              {t('plugins.action.update', 'Update to {version}', { version: available.latest_version })}
            </DropdownMenuItem>
          )}
          {plugin.status === 'failed' && isOperator && hasPermission(PLUGINS_ENABLE) && (
            <DropdownMenuItem onClick={() => handleReEnable(plugin.id)}>
              {t('plugins.action.tryAgain', 'Try again')}
            </DropdownMenuItem>
          )}
          {plugin.status !== 'failed' && toggleAllowed && (
            <DropdownMenuItem onClick={() => togglePluginState(plugin)}>
              {isActive ? t('plugins.action.turnOff', 'Turn off') : t('plugins.action.turnOn', 'Turn on')}
            </DropdownMenuItem>
          )}
          {isOperator && hasPermission(PLUGINS_UNINSTALL) && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="text-destructive focus:text-destructive"
                onClick={() => confirmUninstall(plugin)}
              >
                {t('plugins.detail.uninstall', 'Uninstall')}
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  const detailFailure = detailPlugin as ExtendedPluginEntry | null;

  return (
    <div className="mx-auto max-w-7xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={t('plugins.title', 'Plugins')}
        description={t('plugins.description', 'Add apps, document blocks and agent tools, and manage what is installed.')}
        action={
          canOperate ? (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" aria-label={t('plugins.operator.menu', 'Operator tools')}>
                  <IconDots size={16} />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {hasPermission(PLUGINS_UPLOAD) && (
                  <DropdownMenuItem onClick={() => setUploadOpen(true)}>
                    <IconUpload size={16} />
                    {t('plugins.header.upload', 'Upload plugin')}
                  </DropdownMenuItem>
                )}
                {hasPermission(PLUGINS_RELOAD) && (
                  <DropdownMenuItem onClick={handleReload} disabled={reloadPending}>
                    <IconReload size={16} />
                    {t('plugins.header.reload', 'Reload plugins')}
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          ) : undefined
        }
      />
      <PluginsTabs />

      {isOperator && trustStatus && trustStatus.keys > 0 && trustStatus.stale ? (
        <div role="status" className="flex items-center gap-2 rounded-card border border-warning/30 bg-warning-tint p-4 text-sm text-warning-text">
          <IconAlertTriangle size={16} className="shrink-0" />
          {t(
            'plugins.trust.stale',
            'The list of withdrawn plugins is out of date. Verified plugins keep running, and no new ones can start until it is updated.',
          )}
        </div>
      ) : null}

      {data && !isOperator ? (
        <ReadOnlyNotice title={t('plugins.readOnly', 'Code plugins run for every workspace on this deployment, so the platform operator manages them.')} />
      ) : null}

      {isOperator && hasPermission(PLUGINS_INSTALL)
        ? waitingUpdates.map((plugin) => {
            const waiting = plugin.install?.update_waiting;
            if (!waiting) return null;
            return (
              <div
                key={plugin.id}
                role="status"
                className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-warning/30 bg-warning-tint p-4 text-sm text-warning-text"
              >
                <p className="flex items-center gap-2">
                  <IconAlertTriangle size={16} className="shrink-0" />
                  {t('plugins.updateWaiting', '{name} {version} asks for more access. The current version stays until you decide.', {
                    name: plugin.name,
                    version: waiting.version,
                  })}
                </p>
                <Button
                  variant="outline"
                  onClick={() =>
                    setUpdateTarget({
                      kind: 'review',
                      plugin: plugin.name,
                      version: waiting.version,
                      newPermissions: waiting.new_permissions,
                      newDomains: waiting.new_domains,
                    })
                  }
                >
                  {t('plugins.reviewAccess', 'Review access')}
                </Button>
              </div>
            );
          })
        : null}

      {error ? (
        <AreaError
          message={error}
          retryLabel={t('plugins.tryAgain', 'Try again')}
          onRetry={() => void fetchPlugins()}
        />
      ) : loadingPlugins ? (
        <LoadingRows
          label={t('plugins.loading', 'Loading plugins')}
          headers={[
            t('plugins.col.name', 'Plugin'),
            t('plugins.col.state', 'State'),
            t('plugins.col.version', 'Version'),
          ]}
        />
      ) : plugins.length === 0 ? (
        <EmptyFirstTime
          icon="plugin"
          moment="spot-plugin"
          title={t('plugins.empty.title', 'No plugins are installed yet')}
          description={t('plugins.empty.description', 'Find one in the Store tab and install it here.')}
          action={
            <Button asChild>
              <Link href="/admin/plugins/store">{t('plugins.empty.action', 'Browse the store')}</Link>
            </Button>
          }
        />
      ) : (
        <DataTable
          aria-label={t('plugins.table', 'Installed plugins')}
          columns={columns}
          data={plugins}
          getRowId={(plugin) => plugin.id}
          onRowClick={(plugin) => setDetailPlugin(plugin)}
          rowActions={rowActions}
          rowActionsLabel={t('plugins.col.actions', 'Actions')}
        />
      )}

      {(workspacePlugins ?? []).length > 0 ? (
        <section className="space-y-3" aria-labelledby="plugins-in-workspace">
          <h2 id="plugins-in-workspace" className="text-base font-semibold text-foreground">
            {t('plugins.workspace.title', 'In this workspace')}
          </h2>
          <ul className="divide-y divide-border rounded-card border border-border">
            {(workspacePlugins ?? []).map((entry) => (
              <li key={entry.slug} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="font-medium text-foreground">{entry.plugin}</p>
                  <span className="text-muted-foreground">{entry.version}</span>
                  <Badge variant={entry.trust === 'verified' ? 'default' : 'outline'}>
                    {entry.trust === 'verified' ? t('pluginStore.verified', 'Verified') : t('pluginStore.notVerified', 'Not verified')}
                  </Badge>
                  <Badge variant={entry.enabled ? 'success' : 'secondary'}>
                    {entry.enabled ? t('plugins.state.active', 'Active') : t('plugins.state.off', 'Turned off')}
                  </Badge>
                </div>
                {hasPermission(entry.enabled ? PLUGINS_DISABLE : PLUGINS_ENABLE) ? (
                  <Button variant="outline" onClick={() => void switchWorkspacePlugin(entry.slug, !entry.enabled)}>
                    {entry.enabled ? t('plugins.action.turnOff', 'Turn off') : t('plugins.action.turnOn', 'Turn on')}
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {isOperator && (removed ?? []).length > 0 ? (
        <section className="space-y-3" aria-labelledby="plugins-recently-removed">
          <h2 id="plugins-recently-removed" className="text-base font-semibold text-foreground">
            {t('plugins.removed.title', 'Recently removed')}
          </h2>
          <ul className="divide-y divide-border rounded-card border border-border">
            {(removed ?? []).map((entry) => (
              <li key={entry.name} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                <div className="min-w-0 space-y-0.5">
                  <p className="font-medium text-foreground">{entry.name}</p>
                  <p className="text-muted-foreground">
                    {entry.purge_failed
                      ? t('plugins.removed.purgeFailed', 'Its data could not be removed. Whity will keep trying.')
                      : dates.date(entry.purge_after)
                        ? t('plugins.removed.until', 'Removed by {person}. Its data goes on {date}.', {
                            person: entry.removed_by ?? t('plugins.removed.someone', 'someone'),
                            date: dates.date(entry.purge_after) ?? '',
                          })
                        : t('plugins.removed.untilNoDate', 'Removed by {person}. Its data goes after 30 days.', {
                            person: entry.removed_by ?? t('plugins.removed.someone', 'someone'),
                          })}
                  </p>
                </div>
                {hasPermission(PLUGINS_INSTALL) ? (
                  <Button variant="outline" onClick={() => void reinstall(entry.name)}>
                    {t('plugins.removed.reinstall', 'Reinstall')}
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <PluginUpdateDialog
        target={updateTarget}
        onClose={() => setUpdateTarget(null)}
        onChanged={() => {
          refetchAll();
          void refreshNavigation();
        }}
      />

      {/* Upload Plugin Dialog */}
      <Dialog open={uploadOpen} onOpenChange={(open) => (open ? setUploadOpen(true) : closeUploadDialog())}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('plugins.upload.title', 'Upload plugin')}</DialogTitle>
            <DialogDescription>
              {rt(
                'plugins.upload.description',
                'Upload a plugin package as a <0>.zip</0> (a plugin directory) or a single ' +
                  '<1>.php</1> file. It is staged <2>disabled</2> for review — Enable it once ' +
                  'you have inspected it.',
                undefined,
                [<code key="zip" className="font-mono" />, <code key="php" className="font-mono" />, <strong key="disabled" />]
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2">
            {uploading && <Lottie name="uploading" play="loop" className="mx-auto h-20 w-[120px]" />}
            <label className="block">
              <span className="sr-only">{t('plugins.upload.fileLabel', 'Plugin package')}</span>
              <input
                ref={fileInputRef}
                type="file"
                accept=".zip,.php"
                aria-label={t('plugins.upload.fileLabel', 'Plugin package')}
                onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                disabled={uploading}
                className="block w-full text-sm text-muted-foreground file:me-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-semibold file:text-primary-foreground"
              />
            </label>
            {uploadFile && (
              <p className="text-sm text-muted-foreground">
                {rt(
                  'plugins.upload.selected',
                  'Selected: <0>{name}</0> ({size} KiB)',
                  { name: uploadFile.name, size: (uploadFile.size / 1024).toFixed(1) },
                  [<span key="name" className="font-mono" />]
                )}
              </p>
            )}
            <p className="text-sm text-muted-foreground">
              {t('plugins.upload.maxSize', 'Maximum size {max} MiB.', { max: MAX_UPLOAD_BYTES / (1024 * 1024) })}
            </p>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={closeUploadDialog} disabled={uploading}>
              {t('plugins.upload.cancel', 'Cancel')}
            </Button>
            <Button onClick={handleUpload} disabled={uploading || !uploadFile} loading={uploading}>
              {t('plugins.upload.submit', 'Upload')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Details drawer */}
      <Sheet open={detailPlugin !== null} onOpenChange={(open) => !open && setDetailPlugin(null)}>
        <SheetContent className="overflow-y-auto sm:max-w-lg" closeLabel={t('plugins.detail.close', 'Close')}>
          {detailPlugin && detailFailure && (
            <>
              <SheetHeader>
                <SheetTitle>{detailPlugin.name}</SheetTitle>
                <SheetDescription asChild>
                  <div>
                    <Badge variant={stateOf(detailPlugin).variant}>{stateOf(detailPlugin).label}</Badge>
                  </div>
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-4 px-4 pb-4 text-sm">
                <div>
                  <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.version', 'Version')}</h4>
                  <p className="mt-1 font-medium">{detailPlugin.version || t('plugins.version.unknown', 'Not known')}</p>
                </div>

                <div>
                  <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.trust', 'Verification')}</h4>
                  {detailPlugin.trust === 'verified' && detailPlugin.mark ? (
                    <div className="mt-1 space-y-1">
                      <p className="font-medium">
                        {t('plugins.detail.reviewed', 'Reviewed on {host} on {date}; its code runs on your server.', {
                          host: detailPlugin.mark.host,
                          date: dates.date(detailPlugin.mark.reviewed_at) ?? '',
                        })}
                      </p>
                      {detailPlugin.mark.offline ? (
                        <p className="text-muted-foreground">
                          {t('plugins.detail.markOffline', 'Not confirmed since {date}: the store could not be reached.', {
                            date: dates.date(detailPlugin.mark.checked_at) ?? '',
                          })}
                        </p>
                      ) : null}
                    </div>
                  ) : (
                    <p className="mt-1 text-muted-foreground">
                      {t('plugins.detail.notVerified', 'Not verified. Its code runs on your server once it is on.')}
                    </p>
                  )}
                </div>

                {detailPlugin.install ? (
                  <div>
                    <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.installedBy', 'Installed by')}</h4>
                    <p className="mt-1 font-medium">
                      {detailPlugin.install.installed_by ?? t('plugins.removed.someone', 'someone')}
                      {dates.date(detailPlugin.install.installed_at) ? ` · ${dates.date(detailPlugin.install.installed_at)}` : ''}
                    </p>
                  </div>
                ) : null}

                {(detailPlugin.install?.outside_domains ?? []).length > 0 ? (
                  <div>
                    <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.domains', 'Outside domains the store lists')}</h4>
                    <p className="mt-1 break-all font-medium">{(detailPlugin.install?.outside_domains ?? []).join(', ')}</p>
                  </div>
                ) : null}

                {detailPlugin.status === 'failed' && (
                  <div className="space-y-2 rounded-card border border-destructive/30 bg-destructive/5 p-3">
                    <p className="flex items-center gap-1.5 font-semibold text-destructive">
                      <IconAlertCircle size={16} />
                      {t('plugins.detail.failedPlain', 'This plugin did not load, so its screens and blocks are switched off.')}
                    </p>
                    <TechnicalDetails>
                      {detailFailure.consecutive_errors !== undefined && (
                        <p className="text-xs">
                          {rt(
                            'plugins.detail.consecutiveFailures',
                            'Consecutive failures: <0>{count}</0>',
                            { count: detailFailure.consecutive_errors ?? 0 },
                            [<strong key="count" />]
                          )}
                        </p>
                      )}
                      {detailFailure.last_error && (
                        <div className="space-y-1">
                          <p className="text-xs font-semibold">
                            {detailFailure.last_error.type}: {detailFailure.last_error.message}
                          </p>
                          {detailFailure.last_error.trace && (
                            <pre className="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-background/50 p-2 font-mono text-xs">
                              {detailFailure.last_error.trace}
                            </pre>
                          )}
                        </div>
                      )}
                    </TechnicalDetails>
                    {isOperator && hasPermission(PLUGINS_ENABLE) && (
                      <Button variant="outline" onClick={() => handleReEnable(detailPlugin.id)}>
                        {t('plugins.action.tryAgain', 'Try again')}
                      </Button>
                    )}
                  </div>
                )}

                {detailPlugin.permissions_count !== undefined && (
                  <div>
                    <h4 className="mb-1.5 font-semibold text-muted-foreground">
                      {t('plugins.detail.permissionsTitle', 'Custom Permissions')}
                    </h4>
                    <Badge variant="outline">
                      {t('plugins.detail.permissionsCount', '{count} RBAC permission(s) added', {
                        count: detailPlugin.permissions_count,
                      })}
                    </Badge>
                  </div>
                )}

                <TechnicalDetails>
                  <div>
                    <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.idLabel', 'Plugin id')}</h4>
                    <p className="mt-1 break-all font-mono text-xs">{detailPlugin.id}</p>
                  </div>
                  {detailPlugin.file && (
                    <div>
                      <h4 className="font-semibold text-muted-foreground">{t('plugins.detail.file', 'Disk File')}</h4>
                      <p className="mt-1 break-all font-mono text-xs">{detailPlugin.file}</p>
                    </div>
                  )}
                  {detailPlugin.routes_count !== undefined && (
                    <div>
                      <h4 className="mb-1.5 font-semibold text-muted-foreground">
                        {t('plugins.detail.routesTitle', 'Registered Routes')}
                      </h4>
                      <Badge variant="outline">
                        {t('plugins.detail.routesCount', '{count} active API route(s) registered', {
                          count: detailPlugin.routes_count,
                        })}
                      </Badge>
                    </div>
                  )}
                </TechnicalDetails>

                {/* Screens this plugin declared and did NOT get (#953). */}
                {droppedFor(detailPlugin.name).length > 0 && (
                  <div>
                    <h4 className="mb-1.5 flex items-center gap-1.5 font-semibold text-muted-foreground">
                      <IconAlertTriangle size={14} className="text-destructive" />
                      {t('plugins.detail.droppedTitle', 'Rejected screens')}
                    </h4>
                    <p className="mb-2 text-muted-foreground">
                      {t(
                        'plugins.detail.droppedDescription',
                        'These screens were declared by the plugin and refused by the host, so they do not appear in the navigation.'
                      )}
                    </p>
                    <ul className="space-y-2">
                      {droppedFor(detailPlugin.name).map((entry, index) => (
                        <li
                          key={`${entry.featureId ?? 'unnamed'}-${index}`}
                          className="rounded-md border border-destructive/40 bg-destructive/5 p-2"
                        >
                          <TechnicalDetails>
                            <p className="font-mono font-semibold">
                              {entry.featureId ?? t('plugins.detail.droppedNoId', '(no usable id)')}
                            </p>
                          </TechnicalDetails>
                          {/* The reason is host diagnostics, rendered verbatim. */}
                          <p className="mt-0.5 text-muted-foreground">{entry.reason}</p>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>

      {/* Tier 2: uninstalling is hard to undo. */}
      <ConfirmAction
        open={uninstallTarget !== null}
        onOpenChange={(open) => !open && setUninstallTarget(null)}
        title={t('plugins.uninstall.question', 'Uninstall {name}?', { name: uninstallTarget?.name ?? '' })}
        description={t(
          'plugins.uninstall.keeps',
          'It turns off now and its screens, blocks and tools go away, for every workspace. Its data is kept for 30 days, and reinstalling in that time restores everything. After that the data is deleted.',
        )}
        confirmLabel={t('plugins.uninstall.submit', 'Uninstall plugin')}
        cancelLabel={t('plugins.uninstall.cancel', 'Cancel')}
        onConfirm={handleUninstall}
      />
    </div>
  );
}
