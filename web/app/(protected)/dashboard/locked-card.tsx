'use client';

/**
 * A locked card (WHIT-1202): a paid plugin the workspace's template offers,
 * installed here but not on for this workspace, which it can try now.
 *
 * Dashed, because it hasn't happened yet. It names the plugin as people know
 * it (SDK 1.117), says in two or three lines what it adds, and has one action:
 * start the template's free trial, the same one its guided setup offers. Once
 * the trial starts the card goes and the plugin's own widgets take its place.
 * "See what's included" opens the plugin on Settings › Plugins; the ⋯ menu
 * hides the card like any other, which never withdraws the offer elsewhere.
 */

import { useState } from 'react';
import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import type { DashboardOffer } from './use-dashboard';

/** Where "See what's included" lands: the plugin's row on Settings › Plugins. */
export function pluginDetailsPath(plugin: string): string {
  return `/admin/plugins#plugin-${encodeURIComponent(plugin)}`;
}

export function LockedCard({
  offer,
  primary,
  onHide,
  onStarted,
}: {
  offer: DashboardOffer;
  /** The view's one filled button; a second locked card gets an outline one. */
  primary: boolean;
  onHide: () => void;
  onStarted: () => void;
}) {
  const t = useTranslation('common');
  const { addToast } = useToast();
  const [starting, setStarting] = useState(false);
  const name = offer.pluginName;
  const details = pluginDetailsPath(offer.plugin);

  const start = async () => {
    setStarting(true);
    const { error, response } = await api
      .POST('/api/v1/workspace/templates/{key}/setup/offer/trial', { params: { path: { key: offer.template } } })
      .catch(() => ({ error: undefined, response: null }));
    setStarting(false);
    if (response === null || !response.ok) {
      const message = (error as { error?: string } | undefined)?.error;
      addToast(message || t('dashboard.locked.trialError', 'The trial could not be started. Try again.'), 'error', 6000);
      return;
    }
    addToast(t('dashboard.locked.trialStarted', 'Your {days}-day trial of {name} has started.', { days: offer.trialDays, name }), 'success');
    onStarted();
  };

  return (
    <Card className="gap-3 border-dashed p-4" data-slot="locked-card" data-plugin={offer.plugin}>
      <div className="flex items-center gap-2">
        <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-card bg-muted text-muted-foreground">
          <Icon name="lock" size={16} />
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate font-medium text-foreground">{name}</h3>
          <p className="text-xs text-muted-foreground">{t('dashboard.locked.status', 'Not on yet · free to try')}</p>
        </div>
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
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {offer.lines.length > 0 && (
        <ul className="grid gap-1.5 text-sm" aria-label={t('dashboard.locked.adds', 'What {name} adds', { name })}>
          {offer.lines.map((line) => (
            <li key={line} className="flex items-start gap-2">
              <Icon name="check" size={16} className="mt-0.5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <span className="min-w-0">{line}</span>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Button variant={primary ? 'default' : 'outline'} size="sm" onClick={() => void start()} disabled={starting}>
          {t('dashboard.locked.start', 'Start your {days}-day free trial', { days: offer.trialDays })}
        </Button>
        <Button asChild variant="ghost" size="sm">
          <Link href={details}>{t('dashboard.locked.included', "See what's included")}</Link>
        </Button>
      </div>
      <p className="text-xs text-muted-foreground">{t('dashboard.locked.noCard', 'Free for {days} days. No card needed.', { days: offer.trialDays })}</p>
    </Card>
  );
}
