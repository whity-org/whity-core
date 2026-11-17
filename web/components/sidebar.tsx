'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import type { Membership } from '@/lib/auth-context';
import { useBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useToast } from '@/lib/toast-context';
import { Avatar } from '@whity/ui/avatar';
import { Lockup } from '@whity/ui/lockup';
import { Mark } from '@whity/ui/mark';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { TenantSwitcher as WorkspaceSwitcher } from '@whity/ui/tenant-switcher';
import { AppSidebar, useForcedRail } from '@whity/ui/app-sidebar';
import { useCurrentLanguage, useLanguageCode, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles/role-label';
import { rolesLabel } from '@/lib/workspace-memberships';
import { useCanOfferWorkspaceCreate } from '@/lib/use-workspace-create';
import { NOTIFICATIONS_HREF } from '@/lib/pwa/push';
import {
  IconLogout,
  IconUserCog,
  IconBell,
  IconSelector,
  IconSun,
  IconMoon,
  IconLanguage,
} from '@tabler/icons-react';
import { useState, useCallback, useMemo } from 'react';
import { useShellNav } from '@/components/shell/use-shell-nav';
import { localizedText } from '@whity/ui/localized-text';
import { useCalendarSidebarOpen } from '@/lib/calendar/choices';

/** Settings stays at the foot with the account row on short windows (PW.10). */
const PINNED_GROUP_IDS = ['settings'];

/** The installation's name when nobody has renamed it: the header shows the lockup. */
const DEFAULT_SITE_NAME = 'Whity';
// ---------------------------------------------------------------------------
// TenantSwitcher — uses the shared Switcher primitive from @whity/ui.
// ---------------------------------------------------------------------------

interface TenantSwitcherProps {
  /** The profile's active memberships (from auth-context). */
  memberships: Membership[];
  /** The currently active tenant_id (from user.tenant_id). */
  activeTenantId: number | undefined;
  /** Whether the sidebar is in icon-only (collapsed) mode. */
  collapsed: boolean;
}

function capitalise(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function TenantSwitcher({ memberships, activeTenantId, collapsed }: TenantSwitcherProps) {
  const { switchTenant } = useAuth();
  const router = useRouter();
  const canCreate = useCanOfferWorkspaceCreate();
  const { refresh: refreshNav } = useNavigation();
  const { addToast } = useToast();
  const t = useTranslation('common');
  const currentLanguage = useLanguageCode();
  const [isSwitching, setIsSwitching] = useState(false);

  const workspaces = useMemo(
    () =>
      memberships.map((m) => ({
        id: String(m.tenant_id),
        // #821: the reader's language, else the original name.
        name: localizedText(m.tenant_names, currentLanguage, m.tenant_name_language, m.tenant_name),
        // "Owner" first when they own it (WHIT-1169), then their roles.
        role:
          [m.is_owner ? t('sidebar.tenantSwitcher.owner', 'Owner') : '', capitalise(rolesLabel(m))]
            .filter((part) => part !== '')
            .join(', ') || undefined,
      })),
    [memberships, currentLanguage, t],
  );

  const handleSwitch = useCallback(
    (idStr: string) => {
      const tenantId = Number(idStr);
      if (tenantId === activeTenantId || isSwitching) return;
      setIsSwitching(true);
      void (async () => {
        try {
          await switchTenant(tenantId);
          await refreshNav();
        } catch (err) {
          const message =
            err instanceof Error
              ? err.message
              : t('sidebar.tenantSwitcher.error', 'Couldn’t switch workspace');
          addToast(message, 'error');
        } finally {
          setIsSwitching(false);
        }
      })();
    },
    [activeTenantId, isSwitching, switchTenant, refreshNav, addToast, t],
  );

  if (workspaces.length === 0) return null;

  return (
    <WorkspaceSwitcher
      workspaces={workspaces}
      currentId={activeTenantId !== undefined ? String(activeTenantId) : undefined}
      onChange={handleSwitch}
      collapsed={collapsed}
      disabled={isSwitching}
      onCreate={canCreate ? () => router.push('/workspaces/new') : undefined}
      labels={{
        create: t('sidebar.tenantSwitcher.create', 'Create workspace'),
        trigger: t('sidebar.tenantSwitcher.trigger', 'Switch workspace, current: {name}'),
        find: t('sidebar.tenantSwitcher.find', 'Find a workspace'),
        noResults: t('sidebar.tenantSwitcher.noResults', 'No workspace matches'),
      }}
    />
  );
}

// ---------------------------------------------------------------------------
/**
 * The app's composed sidebar: it owns the DATA (nav items from
 * `/api/navigation`, branding, tenant memberships, theme/language controls)
 * and hands the CHROME to `AppSidebar` from the UI kit — the split that
 * component's own docblock describes. Collapse/expand, the mobile drawer,
 * group disclosure, the filter box and RTL mirroring all live there, so the
 * Tauri desktop client gets the same behaviour from the same code.
 */
export function Sidebar() {
  const router = useRouter();
  const { logout, user, memberships } = useAuth();
  const branding = useBranding();
  const { resolved: resolvedTheme, setPreference } = useThemeMode();
  const t = useTranslation('common');
  // Whether this instance offers a language choice at all (`i18n.enabled`).
  const isI18nEnabled = useI18nEnabled();
  const { groups } = useShellNav();
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage();

  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobileOpen, setIsMobileOpen] = useState(false);

  // The icon rail is a DESKTOP mode: the mobile drawer is full-width, so a
  // collapse chosen on a wide viewport must not follow the user into it and
  // leave them with unlabelled icons in a 16rem-wide sheet.
  // Between 768 and 1023px the sidebar is the rail whatever the toggle says
  // (register 4.5); the logo, switcher and footer follow the same state.
  const forcedRail = useForcedRail();
  // Calendar screens start as the 56px rail so the grid gets the room; a
  // person who opens the sidebar there keeps it open on their calendar (CV.1).
  const pathname = usePathname();
  const onCalendar = pathname === '/calendar' || (pathname?.startsWith('/calendar/') ?? false);
  const [calendarOpen, setCalendarOpen] = useCalendarSidebarOpen();
  const chosenRail = onCalendar ? !calendarOpen : isCollapsed;
  const collapsed = (chosenRail || forcedRail) && !isMobileOpen;
  const onCollapsedChange = useCallback(
    (next: boolean) => (onCalendar ? setCalendarOpen(!next) : setIsCollapsed(next)),
    [onCalendar, setCalendarOpen]
  );


  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  const header = collapsed ? (
    branding.logoSquareUrl ? (
      <img
        src={branding.logoSquareUrl}
        alt={branding.siteName}
        className="mx-auto h-8 w-8 object-contain"
      />
    ) : (
      branding.siteName === DEFAULT_SITE_NAME ? (
        <div className="flex justify-center">
          <Mark size={24} />
        </div>
      ) : (
        <div className="text-center text-xl font-semibold">
          {branding.siteName.charAt(0).toUpperCase()}
        </div>
      )
    )
  ) : (
    <>
      {branding.logoWideUrl ? (
        <img
          src={branding.logoWideUrl}
          alt={branding.siteName}
          className="h-8 w-auto max-w-[180px] object-contain"
        />
      ) : branding.siteName === DEFAULT_SITE_NAME ? (
        // The Whity lockup, as on every 3.3 card; a renamed installation keeps
        // its own name in text.
        <h1 className="m-0">
          <Lockup size={24} />
        </h1>
      ) : (
        <h1 className="text-xl font-semibold">{branding.siteName}</h1>
      )}
    </>
  );

  // The foot is the person, as on every 3.3 card: avatar, name and role in one
  // row that opens the account menu (account settings, language, light or
  // dark, sign out). The user menu has no card of its own yet (WHIT-827), so
  // it is built to the nearest one: the row is the cards', and the menu holds
  // what the old foot showed as four stacked controls.
  const displayName = user?.display_name || user?.email || '';
  const activeMembership = memberships.find((membership) => membership.tenant_id === user?.tenant_id);
  const activeRole = (activeMembership ? rolesLabel(activeMembership) : '') || user?.role || '';
  const footer = (
    <DropdownMenu>
      <DropdownMenuTrigger
        className={`flex min-h-12 w-full items-center gap-3 rounded-lg px-2 py-2 text-start transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring ${collapsed ? 'justify-center' : ''}`}
        aria-label={t('sidebar.accountMenu', 'Account menu')}
        title={collapsed ? displayName : undefined}
        data-testid="account-menu"
      >
        <Avatar name={displayName} size={32} />
        {!collapsed && (
          <>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium" data-testid="account-name">
                {displayName}
              </span>
              {activeRole && (
                <span className="block truncate text-xs text-muted-foreground">{roleLabel(activeRole)}</span>
              )}
            </span>
            <IconSelector size={16} className="shrink-0 text-muted-foreground" aria-hidden />
          </>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent side="top" align="start" className="w-72">
        <DropdownMenuLabel className="font-normal">
          <span className="block truncate text-sm font-medium text-foreground">{displayName}</span>
          {user?.email && user.email !== displayName && (
            <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
          )}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link href="/settings" onClick={() => setIsMobileOpen(false)}>
            <IconUserCog />
            {t('sidebar.accountSettings', 'My settings')}
          </Link>
        </DropdownMenuItem>
        {/* WHIT-1078: notifications on this device, straight to the card. */}
        <DropdownMenuItem asChild>
          <Link href={NOTIFICATIONS_HREF} onClick={() => setIsMobileOpen(false)} data-testid="account-menu-notifications">
            <IconBell />
            {t('sidebar.notifications', 'Notifications')}
          </Link>
        </DropdownMenuItem>
        {isI18nEnabled && availableLanguages.length > 1 && (
          // The language is also the direction (lib/direction-context.tsx):
          // choosing Arabic mirrors the interface. Stored on the profile, so it
          // follows the person across devices.
          <DropdownMenuSub>
            <DropdownMenuSubTrigger data-testid="language-switcher">
              <IconLanguage />
              <span className="flex-1">{t('sidebar.language', 'Language')}</span>
              <span className="text-xs text-muted-foreground">
                {availableLanguages.find((language) => language.code === (currentLanguage || 'en'))?.name}
              </span>
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
              <DropdownMenuRadioGroup
                value={currentLanguage || 'en'}
                onValueChange={(code) => void setLanguage(code)}
              >
                {availableLanguages.map((language) => (
                  <DropdownMenuRadioItem key={language.code} value={language.code} lang={language.code}>
                    {language.name}
                  </DropdownMenuRadioItem>
                ))}
              </DropdownMenuRadioGroup>
            </DropdownMenuSubContent>
          </DropdownMenuSub>
        )}
        {/* Appearance as two choices, as on PwShortSidebar: Light or Dark. */}
        <div
          role="group"
          aria-label={t('sidebar.appearance', 'Appearance')}
          data-testid="theme-toggle"
          className="flex min-h-9 items-center gap-2 px-2 py-1 text-sm"
        >
          <IconSun className="size-4 text-muted-foreground" aria-hidden />
          <span className="flex-1">{t('sidebar.appearance', 'Appearance')}</span>
          <span className="flex rounded-md bg-muted p-0.5">
            {(['light', 'dark'] as const).map((mode) => (
              <button
                key={mode}
                type="button"
                aria-pressed={resolvedTheme === mode}
                onClick={() => setPreference(mode)}
                className={`flex min-h-7 items-center gap-1 rounded px-2 text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring ${
                  resolvedTheme === mode ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground'
                }`}
              >
                {mode === 'light' ? <IconSun className="size-3.5" aria-hidden /> : <IconMoon className="size-3.5" aria-hidden />}
                {mode === 'light' ? t('sidebar.theme.lightShort', 'Light') : t('sidebar.theme.darkShort', 'Dark')}
              </button>
            ))}
          </span>
        </div>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={handleLogout}>
          <IconLogout className="rtl:-scale-x-100" />
          {t('sidebar.logout', 'Sign out')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <AppSidebar
      groups={groups}
      header={header}
      tenantSwitcher={
        // Under the logo, as on the TenantSwitcher card (WC-f8164c87).
        <TenantSwitcher
          memberships={memberships}
          activeTenantId={user?.tenant_id}
          collapsed={collapsed}
        />
      }
      footer={footer}
      collapsed={collapsed}
      onCollapsedChange={onCollapsedChange}
      mobileOpen={isMobileOpen}
      onMobileOpenChange={setIsMobileOpen}
      linkComponent={Link}
      phoneTabBar
      pinnedGroupIds={PINNED_GROUP_IDS}
      collapsibleGroups
      // One search (WHIT-1082): the top bar's, Ctrl+K. The sidebar's own filter box went.
      navLabel={t('sidebar.navLabel', 'Main')}
      openNavLabel={t('sidebar.openNav', 'Open navigation')}
      closeNavLabel={t('sidebar.closeNav', 'Close navigation')}
      collapseLabel={t('sidebar.collapseShort', 'Collapse')}
      collapseAriaLabel={t('sidebar.collapse', 'Collapse sidebar')}
      expandAriaLabel={t('sidebar.expand', 'Expand sidebar')}
    />
  );
}
