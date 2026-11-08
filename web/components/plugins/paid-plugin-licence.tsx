'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Button } from '@whity/ui/button';

type CataloguePlugin = components['schemas']['StoreCataloguePlugin'];
type Licence = components['schemas']['StoreLicenceResponse']['data'];

interface PaidPluginLicenceProps {
  plugin: CataloguePlugin;
  storeUrl: string;
  /** This installation is connected to the store through Whity ID. */
  connected: boolean;
  /** Called with whether the plugin may be installed now (licensed or in grace). */
  onUsable: (usable: boolean) => void;
}

/**
 * A paid store plugin in the install dialog (store licensing, PR-4): its licence here, and the
 * ways to buy it or start its trial. Buying leaves for Whity Pay's checkout and comes back to
 * the store page; a trial starts at once.
 */
export function PaidPluginLicence({ plugin, storeUrl, connected, onUsable }: PaidPluginLicenceProps) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { date } = useDateDisplay();
  const [licence, setLicence] = useState<Licence | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!connected) {
      onUsable(false);
      return;
    }
    const { data } = await api.GET('/api/v1/plugins/store/licences/{slug}', { params: { path: { slug: plugin.slug } } });
    const next = data?.data ?? null;
    setLicence(next);
    onUsable(next !== null && next.state !== 'unlicensed');
  }, [connected, onUsable, plugin.slug]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads the licence when the dialog opens
    void load();
  }, [load]);

  const buy = async (period: string, currency: string, trial: boolean) => {
    setBusy(trial ? 'trial' : `${period}-${currency}`);
    try {
      const { data, error } = await api.POST('/api/v1/plugins/store/checkout', {
        body: { store_url: storeUrl, slug: plugin.slug, period, currency, trial },
      });
      if (error || !data) {
        addToast(error?.error || t('pluginStore.licence.buyError', 'The store could not start the purchase.'), 'error');
        return;
      }
      if (data.data.checkout_url) {
        window.location.assign(data.data.checkout_url);
        return;
      }
      addToast(t('pluginStore.licence.trialStarted', 'The free trial has started.'), 'success');
      await load();
    } finally {
      setBusy(null);
    }
  };

  const prices = plugin.prices ?? [];
  const trialDays = prices.find((price) => price.trial_days)?.trial_days ?? null;
  const until = date(licence?.until ?? null);

  if (!connected) {
    return (
      <p className="rounded-md border border-border bg-muted/40 p-3 text-sm">
        {t('pluginStore.licence.connectFirst', 'This is a paid plugin. Connect this installation to the Whity store to buy it or start a trial.')}
      </p>
    );
  }

  if (licence === null) {
    return <p className="text-sm text-muted-foreground">{t('pluginStore.licence.checking', 'Checking the licence…')}</p>;
  }

  if (licence.state !== 'unlicensed') {
    return (
      <p role="status" className={`rounded-md border p-3 text-sm ${licence.state === 'grace' ? 'border-warning/30 bg-warning-tint text-warning-text' : 'border-border bg-muted/40'}`}>
        {licence.state === 'grace'
          ? t('pluginStore.licence.grace', 'Licensed until {date}, while the licence needs attention.', { date: until ?? '' })
          : until
            ? t('pluginStore.licence.licensedUntil', 'Licensed on this installation until {date}.', { date: until })
            : t('pluginStore.licence.licensed', 'Licensed on this installation.')}
      </p>
    );
  }

  const periodLabel = (period: string) =>
    period === 'month'
      ? t('pluginStore.licence.perMonth', 'a month')
      : period === 'year'
        ? t('pluginStore.licence.perYear', 'a year')
        : t('pluginStore.licence.once', 'once');

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <p className="text-sm">{t('pluginStore.licence.paid', 'This is a paid plugin. Choose how to pay for it on this installation.')}</p>
      <ul className="space-y-2">
        {prices.map((price) => (
          <li key={`${price.plan}-${price.period}-${price.currency}`} className="flex items-center justify-between gap-3">
            <span dir="ltr" className="font-medium tabular-nums">
              {price.currency} {price.amount}
            </span>
            <span className="flex-1 text-sm text-muted-foreground">{periodLabel(price.period)}</span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => void buy(price.period, price.currency, false)}
              disabled={busy !== null}
            >
              {t('pluginStore.licence.buy', 'Buy')}
            </Button>
          </li>
        ))}
      </ul>
      {trialDays ? (
        <Button
          variant="outline"
          size="sm"
          onClick={() => void buy(prices[0]?.period ?? 'month', prices[0]?.currency ?? '', true)}
          disabled={busy !== null}
        >
          {t('pluginStore.licence.trial', 'Start a free {days}-day trial', { days: trialDays })}
        </Button>
      ) : null}
    </div>
  );
}
