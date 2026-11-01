'use client';

/**
 * ⌘K (Ctrl K) everywhere in the app (WHIT-419; register 2.1c; the
 * CommandPalette card). One box finds a record, a page or an action, and its
 * last row is about agents.
 *
 * - Records come from `GET /api/search` (WHIT-783), which only returns what
 *   the caller may see, grouped by type with labels in both languages.
 * - Pages are the caller's own navigation, so a page they can't open is never
 *   offered.
 * - The agent row (owner decision D2, 1 Nov 2026): with no agent connected it
 *   reads "Connect an agent" and opens AI principals. Handing the words to a
 *   connected agent, whose answer arrives as a proposal in Approvals, waits
 *   for the whity-agent request channel (WHIT-814).
 *
 * The editor keeps its own palette (decision E.36); this one is mounted by the
 * standard shell only.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import * as TablerIcons from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { CommandPalette, type CommandPaletteItem } from '@whity-org/ui/command-palette';
import { Icon } from '@whity-org/ui/icon';
import { useCurrentLanguage, useTranslation } from '@whity-org/features/i18n';
import { useNavigation } from '@/lib/navigation-context';
import { useNavItemLabels } from '@/lib/nav-item-labels';
import { useThemeMode } from '@/lib/theme-mode-context';
import type { components } from '@/lib/api/schema';

type SearchGroup = components['schemas']['SearchGroup'];

/** Fired on `window` to open the palette, e.g. by the top bar's search field. */
export const OPEN_COMMAND_PALETTE_EVENT = 'whity:open-command-palette';

const tablerIcons = TablerIcons as unknown as Record<string, TablerIcon | undefined>;

function navIcon(name: string | undefined): TablerIcon {
  if (!name) return TablerIcons.IconFile;
  const pascal = name
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
  return tablerIcons[pascal.startsWith('Icon') ? pascal : `Icon${pascal}`] ?? TablerIcons.IconFile;
}

/** Search starts at two characters, and waits for a pause in typing. */
const MIN_QUERY = 2;
const DEBOUNCE_MS = 200;

export function AppCommandPalette() {
  const t = useTranslation('common');
  const router = useRouter();
  const { currentLanguage } = useCurrentLanguage();
  const { items: navItems } = useNavigation();
  const navLabels = useNavItemLabels();
  const { resolved: theme, toggle: toggleTheme } = useThemeMode();
  const [open, setOpen] = useState(false);
  const [groups, setGroups] = useState<SearchGroup[]>([]);
  const request = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ⌘K / Ctrl K toggles; the top bar's field opens it by event.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setOpen((was) => !was);
      }
    };
    const onOpen = () => setOpen(true);
    window.addEventListener('keydown', onKey);
    window.addEventListener(OPEN_COMMAND_PALETTE_EVENT, onOpen);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener(OPEN_COMMAND_PALETTE_EVENT, onOpen);
    };
  }, []);

  const onOpenChange = useCallback((next: boolean) => {
    setOpen(next);
    if (!next) setGroups([]);
  }, []);

  const search = useCallback((query: string) => {
    if (timer.current) clearTimeout(timer.current);
    const q = query.trim();
    const id = ++request.current;
    if (q.length < MIN_QUERY) {
      setGroups([]);
      return;
    }
    timer.current = setTimeout(() => {
      void (async () => {
        try {
          const response = await fetch(`/api/v1/search?q=${encodeURIComponent(q)}&limit=5`, {
            credentials: 'include',
          });
          if (!response.ok) return;
          const body = (await response.json()) as { data?: SearchGroup[] };
          // Only the latest query's answer lands; a slow earlier one is dropped.
          if (id === request.current) setGroups(body.data ?? []);
        } catch {
          if (id === request.current) setGroups([]);
        }
      })();
    }, DEBOUNCE_MS);
  }, []);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const items = useMemo<CommandPaletteItem[]>(() => {
    const ar = currentLanguage === 'ar';
    const records: CommandPaletteItem[] = [];
    for (const group of groups) {
      for (const hit of group.results) {
        records.push({
          id: `record-${hit.type}-${hit.id}`,
          group: t('palette.group.records', 'Records'),
          label: hit.title,
          hint: [ar ? group.label.ar : group.label.en, hit.subtitle].filter(Boolean).join(' · '),
          // A record type with no page is shown, greyed, rather than hidden.
          disabled: hit.url === null,
          // Search matched these; the palette must not filter them out again.
          always: true,
          icon: <Icon name="document" size={16} />,
          onSelect: () => {
            if (hit.url) router.push(hit.url);
          },
        });
      }
    }

    const pages: CommandPaletteItem[] = navItems
      .filter((item) => item.href)
      .map((item) => {
        const NavIcon = navIcon(item.icon);
        return {
          id: `page-${item.id}`,
          group: t('palette.group.pages', 'Go to'),
          label: navLabels[item.id] ?? item.label,
          keywords: item.label,
          icon: <NavIcon size={16} />,
          onSelect: () => router.push(item.href),
        };
      });

    const actions: CommandPaletteItem[] = [
      {
        id: 'action-theme',
        group: t('palette.group.actions', 'Actions'),
        label:
          theme === 'dark'
            ? t('palette.action.lightMode', 'Switch to light mode')
            : t('palette.action.darkMode', 'Switch to dark mode'),
        icon: <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={16} />,
        onSelect: toggleTheme,
      },
      {
        id: 'action-account',
        group: t('palette.group.actions', 'Actions'),
        label: t('palette.action.account', 'Open your account settings'),
        icon: <Icon name="user" size={16} />,
        onSelect: () => router.push('/settings'),
      },
    ];

    // D2: only for people who may open AI principals, where an agent is connected.
    const aiPage = navItems.find((item) => item.id === 'ai-principals');
    const agent: CommandPaletteItem[] = aiPage
      ? [
          {
            id: 'agent-connect',
            group: t('palette.group.agent', 'Ask an agent'),
            label: t('palette.agent.connect', 'Connect an agent'),
            hint: t('palette.agent.hint', 'It proposes; you approve'),
            agent: true,
            always: true,
            icon: <Icon name="robot" size={16} />,
            onSelect: () => router.push(aiPage.href),
          },
        ]
      : [];

    return [...records, ...pages, ...actions, ...agent];
  }, [groups, navItems, navLabels, currentLanguage, theme, toggleTheme, router, t]);

  return (
    <CommandPalette
      open={open}
      onOpenChange={onOpenChange}
      items={items}
      onQueryChange={search}
      label={t('palette.label', 'Search and commands')}
      placeholder={t('palette.placeholder', 'Search records, pages and actions')}
      emptyLabel={t('palette.empty', 'Nothing matches')}
      footer={{
        move: t('palette.footer.move', 'to move'),
        open: t('palette.footer.open', 'to open'),
        close: t('palette.footer.close', 'to close'),
      }}
    />
  );
}
