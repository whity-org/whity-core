/**
 * The commands ⌘K offers after a slash (WHIT-1069; owner-approved 14 Nov
 * 2026). Each command is listed only for people who can do what it does:
 * permission checks are fail-closed (`can` answers false until permissions
 * load) and the server checks again when the screen it opens saves anything.
 *
 * Nothing here creates, sends or decides. A command goes to a screen, opens
 * that screen's own New dialog already filled in, or changes the person's own
 * preference (theme, language, workspace).
 *
 * Every command and option answers to its English and Arabic names; matching
 * folds Arabic the way server search does ({@see fold}).
 */

import type { IconName } from '@whity/ui/icon';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import type { NewKind } from './new-intent';
import { fold, rank } from './parse';

/** The words of these commands live in the `common` catalogue. */
export function useCommandTranslation(): TranslateFn {
  const t = useTranslation('common');
  return t;
}

/** What `/approve` answers to; the palette loads the waiting list only once one is typed. */
export const APPROVE_NAMES: readonly string[] = ['approve', 'waiting', 'inbox', 'موافقة', 'اعتماد', 'بانتظاري', 'الوارد'];

/** One row under a command once its name is typed: a screen, a kind, a language. */
export interface CommandOption {
  id: string;
  label: string;
  hint?: string;
  icon?: IconName;
  run: () => void;
  /** Keep ⌘K open after running: the option only rewrote the query. */
  keepOpen?: boolean;
  /** The query Tab completes this option into. */
  complete?: string;
  /** Shown greyed: "not right now" (still loading, nothing waiting). */
  disabled?: boolean;
  /** An agent row: dashed, because nothing happens until a person approves. */
  agent?: boolean;
  /** Remembered as a recent place (a screen) rather than as a command run. */
  place?: { id: string; label: string; href: string };
}

/** One thing waiting for the person (`GET /api/me/waiting`), ready to open. */
export interface WaitingEntry {
  key: string;
  title: string;
  hint: string;
  /** Null when the person has nowhere to open it. */
  href: string | null;
  proposal: boolean;
}

/** An agent the person can write to in Chat. */
export interface ChatAgent {
  id: number;
  name: string;
  role?: string;
}

export interface PaletteCommand {
  /** What follows the slash, in English: `new`. */
  name: string;
  /** Every word it answers to, English and Arabic, `name` first. */
  names: readonly string[];
  /** One line on what it does. */
  description: string;
  icon: IconName;
  /** The rows for what was typed after the name. */
  options: (rest: string) => CommandOption[];
}

export interface CommandContext {
  /** Fail-closed permission check. */
  can: (permission: string) => boolean;
  /** The caller's own navigation, labelled in their language. */
  pages: readonly { id: string; href: string; label: string; icon?: IconName }[];
  workspaces: readonly { id: number; name: string }[];
  activeWorkspaceId: number | undefined;
  switchWorkspace: (id: number) => void;
  theme: 'light' | 'dark' | 'system';
  setTheme: (theme: 'light' | 'dark' | 'system') => void;
  languages: readonly { code: string; name: string }[];
  language: string;
  setLanguage: (code: string) => void;
  go: (href: string) => void;
  newThing: (kind: NewKind, text: string, href: string, target?: number) => void;
  /** Rewrites the query, for options that move on to the next step. */
  setQuery: (query: string) => void;
  /** What is waiting for the person; null while it loads or if it failed. */
  waiting: readonly WaitingEntry[] | null;
  /** True once the waiting list has answered (even with nothing). */
  waitingLoaded: boolean;
  /** Agents the person can write to in Chat; null while they load. */
  agents: readonly ChatAgent[] | null;
  /** Where to connect an agent, for someone who may; null otherwise. */
  connectAgentHref: string | null;
  /** The person may create another workspace (WHIT-1169); absent means no. */
  canCreateWorkspace?: boolean;
  /** Whether the operator hid the page at this path (#2386); absent means none are. */
  isHidden?: (href: string) => boolean;
}

