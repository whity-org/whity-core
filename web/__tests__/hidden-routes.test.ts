/**
 * #2386: which pages a hidden id takes away, through the route manifest.
 */
import { isPathHidden, parseHiddenIds, routeFor, NOTHING_HIDDEN } from '@/lib/routes/hidden';
import { buildCoreCommands, type CommandContext } from '@/lib/commands/core-commands';

const hide = (nav: string[], settings: string[] = []) => ({ nav, settings });

describe('isPathHidden', () => {
  it('hides nothing when nothing is hidden', () => {
    expect(isPathHidden('/calendar', NOTHING_HIDDEN)).toBe(false);
  });

  it('hides a menu item’s page and every page under it', () => {
    const hidden = hide(['calendar']);
    expect(isPathHidden('/calendar', hidden)).toBe(true);
    expect(isPathHidden('/calendar/rooms', hidden)).toBe(true);
    expect(isPathHidden('/calendar/services/12', hidden)).toBe(true);
    // A nearer menu item owns its own pages.
    expect(isPathHidden('/calendar/meetings', hidden)).toBe(false);
    expect(isPathHidden('/contacts', hidden)).toBe(false);
  });

  it('matches dynamic segments and ignores the query', () => {
    expect(isPathHidden('/contacts/42?tab=notes', hide(['contacts']))).toBe(true);
    expect(routeFor('/contacts/42')?.path).toBe('/contacts/:id');
  });

  it('never hides a public page that shares a prefix with a menu item', () => {
    expect(isPathHidden('/chat/example-bakery', hide(['chat']))).toBe(false);
    expect(isPathHidden('/chat', hide(['chat']))).toBe(true);
  });

  it('hides one Settings page by settings.<id>', () => {
    const hidden = hide([], ['sso']);
    expect(isPathHidden('/admin/settings/sso', hidden)).toBe(true);
    expect(isPathHidden('/admin/settings/branding', hidden)).toBe(false);
    expect(isPathHidden('/calendar/settings', hide([], ['bookings']))).toBe(true);
  });

  it('hides a plugin screen by plugin-<featureId>, and a core screen there by its own id', () => {
    expect(isPathHidden('/admin/x/reports', hide(['plugin-reports']))).toBe(true);
    expect(isPathHidden('/admin/x/reports/7', hide(['plugin-reports']))).toBe(true);
    expect(isPathHidden('/admin/x/other', hide(['plugin-reports']))).toBe(false);
    expect(isPathHidden('/admin/x/calendar-commission', hide(['calendar-commission']))).toBe(true);
    expect(isPathHidden('/admin/x/forms-builder/3', hide(['forms-builder']))).toBe(true);
  });

  it('leaves sign-in pages alone', () => {
    expect(isPathHidden('/login', hide(['dashboard', 'settings']))).toBe(false);
  });
});

describe('parseHiddenIds', () => {
  it('reads the API body and drops anything else', () => {
    expect(parseHiddenIds({ data: { nav: ['a', 3], settings: ['b'] } })).toEqual({ nav: ['a'], settings: ['b'] });
    expect(parseHiddenIds(null)).toEqual(NOTHING_HIDDEN);
    expect(parseHiddenIds({ data: 'x' })).toEqual(NOTHING_HIDDEN);
  });
});

describe('the palette’s New commands', () => {
  const t = (_key: string, fallback: string) => fallback;
  const ctx = (isHidden?: (href: string) => boolean): CommandContext => ({
    can: () => true,
    pages: [{ id: 'chat', href: '/chat', label: 'Chat' }],
    workspaces: [],
    activeWorkspaceId: 1,
    switchWorkspace: () => undefined,
    theme: 'light',
    setTheme: () => undefined,
    languages: [],
    language: 'en',
    setLanguage: () => undefined,
    go: () => undefined,
    newThing: () => undefined,
    setQuery: () => undefined,
    waiting: null,
    waitingLoaded: false,
    agents: null,
    connectAgentHref: null,
    isHidden,
  });
  const newOptions = (c: CommandContext) =>
    buildCoreCommands(t, c)
      .find((command) => command.name === 'new')
      ?.options('')
      .map((option) => option.id) ?? [];

  it('offers every kind when nothing is hidden', () => {
    expect(newOptions(ctx())).toEqual(expect.arrayContaining(['new-booking', 'new-task', 'new-form']));
  });

  it('drops the kinds whose page the operator hid', () => {
    const options = newOptions(ctx((href) => isPathHidden(href, hide(['calendar', 'forms-builder']))));
    expect(options).not.toContain('new-booking');
    expect(options).not.toContain('new-form');
    expect(options).toContain('new-task');
  });
});
