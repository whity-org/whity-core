/**
 * Ctrl+K offers "Create a workspace" (WHIT-1169) only to someone who may
 * create one, under `/new` and at the end of `/switch`.
 */
import { buildCoreCommands, type CommandContext } from '@/lib/commands/core-commands';

const t = ((_key: string, fallback: string, vars?: Record<string, unknown>) =>
  vars ? fallback.replace(/\{(\w+)\}/g, (_m, k: string) => String(vars[k] ?? '')) : fallback) as never;

function ctx(overrides: Partial<CommandContext> = {}): CommandContext {
  return {
    can: () => false,
    pages: [],
    workspaces: [{ id: 1, name: 'Example Bakery' }],
    activeWorkspaceId: 1,
    switchWorkspace: jest.fn(),
    theme: 'light',
    setTheme: jest.fn(),
    languages: [],
    language: 'en',
    setLanguage: jest.fn(),
    go: jest.fn(),
    newThing: jest.fn(),
    setQuery: jest.fn(),
    waiting: [],
    waitingLoaded: true,
    agents: [],
    connectAgentHref: null,
    ...overrides,
  };
}

function command(c: CommandContext, name: string) {
  return buildCoreCommands(t, c).find((cmd) => cmd.name === name);
}

describe('Create a workspace in Ctrl+K', () => {
  it('is not offered to someone who may not create one', () => {
    const c = ctx();
    expect(command(c, 'new')).toBeUndefined();
    expect(command(c, 'switch')).toBeUndefined();
  });

  it('is under /new and opens the create page', () => {
    const c = ctx({ canCreateWorkspace: true });
    const rows = command(c, 'new')!.options('workspace ');
    expect(rows.map((r) => r.id)).toEqual(['new-workspace']);
    rows[0].run();
    expect(c.go).toHaveBeenCalledWith('/workspaces/new');
  });

  it('answers to its Arabic name', () => {
    const c = ctx({ canCreateWorkspace: true });
    expect(command(c, 'new')!.options('مساحة ').map((r) => r.id)).toEqual(['new-workspace']);
  });

  it('comes last under /switch, after the other workspaces', () => {
    const c = ctx({
      canCreateWorkspace: true,
      workspaces: [
        { id: 1, name: 'Example Bakery' },
        { id: 2, name: 'Example Cafe' },
      ],
    });
    expect(command(c, 'switch')!.options('').map((r) => r.id)).toEqual(['switch-2', 'new-workspace']);
  });
});

describe('Create a workspace in Ctrl+K, typed without a trailing space', () => {
  it('matches /new workspace', () => {
    const c = ctx({ canCreateWorkspace: true });
    expect(command(c, 'new')!.options('workspace').map((r) => r.id)).toContain('new-workspace');
  });
});
