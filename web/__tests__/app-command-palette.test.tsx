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
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';

const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

let mockNav: { id: string; href: string; label: string; icon?: string }[] = [];
jest.mock('@/lib/navigation-context', () => ({ useNavigation: () => ({ items: mockNav, refresh: jest.fn(async () => undefined) }) }));
const setPreference = jest.fn();
jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light', preference: 'light', setPreference, toggle: jest.fn() }),
}));
const setLanguage = jest.fn();
jest.mock('@whity/features/i18n', () => ({
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
const switchTenant = jest.fn(async () => undefined);
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
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
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
  clearNewIntent();
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

  it('goes to a screen from the caller’s own navigation', () => {
    const input = openWith('/go use');
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(push).toHaveBeenCalledWith('/admin/users');
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
    expect(screen.getByTestId('command-palette-count')).toHaveTextContent('7 commands');
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