interface Kind {
  kind: NewKind;
  names: readonly string[];
  label: string;
  icon: IconName;
  href: string;
  allowed: boolean;
}

function words(rest: string): { first: string; after: string; moved: boolean } {
  const space = rest.search(/\s/);
  if (space === -1) return { first: rest, after: '', moved: false };
  return { first: rest.slice(0, space), after: rest.slice(space + 1).trim(), moved: true };
}

function kinds(t: TranslateFn, ctx: CommandContext): Kind[] {
  const { can } = ctx;
  const all: Kind[] = [
    { kind: 'document', names: ['document', 'doc', 'مستند', 'وثيقة'], label: t('palette.new.document', 'New document'), icon: 'document', href: '/admin/document-library', allowed: can('documents:issue') },
    { kind: 'form', names: ['form', 'نموذج', 'استمارة'], label: t('palette.new.form', 'New form'), icon: 'form', href: '/admin/x/forms-builder', allowed: can('forms:manage') },
    { kind: 'booking', names: ['booking', 'appointment', 'حجز', 'موعد'], label: t('palette.new.booking', 'New booking'), icon: 'appointment', href: '/calendar', allowed: can('calendar:write') },
    { kind: 'task', names: ['task', 'todo', 'مهمة'], label: t('palette.new.task', 'New task'), icon: 'list-todo', href: '/tasks', allowed: can('tasks:write') },
    // Chat has no permission of its own: whoever has Chat in their navigation can start one.
    { kind: 'message', names: ['message', 'chat', 'رسالة', 'محادثة'], label: t('palette.new.message', 'New chat'), icon: 'comment', href: '/chat', allowed: ctx.pages.some((p) => p.href === '/chat') },
    { kind: 'person', names: ['person', 'people', 'user', 'شخص', 'مستخدم'], label: t('palette.new.person', 'Invite someone'), icon: 'user', href: '/admin/users', allowed: can('users:write') },
  ];
  // #2386: a page the operator hid takes its "New …" with it.
  return all.filter((k) => k.allowed && !(ctx.isHidden?.(k.href) ?? false));
}

