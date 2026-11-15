'use client';

/**
 * The notifications ask (`PwInstall`, PW.11; WHIT-1077): a card shown to every
 * signed-in person after sign-in, on whichever page they land, saying what
 * notifications are for (chat first, then approvals and agents) and that
 * there's never marketing. The browser's own prompt follows the tap on "Turn
 * on notifications", never a page load.
 *
 * The answer is respected: "Not now" waits 14 days, "Don't ask again" stops it
 * in this browser, and any answer to the browser's prompt ends it (a refusal
 * there can only be undone in the browser's settings). Shown only where this
 * browser can take a push: not where notifications are blocked or unsupported,
 * and on iPhone and iPad only in the app added to the Home Screen. Never on
 * public pages: it lives in the signed-in shell only.
 */

import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { dismissPushAsk, pushAskDismissed, pushSupport, usePush } from '@/lib/pwa/push';

export function PushOffer({ className = '' }: { className?: string }) {
  const t = useTranslation('common');
  const push = usePush();
  // Browser-only reads. On the server this is hidden; in the browser nothing
  // shows before the devices load either, so both first renders are empty.
  const [hidden, setHidden] = useState(
    () => typeof window === 'undefined' || pushAskDismissed() || pushSupport() !== 'ready' || Notification.permission !== 'default'
  );

  if (hidden || push.loading || push.on) return null;

  const close = (how: 'snooze' | 'never') => {
    dismissPushAsk(how);
    setHidden(true);
  };

  return (
    <section
      data-testid="push-offer"
      aria-labelledby="push-offer-title"
      className={`grid gap-2.5 rounded-xl border border-border bg-card p-4 ${className}`}
    >
      <h2 id="push-offer-title" className="text-lg font-semibold">
        {t('push.ask.chatTitle', 'Get notified when someone messages you in chat?')}
      </h2>
      <p className="text-base text-muted-foreground">
        {t(
          'push.ask.chatBody',
          'Messages and mentions in chat, approvals, and your agents asking first. Never marketing. Change it any time in My settings.'
        )}
      </p>
      {push.error && (
        <p role="alert" className="text-sm text-destructive">
          {push.error === 'denied'
            ? t('push.error.denied', 'Notifications are blocked for this site. Allow them in your browser settings.')
            : t('push.error.failed', 'Notifications could not be turned on. Try again.')}
        </p>
      )}
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Button
          disabled={push.busy}
          onClick={async () => {
            const ok = await push.turnOn();
            // Any answer to the browser ends the ask; a failure leaves it to retry.
            if (ok || Notification.permission !== 'default') close('never');
          }}
        >
          {t('push.ask.turnOn', 'Turn on notifications')}
        </Button>
        <Button variant="ghost" onClick={() => close('snooze')}>
          {t('push.ask.notNow', 'Not now')}
        </Button>
        <Button variant="ghost" onClick={() => close('never')}>
          {t('push.ask.never', "Don't ask again")}
        </Button>
      </div>
    </section>
  );
}
