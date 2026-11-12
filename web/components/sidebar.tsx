'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import type { Membership } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { useBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useToast } from '@/lib/toast-context';
import { Avatar } from '@whity/ui/avatar';
import { Lockup } from '@whity/ui/lockup';
import { Mark } from '@whity/ui/mark';
import { ICONS } from '@whity/ui/icon-map';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { TenantSwitcher as WorkspaceSwitcher } from '@whity/ui/tenant-switcher';
import { AppSidebar, useForcedRail } from '@whity/ui/app-sidebar';
import { navGroupsFromServerItems } from '@whity/features/nav';
import { useCurrentLanguage, useLanguageCode, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { roleLabel } from '@whity/features/roles';
import { localizedName } from '@/lib/localized-name';
import * as TablerIcons from '@tabler/icons-react';
import {
  IconLogout,
  IconDashboard,
  IconUserCog,
  IconSettings,
  IconSelector,
  IconSun,
  IconMoon,
} from '@tabler/icons-react';
import { useState, useCallback, useEffect, useMemo } from 'react';
import { useNavItemLabels } from '@/lib/nav-item-labels';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import {
  PLATFORM_SETTINGS_TABS,
  SETTINGS_AREA_NAV_IDS,
  SETTINGS_AREA_PREFIXES,
} from '@/components/settings/settings-area';
import type { Icon } from '@tabler/icons-react';

/**
 * Resolve a navigation `icon` name to a Tabler icon component.
 *
 * Core nav items emit kebab-case names (e.g. `"building-community"`), but
 * plugins may supply any Tabler icon by its kebab/snake name or its full
 * PascalCase component name (e.g. `"IconUsers"`). We normalize the name to the
 * `Icon<PascalCase>` export and look it up dynamically against the full
 * `@tabler/icons-react` set, falling back to a safe default for unknown names
 * so a plugin can never render a missing-icon hole.
 */
const tablerIcons = TablerIcons as unknown as Record<string, Icon | undefined>;

function resolveIcon(name: string | undefined): Icon {
  if (!name) {
    return IconDashboard;
  }

  // Whity's own names first (decision 5.1, `handoff/icons/icons.json`): core
  // registers `comment`, `customer`, `list-todo` and `dashboard`, which are
  // curated names, not Tabler's. Read as Tabler names they were missing (Chat,
  // Contacts and Tasks fell back to the dashboard gauge) or the wrong glyph.
  const curated = (ICONS as Record<string, { icon: Icon } | undefined>)[name.trim()];
  if (curated) {
    return curated.icon;
  }

  // Split on hyphen/underscore/whitespace, capitalize each segment, join.
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');

  const componentName = pascal.startsWith('Icon') ? pascal : `Icon${pascal}`;

  return tablerIcons[componentName] ?? IconDashboard;
}

/**
 * The order the nav's groups appear in, and the only place that sequence is
 * decided. The server says which group each page belongs to (`group` on every
 * `navigation.register` item) and where it sits WITHIN that group (`order`);
 * it deliberately does not say which group comes first, because that is a
 * layout question and the answer differs per client — a Flutter nav rail may
 * want the same pages in a different sequence.
 *
 * A group id missing from this list still renders, after the declared ones —
 * a plugin inventing its own group appears rather than vanishing. `plugins` is
 * listed because `PluginNavigationBridge` defaults every plugin-contributed
 * feature to it, so it is a known-but-not-core group.
 */
const NAV_GROUP_ORDER = [
  'overview',
  // Register, section 27: Work follows the top items, then Operations.
  'work',
  'operations',
  'people',
  'ai',
  'documents',
  'platform',
  'settings',
  'plugins',
] as const;

/**
 * The groups core used before the app map's (WHIT-805), still accepted from
 * plugins that name them: each lands in the group that now holds its pages,
 * so a plugin's item sits beside its neighbours instead of under an old
 * heading of its own.
 */
const LEGACY_NAV_GROUPS: Readonly<Record<string, string>> = {
  access: 'people',
  records: 'documents',
  extend: 'platform',
  system: 'platform',
};

/** The personal account page's nav item; the footer's user row opens it instead. */
const ACCOUNT_SETTINGS_ITEM_ID = 'settings';

/** The installation's name when nobody has renamed it: the header shows the lockup. */
const DEFAULT_SITE_NAME = 'Whity';

/** The system tenant: the only one that runs the platform itself. */
const SYSTEM_TENANT_ID = 0;

/** Fallback heading for a group the shell has no translated label for. */
function prettifyGroupId(groupId: string): string {
  return groupId
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

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

function TenantSwitcher({ memberships, activeTenantId, collapsed }: TenantSwitcherProps) {
  const { switchTenant } = useAuth();
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
        name: localizedName(m.tenant_names, currentLanguage, m.tenant_name_language, m.tenant_name),
        role: m.role ? m.role.charAt(0).toUpperCase() + m.role.slice(1) : undefined,
      })),
    [memberships, currentLanguage],
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
              : t('sidebar.tenantSwitcher.error', 'Couldn’t switch tenant');
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
      labels={{
        trigger: t('sidebar.tenantSwitcher.trigger', 'Switch workspace, current: {name}'),
        find: t('sidebar.tenantSwitcher.find', 'Find a workspace'),
        noResults: t('sidebar.tenantSwitcher.noResults', 'No workspace matches'),
      }}
    />
  );
}

