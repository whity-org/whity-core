'use client';

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { SystemState } from '@whity/ui/system-state';
import { useTranslation } from '@whity/features/i18n';
import { useHomePath } from '@/lib/branding-context';
import { DEFAULT_HOME_PATH } from '@/lib/home-path';

/** 404 (docs/design/components/TplSystemStates): where to go next, never a joke. */
export default function NotFound() {
  const t = useTranslation('common');
  // #2386: home is the dashboard unless the operator chose another page.
  const homePath = useHomePath();
  const dashboard = homePath === DEFAULT_HOME_PATH;
  return (
    <SystemState
      icon="search"
      moment="not-found"
      code="404"
      title={t('system.notFound.title', 'We can’t find that page')}
      description={
        dashboard
          ? t('system.notFound.body', 'It may have moved, or the link is wrong. Check the address or go back to your dashboard.')
          : t('system.notFound.bodyHome', 'It may have moved, or the link is wrong. Check the address or go back to the home page.')
      }
      actions={
        <Button asChild>
          <Link href={homePath}>
            {dashboard ? t('system.notFound.home', 'Go to dashboard') : t('system.notFound.homePage', 'Go to the home page')}
          </Link>
        </Button>
      }
    />
  );
}
