'use client';

import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { SystemState } from '@whity/ui/system-state';
import { useTranslation } from '@whity/features/i18n';
import { latestErrorReference } from '@/lib/api-client';

/**
 * The 500 page: nothing the person did caused it, nothing was lost, one thing
 * to do next, and a reference they can copy for support. The reference is the
 * error digest Next.js assigns or, for a crash in the browser, the one the server
 * gave its last failed call. With neither the copy button is left out: a value only
 * this browser knows would find nothing in the server's log.
 */
export function SystemErrorState({ digest: givenDigest, onRetry }: { digest?: string; onRetry: () => void }) {
  const t = useTranslation('common');
  const [copied, setCopied] = useState(false);
  const digest = givenDigest ?? latestErrorReference() ?? undefined;

  const copy = async () => {
    if (!digest) return;
    try {
      await navigator.clipboard.writeText(digest);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };

  return (
    <SystemState
      icon="alert"
      moment="went-wrong"
      code="500"
      title={t('system.error.title', 'Something went wrong on our side')}
      description={
        digest
          ? t(
              'system.error.bodyWithReference',
              'Nothing you did caused this, and nothing was lost. Try again in a minute. Reference: {reference}.',
              { reference: digest }
            )
          : t('system.error.body', 'Nothing you did caused this, and nothing was lost. Try again in a minute.')
      }
      actions={
        <>
          <Button onClick={onRetry}>{t('system.error.retry', 'Try again')}</Button>
          {digest ? (
            <Button variant="outline" onClick={copy}>
              <Icon name="copy" size={16} />
              {copied ? t('system.error.copied', 'Copied') : t('system.error.copy', 'Copy reference')}
            </Button>
          ) : null}
        </>
      }
    />
  );
}
