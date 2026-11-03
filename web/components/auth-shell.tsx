'use client';

/**
 * The card every signed-out page sits in (docs/design/components/TplAuthSignIn):
 * the workspace's logo, or the Whity mark with the product name, then the
 * title and one line under it. Used by sign-in, password reset and invitations.
 */

import Image from 'next/image';
import { Mark } from '@whity/ui/mark';
import { useBranding } from '@/lib/branding-context';

export function AuthShell({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
}) {
  const branding = useBranding();
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
