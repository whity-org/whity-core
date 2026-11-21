'use client';

/**
 * The Settings area (WHIT-806; owner decision D1, 31 Oct 2026).
 *
 * One Settings item in the sidenav opens this: a grouped sub-nav beside the
 * page. The pages keep their own routes and permission gates; this only puts
 * them in one place, so settings stop being scattered across the sidebar.
 *
 * Which pages a person sees comes from the server, never computed here:
 * `GET /api/v1/settings/tabs` for the settings pages (RBAC-filtered), and the
 * caller's navigation for the data pages that live at their own routes
 * (tags, tag groups, languages, translations).
 *
 * There is no design card for this area yet (design ask WHIT-826). It is
 * built from existing patterns: the sub-nav uses the sidebar's selected look
 * (the tint, 7.3), and it is the one seam the brand team's design replaces.
 */

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Icon } from '@whity/ui/icon';
import type { IconName } from '@whity/ui/icon-map';
import { cn } from '@/lib/utils';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useNavigation } from '@/lib/navigation-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_STAMPS, LICENSING_VIEW } from '@/lib/capabilities';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { MY_SETTINGS_PAGES, SETTINGS_HOME_HREF, WORKSPACE_SETTINGS_ANCHOR } from '@/lib/settings/my-settings';
import { useNavItemLabels } from '@/lib/nav-item-labels';
import { searchSettings, useSettingsIndex } from '@/components/settings/settings-index';
import { useBranding } from '@/lib/branding-context';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { CALENDAR_MANAGE } from '@/lib/calendar/api';

/** The system tenant: the one that runs the install (mirrors use-shell-nav). */
const SYSTEM_TENANT_ID = 0;
import { ScopeChip, useCurrentWorkspace } from '@/components/settings/scope';

type SettingsTab = components['schemas']['SettingsTab'];

type GroupId = 'mine' | 'here' | 'workspace' | 'security' | 'email' | 'data' | 'advanced';

/** Your own settings (WHIT-1081): above the workspace's, for everyone. */
const PERSONAL_GROUPS: readonly GroupId[] = ['mine', 'here'];

/**
 * The workspace's groups, in order (D1, regrouped by WHIT-1082): Workspace,
 * Security, Messages and notifications, Data, Advanced. A page the server adds
 * later lands in Advanced.
 */
const GROUP_OF: Readonly<Record<string, GroupId>> = {
  general: 'workspace',
  branding: 'workspace',
  owners: 'workspace',
  'document-themes': 'workspace',
  'document-stamps': 'workspace',
  billing: 'workspace',
  plugins: 'workspace',
  security: 'security',
  sso: 'security',
  signup: 'security',
  'customer-signup': 'security',
  devices: 'security',
  'audit-logs': 'security',
  messages: 'email',
  email: 'email',
  'email-domains': 'email',
  'notification-log': 'email',
  storage: 'data',
  tags: 'data',
  'tag-groups': 'data',
  languages: 'data',
  translations: 'data',
  fields: 'data',
  'public-forms': 'data',
  bookings: 'data',
  'feature-flags': 'advanced',
  'error-tracking': 'advanced',
};

const GROUP_ORDER: GroupId[] = ['mine', 'here', 'workspace', 'security', 'email', 'data', 'advanced'];

/**
 * Workspace pages that used to be the sidebar's Workspace group (WHIT-1082),
 * by their navigation item id, with the id their link takes here. They keep
 * their routes and permissions; the server's navigation still decides who
 * sees each. The system tenant keeps them in its Platform group instead.
 */
export const WORKSPACE_PAGE_NAV_IDS: Readonly<Record<string, string>> = {
  'tenant-billing': 'billing',
  plugins: 'plugins',
  'audit-logs': 'audit-logs',
  notifications: 'notification-log',
};

/** Document themes (A15) had no link anywhere; they are a workspace setting. */
const DOCUMENT_THEMES_HREF = '/admin/document-themes';
const DOCUMENT_THEMES_PERMISSION = 'documents:themes';

/** Company stamps (#1729) sit beside document themes: who holds each stamp is a workspace setting. */
const DOCUMENT_STAMPS_HREF = '/admin/document-stamps';