// ---------------------------------------------------------------------------

/**
 * How many things wait for the person, by nav item id: the Inbox's open items,
 * summed over its sources (`GET /api/me/inbox/sources` carries each source's
 * open count for exactly this badge), and the agent proposals waiting for
 * their approval (`GET /api/me/agent-proposals?status=waiting`). Re-read on every page change, so acting
 * on an item and moving on updates the count. A failed read shows no count
 * rather than a wrong one.
 */
function useWaitingCounts(pathname: string | null): Record<string, number> {
  const [counts, setCounts] = useState<Record<string, number>>({});
  // A page that just changed what waits (Approvals after a decision) says so
  // with WAITING_CHANGED_EVENT, so the count follows without a navigation.
  const [nudge, setNudge] = useState(0);
  useEffect(() => {
    const onChanged = () => setNudge((n) => n + 1);
    window.addEventListener(WAITING_CHANGED_EVENT, onChanged);
    return () => window.removeEventListener(WAITING_CHANGED_EVENT, onChanged);
  }, []);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      let inbox = 0;
      try {
        const response = await fetch('/api/v1/me/inbox/sources', { credentials: 'include' });
        if (response.ok) {
          const body = (await response.json()) as { data?: { open_count?: number }[] };
          inbox = (body.data ?? []).reduce((sum, source) => sum + (source.open_count ?? 0), 0);
        }
      } catch {
        inbox = 0;
      }
      let approvals = 0;
      try {
        const response = await fetch('/api/v1/me/agent-proposals?status=waiting', { credentials: 'include' });
        if (response.ok) {
          const body = (await response.json()) as { data?: unknown[] };
          approvals = body.data?.length ?? 0;
        }
      } catch {
        approvals = 0;
      }
      if (!cancelled) setCounts((prev) => ({ ...prev, inbox, approvals }));
    })();
    return () => {
      cancelled = true;
    };
  }, [pathname, nudge]);

  return counts;
}

/**
 * The app's composed sidebar: it owns the DATA (nav items from
 * `/api/navigation`, branding, tenant memberships, theme/language controls)
 * and hands the CHROME to `AppSidebar` from the UI kit — the split that
 * component's own docblock describes. Collapse/expand, the mobile drawer,
 * group disclosure, the filter box and RTL mirroring all live there, so the
 * Tauri desktop client gets the same behaviour from the same code.
 */
