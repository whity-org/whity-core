'use client';

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { SystemState } from '@whity/ui/system-state';
import { useTranslation } from '@whity/features/i18n';

/** 404 (docs/design/components/TplSystemStates): where to go next, never a joke. */
export default function NotFound() {
  const t = useTranslation('common');
  return (
    <SystemState
      icon="search"
      moment="not-found"
      code="404"
      title={t('system.notFound.title', 'We can’t find that page')}
      description={t(
        'system.notFound.body',
        'It may have moved, or the link is wrong. Check the address or go back to your dashboard.'
      )}
      actions={
        <Button asChild>
          <Link href="/dashboard">{t('system.notFound.home', 'Go to dashboard')}</Link>
        </Button>
      }
    />
  );
}