/** Booking rules stay in the calendar's own pages; Settings links there. */
const BOOKINGS_HREF = '/calendar/settings';

/** Licensed devices keep their own route, `/licensing`, and their own permission. */
const DEVICES_HREF = '/licensing';

/**
 * The data pages that keep their own routes but belong to this area, by the
 * id their navigation item registers under. Exported so the sidebar can fold
 * them into its single Settings entry.
 */
export const SETTINGS_AREA_NAV_IDS = ['tags', 'tag-groups', 'translations'] as const;

/**
 * Settings pages that live in the sidebar's Platform group for the system tenant
 * (W.2). They keep their addresses, so they leave this sub-nav and don't light up the
 * Settings entry.
 */
export const PLATFORM_SETTINGS_TABS: Readonly<Record<string, string>> = {
  email: '/admin/settings/email',
  storage: '/admin/settings/storage',
  'feature-flags': '/admin/settings/feature-flags',
};

/** Every route prefix that opens inside the Settings area. */
export const SETTINGS_AREA_PREFIXES = [
  SETTINGS_HOME_HREF,
  '/admin/settings',
  '/admin/tags',
  '/admin/tag-groups',
  '/admin/translations',
  DEVICES_HREF,
  DOCUMENT_THEMES_HREF,
  DOCUMENT_STAMPS_HREF,
] as const;

/**
 * Pages in the area for a workspace, but in the Platform group for the system
 * tenant (WHIT-1082): the sidebar lights Settings for them only outside it.
 */
export const WORKSPACE_PAGE_PREFIXES = ['/billing', '/admin/plugins', '/admin/audit-logs', '/admin/notifications'] as const;

/**
 * On a phone the menu is its own page (PwSettings, PW.8); More opens it. It is
 * the area's root since WHIT-1081; `/admin/settings/menu` redirects here.
 */
export const SETTINGS_MENU_HREF = SETTINGS_HOME_HREF;

/** "Last opened" is remembered per browser. */
const LAST_OPENED_KEY = 'whity.settings.lastOpened';

const ROW_ICON: Readonly<Record<string, IconName>> = {
  profile: 'user',
  password: 'two-step',
  display: 'text-size',
  notifications: 'notification',
  'signed-in': 'desktop',
  role: 'role',
  general: 'settings',
  branding: 'branding',
  owners: 'role',
  security: 'shield',
  sso: 'key',
  signup: 'user',
  'customer-signup': 'user',
  'public-forms': 'form',
  messages: 'comment',
  email: 'mail',
  'email-domains': 'world',
  storage: 'archive',
  fields: 'field',
  tags: 'flag',
  languages: 'language',
  translations: 'text',
  'feature-flags': 'trigger',
  'error-tracking': 'error',
  devices: 'desktop',
  'document-themes': 'branding',
  'document-stamps': 'approval',
  billing: 'billing',
  plugins: 'plugin',
  'audit-logs': 'audit',
  'notification-log': 'notification',
  bookings: 'calendar',
};

function readLastOpened(): string | null {
  try {
    return window.localStorage.getItem(LAST_OPENED_KEY);
  } catch {
    return null;
  }
}

function writeLastOpened(id: string) {
  try {
    window.localStorage.setItem(LAST_OPENED_KEY, id);
  } catch {
    // Private windows and blocked storage: the mark is a convenience only.
  }
}

export interface SettingsAreaLink {
  id: string;
  href: string;
  label: string;
}

function isActive(pathname: string, href: string): boolean {
  // General is the area's root, so only an exact match selects it; every
  // other page owns its subtree (a language's own record page included).
  if (href === '/admin/settings') return pathname === href;
  return pathname === href || pathname.startsWith(`${href}/`);
}

/**
 * Whether a navigation item is one of the Settings area's pages, so lists that
 * show the area as one entry (the palette's Go to) leave it out.
 */
export function isInSettingsArea(item: { id: string; group?: string }, isSystemTenant: boolean): boolean {
  return (
    item.id === 'settings' ||
    item.group === 'settings' ||
    (SETTINGS_AREA_NAV_IDS as readonly string[]).includes(item.id) ||
    (!isSystemTenant && item.id in WORKSPACE_PAGE_NAV_IDS)
  );
}

