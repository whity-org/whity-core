/**
 * WHIT-419 — ⌘K across the app (register 2.1c; owner decision D2).
 *
 *  - Ctrl K (or ⌘K) opens it, and the top bar's field does too.
 *  - Pages are the caller's own navigation, so nothing they can't open shows.
 *  - Records come from GET /api/search and are not filtered out again here.
 *  - The agent row reads "Connect an agent", and only for people who may open
 *    AI principals.
 */

import React from 'react';
import { act, render, screen, fireEvent, waitFor, within } from '@testing-library/react';

const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

let mockNav: { id: string; href: string; label: string; icon?: string; group?: string }[] = [];
jest.mock('@/lib/navigation-context', () => ({ useNavigation: () => ({ items: mockNav, refresh: jest.fn(async () => undefined) }) }));
const setPreference = jest.fn();
jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light', preference: 'light', setPreference, toggle: jest.fn() }),
}));
const setLanguage = jest.fn();
jest.mock('@whity/features/i18n', () => ({
  useLanguageCode: () => 'en',
  useTranslation: () => (_key: string, fallback: string, vars?: Record<string, string | number>) =>
    vars ? fallback.replace(/\{(\w+)\}/g, (_m, k: string) => String(vars[k])) : fallback,
  useCurrentLanguage: () => ({
    currentLanguage: 'en',
    availableLanguages: [
      { code: 'en', name: 'English', direction: 'ltr' },
      { code: 'ar', name: 'العربية', direction: 'rtl' },
    ],
    setLanguage,
  }),
}));
const switchTenant = jest.fn<Promise<void>, [number]>(async () => undefined);
/** The signed-in client: `/api/me/*` and Chat's directory, by path. */
let apiRoutes: Record<string, unknown> = {};
/** The typed client's GET, answered from `apiRoutes` by path and query. */
const apiMock = jest.fn(async (path: string, init?: { params?: { query?: Record<string, string | number> } }) => {
  const query = init?.params?.query;
  const url = query ? `${path}?${new URLSearchParams(Object.entries(query).map(([k, v]) => [k, String(v)]))}` : path;
  const body = apiRoutes[url];
  return body === undefined ? { data: undefined, error: {}, response: { status: 404 } } : { data: body, response: { status: 200 } };
});
// Search goes over the wire (asserted on `fetch` below); the palette's own data
// hook answers from `apiRoutes`.
jest.mock('@/lib/api/client', () => {
  const wire = jest.requireActual('./helpers/fetch-like-api').fetchLikeClient();
  return {
    api: {
      GET: (...args: Parameters<typeof apiMock>) =>
        args[0] === '/api/v1/search' ? wire.api.GET(...args) : apiMock(...args),
    },
  };
});
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, tenant_id: 1 },
    memberships: [
      { tenant_id: 1, tenant_name: 'Example Bakery', role: 'owner' },
      { tenant_id: 2, tenant_name: 'Example Clinic', role: 'member' },
    ],
    switchTenant,
  }),
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
let mockPermissions: string[] = [];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (p: string) => mockPermissions.includes(p) }),
}));

import { AppCommandPalette, OPEN_COMMAND_PALETTE_EVENT } from '@/components/shell/app-command-palette';
import { TopBar } from '@/components/shell/top-bar';
import { clearNewIntent, useNewIntent, type NewIntent } from '@/lib/commands/new-intent';

const fetchMock = jest.fn();

beforeEach(() => {
  push.mockReset();
  setPreference.mockReset();
  setLanguage.mockReset();
  switchTenant.mockClear();
  addToast.mockClear();
  clearNewIntent();
  apiMock.mockClear();
  apiRoutes = {};
  window.localStorage.clear();
  mockPermissions = ['tasks:write', 'users:write'];
  fetchMock.mockReset();
  global.fetch = fetchMock as unknown as typeof fetch;
  mockNav = [
    { id: 'users', href: '/admin/users', label: 'Users', icon: 'users' },
    { id: 'ai-principals', href: '/admin/ai-principals', label: 'AI Principals', icon: 'robot' },
  ];
});

