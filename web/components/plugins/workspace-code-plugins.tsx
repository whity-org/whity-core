'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { navigateExternal } from '@/lib/external-navigate';
import { formatMoney } from '@whity/ui/money/currency';
import { useToast } from '@/lib/toast-context';
import { useNavigation } from '@/lib/navigation-context';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Switch } from '@whity/ui/switch';
import { Checkbox } from '@whity/ui/checkbox';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';

type WorkspaceCodePlugin = components['schemas']['WorkspaceCodePlugin'];

/** One price of a paid plugin's add-on plan, from /api/v1/billing/plans. */
interface AddonPrice {
  plan_key: string;
  is_addon: boolean;
  unit_amount: number;
  currency: string;
  billing_period: string;
}
type CodePluginAccess = components['schemas']['CodePluginAccess'];
type Access = CodePluginAccess['access'];

/**
 * Installed plugins, switched on per workspace (WHIT-1124).
 *
 * A workspace sees every plugin the operator lets workspaces use. A plugin
 * the operator left on for everyone reads "Always on"; the others have a
 * switch. Turning one off keeps its data. The last change says who and when,
 * and History lists every change.
 */
export function WorkspaceCodePlugins({ canEnable, canDisable }: { canEnable: boolean; canDisable: boolean }) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { addToast } = useToast();
  const { refresh: refreshNavigation } = useNavigation();
  // WHIT-1148: a plugin with a guided setup opens it as soon as it is on.
  const router = useRouter();
  const openSetup = (plugin: WorkspaceCodePlugin) => {
    if (plugin.onboarding) {
      router.push(`/admin/setup/${encodeURIComponent(plugin.name)}`);
    }
  };
  const [pending, setPending] = useState<string | null>(null);
  const [historyFor, setHistoryFor] = useState<string | null>(null);

  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: response } = await api.GET('/api/v1/workspace/code-plugins');
    return response?.data ?? [];
  }, []);

  // WHIT-1127: what a paid plugin costs, from the deployment's add-on plans.
  const { hasPermission } = useCapabilities();
  const canPay = hasPermission(BILLING_PAY);
  const [buying, setBuying] = useState<string | null>(null);
  const { data: addonPrices } = useFetch(async () => {
    const { data: body, response: res } = await api.GET('/api/v1/billing/plans');
    if (!res.ok) return [] as AddonPrice[];
    return (((body as { data?: AddonPrice[] } | undefined)?.data ?? []) as AddonPrice[]).filter((plan) => plan.is_addon);
  }, []);
  // Every price of the plugin's add-on, shortest period first (WHIT-1149):
  // month, six months, year.
  const PERIOD_ORDER: Record<string, number> = { month: 1, quarter: 2, half_year: 3, year: 4, once: 5 };
  const pricesFor = (product: string | null | undefined): AddonPrice[] => {
    if (!product) return [];
    return (addonPrices ?? [])
      .filter((plan) => plan.plan_key.startsWith(`${product}.`))
      .sort((a, b) => (PERIOD_ORDER[a.billing_period] ?? 9) - (PERIOD_ORDER[b.billing_period] ?? 9));
  };
  const priceFor = (product: string | null | undefined): AddonPrice | null => pricesFor(product)[0] ?? null;
  const periodPrice = (plan: AddonPrice): string => {
    const amount = formatMoney(plan.unit_amount, plan.currency);
    switch (plan.billing_period) {
      case 'year':
        return t('plugins.paid.perYear', '{price} a year', { price: amount });
      case 'half_year':
        return t('plugins.paid.perHalfYear', '{price} every 6 months', { price: amount });
      case 'quarter':
        return t('plugins.paid.perQuarter', '{price} every 3 months', { price: amount });
      case 'once':
        return t('plugins.paid.once', '{price} once', { price: amount });
      default:
        return t('plugins.paid.perMonth', '{price} a month', { price: amount });
    }
  };

  const startTrial = async (plugin: WorkspaceCodePlugin) => {
    setPending(plugin.name);
    const { error: trialError } = await api.POST('/api/v1/workspace/code-plugins/{plugin}/trial', {
      params: { path: { plugin: plugin.name } },
    });
    setPending(null);
    if (trialError) {
      addToast(trialError.error || t('plugins.paid.trialError', 'The trial could not be started. Try again.'), 'error');
      return;
    }
    addToast(
      t('plugins.paid.trialStarted', 'Your {days}-day trial of {name} has started.', { days: plugin.trialDays ?? 0, name: plugin.name }),
      'success',
    );
    refetch();
    void refreshNavigation();
    openSetup(plugin);
  };

  const buy = async (plugin: WorkspaceCodePlugin, price: AddonPrice) => {
    setBuying(plugin.name);
    try {
      const { data: body, response: res } = await api.POST('/api/v1/billing/checkout', {
        body: { plan_key: price.plan_key, billing_period: price.billing_period } as never,
      });
      const url = (body as { data?: { url?: string } } | undefined)?.data?.url;
      if (!res.ok || !url) {
        addToast(t('billing.plans.error.start', 'Could not start checkout. Please try again.'), 'error');
        return;
      }
      navigateExternal(url);
    } finally {
      setBuying(null);
    }
  };

  const statusLine = (plugin: WorkspaceCodePlugin): string | null => {
    if (!plugin.paid || !plugin.enabled) return null;
    switch (plugin.reason) {
      case 'trial':
        return t('plugins.paid.trialUntil', 'Free trial until {date}', { date: dates.date(plugin.trialEndsAt ?? '') ?? '' });
      case 'grace':
        return t('plugins.paid.graceUntil', 'Its access has ended. It keeps working until {date}, then becomes read-only.', {
          date: dates.date(plugin.graceUntil ?? '') ?? '',
        });
      case 'lapsed':
        return t('plugins.paid.readOnly', 'Read-only: you can see everything, but not change it, until you buy it again. Nothing was deleted.');
      case 'installation_unlicensed':
        return t('plugins.paid.unlicensed', 'Read-only: your platform’s licence for this plugin has ended. Ask your platform operator.');
      case 'installation_grace':
        return t('plugins.paid.licenceGrace', 'Your platform’s licence for this plugin needs renewing soon.');
      case 'entitled':
        return t('plugins.paid.included', 'Included in your plan or bought for this workspace');
      case 'free_for_all':
        return t('plugins.paid.freeForAll', 'Free for every workspace on this platform');
      default:
        return null;
    }
  };

  const switchPlugin = async (plugin: WorkspaceCodePlugin, on: boolean) => {
    setPending(plugin.name);
    const path = { params: { path: { plugin: plugin.name } } };
    const { error: switchError } = on
      ? await api.POST('/api/v1/workspace/code-plugins/{plugin}/enable', path)
      : await api.POST('/api/v1/workspace/code-plugins/{plugin}/disable', path);
    setPending(null);
    if (switchError) {
      addToast(
        switchError.error ||
          (on ? t('plugins.error.enable', 'Failed to enable plugin') : t('plugins.error.disable', 'Failed to disable plugin')),
        'error',
      );
      return;
    }
    addToast(
      on
        ? t('plugins.workspaceCode.turnedOn', '{name} is on for this workspace.', { name: plugin.name })
        : t('plugins.workspaceCode.turnedOff', '{name} is off. Its data is kept, and turning it back on brings everything back.', {
            name: plugin.name,
          }),
      'success',
    );
    refetch();
    void refreshNavigation();
    if (on) {
      openSetup(plugin);
    }
  };

  const lastChange = (plugin: WorkspaceCodePlugin): string | null => {
    if (!plugin.changedAt) return null;
    const when = dates.date(plugin.changedAt) ?? '';
    const who = plugin.changedBy?.name || t('plugins.removed.someone', 'someone');

    return plugin.enabled
      ? t('plugins.workspaceCode.turnedOnBy', 'Turned on by {who}, {when}', { who, when })
      : t('plugins.workspaceCode.turnedOffBy', 'Turned off by {who}, {when}', { who, when });
  };

  return (
    <section className="space-y-3" aria-labelledby="plugins-for-workspace">
      <div className="space-y-1">
        <h2 id="plugins-for-workspace" className="text-base font-semibold text-foreground">
          {t('plugins.workspaceCode.title', 'Plugins for this workspace')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t(
            'plugins.workspaceCode.description',
            'Your platform installs these once. Turn on the ones this workspace needs. Turning one off hides it and keeps its data.',
          )}
        </p>
      </div>

      {error ? (
        <AreaError message={error} retryLabel={t('plugins.tryAgain', 'Try again')} onRetry={() => void refetch()} />
      ) : loading ? (
        <LoadingRows label={t('plugins.loading', 'Loading plugins')} headers={[t('plugins.col.name', 'Plugin')]} />
      ) : (data ?? []).length === 0 ? (
        <p className="rounded-card border border-border p-4 text-sm text-muted-foreground">
          {t('plugins.workspaceCode.empty', 'No plugins are available to this workspace yet.')}
        </p>
      ) : (
        <ul className="divide-y divide-border rounded-card border border-border">
          {(data ?? []).map((plugin) => {
            const allowed = plugin.enabled ? canDisable : canEnable;
            const change = lastChange(plugin);
            const status = statusLine(plugin);
            const price = priceFor(plugin.product);
            const prices = pricesFor(plugin.product);
            // A paid plugin this workspace can't turn on yet offers a trial or Buy instead of the switch.
            const needsPurchase = plugin.paid && !plugin.mayTurnOn;
            const offerBuy =
              plugin.paid && canPay && price !== null && (needsPurchase || plugin.reason === 'trial' || plugin.reason === 'grace' || plugin.reason === 'lapsed');
            return (
              <li key={plugin.name} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium text-foreground">{plugin.name}</p>
                    <span className="font-mono text-muted-foreground" dir="ltr">
                      {plugin.version}
                    </span>
                    {!plugin.canSwitch ? (
                      <Badge variant="secondary">{t('plugins.workspaceCode.alwaysOn', 'Always on')}</Badge>
                    ) : null}
                    {plugin.paid ? <Badge variant="outline">{t('plugins.paid.badge', 'Paid')}</Badge> : null}
                    {plugin.state === 'read_only' ? (
                      <Badge variant="secondary">{t('plugins.paid.readOnlyBadge', 'Read-only')}</Badge>
                    ) : null}
                  </div>
                  {status ? (
                    <p className={plugin.state === 'read_only' || plugin.reason === 'grace' ? 'text-warning-text' : 'text-muted-foreground'}>
                      {status}
                    </p>
                  ) : null}
                  {needsPurchase && prices.length > 0 ? (
                    <p className="text-muted-foreground">
                      {t('plugins.paid.prices', 'For this workspace: {prices}', { prices: prices.map(periodPrice).join(' · ') })}
                    </p>
                  ) : null}
                  {change ? <p className="text-muted-foreground">{change}</p> : null}
                </div>
                <div className="flex items-center gap-3">
                  {plugin.onboarding && plugin.enabled && plugin.state !== 'read_only' ? (
                    <Button asChild variant="outline" size="sm">
                      <Link href={`/admin/setup/${encodeURIComponent(plugin.name)}`}>{t('plugins.workspaceCode.setUp', 'Set up')}</Link>
                    </Button>
                  ) : null}
                  {plugin.canSwitch ? (
                    <Button variant="outline" size="sm" onClick={() => setHistoryFor(plugin.name)}>
                      {t('plugins.workspaceCode.history', 'History')}
                    </Button>
                  ) : null}
                  {needsPurchase && plugin.trialAvailable && canEnable ? (
                    <Button variant="outline" size="sm" disabled={pending === plugin.name} onClick={() => void startTrial(plugin)}>
                      {t('plugins.paid.startTrial', 'Start {days}-day trial', { days: plugin.trialDays ?? 0 })}
                    </Button>
                  ) : null}
                  {offerBuy && price && prices.length === 1 ? (
                    <Button variant="outline" size="sm" disabled={buying === plugin.name} onClick={() => void buy(plugin, price)}>
                      {t('plugins.paid.buy', 'Buy')}
                    </Button>
                  ) : null}
                  {offerBuy && prices.length > 1 ? (
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" disabled={buying === plugin.name}>
                          {t('plugins.paid.buy', 'Buy')}
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {prices.map((option) => (
                          <DropdownMenuItem key={option.plan_key + option.billing_period} onClick={() => void buy(plugin, option)}>
                            {periodPrice(option)}
                          </DropdownMenuItem>
                        ))}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  ) : null}
                  {plugin.canSwitch && !needsPurchase ? (
                    <Switch
                      checked={plugin.enabled}
                      disabled={!allowed || pending === plugin.name}
                      onCheckedChange={(checked) => void switchPlugin(plugin, checked)}
                      aria-label={t('plugins.workspaceCode.switch', 'Use {name} in this workspace', { name: plugin.name })}
                    />
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      <PluginHistorySheet plugin={historyFor} onClose={() => setHistoryFor(null)} />
    </section>
  );
}

function PluginHistorySheet({ plugin, onClose }: { plugin: string | null; onClose: () => void }) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { data, loading } = useFetch(async () => {
    if (plugin === null) return [];
    const { data: response } = await api.GET('/api/v1/workspace/code-plugins/{plugin}/history', {
      params: { path: { plugin } },
    });
    return response?.data ?? [];
  }, [plugin]);

  return (
    <Sheet open={plugin !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{t('plugins.workspaceCode.historyTitle', 'History of {name}', { name: plugin ?? '' })}</SheetTitle>
          <SheetDescription>
            {t('plugins.workspaceCode.historyDescription', 'Who turned it on or off in this workspace, newest first.')}
          </SheetDescription>
        </SheetHeader>
        {loading ? (
          <LoadingRows label={t('plugins.loading', 'Loading plugins')} headers={[t('plugins.workspaceCode.when', 'When')]} />
        ) : (data ?? []).length === 0 ? (
          <p className="p-4 text-sm text-muted-foreground">{t('plugins.workspaceCode.noHistory', 'Nobody has changed it yet.')}</p>
        ) : (
          <ul className="divide-y divide-border p-4 text-sm">
            {(data ?? []).map((event, index) => (
              <li key={`${event.at}-${index}`} className="flex items-center justify-between gap-3 py-3">
                <span>
                  {event.action === 'enabled'
                    ? t('plugins.workspaceCode.eventOn', '{who} turned it on', {
                        who: event.by?.name || t('plugins.removed.someone', 'someone'),
                      })
                    : t('plugins.workspaceCode.eventOff', '{who} turned it off', {
                        who: event.by?.name || t('plugins.removed.someone', 'someone'),
                      })}
                </span>
                <span className="text-muted-foreground">{dates.dateTime(event.at) ?? ''}</span>
              </li>
            ))}
          </ul>
        )}
      </SheetContent>
    </Sheet>
  );
}

/**
 * The operator's "Who can use it" for every installed plugin (WHIT-1124).
 * All: on everywhere. Workspaces choose: off until a workspace turns it on.
 * Only the platform: the system workspace alone.
 */
export function PluginWorkspaceAccess({ canChange }: { canChange: boolean }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { data, loading, refetch } = useFetch(async () => {
    const { data: response } = await api.GET('/api/v1/plugins/workspace-access');
    return response?.data ?? [];
  }, []);

  const labels: Record<Access, string> = {
    all: t('plugins.access.all', 'Every workspace'),
    choose: t('plugins.access.choose', 'Workspaces choose'),
    operator: t('plugins.access.operator', 'Only the platform'),
  };

  const save = async (plugin: CodePluginAccess, access: Access, freeForAll: boolean) => {
    const { error: saveError } = await api.PUT('/api/v1/plugins/{plugin}/workspace-access', {
      params: { path: { plugin: plugin.name } },
      body: { access, freeForAll },
    });
    if (saveError) {
      addToast(saveError.error || t('plugins.access.error', 'That could not be saved. Try again.'), 'error');
      return;
    }
    addToast(t('plugins.access.saved', '{name}: {access}', { name: plugin.name, access: labels[access] }), 'success');
    refetch();
  };

  if (loading || (data ?? []).length === 0) return null;

  return (
    <section className="space-y-3" aria-labelledby="plugins-who-can-use">
      <div className="space-y-1">
        <h2 id="plugins-who-can-use" className="text-base font-semibold text-foreground">
          {t('plugins.access.title', 'Who can use each plugin')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t(
            'plugins.access.description',
            'Workspaces choose: each workspace turns it on in Settings, and it stays off until then. Free for every workspace: a paid plugin needs no plan, add-on or trial.',
          )}
        </p>
      </div>
      <ul className="divide-y divide-border rounded-card border border-border">
        {(data ?? []).map((plugin) => (
          <li key={plugin.name} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
            <p className="font-medium text-foreground">{plugin.name}</p>
            <div className="flex flex-wrap items-center gap-4">
              <label className="flex items-center gap-2">
                <Checkbox
                  checked={plugin.freeForAll}
                  disabled={!canChange}
                  onCheckedChange={(checked) => void save(plugin, plugin.access, checked === true)}
                />
                {t('plugins.access.freeForAll', 'Free for every workspace')}
              </label>
              <Select
                value={plugin.access}
                disabled={!canChange}
                onValueChange={(value) => void save(plugin, value as Access, plugin.freeForAll)}
              >
                <SelectTrigger className="w-56" aria-label={t('plugins.access.label', 'Who can use {name}', { name: plugin.name })}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(labels) as Access[]).map((value) => (
                    <SelectItem key={value} value={value}>
                      {labels[value]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
