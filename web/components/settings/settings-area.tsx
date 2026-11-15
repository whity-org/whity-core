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
import { LICENSING_VIEW } from '@/lib/capabilities';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

type SettingsTab = components['schemas']['SettingsTab'];

type GroupId = 'workspace' | 'security' | 'email' | 'data' | 'advanced' | 'devices';

/** D1's groups, in order. A page the server adds later lands in Advanced. */
const GROUP_OF: Readonly<Record<string, GroupId>> = {
  general: 'workspace',
  branding: 'workspace',
  security: 'security',
  sso: 'security',
  signup: 'security',
  'customer-signup': 'security',
  'public-forms': 'security',
  messages: 'email',
  email: 'email',
  'email-domains': 'email',
  storage: 'data',
  fields: 'data',
  tags: 'data',
  'tag-groups': 'data',
  languages: 'data',
  translations: 'data',
  'feature-flags': 'advanced',
  'error-tracking': 'advanced',
};

const GROUP_ORDER: GroupId[] = ['workspace', 'security', 'email', 'data', 'advanced', 'devices'];

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
  '/admin/settings',
  '/admin/tags',
  '/admin/tag-groups',
  '/admin/translations',
  DEVICES_HREF,
] as const;

/** On a phone the menu is its own page (PwSettings, PW.3); More opens it. */
export const SETTINGS_MENU_HREF = '/admin/settings/menu';

/** "Last opened" is remembered per browser. */
const LAST_OPENED_KEY = 'whity.settings.lastOpened';

const ROW_ICON: Readonly<Record<string, IconName>> = {
  general: 'settings',
  branding: 'branding',
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

export function SettingsArea({ children }: { children: ReactNode }) {
  const t = useTranslation('admin');
  const tc = useTranslation('common');
  const pathname = usePathname() ?? '';
  const { items: navItems } = useNavigation();
  const { has } = useCapabilities();
  const canSeeDevices = has(LICENSING_VIEW);
  // WHIT-955: the workspace's own fields on its records (A9).
  const canManageFields = has('fields:manage');
  const language = useLanguageCode();

  // The server words the tabs in the caller's language, so a switch needs them again.
  const { data: tabs } = useFetch<SettingsTab[]>(async () => {
    const { data: body } = await api.GET('/api/v1/settings/tabs');
    return body?.data ?? [];
  }, [language]);

  const groups = useMemo(() => {
    const groupLabels: Record<GroupId, string> = {
      workspace: t('settingsArea.group.workspace', 'Workspace'),
      security: t('settingsArea.group.security', 'Sign-in and security'),
      email: t('settingsArea.group.email', 'Email'),
      data: t('settingsArea.group.data', 'Data'),
      advanced: t('settingsArea.group.advanced', 'Advanced'),
      devices: t('settingsArea.group.devices', 'Devices'),
    };

    const dataLabels: Record<string, string> = {
      tags: tc('sidebar.item.tags', 'Tags'),
      'tag-groups': tc('sidebar.item.tagGroups', 'Tag Groups'),
      languages: tc('sidebar.item.languages', 'Languages'),
      translations: tc('sidebar.item.translations', 'Translations'),
    };

    const links: (SettingsAreaLink & { group: GroupId })[] = [];
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
      links.push({ id: 'devices', href: DEVICES_HREF, label: t('settingsArea.devices', 'Licensed devices'), group: 'devices' });
    }
    // Within a group, the order GROUP_OF lists them in.
    const rank = (id: string) => {
      const index = Object.keys(GROUP_OF).indexOf(id);
      return index === -1 ? Number.MAX_SAFE_INTEGER : index;
    };
    return GROUP_ORDER.map((group) => ({
      id: group,
      label: groupLabels[group],
      links: links.filter((link) => link.group === group).sort((a, b) => rank(a.id) - rank(b.id)),
    })).filter((group) => group.links.length > 0);
  }, [tabs, navItems, canSeeDevices, canManageFields, t, tc]);

  const onMenu = pathname === SETTINGS_MENU_HREF;
  // Read when the menu opens; the page you leave it for is written back below.
  const lastOpened = useMemo(() => (onMenu ? readLastOpened() : null), [onMenu]);
  const [query, setQuery] = useState('');
  const lastRef = useRef<HTMLAnchorElement | null>(null);

  // The page you are on is the one marked next time you open the menu.
  const current = useMemo(() => {
    const all = groups.flatMap((group) => group.links.map((link) => ({ ...link, groupLabel: group.label })));
    // The longest matching address wins, so a record page maps to its list page.
    return all
      .filter((link) => isActive(pathname, link.href))
      .sort((a, b) => b.href.length - a.href.length)[0];
  }, [groups, pathname]);

  useEffect(() => {
    if (!onMenu && current) writeLastOpened(current.id);
  }, [onMenu, current]);

  useEffect(() => {
    if (onMenu && lastOpened) lastRef.current?.scrollIntoView?.({ block: 'center' });
  }, [onMenu, lastOpened, groups]);

  const needle = query.trim().toLowerCase();
  const menuGroups = groups
    .map((group) => ({
      ...group,
      links: needle ? group.links.filter((link) => link.label.toLowerCase().includes(needle)) : group.links,
    }))
    .filter((group) => group.links.length > 0);

  // A page deeper than its list page goes back to that list; any other goes to the menu.
  const deeper = Boolean(current && pathname !== current.href && current.href !== '/admin/settings');
  const backHref = deeper && current ? current.href : SETTINGS_MENU_HREF;
  const backLabel = deeper && current ? current.label : t('settingsArea.title', 'Settings');
  const crumbs = current
    ? [t('settingsArea.title', 'Settings'), current.groupLabel, ...(deeper ? [current.label] : [])]
    : [];

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 md:grid-cols-[200px_minmax(0,1fr)] md:gap-8">
      {/* One shrinkable column on phones: without it the grid track sizes to the
          sideways-scrolling nav row's full width and the whole page scrolls sideways. */}
      {/* From 640px up: today's sub-nav. Under it the menu is its own page. */}
      <nav
        aria-label={t('settingsArea.navLabel', 'Settings')}
        data-testid="settings-area-nav"
        className="max-sm:hidden md:sticky md:top-0 md:self-start"
      >
        <p className="mb-2 px-2 text-lg font-semibold">{t('settingsArea.title', 'Settings')}</p>
        {/* Under 768px the groups sit in a row that scrolls sideways, so the page stays first. */}
        <div className="flex gap-4 overflow-x-auto pb-2 md:grid md:gap-3 md:overflow-visible md:pb-0">
          {groups.map((group) => (
            <div key={group.id} className="grid shrink-0 content-start gap-px">
              <p data-settings-group className="px-2 py-1 text-xs font-medium text-muted-foreground">
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
                      'flex items-center rounded-md px-2 py-[7px] text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring max-md:min-h-11',
                      active
                        ? 'bg-primary-tint text-primary-tint-foreground'
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
              <h2 data-settings-group className="mb-1.5 px-1 text-sm font-medium text-muted-foreground">
                {group.label}
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
      <div className={cn('min-w-0', onMenu && 'max-sm:hidden')}>{children}</div>
    </div>
  );
}