describe('AppCommandPalette', () => {
  it('opens on Ctrl K with pages, actions and the Connect an agent row', () => {
    render(<AppCommandPalette />);
    expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument();
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    expect(screen.getByTestId('command-palette')).toBeInTheDocument();
    expect(screen.getByText('Users')).toBeInTheDocument();
    expect(screen.getByText('Switch to dark mode')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Connect an agent'));
    expect(push).toHaveBeenCalledWith('/admin/ai-principals');
  });

  it('has no agent row for someone who may not open AI principals', () => {
    mockNav = [{ id: 'users', href: '/admin/users', label: 'Users' }];
    render(<AppCommandPalette />);
    act(() => {
      window.dispatchEvent(new Event(OPEN_COMMAND_PALETTE_EVENT));
    });
    expect(screen.queryByText('Connect an agent')).not.toBeInTheDocument();
  });

  it('shows records from search, labelled by type', async () => {
    jest.useFakeTimers();
    fetchMock.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: [
          {
            type: 'document',
            label: { en: 'Documents', ar: 'المستندات' },
            results: [{ type: 'document', id: '42', title: 'INV-1042 Example Clinic', subtitle: 'overdue', url: '/admin/document-library/42' }],
          },
        ],
      }),
    });
    render(<AppCommandPalette />);
    fireEvent.keyDown(window, { key: 'k', metaKey: true });
    fireEvent.change(screen.getByTestId('command-palette-input'), { target: { value: 'inv' } });
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    jest.useRealTimers();
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/search?q=inv&limit=5', expect.anything());
    const record = await screen.findByText('INV-1042 Example Clinic');
    expect(screen.getByText('Documents · overdue')).toBeInTheDocument();
    fireEvent.click(record);
    await waitFor(() => expect(push).toHaveBeenCalledWith('/admin/document-library/42'));
  });
});

