'use client';

/**
 * The sheet a phone's browser shows after sign-in (WHIT-1155): open the
 * installed app instead, or install it. See `lib/pwa/app-switch.ts` for when.
 *
 * Wears the installed app's own name and icon (the host's branding: what a
 * person sees on their home screen). Arabic and English, logical sides.
 */

import { useState } from 'react';
import { Icon } from '@whity/ui/icon';
import { Button } from '@whity/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { useTranslation } from '@whity/features/i18n';
import { snoozeAppSwitch, useAppSwitch } from '@/lib/pwa/app-switch';

export function AppSwitchSheet() {
  const t = useTranslation('common');
  const { offer, name, iconUrl, install } = useAppSwitch();
  const [closed, setClosed] = useState(false);

  if (offer === null || closed) return null;

  const notNow = () => {
    snoozeAppSwitch();
    setClosed(true);
  };

  const title =
    offer === 'open'
      ? t('pwa.switch.openTitle', '{name} is installed on this phone').replace('{name}', name)
      : t('pwa.install.title', 'Install {name} on this phone').replace('{name}', name);

  const steps: { text: string; icon: 'upload' | 'plus' | null }[] = [
    { text: t('pwa.install.step1', 'Tap Share at the bottom of Safari'), icon: 'upload' },
    { text: t('pwa.install.step2', 'Choose “Add to Home Screen”'), icon: 'plus' },
    { text: t('pwa.install.step3', 'Tap Add'), icon: null },
  ];

  return (
    <Sheet open onOpenChange={(open) => !open && notNow()}>
      <SheetContent
        side="bottom"
        closeLabel={t('common.close', 'Close')}
        className="gap-3 rounded-t-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom))] text-sm sm:mx-auto sm:max-w-md"
        data-testid="app-switch"
        data-offer={offer}
      >
        <div aria-hidden className="mx-auto h-1 w-10 rounded-full bg-border" />
        <SheetHeader className="flex-row items-center gap-3">
          {iconUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={iconUrl} alt="" width={40} height={40} className="size-10 rounded-lg" />
          ) : (
            <span className="grid size-10 place-items-center rounded-lg bg-primary text-primary-foreground">
              <Icon name="upload" size={20} />
            </span>
          )}
          <SheetTitle className="text-lg font-semibold">{title}</SheetTitle>
        </SheetHeader>

        {offer === 'open' && (
          <>
            <SheetDescription className="text-base text-muted-foreground">
              {t('pwa.switch.openBody', 'Open {name} from your home screen: it opens full screen and gets your notifications.').replace('{name}', name)}
            </SheetDescription>
            <p className="text-sm text-muted-foreground">
              {t('pwa.switch.openChrome', 'In Chrome you can also tap ⋮ and choose “Open in app”.')}
            </p>
          </>
        )}

        {offer === 'install' && (
          <>
            <SheetDescription className="text-base text-muted-foreground">
              {t('pwa.switch.installBody', 'It opens full screen, gets notifications when someone messages you, and needs no app store.')}
            </SheetDescription>
            <Button size="lg" onClick={() => void install().then(() => setClosed(true))} data-testid="app-switch-install">
              {t('pwa.switch.install', 'Install')}
            </Button>
          </>
        )}

        {offer === 'ios' && (
          <>
            <SheetDescription className="sr-only">
              {t('pwa.install.sheetDescription', 'Three steps to add the app to your home screen')}
            </SheetDescription>
            <ol className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
              {steps.map((step, index) => (
                <li key={index} className="flex min-h-12 items-center gap-3 px-4 py-2 text-base">
                  <span className="grid size-7 shrink-0 place-items-center rounded-full bg-muted text-sm font-medium">
                    {index + 1}
                  </span>
                  <span className="min-w-0 flex-1">{step.text}</span>
                  {step.icon && <Icon name={step.icon} size={22} className="text-muted-foreground" />}
                </li>
              ))}
            </ol>
            <p className="text-sm text-muted-foreground">
              {t('pwa.switch.iosNotifications', 'On iPhone, notifications only come to the installed app.')}
            </p>
          </>
        )}

        <Button variant={offer === 'install' ? 'ghost' : 'outline'} size="lg" onClick={notNow} data-testid="app-switch-not-now">
          {t('pwa.install.notNow', 'Not now')}
        </Button>
      </SheetContent>
    </Sheet>
  );
}
