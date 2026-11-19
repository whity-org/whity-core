'use client';

/**
 * From your apps (H.4, H.5): each installed app may add one card and, since SDK
 * 1.115, widgets: a stat, a short list, one chart or a few actions. Everything is in
 * the person's scope, because its data comes from the app's own gated route.
 *
 * Cards and widgets share one order, the apps' until the person changes it.
 * Customise turns on Move earlier and Move later, and a size choice for widgets.
 * Each ⋯ menu has Hide from home and Open. Hidden items are listed at the foot
 * with Show, so hiding is never a dead end. With nothing, nothing appears at all.
 *
 * Locked cards (WHIT-1202) come first: a paid plugin the workspace's template
 * offers and it can try now. They hide and move like the rest.
 */

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Chart } from '@/components/lazy-heavy';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { iconNames, type IconName } from '@whity/ui/icon-map';
import { Skeleton } from '@whity/ui/skeleton';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { api, apiFetch } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useNavigation } from '@/lib/navigation-context';
import { AppWidgetBody } from './app-widget';
import { LockedCard } from './locked-card';
import type { Dashboard, DashboardOffer, DashboardWidget, WidgetSize } from './use-dashboard';

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
  position?: number | null;
}

/** What an app's `source` route answers (`HomeCardValidator` in the SDK). */
interface HomeCardData {
  value: string | number;
  caption?: string;
  lines?: Array<{ label: string; value: string }>;
  chart?: Array<{ label: string; value: number }>;
}

type Item =
  | { key: string; kind: 'card'; hidden: boolean; size: null; card: HomeCard }
  | { key: string; kind: 'widget'; hidden: boolean; size: WidgetSize; widget: DashboardWidget }
  | { key: string; kind: 'offer'; hidden: boolean; size: null; offer: DashboardOffer };

const MAX_LINES = 5;
const MAX_CHART_POINTS = 12;

/** Grid widths: small is one column, medium two, large the whole row. */
const SPAN: Record<WidgetSize, string> = {
  small: '',
  medium: 'sm:col-span-2',
  large: 'sm:col-span-2 lg:col-span-3',
};

/**
 * The apps' own order (each app's card, then its widgets; then widgets of apps
 * with no card), then the person's positions over it. Stable, like the server's.
 */
export function mergeItems(cards: HomeCard[], widgets: DashboardWidget[], offers: DashboardOffer[] = []): Item[] {
  const fromCards: Item[] = cards.map((card) => ({ key: card.key, kind: 'card', hidden: card.hidden, size: null, card }));
  const asItem = (widget: DashboardWidget): Item => ({ key: widget.key, kind: 'widget', hidden: widget.hidden, size: widget.size, widget });
  const placed = new Set<string>();
  // Locked cards first: what the workspace's template offers to try.
  const apps: Item[] = offers.map((offer) => ({ key: offer.key, kind: 'offer', hidden: offer.hidden, size: null, offer }));
  for (const card of fromCards) {
    apps.push(card);
    for (const widget of widgets.filter((w) => w.plugin === card.key)) {
      apps.push(asItem(widget));
      placed.add(widget.key);
    }
  }
  for (const widget of widgets) if (!placed.has(widget.key)) apps.push(asItem(widget));

  const positionOf = (item: Item) =>
    (item.kind === 'card' ? item.card.position : item.kind === 'offer' ? item.offer.position : item.widget.position) ?? Number.MAX_SAFE_INTEGER;
  return apps
    .map((item, index) => ({ item, index }))
    .sort((a, b) => positionOf(a.item) - positionOf(b.item) || a.index - b.index)
    .map(({ item }) => item);
}

