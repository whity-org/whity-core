'use client';

/**
 * The notifications ask (`PwInstall`, PW.11): a card that says what
 * notifications are for and that there's never marketing. The browser's own
 * prompt follows the tap on "Turn on notifications", never a page load. Asked
 * once: "Not now", or any answer, is remembered in this browser, and the
 * choice stays in Settings › Preferences.
 *
 * Shown only where this browser can take a push and the person hasn't been
 * asked: not on an iPhone that hasn't installed the app (Safari offers push
 * only to Home Screen apps), not where notifications are blocked.
 */

import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { dismissPushAsk, pushAskDismissed, pushSupport, usePush } from '@/lib/pwa/push';

export function PushOffer() {
  const t = useTranslation('common');
  const push = usePush();
  // Browser-only reads. On the server this is hidden; in the browser nothing
  // shows before the devices load either, so both first renders are empty.
  const [hidden, setHidden] = useState(
    () => typeof window === 'undefined' || pushAskDismissed() || pushSupport() !== 'ready' || Notification.permission !== 'default'
  );

  if (hidden || push.loading || push.on) return null;

  const close = () => {
    dismissPushAsk();
    setHidden(true);
  };

  return (
    <section
      data-testid="push-offer"
      aria-labelledby="push-offer-title"
      className="grid gap-2.5 rounded-xl border border-border bg-card p-4"
    >
      <h2 id="push-offer-title" className="text-lg font-semibold">
        {t('push.ask.title', 'Get told when something needs you?')}
      </h2>
      <p className="text-base text-muted-foreground">
        {t(
          'push.ask.body',
          'Approvals, messages from customers, and your agents asking first. Never marketing. Change it any time in Settings › Preferences.'
        )}
      </p>
      {push.error && (
        <p role="alert" className="text-sm text-destructive">
          {push.error === 'denied'
            ? t('push.error.denied', 'Notifications are blocked for this site. Allow them in your browser settings.')
            : t('push.error.failed', 'Notifications could not be turned on. Try again.')}
        </p>
      )}
      <div className="flex flex-col gap-2 sm:flex-row">
        <Button
          disabled={push.busy}
          onClick={async () => {
            const ok = await push.turnOn();
            if (ok || Notification.permission !== 'default') close();
          }}
        >
          {t('push.ask.turnOn', 'Turn on notifications')}
        </Button>
        <Button variant="ghost" onClick={close}>
          {t('push.ask.notNow', 'Not now')}
        </Button>
      </div>
    </section>
  );
}
