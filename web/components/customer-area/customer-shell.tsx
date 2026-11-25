'use client';

/**
 * The customer area's frame (WHIT-1416, design section 41 §7, CA.11, CA.12).
 *
 * The workspace's logo, name and colour on every screen (CA.2). From 640px a
 * top bar: the logo and name at the start, the tabs in a row, the account
 * menu at the end; no sidebar, since a customer has few places to go. Under
 * 640px the tabs move to a bar at the bottom (PW.1, PW.3): up to five, padded
 * for the safe area, hidden while the keyboard is open.
 *
 * No command palette, no agents, no approvals, no Create (M.31).
 */

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import type { ReactNode } from 'react';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { Icon, type IconName } from '@whity/ui/icon';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useKeyboardOpen } from '@whity/ui/phone-tab-bar';
import { cn } from '@whity/ui/utils';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import { UserText } from '@/components/ui/user-text';
import { useAuth } from '@/lib/auth-context';
import { useBranding } from '@/lib/branding-context';
import { areaHref, type CustomerAreaData, type CustomerTabId } from '@/lib/customer-area';

interface Tab {
  id: CustomerTabId;
  label: string;
  icon: IconName;
  href: string;
  count: number;
}

const ICONS: Record<CustomerTabId, IconName> = {
  home: 'dashboard',
  bookings: 'appointment',
  bills: 'invoice',
  messages: 'comment',
  account: 'user',
};

/** Home first and Account last, the workspace's tabs between (CA.13). */
export function useCustomerTabs(area: CustomerAreaData): Tab[] {
  const t = useTranslation('customer');
  const handle = area.workspace.handle;
  const label: Record<CustomerTabId, string> = {
    home: t('tab.home', 'Home'),
    bookings: t('tab.bookings', 'Bookings'),
    bills: t('tab.bills', 'Bills'),
    messages: t('tab.messages', 'Messages'),
    account: t('tab.account', 'Account'),
  };
  const ids: CustomerTabId[] = ['home', ...area.tabs.map((tab) => tab.id as CustomerTabId), 'account'];
  const counts = new Map(area.tabs.map((tab) => [tab.id, tab.count]));

  return ids.map((id) => ({ id, label: label[id], icon: ICONS[id], href: areaHref(handle, id), count: counts.get(id as never) ?? 0 }));
}

export function CustomerShell({ area, children }: { area: CustomerAreaData; children: ReactNode }) {
  const t = useTranslation('customer');
  const branding = useBranding();
  const pathname = usePathname() ?? '';
  const tabs = useCustomerTabs(area);
  const keyboardOpen = useKeyboardOpen();
  const { logout } = useAuth();
  const router = useRouter();
  const handle = area.workspace.handle;
  const active = activeTab(pathname, handle);

  const signOut = async () => {
    await logout();
    router.replace(areaHref(handle));
  };

  return (
    <div className="flex min-h-screen flex-col bg-background">
      <header className="sticky top-0 z-30 border-b border-border bg-card pt-[env(safe-area-inset-top)]">
        <div className="mx-auto flex h-16 max-w-5xl items-center gap-4 px-4">
          <Link href={areaHref(handle, 'home')} className="flex min-w-0 items-center gap-3 rounded-control outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <TenantLogo
              name={branding.siteName}
              src={branding.logoSquareUrl ?? branding.logoWideUrl}
              shape="square"
              size={32}
              showName={false}
            />
            <UserText className="truncate font-semibold">{area.workspace.name}</UserText>
          </Link>
          <nav aria-label={t('nav.label', 'Your account')} className="ms-4 hidden flex-1 items-center gap-1 sm:flex" data-testid="customer-tabs">
            {tabs.map((tab) => (
              <Link
                key={tab.id}
                href={tab.href}
                aria-current={active === tab.id ? 'page' : undefined}
                className={cn(
                  'relative inline-flex h-10 items-center gap-2 rounded-control px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  active === tab.id ? 'bg-primary/10 font-semibold text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                )}
              >
                <Icon name={tab.icon} size={18} />
                {tab.label}
                {tab.count > 0 && <Count n={tab.count} label={t('nav.count', '{count} waiting', { count: tab.count })} />}
              </Link>
            ))}
          </nav>
          <div className="ms-auto flex items-center gap-2">
            <LanguageSwitcher
              variant="dropdown"
              className="hidden h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 sm:block"
            />
            <button
              type="button"
              onClick={() => void signOut()}
              className="hidden h-10 items-center gap-2 rounded-control border border-input px-3 text-sm outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring sm:inline-flex"
            >
              <Icon name="logout" size={18} />
              {t('nav.signOut', 'Sign out')}
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6 max-sm:pb-[calc(5rem+env(safe-area-inset-bottom))]">
        {children}
      </main>

      <footer className="mx-auto hidden w-full max-w-5xl px-4 pb-6 sm:block">
        {branding.builtOnWhity && <BuiltOnWhity label={t('builtOn', 'Built on Whity')} />}
      </footer>

      <nav
        aria-label={t('nav.label', 'Your account')}
        hidden={keyboardOpen}
        className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card pb-[env(safe-area-inset-bottom)] sm:hidden"
        data-testid="customer-phone-tabs"
      >
        <ul className="grid h-16 items-stretch px-1" style={{ gridTemplateColumns: `repeat(${Math.min(tabs.length, 5)}, minmax(0, 1fr))` }}>
          {tabs.slice(0, 5).map((tab) => (
            <li key={tab.id} className="flex">
              <Link
                href={tab.href}
                aria-current={active === tab.id ? 'page' : undefined}
                className={cn(
                  'relative flex min-h-12 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  active === tab.id ? 'font-semibold text-primary' : 'text-muted-foreground',
                )}
              >
                <Icon name={tab.icon} size={22} />
                <span className="truncate">{tab.label}</span>
                {tab.count > 0 && (
                  <span className="absolute top-1 start-[calc(50%+6px)]">
                    <Count n={tab.count} label={t('nav.count', '{count} waiting', { count: tab.count })} />
                  </span>
                )}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}

function Count({ n, label }: { n: number; label: string }) {
  return (
    <span
      aria-label={label}
      className="inline-grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-xs leading-none font-semibold text-primary-foreground"
    >
      <bdi dir="ltr">{n > 99 ? '99+' : n}</bdi>
    </span>
  );
}

/** Which tab a path belongs to: `/@h/bills/quotes/7` (or its rewrite `/w/h/...`) is Bills. */
export function activeTab(pathname: string, handle: string): CustomerTabId {
  const rest = pathname.replace(new RegExp(`^/(?:@|w/)${handle}`), '').split('/')[1] ?? '';
  return (['bookings', 'bills', 'messages', 'account'] as const).find((id) => id === rest) ?? 'home';
}