export function HomeApps({ dashboard, onDashboardChange }: { dashboard: Dashboard | null; onDashboardChange: () => void }) {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const { addToast } = useToast();
  const { refresh: refreshNavigation } = useNavigation();
  const [customising, setCustomising] = useState(false);
  // Set while a change is saving, so the order shown is the one just chosen.
  const [pending, setPending] = useState<Item[] | null>(null);
  // Widgets whose route says this person may not see them now.
  const [denied, setDenied] = useState<Set<string>>(new Set());

  const cards = useFetch<HomeCard[]>(async () => {
    const { data: body, response } = await api.GET('/api/v1/me/home-cards');
    // A failed read leaves the cards out; the home's first job is Waiting for you.
    if (!response.ok) return [];
    return ((body as { data?: HomeCard[] } | undefined)?.data) ?? [];
  }, []);

  const merged = useMemo(() => mergeItems(cards.data ?? [], dashboard?.widgets ?? [], dashboard?.offers ?? []), [cards.data, dashboard]);
  const all = (pending ?? merged).filter((item) => !denied.has(item.key));

  const name = (item: Item) =>
    item.kind === 'card'
      ? locale === 'ar'
        ? item.card.labelAr
        : item.card.label
      : item.kind === 'offer'
        ? item.offer.pluginName
        : (item.widget.title ?? item.widget.plugin);

  // The trial is on: the plugin's own cards, widgets and screens take over.
  const trialStarted = () => {
    cards.refetch();
    onDashboardChange();
    void refreshNavigation();
  };

  const save = async (next: Item[]) => {
    setPending(next);
    const response = await api
      .PUT('/api/v1/me/dashboard/layout', {
        body: { items: next.map((item) => ({ key: item.key, hidden: item.hidden, size: item.size })) } as never,
      })
      .then((r) => r.response)
      .catch(() => null);
    if (response === null || !response.ok) {
      addToast(t('dashboard.apps.hideFailed', 'That card could not be changed. Try again.'), 'error', 6000);
    }
    cards.refetch();
    onDashboardChange();
    setPending(null);
  };

  const change = (key: string, patch: Partial<Pick<Item, 'hidden'>> & { size?: WidgetSize }) =>
    void save(all.map((item) => (item.key === key ? ({ ...item, ...patch } as Item) : item)));

  /** Swap with the nearest shown neighbour, so a move is always visible. */
  const move = (key: string, direction: -1 | 1) => {
    const order = [...all];
    const from = order.findIndex((item) => item.key === key);
    let to = from + direction;
    while (to >= 0 && to < order.length && order[to].hidden) to += direction;
    if (from === -1 || to < 0 || to >= order.length) return;
    [order[from], order[to]] = [order[to], order[from]];
    void save(order);
  };

  const onDenied = useCallback((key: string) => setDenied((keys) => new Set(keys).add(key)), []);

  const shown = all.filter((item) => !item.hidden);
  // The view's one filled button is the first locked card's trial.
  const firstOffer = shown.find((item) => item.kind === 'offer')?.key;
  const hidden = all.filter((item) => item.hidden);
  if (all.length === 0) return null;

  return (
    <section aria-labelledby="dashboard-apps" className="grid gap-3">
      {shown.length > 0 && (
        <>
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 id="dashboard-apps" className="text-lg font-semibold">
              {t('dashboard.apps.title', 'From your apps')}
            </h2>
            <p className="min-w-0 flex-1 text-sm text-muted-foreground">
              {customising
                ? t('dashboard.apps.customiseHint', 'Move, resize or hide what your apps show. Changes save as you make them.')
                : t('dashboard.apps.hintArrange', 'Hide any from its ⋯ menu, or choose Customise to arrange them.')}
            </p>
            {shown.length > 1 || shown.some((item) => item.kind === 'widget' && item.widget.sizes.length > 1) ? (
              <Button variant="ghost" size="sm" onClick={() => setCustomising((on) => !on)} aria-pressed={customising}>
                {customising ? t('dashboard.apps.done', 'Done') : t('dashboard.apps.customise', 'Customise')}
              </Button>
            ) : null}
          </div>
          <div className="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {shown.map((item, index) => (
              <div key={item.key} className={item.kind === 'widget' ? SPAN[item.size] : ''}>
                {item.kind === 'offer' ? (
                  <LockedCard
                    offer={item.offer}
                    primary={item.key === firstOffer}
                    onHide={() => change(item.key, { hidden: true })}
                    onStarted={trialStarted}
                  />
                ) : item.kind === 'card' ? (
                  <AppCard card={item.card} name={name(item)} onHide={() => change(item.key, { hidden: true })} />
                ) : (
                  <WidgetCard widget={item.widget} name={name(item)} onHide={() => change(item.key, { hidden: true })} onDenied={onDenied} />
                )}
                {customising && (
                  <Arrange
                    name={name(item)}
                    first={index === 0}
                    last={index === shown.length - 1}
                    sizes={item.kind === 'widget' ? item.widget.sizes : []}
                    size={item.size}
                    onMove={(direction) => move(item.key, direction)}
                    onSize={(size) => change(item.key, { size })}
                  />
                )}
              </div>
            ))}
          </div>
        </>
      )}
      {hidden.length > 0 && (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground" aria-label={t('dashboard.apps.hiddenLabel', 'Hidden cards')}>
          {hidden.map((item) => (
            <li key={item.key} className="flex items-center gap-2">
              <span>{t('dashboard.apps.hidden', 'Card hidden: {name}', { name: name(item) })}</span>
              <Button variant="ghost" size="sm" onClick={() => change(item.key, { hidden: false })}>
                {t('dashboard.apps.show', 'Show')}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** Customise's controls under one item: move it, and size a widget. */
function Arrange({
  name,
  first,
  last,
  sizes,
  size,
  onMove,
  onSize,
}: {
  name: string;
  first: boolean;
  last: boolean;
  sizes: WidgetSize[];
  size: WidgetSize | null;
  onMove: (direction: -1 | 1) => void;
  onSize: (size: WidgetSize) => void;
}) {
  const t = useTranslation('common');
  const sizeLabel: Record<WidgetSize, string> = {
    small: t('dashboard.widgets.small', 'Small'),
    medium: t('dashboard.widgets.medium', 'Medium'),
    large: t('dashboard.widgets.large', 'Large'),
  };

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2" data-slot="widget-arrange">
      <Button variant="outline" size="sm" disabled={first} onClick={() => onMove(-1)} aria-label={t('dashboard.apps.moveEarlierName', 'Move {name} earlier', { name })}>
        <Icon name="chevron-left" size={16} />
        {t('dashboard.apps.moveEarlier', 'Move earlier')}
      </Button>
      <Button variant="outline" size="sm" disabled={last} onClick={() => onMove(1)} aria-label={t('dashboard.apps.moveLaterName', 'Move {name} later', { name })}>
        {t('dashboard.apps.moveLater', 'Move later')}
        <Icon name="chevron-right" size={16} />
      </Button>
      {sizes.length > 1 && (
        <div role="group" aria-label={t('dashboard.widgets.sizeOf', 'Size of {name}', { name })} className="flex items-center gap-1">
          {sizes.map((option) => (
            <Button key={option} variant={option === size ? 'outline' : 'ghost'} size="sm" aria-pressed={option === size} onClick={() => onSize(option)}>
              {sizeLabel[option]}
            </Button>
          ))}
        </div>
      )}
    </div>
  );
}

/** The header every item shares: the app's icon, its name, and ⋯. */
function ItemHeader({ icon, name, href, onHide }: { icon?: string | null; name: string; href?: string | null; onHide: () => void }) {
  const t = useTranslation('common');
  const router = useRouter();

  return (
    <div className="flex items-center gap-2">
      <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-card bg-muted text-muted-foreground">
        <Icon name={iconNames.includes(icon as IconName) ? (icon as IconName) : 'plugin'} size={16} />
      </span>
      <h3 className="min-w-0 flex-1 truncate font-medium text-foreground">
        {href ? (
          <Link href={href} className="underline-offset-2 hover:underline">
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
          {href && (
            <DropdownMenuItem onClick={() => router.push(href)}>
              {t('dashboard.apps.open', 'Open {name}', { name })}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function WidgetCard({
  widget,
  name,
  onHide,
  onDenied,
}: {
  widget: DashboardWidget;
  name: string;
  onHide: () => void;
  onDenied: (key: string) => void;
}) {
  const denied = useCallback(() => onDenied(widget.key), [onDenied, widget.key]);

  return (
    <Card className="gap-3 p-4" data-slot="app-widget" data-kind={widget.kind}>
      <ItemHeader icon={widget.icon} name={name} href={widget.href} onHide={onHide} />
      {widget.description && <p className="text-sm text-muted-foreground">{widget.description}</p>}
      <AppWidgetBody widget={widget} onDenied={denied} />
    </Card>
  );
}

function AppCard({ card, name, onHide }: { card: HomeCard; name: string; onHide: () => void }) {
  const t = useTranslation('common');
  const locale = useFormattingLocale();
  const [denied, setDenied] = useState(false);

  const data = useFetch<HomeCardData | null>(async () => {
    if (card.source === null || card.source === undefined) return null;
    // The person's choice in this product, which the browser's own header may not be.
    const response = await apiFetch(card.source, locale ? { headers: { 'Accept-Language': locale } } : undefined);
    // The app says this person may not see it now, so there is no card to show.
    if (response.status === 403 || response.status === 404) {
      setDenied(true);
      return null;
    }
    if (!response.ok) throw new Error(t('dashboard.apps.error', 'This card could not be loaded.'));
    return ((await response.json()) as { data?: HomeCardData }).data ?? null;
  }, [card.source, locale]);

  if (denied) return null;

  const value = card.source ? data.data?.value : card.value;
  const caption = card.source ? data.data?.caption : locale === 'ar' ? card.captionAr : card.caption;
  const lines = (data.data?.lines ?? []).slice(0, MAX_LINES);
  const chart = (data.data?.chart ?? []).slice(0, MAX_CHART_POINTS);
  const loading = data.loading && data.data === null;

  return (
    <Card className="gap-3 p-4">
      <ItemHeader icon={card.icon} name={name} href={card.href} onHide={onHide} />

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
