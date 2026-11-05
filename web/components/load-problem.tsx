'use client';

import { AccessDeniedPanel, AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';

/**
 * What a list or record shows when its request did not give data: "you don't
 * have access" in plain words when the server refused it (403), otherwise the
 * failure with Try again. Never both, and never an empty list.
 */
export function LoadProblem({
  forbidden,
  message,
  onRetry,
  area,
}: {
  forbidden: boolean;
  message: string;
  onRetry: () => void;
  /** What the person can't open, in a few words: "agents", "users". */
  area: string;
}) {
  const t = useTranslation('common');
  if (forbidden) {
    return (
      <AccessDeniedPanel
        title={t('access.denied.title', 'You don’t have access to {area}', { area })}
        description={t(
          'access.denied.description',
          'Your role doesn’t include this. Ask an owner of this workspace if you need it.'
        )}
      />
    );
  }
  return <AreaError message={message} retryLabel={t('access.retry', 'Try again')} onRetry={onRetry} />;
}
