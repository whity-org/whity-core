'use client';

/**
 * From your apps (H.4): each installed app may add one card, in install order. A
 * card names its app and shows one number, a few lines and at most one chart, all
 * in the person's scope, because its data comes from the app's own gated route.
 *
 * Its ⋯ menu has Hide from home and Open. Hidden cards are listed at the foot with
 * Show, so hiding is never a dead end. With no cards, nothing appears at all.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Chart } from '@whity/ui/chart';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { iconNames, type IconName } from '@whity/ui/icon-map';
import { Skeleton } from '@whity/ui/skeleton';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';

/** One entry of `GET /api/me/home-cards`. */
export interface HomeCard {
  key: string;
  label: string;
  labelAr: string;
  caption?: string | null;
  captionAr?: string | null;
  icon?: string | null;
  href?: string | null;
  source?: string | null;
  value?: number | null;
  hidden: boolean;
}

/** What an app's `source` route answers (`HomeCardValidator` in the SDK). */
interface HomeCardData {
  value: string | number;
  caption?: string;
  lines?: Array<{ label: string; value: string }>;
  chart?: Array<{ label: string; value: number }>;
}

const MAX_LINES = 5;
const MAX_CHART_POINTS = 12;

export function HomeApps() {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const cards = useFetch<HomeCard[]>(async () => {
    const response = await apiClient('/api/v1/me/home-cards');
    // A failed read leaves the section out; the home's first job is Waiting for you.
    if (!response.ok) return [];
    return ((await response.json()) as { data?: HomeCard[] }).data ?? [];
  }, [apiClient]);

  const name = (card: HomeCard) => (locale === 'ar' ? card.labelAr : card.label);

  const setHidden = async (card: HomeCard, hidden: boolean) => {
    const response = await apiClient(`/api/v1/me/home-cards/${encodeURIComponent(card.key)}/hidden`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ hidden }),
    }).catch(() => null);
    if (response === null || !response.ok) {
      addToast(t('dashboard.apps.hideFailed', 'That card could not be changed. Try again.'), 'error', 6000);
      return;
    }
    cards.refetch();
  };

  const all = cards.data ?? [];
  const shown = all.filter((card) => !card.hidden);
  const hidden = all.filter((card) => card.hidden);
  if (all.length === 0) return null;

  return (
    <section aria-labelledby="dashboard-apps" className="grid gap-3">
      {shown.length > 0 && (
        <>
          <div className="flex flex-wrap items-baseline gap-x-3">
            <h2 id="dashboard-apps" className="text-lg font-semibold">
              {t('dashboard.apps.title', 'From your apps')}
            </h2>
            <p className="text-sm text-muted-foreground">
              {t('dashboard.apps.hint', 'Each app can add one card. Hide any from its ⋯ menu.')}
            </p>
          </div>
          <div className="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {shown.map((card) => (
              <AppCard key={card.key} card={card} name={name(card)} onHide={() => void setHidden(card, true)} />
            ))}
          </div>
        </>
      )}
      {hidden.length > 0 && (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground" aria-label={t('dashboard.apps.hiddenLabel', 'Hidden cards')}>
          {hidden.map((card) => (
            <li key={card.key} className="flex items-center gap-2">
              <span>{t('dashboard.apps.hidden', 'Card hidden: {name}', { name: name(card) })}</span>
              <Button variant="ghost" size="sm" onClick={() => void setHidden(card, false)}>
                {t('dashboard.apps.show', 'Show')}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function AppCard({ card, name, onHide }: { card: HomeCard; name: string; onHide: () => void }) {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const { apiClient } = useAuth();
  const router = useRouter();
  const [denied, setDenied] = useState(false);

  const data = useFetch<HomeCardData | null>(async () => {
    if (card.source === null || card.source === undefined) return null;
    const response = await apiClient(card.source);
    // The app says this person may not see it now, so there is no card to show.
    if (response.status === 403 || response.status === 404) {
      setDenied(true);
      return null;
    }
    if (!response.ok) throw new Error(t('dashboard.apps.error', 'This card could not be loaded.'));
    return ((await response.json()) as { data?: HomeCardData }).data ?? null;
  }, [apiClient, card.source]);

  if (denied) return null;

  const value = card.source ? data.data?.value : card.value;
  const caption = card.source ? data.data?.caption : locale === 'ar' ? card.captionAr : card.caption;
  const lines = (data.data?.lines ?? []).slice(0, MAX_LINES);
  const chart = (data.data?.chart ?? []).slice(0, MAX_CHART_POINTS);
  const loading = data.loading && data.data === null;

  return (
    <Card className="gap-3 p-4">
      <div className="flex items-center gap-2">
        <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-card bg-muted text-muted-foreground">
          <Icon name={iconNames.includes(card.icon as IconName) ? (card.icon as IconName) : 'plugin'} size={16} />
        </span>
        <h3 className="min-w-0 flex-1 truncate font-medium text-foreground">
          {card.href ? (
            <Link href={card.href} className="underline-offset-2 hover:underline">
              {name}
            </Link>
          ) : (
            name
          )}
        </h3>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={t('dashboard.apps.options', 'Options for {name}', { name })}
              title={t('dashboard.apps.options', 'Options for {name}', { name })}
            >
              <Icon name="more" size={16} />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={onHide}>{t('dashboard.apps.hide', 'Hide from home')}</DropdownMenuItem>
            {card.href && (
              <DropdownMenuItem onClick={() => router.push(card.href as string)}>
                {t('dashboard.apps.open', 'Open {name}', { name })}
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {loading ? (
        <Skeleton className="h-16 w-full" />
      ) : data.error !== null || value === undefined || value === null ? (
        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
          <span>{t('dashboard.apps.error', 'This card could not be loaded.')}</span>
          {card.source && (
            <Button variant="ghost" size="sm" onClick={() => data.refetch()}>
              {t('dashboard.tryAgain', 'Try again')}
            </Button>
          )}
        </div>
      ) : (
        <>
          <p className="flex flex-wrap items-baseline gap-x-2">
            <span dir="ltr" className="text-2xl font-semibold tabular-nums">
              {value}
            </span>
            {caption && <span className="text-sm text-muted-foreground">{caption}</span>}
          </p>
          {chart.length > 0 && (
            <Chart
              type="bar"
              data={chart}
              xKey="label"
              series={[{ key: 'value', label: caption ?? name, color: 1 }]}
              height={96}
              legend={false}
            />
          )}
          {lines.length > 0 && (
            <ul className="grid gap-1 text-sm">
              {lines.map((line) => (
                <li key={line.label} className="flex justify-between gap-3">
                  <span className="min-w-0 truncate text-muted-foreground">{line.label}</span>
                  <span dir="ltr" className="font-medium tabular-nums">
                    {line.value}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </Card>
  );
}
