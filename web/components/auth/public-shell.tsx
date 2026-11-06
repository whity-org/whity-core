'use client';

import type { ReactNode } from 'react';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import { useBranding } from '@/lib/branding-context';

/**
 * The frame of a page a stranger opens for a workspace (WHIT-915): the
 * workspace's logo above, "Built on Whity" and the language switcher below.
 * Same shape as the public form page, /f/[slug].
 */
export function PublicShell({ children }: { children: ReactNode }) {
  const branding = useBranding();
  const t = useTranslation('auth');

  return (
    <main className="flex min-h-screen flex-col items-center bg-background px-4 py-10">
      <div className="w-full max-w-md flex-1 space-y-6">
        <div className="flex justify-center">
          <TenantLogo
            name={branding.siteName}
            src={branding.logoWideUrl ?? branding.logoSquareUrl}
            shape={branding.logoWideUrl ? 'wide' : 'square'}
            size={40}
          />
        </div>
        {children}
      </div>
      <footer className="mt-10 flex w-full max-w-md flex-wrap items-center justify-between gap-3">
        <BuiltOnWhity label={t('join.builtOn', 'Built on Whity')} />
        <LanguageSwitcher
          variant="dropdown"
          className="h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
        />
      </footer>
    </main>
  );
}
