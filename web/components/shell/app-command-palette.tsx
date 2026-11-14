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
 * - Typing `/` switches to commands (WHIT-1069): `/new`, `/go`, `/invite`,
 *   `/switch`, `/theme`, `/lang`, `/help` ({@see buildCoreCommands}). Tab
 *   takes the highlighted command and moves on to its details; Enter runs.
 *   The slash KEY works on any layout, since the Arabic layout types "ظ"
 *   there. Words without the slash still find a command ("invite").
 *
 * The editor keeps its own palette (decision E.36); this one is mounted by the
 * standard shell only.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import * as TablerIcons from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { CommandPalette, type CommandPaletteItem } from '@whity/ui/command-palette';
import { Icon } from '@whity/ui/icon';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useNavigation } from '@/lib/navigation-context';
import { useNavItemLabels } from '@/lib/nav-item-labels';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { localizedName } from '@/lib/localized-name';
import { useCapabilities } from '@/hooks/useCapabilities';
import { buildCoreCommands, useCommandTranslation, type CommandContext, type PaletteCommand } from '@/lib/commands/core-commands';
import { requestNew } from '@/lib/commands/new-intent';
import { fold, parseCommand, rank } from '@/lib/commands/parse';
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
  const commandT = useCommandTranslation();
  const router = useRouter();
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage();
  const { items: navItems, refresh: refreshNav } = useNavigation();
  const navLabels = useNavItemLabels();
  const {
    resolved: theme,
    preference: themePreference,
    setPreference: setThemePreference,
    toggle: toggleTheme,
  } = useThemeMode();
  const { user, memberships, switchTenant } = useAuth();
  const { has } = useCapabilities();
  const { addToast } = useToast();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [groups, setGroups] = useState<SearchGroup[]>([]);
  const request = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ⌘K / Ctrl K toggles; the top bar's field opens it by event.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setOpen((was) => !was);
        setQuery('');
      }
    };
    const onOpen = () => {
      setOpen(true);
      setQuery('');
    };
    window.addEventListener('keydown', onKey);
    window.addEventListener(OPEN_COMMAND_PALETTE_EVENT, onOpen);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener(OPEN_COMMAND_PALETTE_EVENT, onOpen);
    };
  }, []);

  const onOpenChange = useCallback((next: boolean) => {
    setOpen(next);
    setQuery('');
    if (!next) setGroups([]);
  }, []);

  const search = useCallback((text: string) => {
    if (timer.current) clearTimeout(timer.current);
    const q = text.trim();
    const id = ++request.current;
    // A command is not a record: `/new` asks nothing of the server.
    if (q.length < MIN_QUERY || q.startsWith('/')) {
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

  const onQueryChange = useCallback(
    (text: string) => {
      setQuery(text);
      search(text);
    },
    [search],
  );

  const commands = useMemo<PaletteCommand[]>(() => {
    const ctx: CommandContext = {
      can: has,
      pages: navItems
        .filter((item) => item.href)
        .map((item) => ({ id: item.id, href: item.href, label: navLabels[item.id] ?? item.label })),
      workspaces: memberships.map((m) => ({
        id: m.tenant_id,
        name: localizedName(m.tenant_names, currentLanguage, m.tenant_name_language, m.tenant_name),
      })),
      activeWorkspaceId: user?.tenant_id,
      switchWorkspace: (id) => {
        void (async () => {
          try {
            await switchTenant(id);
            await refreshNav();
          } catch (err) {
            addToast(
              err instanceof Error ? err.message : t('sidebar.tenantSwitcher.error', 'Couldn’t switch tenant'),
              'error',
            );
          }
        })();
      },
      theme: themePreference,
      setTheme: setThemePreference,
      languages: availableLanguages.map((l) => ({ code: l.code, name: l.name })),
      language: currentLanguage ?? '',
      setLanguage: (code) => void setLanguage(code),
      go: (href) => router.push(href),
      newThing: (kind, text, href) => {
        requestNew({ kind, text });
        router.push(href);
      },
      // A command only rewrites the query into another command, which never
      // searches, so the plain setter is enough.
      setQuery,
    };
    return buildCoreCommands(commandT, ctx);
  }, [
    t,
    commandT,
    has,
    navItems,
    navLabels,
    memberships,
    currentLanguage,
    user?.tenant_id,
    switchTenant,
    refreshNav,
    addToast,
    themePreference,
    setThemePreference,
    availableLanguages,
    setLanguage,
    router,
  ]);

  /** A command as a row: picking it (or Tab) moves on to its details. */
  const commandRow = useCallback(
    (c: PaletteCommand, withKeywords: boolean): CommandPaletteItem => ({
      id: `command-${c.name}`,
      group: t('palette.group.commands', 'Commands'),
      label: `/${c.name}`,
      hint: c.description,
      keywords: withKeywords ? c.names.join(' ') : undefined,
      icon: <Icon name={c.icon} size={16} />,
      keepOpen: true,
      onComplete: () => setQuery(`/${c.name} `),
      onSelect: () => setQuery(`/${c.name} `),
    }),
    [t],
  );

  /** Command mode: the commands, or once one is named, its options. Null outside it. */
  const commandItems = useMemo<CommandPaletteItem[] | null>(() => {
    const parsed = parseCommand(query);
    if (parsed === null) return null;
    if (!parsed.named) return rank(parsed.name, commands, (c) => c.names).map((c) => commandRow(c, false));
    const typed = fold(parsed.name);
    const command = commands.find((c) => c.names.some((n) => fold(n) === typed));
    if (command === undefined) return [];
    return command.options(parsed.rest).map((o) => ({
      id: `option-${o.id}`,
      group: `/${command.name}`,
      label: o.label,
      hint: o.hint,
      icon: o.icon !== undefined ? <Icon name={o.icon} size={16} /> : undefined,
      keepOpen: o.keepOpen,
      onComplete: o.complete !== undefined ? () => setQuery(o.complete as string) : undefined,
      onSelect: o.run,
    }));
  }, [query, commands, commandRow]);

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

    // Typing "invite" without the slash still finds the command; its names
    // in both languages are what it matches on. Hidden on an empty box, which
    // would otherwise list every command twice over.
    const commandRows: CommandPaletteItem[] =
      query.trim() === '' ? [] : commands.filter((c) => c.name !== 'help').map((c) => commandRow(c, true));

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

    return [...records, ...pages, ...actions, ...commandRows, ...agent];
  }, [groups, navItems, navLabels, currentLanguage, theme, toggleTheme, router, t, query, commands, commandRow]);

  const commandMode = commandItems !== null;

  return (
    <CommandPalette
      open={open}
      onOpenChange={onOpenChange}
      items={commandItems ?? items}
      query={query}
      onQueryChange={onQueryChange}
      filter={!commandMode}
      mode={commandMode ? t('palette.mode.commands', 'Commands') : undefined}
      slashKey
      countLabel={(count) =>
        commandMode
          ? t('palette.count.commands', '{count} commands', { count })
          : t('palette.count.results', '{count} results', { count })
      }
      label={t('palette.label', 'Search and commands')}
      placeholder={t('palette.placeholder', 'Search, or type / for commands')}
      emptyLabel={
        commandMode
          ? t('palette.empty.command', 'No command matches. Type /help to see them all.')
          : t('palette.empty', 'Nothing matches')
      }
      footer={{
        move: t('palette.footer.move', 'to move'),
        open: t('palette.footer.open', 'to open'),
        complete: commandMode ? t('palette.footer.complete', 'to pick') : undefined,
        close: t('palette.footer.close', 'to close'),
      }}
    />
  );
}
