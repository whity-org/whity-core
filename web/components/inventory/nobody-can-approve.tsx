'use client';

/**
 * A stock change that waits with nobody to approve it (WHIT-1301). It fails
 * closed: nothing changes in stock until someone can approve it. This says
 * so in plain words, and how to unblock it, with Send again once that's done.
 */

import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';

export function NobodyCanApprove({
  number,
  onSendAgain,
  testId = 'nobody-can-approve',
}: {
  /** "ADJ-0004": named when the page lists more than one. */
  number?: string;
  /** Tries the route again; absent for people who can't send it. */
  onSendAgain?: () => Promise<void>;
  testId?: string;
}) {
  const t = useTranslation('admin');
  const [sending, setSending] = useState(false);
  const text = number
    ? t(
        'inventory.approval.nobodyNumbered',
        '{number} is waiting for approval, and nobody can approve it yet, so the stock hasn’t changed. Someone who runs the inventory settings needs to give another person that access, or choose an approval route. Then send it again.',
        { number }
      )
    : t(
        'inventory.approval.nobody',
        'This is waiting for approval, and nobody can approve it yet, so the stock hasn’t changed. Someone who runs the inventory settings needs to give another person that access, or choose an approval route. Then send it again.'
      );

  return (
    <Alert variant="warning" data-testid={testId}>
      <AlertDescription>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <span>{text}</span>
          {onSendAgain && (
            <Button
              variant="outline"
              size="sm"
              disabled={sending}
              onClick={async () => {
                setSending(true);
                try {
                  await onSendAgain();
                } finally {
                  setSending(false);
                }
              }}
            >
              {t('inventory.approval.sendAgain', 'Send again')}
            </Button>
          )}
        </div>
      </AlertDescription>
    </Alert>
  );
}
