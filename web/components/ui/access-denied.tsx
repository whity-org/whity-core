'use client';

/**
 * The design system's AccessDenied with this app's translations. The design
 * system's default titles are English ("Access Denied"), so every page that
 * passed only a translated description showed an English title in Arabic.
 * Here the default title is translated by variant, in sentence case; a page
 * that passes its own title keeps it. See ./input.tsx.
 */

import { useTranslation } from '@whity/features/i18n';
import { AccessDenied as BaseAccessDenied, type AccessDeniedProps } from '@whity/ui/access-denied';

export type { AccessDeniedProps } from '@whity/ui/access-denied';

export function AccessDenied(props: AccessDeniedProps) {
  const t = useTranslation('common');

  // Literal keys, so i18n:extract finds them.
  const titles: Record<NonNullable<AccessDeniedProps['variant']>, string> = {
    forbidden: t('ui.accessDenied.forbidden', 'Access denied'),
    unauthorized: t('ui.accessDenied.unauthorized', 'Sign in needed'),
    'not-found': t('ui.accessDenied.notFound', 'Page not found'),
    error: t('ui.accessDenied.error', 'Something went wrong'),
    success: t('ui.accessDenied.success', 'Done'),
    maintenance: t('ui.accessDenied.maintenance', 'Down for maintenance'),
  };

  return <BaseAccessDenied {...props} title={props.title ?? titles[props.variant ?? 'forbidden']} />;
}
