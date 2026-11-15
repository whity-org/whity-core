'use client';

/**
 * The install row on More (`PwInstall`, PW.11): "Install <workspace> on this
 * phone", offered once, in the browser only. On Android it opens the browser's
 * own prompt; on iPhone it opens a sheet with the three steps.
 */

import { useState } from 'react';
import { Icon } from '@whity/ui/icon';
import { Button } from '@whity/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { useTranslation } from '@whity/features/i18n';
import { dismissInstallOffer, runInstallPrompt, useInstallOffer } from '@/lib/pwa/install-offer';

export function InstallOffer() {
  const t = useTranslation('common');
  const { kind, name, iconUrl } = useInstallOffer();
  const [open, setOpen] = useState(false);

  if (!kind) return null;

  const title = t('pwa.install.title', 'Install {name} on this phone').replace('{name}', name);
  const steps: { text: string; icon: 'upload' | 'plus' | null }[] = [
    { text: t('pwa.install.step1', 'Tap Share at the bottom of Safari'), icon: 'upload' },
    { text: t('pwa.install.step2', 'Choose “Add to Home Screen”'), icon: 'plus' },
    { text: t('pwa.install.step3', 'Tap Add'), icon: null },
  ];

  return (
    <>
      <button
        type="button"
        data-testid="install-offer"
        onClick={() => (kind === 'prompt' ? void runInstallPrompt() : setOpen(true))}
        className="flex min-h-16 w-full items-center gap-3 rounded-xl border border-primary bg-card px-4 py-3 text-start outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring sm:hidden"
      >
        {iconUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={iconUrl} alt="" width={28} height={28} className="size-7 rounded-md" />
        ) : (
          <Icon name="upload" size={22} className="text-primary" />
        )}
        <span className="min-w-0 flex-1">
          <span className="block text-base font-medium">{title}</span>
          <span className="block text-sm text-muted-foreground">
            {t('pwa.install.sub', 'Opens full screen. No app store.')}
          </span>
        </span>
        <Icon name="chevron-right" size={18} className="text-muted-foreground" />
      </button>
      {kind === 'ios' && (
        <Sheet open={open} onOpenChange={setOpen}>
          <SheetContent
            side="bottom"
            closeLabel={t('common.close', 'Close')}
            className="gap-3 rounded-t-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom))] text-sm sm:mx-auto sm:max-w-md"
            data-testid="install-sheet"
          >
            <div aria-hidden className="mx-auto h-1 w-10 rounded-full bg-border" />
            <SheetHeader>
              <SheetTitle className="text-lg font-semibold">
                {t('pwa.install.sheetTitle', 'Install {name}').replace('{name}', name)}
              </SheetTitle>
              <SheetDescription className="sr-only">
                {t('pwa.install.sheetDescription', 'Three steps to add the app to your home screen')}
              </SheetDescription>
            </SheetHeader>
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
              {t('pwa.install.android', 'On Android, Chrome asks for you: tap Install.')}
            </p>
            <Button
              variant="outline"
              size="lg"
              onClick={() => {
                setOpen(false);
                dismissInstallOffer();
              }}
            >
              {t('pwa.install.notNow', 'Not now')}
            </Button>
          </SheetContent>
        </Sheet>
      )}
    </>
  );
}
