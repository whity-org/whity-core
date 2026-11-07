'use client';

/**
 * The card every signed-out page sits in (docs/design/components/TplAuthSignIn):
 * the workspace's logo, or the Whity mark with the product name, then the
 * title and one line under it. Used by sign-in, password reset and invitations.
 *
 * With an `aside` (sign-in, #1734) it becomes two columns on wide screens: the
 * form on the start side and the brand panel on the end side, mirrored by the
 * grid in RTL and stacked, form first, on phones. Whity's own console shows the
 * lockup; a white-labelled workspace (`isWhiteLabel`) shows its own logo, gets
 * `.wy-theme-partner` and the "Built on Whity" credit at the foot, and never
 * the Whity patterns.
 */

import Image from 'next/image';
import { Mark } from '@whity/ui/mark';
import { Lockup } from '@whity/ui/lockup';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import { useBranding } from '@/lib/branding-context';
import type { Branding } from '@/lib/branding';

/**
 * A workspace shown under its own name or logo is white-labelled. A main
 * colour alone is not: Whity's own console may set one (app.whity.dev does)
 * and is still Whity.
 */
export function isWhiteLabel(branding: Branding): boolean {
  return (
    branding.siteName.trim() !== 'Whity' || branding.logoWideUrl !== null || branding.logoSquareUrl !== null
  );
}

export function AuthShell({
  title,
  subtitle,
  aside,
  children,
}: {
  title: string;
  subtitle?: string;
  /** The brand panel. Turns the page into the two-column sign-in layout. */
  aside?: React.ReactNode;
  children: React.ReactNode;
}) {
  const branding = useBranding();
  const t = useTranslation('auth');

  if (aside) {
    const partner = isWhiteLabel(branding);
    return (
      <div
        data-brand={partner ? 'partner' : 'whity'}
        className={partner ? 'wy-theme-partner grid min-h-screen bg-background lg:grid-cols-2' : 'grid min-h-screen bg-background lg:grid-cols-2'}
      >
        <main className="flex flex-col bg-card px-4 pt-4 pb-8 sm:px-10 lg:min-h-screen">
          <div className="flex justify-end">
            <LanguageSwitcher
              variant="dropdown"
              className="h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
            />
          </div>
          <div className="flex flex-1 items-center justify-center py-8">
            <div className="w-full max-w-sm space-y-6">
              <div className="space-y-5">
                {partner ? (
                  <TenantLogo
                    name={branding.siteName}
                    src={branding.logoWideUrl ?? branding.logoSquareUrl}
                    shape={branding.logoWideUrl ? 'wide' : 'square'}
                    size={branding.logoWideUrl ? 40 : 32}
                  />
                ) : (
                  <Lockup size={32} />
                )}
                <div className="space-y-1">
                  <h1 className="text-2xl font-semibold text-foreground">{title}</h1>
                  {subtitle ? <p className="text-muted-foreground">{subtitle}</p> : null}
                </div>
              </div>
              {children}
            </div>
          </div>
          {partner ? (
            <footer className="flex justify-center">
              <BuiltOnWhity label={t('join.builtOn', 'Built on Whity')} />
            </footer>
          ) : null}
        </main>
        {aside}
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <div className="w-full max-w-md space-y-5 rounded-card border border-border bg-card p-6 shadow-sm sm:p-8">
        <div className="flex flex-col items-center gap-4 text-center">
          {branding.logoWideUrl ? (
            <Image
              src={branding.logoWideUrl}
              alt={branding.siteName}
              width={220}
              height={40}
              className="h-10 w-auto max-w-[220px] object-contain"
            />
          ) : (
            <span className="flex items-center gap-2 text-lg font-semibold text-foreground">
              <Mark size={32} label="" />
              {branding.siteName}
            </span>
          )}
          <div className="space-y-1">
            <h1 className="text-xl font-semibold text-foreground">{title}</h1>
            {subtitle ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
          </div>
        </div>
        {children}
      </div>
    </div>
  );
}