export function buildCoreCommands(t: TranslateFn, ctx: CommandContext): PaletteCommand[] {
  const commands: PaletteCommand[] = [];
  const now = t('palette.option.current', 'Now');

  const available = kinds(t, ctx);
  // "Create workspace" (WHIT-1169): about the person, not this workspace, so it
  // isn't a NewKind; it opens the create page.
  const workspaceNames = ['workspace', 'company', 'مساحة', 'شركة'];
  const newWorkspace = (): CommandOption => ({
    id: 'new-workspace',
    label: t('palette.new.workspace', 'Create a workspace'),
    hint: t('palette.new.workspaceHint', 'You’ll own it'),
    icon: 'tenant',
    complete: '/new workspace',
    run: () => ctx.go('/workspaces/new'),
  });
  if (available.length > 0 || ctx.canCreateWorkspace === true) {
    commands.push({
      name: 'new',
      names: ['new', 'create', 'add', 'جديد', 'إنشاء', 'أضف'],
      description: t('palette.command.new', 'Start a document, form, booking, task or chat'),
      icon: 'plus',
      options: (rest) => {
        const { first, after, moved } = words(rest);
        const exact = moved ? available.filter((k) => k.names.some((n) => fold(n) === fold(first))) : [];
        if (ctx.canCreateWorkspace === true && moved && workspaceNames.some((n) => fold(n) === fold(first))) {
          return [newWorkspace()];
        }
        const shown = exact.length > 0 ? exact : rank(first, available, (k) => k.names);
        const text = exact.length > 0 ? after : '';
        const workspaceRow =
          ctx.canCreateWorkspace === true && exact.length === 0 && (first === '' || rank(first, [workspaceNames], (n) => n).length > 0)
            ? [newWorkspace()]
            : [];
        return [...shown.map((k) => ({
          id: `new-${k.kind}`,
          label: text !== '' ? `${k.label}: ${text}` : k.label,
          icon: k.icon,
          complete: `/new ${k.names[0]} `,
          run: () => ctx.newThing(k.kind, text, k.href),
        })), ...workspaceRow];
      },
    });
  }

  commands.push({
    name: 'go',
    names: ['go', 'open', 'page', 'اذهب', 'انتقل', 'افتح'],
    description: t('palette.command.go', 'Go to a screen'),
    icon: 'arrow-right',
    options: (rest) =>
      rank(rest.trim(), ctx.pages, (p) => [p.label, p.id]).map((p) => ({
        id: `go-${p.id}`,
        label: p.label,
        icon: p.icon,
        place: { id: `page-${p.id}`, label: p.label, href: p.href },
        run: () => ctx.go(p.href),
      })),
  });

  if (ctx.can('users:write')) {
    commands.push({
      name: 'invite',
      names: ['invite', 'دعوة', 'ادع'],
      description: t('palette.command.invite', 'Invite someone to this workspace'),
      icon: 'mail',
      options: (rest) => {
        const emails = rest.trim();
        return [
          {
            id: 'invite',
            label: emails !== '' ? t('palette.invite.to', 'Invite {emails}', { emails }) : t('palette.new.person', 'Invite someone'),
            hint: t('palette.invite.hint', 'Opens the invite form; nothing is sent yet'),
            icon: 'mail',
            run: () => ctx.newThing('person', emails, '/admin/users'),
          },
        ];
      },
    });
  }

  const others = ctx.workspaces.filter((w) => w.id !== ctx.activeWorkspaceId);
  if (others.length > 0 || ctx.canCreateWorkspace === true) {
    commands.push({
      name: 'switch',
      names: ['switch', 'workspace', 'تبديل', 'بدل', 'مساحة'],
      description: t('palette.command.switch', 'Switch to another workspace'),
      icon: 'tenant',
      options: (rest) => [
        ...rank(rest.trim(), others, (w) => [w.name]).map((w): CommandOption => ({
          id: `switch-${w.id}`,
          label: w.name,
          icon: 'tenant',
          run: () => ctx.switchWorkspace(w.id),
        })),
        // Last, as at the foot of the workspace switcher (TenantSwitcher card).
        ...(ctx.canCreateWorkspace === true && rest.trim() === '' ? [newWorkspace()] : []),
      ],
    });
  }

  const themes = [
    { value: 'light' as const, names: ['light', 'فاتح'], label: t('palette.theme.light', 'Light'), icon: 'sun' as const },
    { value: 'dark' as const, names: ['dark', 'داكن', 'غامق'], label: t('palette.theme.dark', 'Dark'), icon: 'moon' as const },
    { value: 'system' as const, names: ['system', 'auto', 'النظام', 'تلقائي'], label: t('palette.theme.system', 'Same as this device'), icon: 'desktop' as const },
  ];
  commands.push({
    name: 'theme',
    names: ['theme', 'dark', 'light', 'mode', 'المظهر', 'الوضع'],
    description: t('palette.command.theme', 'Light, dark or the same as this device'),
    icon: 'moon',
    options: (rest) =>
      rank(rest.trim(), themes, (o) => o.names).map((o) => ({
        id: `theme-${o.value}`,
        label: o.label,
        hint: o.value === ctx.theme ? now : undefined,
        icon: o.icon,
        run: () => ctx.setTheme(o.value),
      })),
  });

  if (ctx.languages.length > 1) {
    commands.push({
      name: 'lang',
      names: ['lang', 'language', 'اللغة', 'لغة'],
      description: t('palette.command.lang', 'Change the language'),
      icon: 'language',
      options: (rest) =>
        rank(rest.trim(), ctx.languages, (l) => [l.name, l.code, ...(l.code === 'ar' ? ['arabic', 'عربي', 'العربية'] : []), ...(l.code === 'en' ? ['english', 'إنجليزي', 'الإنجليزية'] : [])]).map((l) => ({
          id: `lang-${l.code}`,
          label: l.name,
          hint: l.code === ctx.language ? now : undefined,
          icon: 'language',
          run: () => ctx.setLanguage(l.code),
        })),
    });
  }

  commands.push({
    name: 'approve',
    names: APPROVE_NAMES,
    description: t('palette.command.approve', 'What is waiting for you; opens it, never decides'),
    icon: 'approval',
    options: (rest) => {
      if (ctx.waiting === null) {
        return [
          {
            id: 'approve-state',
            label: ctx.waitingLoaded
              ? t('palette.approve.failed', 'What is waiting could not be loaded.')
              : t('palette.approve.loading', 'Loading what is waiting for you…'),
            disabled: true,
            run: () => undefined,
          },
        ];
      }
      if (ctx.waiting.length === 0) {
        return [{ id: 'approve-none', label: t('palette.approve.none', 'Nothing is waiting for you'), icon: 'check', disabled: true, run: () => undefined }];
      }
      return rank(rest.trim(), ctx.waiting, (w) => [w.title, w.hint]).map((w) => ({
        id: `approve-${w.key}`,
        label: w.title,
        hint: w.hint,
        icon: w.proposal ? 'robot' : 'inbox',
        agent: w.proposal,
        disabled: w.href === null,
        run: () => {
          if (w.href !== null) ctx.go(w.href);
        },
      }));
    },
  });

  if (ctx.pages.some((p) => p.href === '/chat') || ctx.connectAgentHref !== null) {
    commands.push({
      name: 'ask',
      names: ['ask', 'agent', 'اسأل', 'مساعد', 'وكيل'],
      description: t('palette.command.ask', 'Ask an agent; it proposes, you approve'),
      icon: 'robot',
      options: (rest) => {
        const words = rest.trim();
        const agents = ctx.agents;
        if (agents === null) {
          return [{ id: 'ask-loading', label: t('palette.ask.loading', 'Loading your agents…'), disabled: true, agent: true, run: () => undefined }];
        }
        if (agents.length === 0) {
          const connect = ctx.connectAgentHref;
          return [
            {
              id: 'ask-connect',
              label: t('palette.agent.connect', 'Connect an agent'),
              hint: t('palette.agent.hint', 'It proposes; you approve'),
              icon: 'robot',
              agent: true,
              disabled: connect === null,
              run: () => {
                if (connect !== null) ctx.go(connect);
              },
            },
          ];
        }
        // Every agent is offered the words; the arrows pick which one.
        return agents.map((a) => ({
          id: `ask-${a.id}`,
          label: words !== '' ? t('palette.ask.with', 'Ask {name}: {words}', { name: a.name, words }) : t('palette.ask.agent', 'Ask {name}', { name: a.name }),
          hint: [a.role, t('palette.ask.hint', 'Opens your chat with it; nothing is sent yet')].filter(Boolean).join(' · '),
          icon: 'robot',
          agent: true,
          run: () => ctx.newThing('ask', words, '/chat', a.id),
        }));
      },
    });
  }

  commands.push({
    name: 'help',
    names: ['help', 'commands', 'مساعدة', 'الأوامر'],
    description: t('palette.command.help', 'Every command, and the keys to use them'),
    icon: 'help',
    options: (rest) =>
      rank(rest.trim(), commands.filter((c) => c.name !== 'help'), (c) => c.names).map((c) => ({
        id: `help-${c.name}`,
        label: `/${c.name}`,
        hint: c.description,
        icon: c.icon,
        keepOpen: true,
        complete: `/${c.name} `,
        run: () => ctx.setQuery(`/${c.name} `),
      })),
  });

  return commands;
}