/** WHIT-1069: typing / switches to commands. */
describe('AppCommandPalette commands', () => {
  function openWith(text: string) {
    render(<AppCommandPalette />);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const input = screen.getByTestId('command-palette-input');
    fireEvent.change(input, { target: { value: text } });
    return input as HTMLInputElement;
  }

  it('lists the commands this person can run after a slash, without searching', () => {
    openWith('/');
    expect(screen.getByTestId('command-palette-mode')).toHaveTextContent('Commands');
    for (const name of ['/new', '/go', '/invite', '/switch', '/theme', '/lang', '/help']) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('hides /invite from people who may not invite', () => {
    mockPermissions = ['tasks:write'];
    openWith('/');
    expect(screen.queryByText('/invite')).not.toBeInTheDocument();
  });

  it('offers only the kinds this person may create', () => {
    openWith('/new ');
    expect(screen.getByText('New task')).toBeInTheDocument();
    expect(screen.getByText('Invite someone')).toBeInTheDocument();
    expect(screen.queryByText('New document')).not.toBeInTheDocument();
    expect(screen.queryByText('New form')).not.toBeInTheDocument();
  });

  it('completes a command on Tab and keeps the palette open', () => {
    const input = openWith('/ne');
    fireEvent.keyDown(input, { key: 'Tab' });
    expect(input.value).toBe('/new ');
    expect(screen.getByTestId('command-palette')).toBeInTheDocument();
  });

  it('hands /new task with its title to the tasks screen', () => {
    const seen: NewIntent[] = [];
    function TasksScreenStub() {
      useNewIntent('task', (intent) => seen.push(intent));
      return null;
    }
    const input = openWith('/new task Order flour');
    expect(screen.getByText('New task: Order flour')).toBeInTheDocument();
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(push).toHaveBeenCalledWith('/tasks');
    expect(screen.queryByTestId('command-palette')).not.toBeInTheDocument();
    render(<TasksScreenStub />);
    expect(seen).toEqual([{ kind: 'task', text: 'Order flour' }]);
  });

  it('opens the invite form filled in, never sending', () => {
    const input = openWith('/invite amro@example.com');
    expect(screen.getByText('Invite amro@example.com')).toBeInTheDocument();
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(push).toHaveBeenCalledWith('/admin/users');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('answers to Arabic names', () => {
    openWith('/جديد مهمه');
    expect(screen.getByText('New task')).toBeInTheDocument();
  });

  it('enters command mode from the slash key on the Arabic layout', () => {
    render(<AppCommandPalette />);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const input = screen.getByTestId('command-palette-input') as HTMLInputElement;
    fireEvent.keyDown(input, { key: 'ظ', code: 'Slash' });
    expect(input.value).toBe('/');
    expect(screen.getByTestId('command-palette-mode')).toBeInTheDocument();
  });

  it('sets the theme and the language', () => {
    let input = openWith('/theme dark');
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(setPreference).toHaveBeenCalledWith('dark');

    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    input = screen.getByTestId('command-palette-input') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '/lang arabic' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(setLanguage).toHaveBeenCalledWith('ar');
  });

  it('switches to another workspace, not the current one', async () => {
    openWith('/switch ');
    expect(screen.queryByText('Example Bakery')).not.toBeInTheDocument();
    fireEvent.click(screen.getByText('Example Clinic'));
    await waitFor(() => expect(switchTenant).toHaveBeenCalledWith(2));
  });

  it('a refused switch says why in plain words, never silently (2026-11-22)', async () => {
    const { WorkspaceSwitchRefused } = jest.requireActual('@/lib/workspace-switch');
    switchTenant.mockRejectedValueOnce(new WorkspaceSwitchRefused(403, 'Access to the requested tenant is forbidden'));
    openWith('/switch ');
    fireEvent.click(screen.getByText('Example Clinic'));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(
        'You’re no longer a member of that workspace. Ask one of its owners to add you again.',
        'error',
      ),
    );
  });

  it('goes to a screen from the caller’s own navigation', () => {
    const input = openWith('/go use');
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(push).toHaveBeenCalledWith('/admin/users');
  });

  it('goes to the notifications settings by name (WHIT-1078, now through the settings index)', () => {
    const input = openWith('/go notif');
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(push).toHaveBeenCalledWith('/settings/notifications');
  });

  it('says so when no command matches', () => {
    openWith('/nothing ');
    expect(screen.getByTestId('command-palette-empty')).toHaveTextContent('No command matches');
  });

  it('finds a command by its word without the slash', () => {
    openWith('invite');
    fireEvent.click(screen.getByText('/invite'));
    expect((screen.getByTestId('command-palette-input') as HTMLInputElement).value).toBe('/invite ');
  });

  it('reads out how many rows there are', () => {
    openWith('/');
    expect(screen.getByTestId('command-palette-count')).toHaveTextContent('9 commands');
  });
});

/** WHIT-1070: recent items, /approve (opens, never decides) and /ask. */
describe('AppCommandPalette recent, approve and ask', () => {
  const press = (key: string) => fireEvent.keyDown(screen.getByTestId('command-palette-input'), { key });
  const type = (value: string) => fireEvent.change(screen.getByTestId('command-palette-input'), { target: { value } });
  const openPalette = () => fireEvent.keyDown(window, { key: 'k', ctrlKey: true });

  it('leads the empty box with what was opened and run lately, without the words typed', () => {
    render(<AppCommandPalette />);
    openPalette();
    expect(screen.queryByText('Recent')).not.toBeInTheDocument();

    type('/go users');
    press('Enter');
    openPalette();
    type('/new task Order flour for Example Bakery');
    press('Enter');

    openPalette();
    expect(screen.getByText('Recent')).toBeInTheDocument();
    expect(screen.getByTestId('command-item-recent-page-users')).toHaveTextContent('Users');
    expect(screen.getByTestId('command-item-recent-command-/new task')).toBeInTheDocument();
    expect(window.localStorage.getItem('whity:palette-recent:1:1')).not.toContain('Order flour');

    fireEvent.click(screen.getByTestId('command-item-recent-command-/new task'));
    expect((screen.getByTestId('command-palette-input') as HTMLInputElement).value).toBe('/new task ');
  });

  it('adds the server’s recent documents for someone who may read them', async () => {
    mockPermissions = ['documents:read'];
    apiRoutes['/api/v1/me/recent-documents'] = { data: [{ kind: 'document', id: 42, title: 'INV-1042 Example Clinic' }] };
    render(<AppCommandPalette />);
    openPalette();
    fireEvent.click(await screen.findByText('INV-1042 Example Clinic'));
    expect(push).toHaveBeenCalledWith('/admin/document-library/42');
  });

  it('lists what is waiting under /approve and only opens it', async () => {
    mockNav = [...mockNav, { id: 'approvals', href: '/admin/approvals', label: 'Approvals' }];
    apiRoutes['/api/v1/me/waiting?limit=20'] = {
      data: [
        {
          key: 'proposal:7',
          kind: 'proposal',
          at: '2026-11-14 09:00:00',
          proposal: { id: 7, summary: 'Send INV-1042 to Example Clinic', agent: { name: 'Finance clerk', nameAr: 'كاتب المالية' } },
        },
      ],
      counts: { inbox: 0, proposals: 1, total: 1 },
      unavailable: [],
    };
    render(<AppCommandPalette />);
    openPalette();
    type('/approve ');
    const row = await screen.findByText('Send INV-1042 to Example Clinic');
    expect(screen.getByText('Finance clerk')).toBeInTheDocument();
    fireEvent.click(row);
    expect(push).toHaveBeenCalledWith('/admin/approvals');
    expect(apiMock.mock.calls.map(([url]) => url)).not.toContainEqual(expect.stringContaining('/approve'));
  });

  it('says so when nothing is waiting', async () => {
    apiRoutes['/api/v1/me/waiting?limit=20'] = { data: [], counts: { inbox: 0, proposals: 0, total: 0 }, unavailable: [] };
    render(<AppCommandPalette />);
    openPalette();
    type('/approve');
    type('/approve ');
    expect(await screen.findByText('Nothing is waiting for you')).toBeInTheDocument();
  });

  it('hands /ask to the chat with the chosen agent, unsent', async () => {
    mockNav = [...mockNav, { id: 'chat', href: '/chat', label: 'Chat' }];
    apiRoutes['/api/v1/chat/directory?q='] = {
      data: { people: [], agents: [{ type: 'agent', id: 3, name: 'Finance clerk', name_ar: 'كاتب المالية', role: 'Invoices', role_ar: 'الفواتير' }] },
    };
    const seen: NewIntent[] = [];
    function ChatScreenStub() {
      useNewIntent('ask', (intent) => seen.push(intent));
      return null;
    }
    render(<AppCommandPalette />);
    openPalette();
    type('/ask draft the reminder for INV-1042');
    fireEvent.click(await screen.findByText('Ask Finance clerk: draft the reminder for INV-1042'));
    expect(push).toHaveBeenCalledWith('/chat');
    render(<ChatScreenStub />);
    expect(seen).toEqual([{ kind: 'ask', text: 'draft the reminder for INV-1042', target: 3 }]);
  });

  it('turns the last row into Ask an agent once there is an agent to ask', async () => {
    mockNav = [...mockNav, { id: 'chat', href: '/chat', label: 'Chat' }];
    apiRoutes['/api/v1/chat/directory?q='] = {
      data: { people: [], agents: [{ type: 'agent', id: 3, name: 'Finance clerk', name_ar: '', role: '', role_ar: '' }] },
    };
    render(<AppCommandPalette />);
    openPalette();
    type('overdue invoices');
    fireEvent.click(await screen.findByText('Ask an agent: overdue invoices'));
    expect((screen.getByTestId('command-palette-input') as HTMLInputElement).value).toBe('/ask overdue invoices');
    expect(screen.queryByText('Connect an agent')).not.toBeInTheDocument();
  });
});

describe('TopBar', () => {
  it('opens the palette from its search field', () => {
    render(
      <>
        <TopBar />
        <AppCommandPalette />
      </>,
    );
    fireEvent.click(screen.getByTestId('top-bar-search'));
    expect(screen.getByTestId('command-palette')).toBeInTheDocument();
  });
});

describe('AppCommandPalette: every setting by name (WHIT-1084)', () => {
  function type(text: string) {
    render(<AppCommandPalette />);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    fireEvent.change(screen.getByTestId('command-palette-input'), { target: { value: text } });
  }

  it('finds Notifications from an Arabic word on an English screen, with where it applies', () => {
    type('إشعارات');
    const row = screen.getByText('Notifications', { selector: '[data-testid="command-palette"] *' });
    expect(row.closest('[role="option"]')?.textContent).toContain('Example Bakery');
    fireEvent.click(row);
    expect(push).toHaveBeenCalledWith('/settings/notifications');
  });

  it('finds a thing on a page, not only the page: text size opens Language and display', () => {
    type('text size');
    fireEvent.click(screen.getByText('Text size'));
    expect(push).toHaveBeenCalledWith('/settings/display');
  });

  it('folds Arabic letter forms, so a hamza typed differently still matches', () => {
    type('اشعارات');
    expect(screen.getByText('Notifications', { selector: '[data-testid="command-palette"] *' })).toBeInTheDocument();
  });

  it('shows the Settings area as one place in Go to, not its pages one by one', () => {
    mockNav = [
      { id: 'users', href: '/admin/users', label: 'Users' },
      { id: 'website-settings', href: '/admin/settings', label: 'Workspace settings', group: 'settings' },
      { id: 'tags', href: '/admin/tags', label: 'Tags' },
    ];
    render(<AppCommandPalette />);
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
    const palette = screen.getByTestId('command-palette');
    expect(within(palette).getByText('Settings')).toBeInTheDocument();
    expect(within(palette).queryByText('Workspace settings')).toBeNull();
    expect(within(palette).queryByText('Tags')).toBeNull();
  });
});
