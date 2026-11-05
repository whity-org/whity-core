'use client';

import { AccessDeniedPanel } from '@whity/ui/page-states';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { useTranslation } from '@whity/features/i18n';

/**
 * "You don't have access" in plain words (docs/design, the access-denied state).
 * The permission that would grant it is shown only with Show technical details,
 * never in the sentence.
 */
export function NoAccess({ area, permission }: { area: string; permission?: string }) {
  const t = useTranslation('common');
  return (
    <div className="space-y-3">
      <AccessDeniedPanel
        title={t('access.denied.title', 'You don’t have access to {area}', { area })}
        description={t(
          'access.denied.description',
          'Your role doesn’t include this. Ask an owner of this workspace if you need it.'
        )}
      />
      {permission ? (
        <TechnicalDetails>
          <p className="text-center text-sm text-muted-foreground" dir="ltr">
            {t('access.denied.permission', 'Needs the {permission} permission.', { permission })}
          </p>
        </TechnicalDetails>
      ) : null}
    </div>
  );
}
