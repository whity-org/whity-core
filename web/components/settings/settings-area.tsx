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
import { useMemo, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useNavigation } from '@/lib/navigation-context';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@/lib/api/schema';

type SettingsTab = components['schemas']['SettingsTab'];

type GroupId = 'workspace' | 'security' | 'email' | 'data' | 'advanced';

/** D1's groups, in order. A page the server adds later lands in Advanced. */
const GROUP_OF: Readonly<Record<string, GroupId>> = {
  general: 'workspace',
  branding: 'workspace',
  security: 'security',
  sso: 'security',
  signup: 'security',
  email: 'email',
  'email-domains': 'email',
  storage: 'data',
  tags: 'data',
  'tag-groups': 'data',
  languages: 'data',
  translations: 'data',
  'feature-flags': 'advanced',
  'error-tracking': 'advanced',
};

const GROUP_ORDER: GroupId[] = ['workspace', 'security', 'email', 'data', 'advanced'];

/**
 * The data pages that keep their own routes but belong to this area, by the
 * id their navigation item registers under. Exported so the sidebar can fold
 * them into its single Settings entry.
 */
export const SETTINGS_AREA_NAV_IDS = ['tags', 'tag-groups', 'languages', 'translations'] as const;

/** Every route prefix that opens inside the Settings area. */
export const SETTINGS_AREA_PREFIXES = [
  '/admin/settings',
  '/admin/tags',
  '/admin/tag-groups',
  '/admin/languages',
  '/admin/translations',
] as const;

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

  const { data: tabs } = useFetch<SettingsTab[]>(async () => {
    const { data: body } = await api.GET('/api/v1/settings/tabs');
    return body?.data ?? [];
  }, []);

  const groups = useMemo(() => {
    const groupLabels: Record<GroupId, string> = {
      workspace: t('settingsArea.group.workspace', 'Workspace'),
      security: t('settingsArea.group.security', 'Sign-in and security'),
      email: t('settingsArea.group.email', 'Email'),
      data: t('settingsArea.group.data', 'Data'),
      advanced: t('settingsArea.group.advanced', 'Advanced'),
    };

    const dataLabels: Record<string, string> = {
      tags: tc('sidebar.item.tags', 'Tags'),
      'tag-groups': tc('sidebar.item.tagGroups', 'Tag Groups'),
      languages: tc('sidebar.item.languages', 'Languages'),
      translations: tc('sidebar.item.translations', 'Translations'),
    };

    const links: (SettingsAreaLink & { group: GroupId })[] = [];
    for (const tab of tabs ?? []) {
      links.push({ id: tab.id, href: tab.href, label: tab.label, group: GROUP_OF[tab.id] ?? 'advanced' });
    }
    for (const id of SETTINGS_AREA_NAV_IDS) {
      const item = navItems.find((nav) => nav.id === id);
      if (item) links.push({ id, href: item.href, label: dataLabels[id] ?? item.label, group: 'data' });
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
  }, [tabs, navItems, t, tc]);

  return (
    <div className="grid gap-6 md:grid-cols-[200px_minmax(0,1fr)] md:gap-8">
      <nav
        aria-label={t('settingsArea.navLabel', 'Settings')}
        data-testid="settings-area-nav"
        className="md:sticky md:top-0 md:self-start"
      >
        <p className="mb-2 px-2 text-lg font-semibold">{t('settingsArea.title', 'Settings')}</p>
        {/* Under 768px the groups sit in a row that scrolls sideways, so the page stays first. */}
        <div className="flex gap-4 overflow-x-auto pb-2 md:grid md:gap-3 md:overflow-visible md:pb-0">
          {groups.map((group) => (
            <div key={group.id} className="grid shrink-0 content-start gap-px">
              <p className="px-2 py-1 text-[0.6875rem] font-semibold tracking-wider text-muted-foreground uppercase">
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
                      'rounded-md px-2 py-[7px] text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring',
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
      <div className="min-w-0">{children}</div>
    </div>
  );
}