export interface SettingsAreaGroup {
  id: GroupId;
  label: string;
  links: SettingsAreaLink[];
}

/** Whether a group holds your own settings (My settings, You in [workspace]). */
export function isPersonalGroup(id: GroupId): boolean {
  return PERSONAL_GROUPS.includes(id);
}

/**
 * Every page in the Settings area this person may open, grouped and ordered
 * as the sub-nav shows them. The one list behind the sub-nav, the phone menu
 * and the settings search (WHIT-1084), so they can never disagree.
 */
export function useSettingsGroups({ enabled = true }: { enabled?: boolean } = {}): SettingsAreaGroup[] {
  const t = useTranslation('admin');
  const tc = useTranslation('common');
  const ta = useTranslation('auth');
  const { id: workspaceId, name: workspaceName } = useCurrentWorkspace();
  // The system tenant runs the install: its workspace-wide pages stay in Platform.
  const isSystemTenant = workspaceId === SYSTEM_TENANT_ID;
  const { items: navItems } = useNavigation();
  const navLabels = useNavItemLabels();
  const { has } = useCapabilities();
  const canSeeDevices = has(LICENSING_VIEW);
  // WHIT-955: the workspace's own fields on its records (A9).
  const canManageFields = has('fields:manage');
  const canEditThemes = has(DOCUMENT_THEMES_PERMISSION);
  const canManageStamps = has(DOCUMENTS_STAMPS);
  const canManageBookings = has(CALENDAR_MANAGE);
  const language = useLanguageCode();

  // The server words the tabs in the caller's language, so a switch needs them again.
  // `enabled: false` (the palette while closed) asks nothing.
  const { data: tabs } = useFetch<SettingsTab[]>(async () => {
    if (!enabled) return [];
    const { data: body } = await api.GET('/api/v1/settings/tabs');
    return body?.data ?? [];
  }, [language, enabled]);

  const groups = useMemo(() => {
    const groupLabels: Record<GroupId, string> = {
      mine: ta('settings.title', 'My settings'),
      here: workspaceName
        ? t('settingsArea.group.here', 'You in {workspace}').replace('{workspace}', workspaceName)
        : t('settingsArea.group.hereNoName', 'You in this workspace'),
      workspace: t('settingsArea.group.workspace', 'Workspace'),
      security: t('settingsArea.group.security', 'Security'),
      email: t('settingsArea.group.email', 'Messages and notifications'),
      data: t('settingsArea.group.data', 'Data'),
      advanced: t('settingsArea.group.advanced', 'Advanced'),
    };
    const workspacePageLabels: Record<string, string> = {
      billing: t('settingsArea.billing', 'Plan and billing'),
      plugins: navLabels.plugins ?? 'Plugins',
      'audit-logs': navLabels['audit-logs'] ?? 'Audit Logs',
      'notification-log': navLabels.notifications ?? 'Notification log',
    };

    const dataLabels: Record<string, string> = {
      tags: tc('sidebar.item.tags', 'Tags'),
      'tag-groups': tc('sidebar.item.tagGroups', 'Tag Groups'),
      languages: tc('sidebar.item.languages', 'Languages'),
      translations: tc('sidebar.item.translations', 'Translations'),
    };

    const personalLabels: Record<string, string> = {
      profile: ta('settings.profile.title', 'Profile'),
      password: ta('settings.tab.security', 'Password and two-step'),
      display: ta('settings.tab.preferences', 'Language and display'),
      notifications: ta('settings.notifications.title', 'Notifications'),
      'signed-in': ta('settings.tab.sessions', 'Where you’re signed in'),
      role: ta('settings.role.title', 'Your role'),
    };

    const links: (SettingsAreaLink & { group: GroupId })[] = MY_SETTINGS_PAGES.map((page) => ({
      id: page.id,
      href: page.href,
      label: personalLabels[page.id] ?? page.id,
      group: page.group,
    }));
    for (const tab of tabs ?? []) {
      if (tab.id in PLATFORM_SETTINGS_TABS) continue;
      links.push({ id: tab.id, href: tab.href, label: tab.label, group: GROUP_OF[tab.id] ?? 'advanced' });
    }
    for (const id of SETTINGS_AREA_NAV_IDS) {
      // Tag groups are on the Tags page now, so they get no link of their own.
      if (id === 'tag-groups') continue;
      const item = navItems.find((nav) => nav.id === id);
      if (item) links.push({ id, href: item.href, label: dataLabels[id] ?? item.label, group: 'data' });
    }
    if (canManageFields) {
      links.push({ id: 'fields', href: '/admin/settings/fields', label: t('settingsArea.fields', 'Fields'), group: 'data' });
    }
    if (canSeeDevices) {
      links.push({ id: 'devices', href: DEVICES_HREF, label: t('settingsArea.devices', 'Licensed devices'), group: 'security' });
    }
    if (canEditThemes) {
      links.push({ id: 'document-themes', href: DOCUMENT_THEMES_HREF, label: t('settingsArea.documentThemes', 'Document themes'), group: 'workspace' });
    }
    if (canManageStamps) {
      links.push({ id: 'document-stamps', href: DOCUMENT_STAMPS_HREF, label: t('settingsArea.documentStamps', 'Company stamps'), group: 'workspace' });
    }
    if (canManageBookings) {
      links.push({ id: 'bookings', href: BOOKINGS_HREF, label: t('settingsArea.bookings', 'Bookings'), group: 'data' });
    }
    if (!isSystemTenant) {
      for (const [navId, id] of Object.entries(WORKSPACE_PAGE_NAV_IDS)) {
        // The server's navigation decides who sees each, by its own permission.
        const item = navItems.find((nav) => nav.id === navId);
        if (item) links.push({ id, href: item.href, label: workspacePageLabels[id] ?? item.label, group: GROUP_OF[id] });
      }
    }
    // Within a group, the order GROUP_OF lists them in (yours in their own order).
    const rank = (id: string) => {
      const personal = MY_SETTINGS_PAGES.findIndex((page) => page.id === id);
      if (personal !== -1) return personal;
      const index = Object.keys(GROUP_OF).indexOf(id);
      return index === -1 ? Number.MAX_SAFE_INTEGER : index;
    };
    return GROUP_ORDER.map((group) => ({
      id: group,
      label: groupLabels[group],
      links: links.filter((link) => link.group === group).sort((a, b) => rank(a.id) - rank(b.id)),
    })).filter((group) => group.links.length > 0);
  }, [tabs, navItems, navLabels, canSeeDevices, canManageFields, canEditThemes, canManageStamps, canManageBookings, isSystemTenant, t, tc, ta, workspaceName]);

  return groups;
}

