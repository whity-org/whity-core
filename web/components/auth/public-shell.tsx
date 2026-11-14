'use client';

import type { ReactNode } from 'react';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import { useBranding, WorkspaceBrandProvider, type WorkspaceBrand } from '@/lib/branding-context';

/**
 * The frame of a page a stranger opens for a workspace (WHIT-915): the
 * workspace's logo above, "Built on Whity" and the language switcher below.
 * Same shape as the public form page, /f/[slug]. A page whose API sends the
 * workspace's brand passes it as `brand`, and the frame and everything in it
 * take the workspace's logo and colour (WHIT-1014).
 */
export function PublicShell({
  children,
  wide = false,
  brand = null,
}: {
  children: ReactNode;
  /** A wider column, for the booking page's week of days (WHIT-945). */
  wide?: boolean;
  /** The workspace's own brand when the page's API sent it (WHIT-1014); else the host's. */
  brand?: WorkspaceBrand | null;
}) {
  const frame = <ShellFrame wide={wide}>{children}</ShellFrame>;
  return brand ? <WorkspaceBrandProvider brand={brand}>{frame}</WorkspaceBrandProvider> : frame;
}

/** Inside the brand provider, so the logo is the workspace's when one was given. */
function ShellFrame({ children, wide }: { children: ReactNode; wide: boolean }) {
  const branding = useBranding();
  const t = useTranslation('auth');
  const width = wide ? 'max-w-2xl' : 'max-w-md';

  return (
    <main className="flex min-h-screen flex-col items-center bg-background px-4 py-10">
      <div className={`w-full ${width} flex-1 space-y-6`}>
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
      <footer className={`mt-10 flex w-full ${width} flex-wrap items-center justify-between gap-3`}>
        {/* WHIT-932: the workspace's plan may remove the credit. */}
        {branding.builtOnWhity && <BuiltOnWhity label={t('join.builtOn', 'Built on Whity')} />}
        <LanguageSwitcher
          variant="dropdown"
          className="h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
        />
      </footer>
    </main>
  );
}