export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user, memberships } = useAuth();
  const { items: navItems } = useNavigation();
  const branding = useBranding();
  const { resolved: resolvedTheme, toggle: toggleTheme } = useThemeMode();
  const t = useTranslation('common');
  // Whether this instance offers a language choice at all (`i18n.enabled`).
  const isI18nEnabled = useI18nEnabled();
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage();

  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobileOpen, setIsMobileOpen] = useState(false);

  // The icon rail is a DESKTOP mode: the mobile drawer is full-width, so a
  // collapse chosen on a wide viewport must not follow the user into it and
  // leave them with unlabelled icons in a 16rem-wide sheet.
  // Between 768 and 1023px the sidebar is the rail whatever the toggle says
  // (register 4.5); the logo, switcher and footer follow the same state.
  const forcedRail = useForcedRail();
  const collapsed = (isCollapsed || forcedRail) && !isMobileOpen;

  // Literal `t()` calls, not a computed key — `i18n:extract` rebuilds the
  // catalogue by scanning the source for them, so a dynamic
  // `t('sidebar.group.' + id)` would never make it into the translations
  // table and every heading would render as a raw key.
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;
  const counts = useWaitingCounts(pathname);

  // The top group (Dashboard, Inbox) has no heading, as on the app map. The
  // admin group is the Platform on the system tenant, which runs the whole
  // deployment, and the Workspace everywhere else.
  const groupLabels = useMemo<Record<string, string | undefined>>(
    () => ({
      overview: undefined,
      work: t('sidebar.group.work', 'Work'),
      operations: t('sidebar.group.operations', 'Operations'),
      people: t('sidebar.group.people', 'People'),
      ai: t('sidebar.group.ai', 'AI'),
      documents: t('sidebar.group.documents', 'Documents'),
      platform: isSystemTenant
        ? t('sidebar.group.platform', 'Platform')
        : t('sidebar.group.workspace', 'Workspace'),
      settings: t('sidebar.group.settings', 'Settings'),
      plugins: t('sidebar.group.plugins', 'Plugins'),
    }),
    [t, isSystemTenant],
  );

  // The same literal-`t()` discipline as the group headings above, for the
  // same reason and one more: an item's `label` arrives from
  // `GET /api/v1/navigation`, where it is a hardcoded English string in the
  // registry rather than a `t()` call. Nothing scans that registry, so none of
  // these names has ever reached the catalogue — which is why an Arabic sidebar
  // used to read its GROUP headings in Arabic and every PAGE name under them in
  // English.
  //
  // Keyed by the server's stable item `id`, never by its English text, so
  // rewording a label upstream is a translation edit and not a key rename. An
  // id this map does not know keeps the server's label: that is the case for
  // every plugin-contributed entry, whose name is a plugin's to give.
  const itemLabels = useNavItemLabels();

  const groups = useMemo(() => {
    // The Settings area (WHIT-806, owner decision D1): every settings page,
    // the data pages it holds and the server's `settings` group become ONE
    // entry at the foot, opening the area's own sub-nav. The personal account
    // page leaves the nav too: it is the user row in the footer.
    const inSettingsArea = (item: { id: string; group?: string }) =>
      item.group === 'settings' || (SETTINGS_AREA_NAV_IDS as readonly string[]).includes(item.id);
    const settingsEntry = navItems.find((item) => item.id === 'website-settings') ?? navItems.find(inSettingsArea);
    const regrouped = navItems
      .filter((item) => !inSettingsArea(item) && item.id !== ACCOUNT_SETTINGS_ITEM_ID)
      .map((item) =>
        item.group && LEGACY_NAV_GROUPS[item.group]
          ? { ...item, group: LEGACY_NAV_GROUPS[item.group] }
          : item,
      );
    const built = navGroupsFromServerItems(regrouped, {
      currentPath: pathname,
      groupOrder: NAV_GROUP_ORDER,
      groupLabel: (groupId) =>
        groupId in groupLabels ? groupLabels[groupId] : prettifyGroupId(groupId),
      itemLabel: (itemId) => itemLabels[itemId],
      renderIcon: (icon) => {
        const IconComponent = resolveIcon(icon);
        return <IconComponent size={20} />;
      },
    });
    const countLabel = (count: number) =>
      t('sidebar.count.waiting', '{count} waiting').replace('{count}', String(count));
    const withCounts = built.map((group) => ({
      ...group,
      items: group.items.map((item) =>
        counts[item.id] ? { ...item, count: counts[item.id], countLabel: countLabel(counts[item.id]) } : item,
      ),
    }));
    if (!settingsEntry) return withCounts;
    return [
      ...withCounts,
      {
        id: 'settings',
        items: [
          {
            id: 'settings-area',
            label: t('sidebar.item.settings', 'Settings'),
            href: settingsEntry.href,
            icon: <IconSettings size={20} />,
            active:
              !Object.values(PLATFORM_SETTINGS_TABS).includes(pathname ?? '') &&
              SETTINGS_AREA_PREFIXES.some(
                (prefix) => pathname === prefix || (pathname ?? '').startsWith(`${prefix}/`),
              ),
          },
        ],
      },
    ];
  }, [navItems, pathname, groupLabels, itemLabels, counts, t]);

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
  const activeRole =
    memberships.find((membership) => membership.tenant_id === user?.tenant_id)?.role ?? user?.role ?? '';
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
      <DropdownMenuContent side="top" align="start" className="w-60">
        <DropdownMenuLabel className="font-normal text-muted-foreground">
          <span className="block text-xs">{t('sidebar.loggedInAs', 'Logged in as')}</span>
          <span className="block truncate text-sm text-foreground">{user?.email}</span>
        </DropdownMenuLabel>
        <DropdownMenuItem asChild>
          <Link href="/settings" onClick={() => setIsMobileOpen(false)}>
            <IconUserCog />
            {t('sidebar.accountSettings', 'Account settings')}
          </Link>
        </DropdownMenuItem>
        {isI18nEnabled && availableLanguages.length > 1 && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
              {t('sidebar.language', 'Language')}
            </DropdownMenuLabel>
            {/*
              The language is also the direction (lib/direction-context.tsx):
              choosing Arabic mirrors the interface. Stored on the profile, so
              it follows the person across devices.
            */}
            <DropdownMenuRadioGroup
              value={currentLanguage || 'en'}
              onValueChange={(code) => void setLanguage(code)}
              data-testid="language-switcher"
            >
              {availableLanguages.map((language) => (
                <DropdownMenuRadioItem key={language.code} value={language.code} lang={language.code}>
                  {language.name}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={toggleTheme} data-testid="theme-toggle">
          {resolvedTheme === 'dark' ? <IconSun /> : <IconMoon />}
          {resolvedTheme === 'dark'
            ? t('sidebar.theme.light', 'Light mode')
            : t('sidebar.theme.dark', 'Dark mode')}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={handleLogout}>
          <IconLogout className="rtl:-scale-x-100" />
          {t('sidebar.logout', 'Logout')}
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
      onCollapsedChange={setIsCollapsed}
      mobileOpen={isMobileOpen}
      onMobileOpenChange={setIsMobileOpen}
      linkComponent={Link}
      collapsibleGroups
      searchable
      searchPlaceholder={t('sidebar.search.placeholder', 'Search pages')}
      clearSearchLabel={t('sidebar.search.clear', 'Clear search')}
      searchNoResultsLabel={t('sidebar.search.noResults', 'No matching pages')}
      navLabel={t('sidebar.navLabel', 'Main')}
      openNavLabel={t('sidebar.openNav', 'Open navigation')}
      closeNavLabel={t('sidebar.closeNav', 'Close navigation')}
      collapseLabel={t('sidebar.collapseShort', 'Collapse')}
      collapseAriaLabel={t('sidebar.collapse', 'Collapse sidebar')}
      expandAriaLabel={t('sidebar.expand', 'Expand sidebar')}
    />
  );
}