export function SettingsArea({ children }: { children: ReactNode }) {
  const t = useTranslation('admin');
  const tc = useTranslation('common');
  const { name: workspaceName } = useCurrentWorkspace();
  const pathname = usePathname() ?? '';
  const { logoSquareUrl } = useBranding();
  const groups = useSettingsGroups();

  const onMenu = pathname === SETTINGS_MENU_HREF;
  // Read when the menu opens; the page you leave it for is written back below.
  const lastOpened = useMemo(() => (onMenu ? readLastOpened() : null), [onMenu]);
  const [query, setQuery] = useState('');
  const lastRef = useRef<HTMLAnchorElement | null>(null);

  // The page you are on is the one marked next time you open the menu.
  const current = useMemo(() => {
    const all = groups.flatMap((group) =>
      group.links.map((link) => ({ ...link, groupLabel: group.label, groupId: group.id })),
    );
    // The longest matching address wins, so a record page maps to its list page.
    return all
      .filter((link) => isActive(pathname, link.href))
      .sort((a, b) => b.href.length - a.href.length)[0];
  }, [groups, pathname]);

  useEffect(() => {
    if (!onMenu && current) writeLastOpened(current.id);
  }, [onMenu, current]);

  useEffect(() => {
    if (!onMenu) return;
    // More's "Workspace settings" row lands on the workspace's part of the menu.
    if (window.location.hash === `#${WORKSPACE_SETTINGS_ANCHOR}`) {
      document.getElementById(WORKSPACE_SETTINGS_ANCHOR)?.scrollIntoView?.({ block: 'start' });
      return;
    }
    if (lastOpened) lastRef.current?.scrollIntoView?.({ block: 'center' });
  }, [onMenu, lastOpened, groups]);

  // The menu's search reads the settings index (WHIT-1084): names, what is on
  // each page and words in both languages, so "إشعارات" or "push" finds
  // Notifications on an English screen too.
  const index = useSettingsIndex(groups);
  const matched = query.trim() ? new Set(searchSettings(index, query, index.length).map((entry) => entry.pageId)) : null;
  const menuGroups = groups
    .map((group) => ({
      ...group,
      links: matched ? group.links.filter((link) => matched.has(link.id)) : group.links,
    }))
    .filter((group) => group.links.length > 0);

  // A page deeper than its list page goes back to that list; any other goes to the menu.
  const deeper = Boolean(current && pathname !== current.href && current.href !== '/admin/settings');
  const backHref = deeper && current ? current.href : SETTINGS_MENU_HREF;
  const backLabel = deeper && current ? current.label : t('settingsArea.title', 'Settings');
  const crumbs = current
    ? [t('settingsArea.title', 'Settings'), current.groupLabel, ...(deeper ? [current.label] : [])]
    : [];

  // Workspace settings start under their own heading, naming the workspace they change.
  const firstWorkspaceGroup = groups.find((group) => !PERSONAL_GROUPS.includes(group.id))?.id;
  const firstWorkspaceMenuGroup = menuGroups.find((group) => !PERSONAL_GROUPS.includes(group.id))?.id;
  const workspaceSectionLabel = workspaceName
    ? t('settingsArea.workspaceSection', 'Workspace settings · {workspace}').replace('{workspace}', workspaceName)
    : t('settingsArea.workspaceSectionNoName', 'Workspace settings');

  return (
    <div className="grid gap-4">
      {/* The cards' frame (TplSettings*): "Settings › Page" above both columns,
          then the sub-nav beside the page. Each page keeps its own title. */}
      {current && (
        <nav
          aria-label={t('settingsArea.breadcrumb', 'You are here')}
          data-testid="settings-breadcrumb"
          className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground max-sm:hidden"
        >
          <Link
            href={SETTINGS_HOME_HREF}
            className="rounded-sm outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring"
          >
            {t('settingsArea.title', 'Settings')}
          </Link>
          <Icon name="chevron-right" size={14} className="rtl:-scale-x-100" />
          <span aria-current="page" className="text-foreground">
            {current.label}
          </span>
        </nav>
      )}
      <div className="grid grid-cols-[minmax(0,1fr)] gap-6 md:grid-cols-[220px_minmax(0,1fr)] md:gap-8">
      {/* One shrinkable column on phones: without it the grid track sizes to the
          sideways-scrolling nav row's full width and the whole page scrolls sideways. */}
      {/* From 640px up: today's sub-nav. Under it the menu is its own page. */}
      <nav
        aria-label={t('settingsArea.navLabel', 'Settings')}
        data-testid="settings-area-nav"
        className="max-sm:hidden md:sticky md:top-4 md:self-start"
      >
        {/* Under 768px the groups sit in a row that scrolls sideways, so the page stays first. */}
        <div className="flex gap-4 overflow-x-auto pb-2 md:grid md:gap-3 md:overflow-visible md:pb-0">
          {groups.map((group) => (
            <div key={group.id} className="grid shrink-0 content-start gap-px">
              {group.id === firstWorkspaceGroup && (
                <p data-settings-section className="mt-2 border-t border-border px-2 pt-3 pb-1 text-sm font-semibold max-md:mt-0 max-md:border-t-0 max-md:pt-1">
                  {workspaceSectionLabel}
                </p>
              )}
              {/* The cards' group label: small, semibold, uppercase in Latin scripts. */}
              <p
                data-settings-group
                className="px-2.5 pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase first:pt-0"
              >
                {group.label}
              </p>
              {group.links.map((link) => {
                const active = isActive(pathname, link.href);
                return (
                  <Link
                    key={link.id}
                    href={link.href}
                    data-testid={`settings-area-link-${link.id}`}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'flex items-center rounded-control px-2.5 py-2 text-sm whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring max-md:min-h-11',
                      active
                        ? 'bg-primary-tint font-medium text-primary-tint-foreground'
                        : 'text-foreground hover:bg-muted',
                    )}
                  >
                    {link.label}
                  </Link>
                );
              })}
            </div>
          ))}
        </div>
      </nav>

      {/* Phone: the menu is its own page (PwSettings). */}
      {onMenu && (
        <div className="flex flex-col gap-5 sm:hidden" data-testid="settings-menu">
          <Link
            href="/more"
            className="-ms-1 flex min-h-11 w-fit items-center gap-1 text-base text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <Icon name="chevron-left" size={24} />
            {tc('phoneNav.more', 'More')}
          </Link>
          <h1 className="text-2xl font-semibold">{t('settingsArea.title', 'Settings')}</h1>
          {menuGroups.map((group) => (
            <section key={group.id} aria-label={group.label}>
              {group.id === firstWorkspaceMenuGroup && (
                <p
                  id={WORKSPACE_SETTINGS_ANCHOR}
                  data-settings-section
                  className="mb-3 scroll-mt-4 border-t border-border px-1 pt-4 text-base font-semibold"
                >
                  {workspaceSectionLabel}
                </p>
              )}
              <h2 data-settings-group className="mb-1.5 flex flex-wrap items-center gap-2 px-1 text-sm font-medium text-muted-foreground">
                {group.label}
                {group.id === 'mine' && <ScopeChip scope="account" />}
              </h2>
              <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                {group.links.map((link) => {
                  const last = link.id === lastOpened;
                  return (
                    <li key={link.id}>
                      <Link
                        href={link.href}
                        ref={last ? lastRef : undefined}
                        data-testid={`settings-menu-row-${link.id}`}
                        className={cn(
                          'flex min-h-12 items-center gap-3 px-4 py-2 text-base text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                          last && 'border-s-4 border-primary bg-primary-tint text-primary-tint-foreground',
                        )}
                      >
                        <Icon name={ROW_ICON[link.id] ?? 'settings'} size={20} className="text-muted-foreground" />
                        <span className="min-w-0 flex-1">
                          {link.label}
                          {last && (
                            <small className="block text-sm text-muted-foreground">
                              {t('settingsArea.lastOpened', 'Last opened')}
                            </small>
                          )}
                        </span>
                        <Icon name="chevron-right" size={18} className="text-muted-foreground" />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}
          <p className="px-1 text-sm text-muted-foreground">
            {t('settingsArea.roleNote', 'You see the settings your role can change. An owner can change the rest.')}
          </p>
          <div className="sticky bottom-0 -mx-4 border-t border-border bg-background px-4 py-3">
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              aria-label={t('settingsArea.search', 'Search settings')}
              placeholder={t('settingsArea.search', 'Search settings')}
              data-testid="settings-menu-search"
              className="h-11 w-full rounded-lg border border-input bg-muted px-3 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>
        </div>
      )}

      {/* Phone: a page shows a back control and where it sits, then its own title. */}
      {!onMenu && current && (
        <div className="-mb-3 sm:hidden" data-testid="settings-back">
          <Link
            href={backHref}
            className="-ms-1 flex min-h-11 w-fit items-center gap-1 text-base text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <Icon name="chevron-left" size={24} />
            {backLabel}
          </Link>
          <p className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground" data-testid="settings-crumb">
            {crumbs.map((crumb, index) => (
              <span key={index} className="flex items-center gap-1">
                {index > 0 && <Icon name="chevron-right" size={14} />}
                {crumb}
              </span>
            ))}
          </p>
        </div>
      )}
      <div className={cn('min-w-0', onMenu && 'max-sm:hidden')}>
        {/* A workspace page says whose it is: a change here reaches everyone in it (WHIT-1082). */}
        {current && !PERSONAL_GROUPS.includes(current.groupId) && current.href !== BOOKINGS_HREF && workspaceName && (
          <p
            data-testid="workspace-scope"
            className="mb-4 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
          >
            <TenantLogo name={workspaceName} src={logoSquareUrl} size={20} showName={false} />
            <span>
              {t('settingsArea.workspaceScope', 'Workspace settings for {workspace}. A change here applies to everyone in it.').replace(
                '{workspace}',
                workspaceName,
              )}
            </span>
          </p>
        )}
        {children}
      </div>
      </div>
    </div>
  );
}
