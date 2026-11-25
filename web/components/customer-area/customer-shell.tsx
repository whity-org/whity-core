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
import { useEffect, useState, type ReactNode } from 'react';
import { addScopedAuthorization, apiFetch } from '@/lib/api/client';
import { screenAuthorization } from '@/lib/customer-screen';
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
import { useLocalized } from './area-parts';

interface Tab {
  id: CustomerTabId;
  label: string;
  icon: IconName;
  href: string;
  count: number;
}

const ICONS: Record<string, IconName> = {
  home: 'dashboard',
  bookings: 'appointment',
  bills: 'invoice',
  messages: 'comment',
  account: 'user',
  more: 'more',
};

/** Up to five tabs fit the phone bar; past that: Home, the next three, and More (CA.12). */
export const PHONE_TABS = 5;

/** Home first and Account last, the workspace's tabs between (CA.13). */
export function useCustomerTabs(area: CustomerAreaData): Tab[] {
  const t = useTranslation('customer');
  const name = useLocalized();
  const handle = area.workspace.handle;
  const core: Record<string, string> = {
    home: t('tab.home', 'Home'),
    bookings: t('tab.bookings', 'Bookings'),
    bills: t('tab.bills', 'Bills'),
    messages: t('tab.messages', 'Messages'),
    account: t('tab.account', 'Account'),
  };
  const middle: Tab[] = area.tabs.map((tab) => {
    const id = tab.id as CustomerTabId;
    if (id.startsWith('x:')) {
      // A plugin's tab (SDK 1.173): its own label and icon, its own page.
      const label = typeof tab.label === 'string' ? tab.label : name(tab.label as Record<string, string> | null);
      return { id, label, icon: (tab.icon as IconName | null) ?? 'plugin', href: areaHref(handle, `x/${id.slice(2)}`), count: tab.count };
    }
    return { id, label: core[id] ?? id, icon: ICONS[id] ?? 'plugin', href: areaHref(handle, id), count: tab.count };
  });

  return [
    { id: 'home', label: core.home, icon: ICONS.home, href: areaHref(handle, 'home'), count: 0 },
    ...middle,
    { id: 'account', label: core.account, icon: ICONS.account, href: areaHref(handle, 'account'), count: 0 },
  ];
}

/**
 * A plugin tab's count (SDK 1.173): its `count` route, called with that
 * screen's customer token, answers `{"count": n}`.
 */
function usePluginCounts(area: CustomerAreaData): Record<string, number> {
  const [counts, setCounts] = useState<Record<string, number>>({});
  useEffect(() => {
    const handle = area.workspace.handle;
    let alive = true;
    const removers: (() => void)[] = [];
    for (const tab of area.tabs) {
      const path = tab.count_path;
      if (!tab.id.startsWith('x:') || typeof path !== 'string' || path === '') continue;
      removers.push(addScopedAuthorization(screenAuthorization(handle, tab.id.slice(2), [path])));
      apiFetch(path)
        .then((r) => (r.ok ? r.json() : null))
        .then((body: { count?: unknown; data?: { count?: unknown } } | null) => {
          const n = body?.count ?? body?.data?.count;
          if (alive && typeof n === 'number' && Number.isFinite(n)) setCounts((c) => ({ ...c, [tab.id]: Math.max(0, Math.floor(n)) }));
        })
        .catch(() => undefined);
    }
    return () => {
      alive = false;
      removers.forEach((remove) => remove());
    };
  }, [area]);

  return counts;
}

/** The phone bar: all tabs when they fit, else Home, the next three and More. */
export function phoneTabs(tabs: Tab[], moreLabel: string, handle: string): Tab[] {
  if (tabs.length <= PHONE_TABS) return tabs;
  const rest = tabs.slice(PHONE_TABS - 1);
  return [
    ...tabs.slice(0, PHONE_TABS - 1),
    { id: 'more', label: moreLabel, icon: 'more', href: areaHref(handle, 'more'), count: rest.reduce((n, tab) => n + tab.count, 0) },
  ];
}

export function CustomerShell({ area, children }: { area: CustomerAreaData; children: ReactNode }) {
  const t = useTranslation('customer');
  const branding = useBranding();
  const pathname = usePathname() ?? '';
  const pluginCounts = usePluginCounts(area);
  const tabs = useCustomerTabs(area).map((tab) => (tab.id in pluginCounts ? { ...tab, count: pluginCounts[tab.id] } : tab));
  const phone = phoneTabs(tabs, t('tab.more', 'More'), area.workspace.handle);
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
        <ul className="grid h-16 items-stretch px-1" style={{ gridTemplateColumns: `repeat(${phone.length}, minmax(0, 1fr))` }}>
          {phone.map((tab) => (
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
  const parts = pathname.replace(new RegExp(`^/(?:@|w/)${handle}`), '').split('/');
  const rest = parts[1] ?? '';
  if (rest === 'x' && parts[2]) return `x:${parts[2]}`;
  return (['bookings', 'bills', 'messages', 'account', 'more'] as const).find((id) => id === rest) ?? 'home';
}
