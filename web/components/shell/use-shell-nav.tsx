'use client';

/**
 * The app's navigation as data, shared by the sidebar (desktop, drawer and
 * rail) and the phone's tab bar and More page (`PwNav`). Built once from
 * `GET /api/v1/navigation`, in the same groups and order everywhere, so the
 * phone's More lists exactly what the sidebar holds (PW.2).
 */

import { usePathname } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { ICONS } from '@whity/ui/icon-map';
import type { AppSidebarNavGroup } from '@whity/ui/app-sidebar';
import { navGroupsFromServerItems } from '@whity/features/nav';
import { useTranslation } from '@whity/features/i18n';
import * as TablerIcons from '@tabler/icons-react';
import { IconDashboard, IconSettings } from '@tabler/icons-react';
import type { Icon } from '@tabler/icons-react';
import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { useNavItemLabels } from '@/lib/nav-item-labels';
import { WAITING_CHANGED_EVENT } from '@/lib/waiting-changed';
import {
  PLATFORM_SETTINGS_TABS,
  SETTINGS_AREA_NAV_IDS,
  SETTINGS_AREA_PREFIXES,
} from '@/components/settings/settings-area';
import { SETTINGS_HOME_HREF } from '@/lib/settings/my-settings';

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

export function resolveIcon(name: string | undefined): Icon {
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
export const ACCOUNT_SETTINGS_ITEM_ID = 'settings';


/** The system tenant: the only one that runs the platform itself. */
export const SYSTEM_TENANT_ID = 0;

/** Fallback heading for a group the shell has no translated label for. */
function prettifyGroupId(groupId: string): string {
  return groupId
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}


/**
 * How many things wait for the person, by nav item id: the Inbox's open items,
 * summed over its sources (`GET /api/me/inbox/sources` carries each source's
 * open count for exactly this badge), the agent proposals waiting for
 * their approval (`GET /api/me/agent-proposals?status=waiting`), and unread
 * chat messages (`GET /api/v1/chat/chats`, the phone tab bar's Chat count). Re-read on every page change, so acting
 * on an item and moving on updates the count. A failed read shows no count
 * rather than a wrong one.
 */
export function useWaitingCounts(pathname: string | null): Record<string, number> {
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
      // Three reads at once; a failed one shows no count rather than a wrong one.
      const [sources, proposals, chats] = await Promise.all([
        api.GET('/api/v1/me/inbox/sources').catch(() => null),
        api.GET('/api/v1/me/agent-proposals', { params: { query: { status: 'waiting' } } }).catch(() => null),
        api.GET('/api/v1/chat/chats').catch(() => null),
      ]);
      const list = (result: { data?: unknown } | null): Record<string, unknown>[] => {
        const body = result?.data as { data?: unknown } | undefined;
        return Array.isArray(body?.data) ? (body.data as Record<string, unknown>[]) : [];
      };
      const sum = (rows: Record<string, unknown>[], key: string) =>
        rows.reduce((total, row) => total + (typeof row[key] === 'number' ? (row[key] as number) : 0), 0);
      const inbox = sum(list(sources), 'open_count');
      const approvals = list(proposals).length;
      // Chat (CH.3): unread messages over every chat the person is in.
      const chat = sum(list(chats), 'unread');
      if (!cancelled) setCounts((prev) => ({ ...prev, inbox, approvals, chat }));
    })();
    return () => {
      cancelled = true;
    };
  }, [pathname, nudge]);

  return counts;
}


export interface ShellNav {
  groups: AppSidebarNavGroup[];
  /** Waiting counts by nav item id (`inbox`, `approvals`, `chat`). */
  counts: Record<string, number>;
  /** Server nav items by id, for the tab bar's fixed places. */
  hrefOf: (id: string) => string | undefined;
}

/** The nav groups and counts the shell renders, from the server's items. */
export function useShellNav(): ShellNav {
  const pathname = usePathname();
  const { user } = useAuth();
  const { items: navItems } = useNavigation();
  const t = useTranslation('common');

  // Literal `t()` calls, not a computed key — `i18n:extract` rebuilds the
  // catalogue by scanning the source for them, so a dynamic
  // `t('sidebar.group.' + id)` would never make it into the translations
  // table and every heading would render as a raw key.
  const counts = useWaitingCounts(pathname);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

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
    // entry at the foot, opening the area's own sub-nav. Since WHIT-1081 your
    // own settings are in the same area, so the entry is there for everyone,
    // members who can change nothing of the workspace's included.
    const inSettingsArea = (item: { id: string; group?: string }) =>
      item.group === 'settings' || (SETTINGS_AREA_NAV_IDS as readonly string[]).includes(item.id);
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
    return [
      ...withCounts,
      {
        id: 'settings',
        items: [
          {
            id: 'settings-area',
            label: t('sidebar.item.settings', 'Settings'),
            href: SETTINGS_HOME_HREF,
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

  const hrefOf = useMemo(() => {
    const byId = new Map(navItems.map((item) => [item.id, item.href]));
    return (id: string) => byId.get(id);
  }, [navItems]);

  return { groups, counts, hrefOf };
}
