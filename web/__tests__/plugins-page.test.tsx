/**
 * WC-221: plugins console — per-action RBAC gating, the Upload dialog, version
 * badges, and the optimistic sidenav update.
 *
 * The page is gated at the page level on `plugins:read` (Access Denied without
 * it). Each ACTION is then gated through <PermissionButton>:
 *   - Reload          plugins:reload   (non-destructive: disabled + tooltip)
 *   - Enable/Disable  plugins:enable / plugins:disable, chosen by status
 *   - Re-enable       plugins:enable   (non-destructive)
 *   - Uninstall       plugins:uninstall (DESTRUCTIVE: hidden when unpermitted)
 *   - Upload          plugins:upload   (non-destructive)
 *
 * The Upload dialog POSTs multipart to /api/v1/plugins/upload and refetches the
 * list on success; disabling a plugin optimistically removes its sidebar links
 * and refreshes the nav.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PluginsPage from '@/app/(protected)/admin/plugins/page';
import { useCapabilities } from '@/hooks/useCapabilities';
import { api } from '@/lib/api/client';
import { uploadPluginPackage } from '@/lib/api/plugin-upload';
import { useNavigation } from '@/lib/navigation-context';
import { usePluginFeatures } from '@/lib/plugin-features-context';
import { useToast } from '@/lib/toast-context';

jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: jest.fn(),
}));
jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn(), POST: jest.fn() },
}));
jest.mock('@/lib/api/plugin-upload', () => ({
  uploadPluginPackage: jest.fn(),
}));
jest.mock('@/lib/navigation-context', () => ({
  useNavigation: jest.fn(),
}));
jest.mock('@/lib/plugin-features-context', () => ({
  usePluginFeatures: jest.fn(),
}));
jest.mock('@/lib/toast-context', () => ({
  useToast: jest.fn(),
}));
jest.mock('next/navigation', () => ({
  usePathname: () => '/admin/plugins',
}));

const mockUseCapabilities = useCapabilities as jest.MockedFunction<
  typeof useCapabilities
>;
const mockUseNavigation = useNavigation as jest.MockedFunction<
  typeof useNavigation
>;
const mockUsePluginFeatures = usePluginFeatures as jest.MockedFunction<
  typeof usePluginFeatures
>;
const mockUseToast = useToast as jest.MockedFunction<typeof useToast>;
const mockUpload = uploadPluginPackage as jest.MockedFunction<
  typeof uploadPluginPackage
>;
const mockApiGet = api.GET as unknown as jest.Mock;
const mockApiPost = api.POST as unknown as jest.Mock;

const addToast = jest.fn();
const refresh = jest.fn(() => Promise.resolve());
const removeItemsByHref = jest.fn();

interface PluginRow {
  id: string;
  name: string;
  enabled: boolean;
  file: string | null;
  status?: string;
  version?: string;
  routes_count?: number;
  permissions_count?: number;
  install?: {
    update_waiting: { version: string; new_permissions: string[]; new_domains: string[] } | null;
  };
}

const ACTIVE_PLUGIN: PluginRow = {
  id: 'hello-world',
  name: 'HelloWorld',
  enabled: true,
  file: 'HelloWorld/Plugin.php',
  status: 'active',
  version: '1.2.0',
  routes_count: 2,
  permissions_count: 1,
};

const DISABLED_PLUGIN: PluginRow = {
  id: 'beta-thing',
  name: 'BetaThing',
  enabled: false,
  file: 'BetaThing.php',
  status: 'disabled',
  version: '0.3.0-beta',
};

/** A jest mock that resolves the typed-client `{ data }` plugin-list shape. */
function mockPluginList(plugins: PluginRow[], canOperate = true, removed: unknown[] = []): void {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/plugins') {
      return Promise.resolve({ data: { data: plugins, meta: { can_operate: canOperate } } });
    }
    if (path === '/api/v1/plugins/removed') {
      return Promise.resolve({ data: { data: removed } });
    }
    return Promise.resolve({ data: { data: [] } });
  });
}

function setCapabilities(perms: string[]): void {
  const has = (slug: string) => perms.includes(slug);
  mockUseCapabilities.mockReturnValue({
    permissions: perms,
    loading: false,
    has,
    hasAny: (slugs: readonly string[]) => slugs.some(has),
    hasAll: (slugs: readonly string[]) => slugs.every(has),
    hasPermission: has,
  });
}

/** Opens a row's menu and returns the menu element. */
async function openRowMenu(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole('button', { name: `More actions for ${name}` }));
  return screen.findByRole('menu');
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseToast.mockReturnValue({
    toasts: [],
    addToast,
    removeToast: jest.fn(),
  });
  mockUseNavigation.mockReturnValue({
    items: [],
    isLoading: false,
    refresh,
    removeItemsByHref,
  });
  mockUsePluginFeatures.mockReturnValue({ features: [], dropped: [], isLoading: false });
  mockApiPost.mockResolvedValue({ data: {}, error: undefined });
});

describe('PluginsPage RBAC page gate', () => {
  it('shows Access Denied when the caller lacks plugins:read', () => {
    setCapabilities([]);
    mockPluginList([]);

    render(<PluginsPage />);

    expect(screen.getByText('You don’t have access to plugins')).toBeInTheDocument();
  });
});

describe('PluginsPage per-action gating (plugins:read only)', () => {
  beforeEach(() => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN]);
  });

  it('renders the list once read access resolves', async () => {
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
  });

  it('shows no operator menu to someone without plugins:upload or plugins:reload', async () => {
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(screen.queryByRole('button', { name: 'Operator tools' })).not.toBeInTheDocument();
  });

  it('HIDES Turn off when the caller lacks plugins:disable', async () => {
    const user = userEvent.setup();
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const menu = await openRowMenu(user, 'HelloWorld');
    expect(within(menu).getByRole('menuitem', { name: 'Details' })).toBeInTheDocument();
    expect(within(menu).queryByRole('menuitem', { name: 'Turn off' })).not.toBeInTheDocument();
  });

  it('HIDES the destructive Uninstall item for a read-only caller', async () => {
    const user = userEvent.setup();
    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const menu = await openRowMenu(user, 'HelloWorld');
    expect(within(menu).queryByRole('menuitem', { name: 'Uninstall' })).not.toBeInTheDocument();
  });
});

describe('PluginsPage per-action gating (matching perms)', () => {
  it('shows Upload plugin and Reload plugins in the operator menu', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:reload', 'plugins:upload', 'plugins:disable']);
    mockPluginList([ACTIVE_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    await user.click(screen.getByRole('button', { name: 'Operator tools' }));
    const menu = await screen.findByRole('menu');
    expect(within(menu).getByRole('menuitem', { name: /Upload plugin/i })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: /Reload plugins/i })).toBeInTheDocument();
  });

  it('offers Turn on for a turned-off plugin only with plugins:enable', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:disable']);
    mockPluginList([DISABLED_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('BetaThing');

    const menu = await openRowMenu(user, 'BetaThing');
    expect(within(menu).queryByRole('menuitem', { name: 'Turn on' })).not.toBeInTheDocument();
  });

  it('Uninstall asks first, naming what happens, then posts', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:uninstall']);
    mockPluginList([ACTIVE_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const menu = await openRowMenu(user, 'HelloWorld');
    await user.click(within(menu).getByRole('menuitem', { name: 'Uninstall' }));
    const dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).getByText('Uninstall HelloWorld?')).toBeInTheDocument();
    expect(mockApiPost).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Uninstall plugin' }));
    await waitFor(() =>
      expect(mockApiPost).toHaveBeenCalledWith(
        '/api/v1/plugins/{id}/uninstall',
        expect.objectContaining({ params: { path: { id: 'hello-world' } } })
      )
    );
  });
});

describe('PluginsPage version badges', () => {
  it('renders a Stable badge for a 1.x plugin and Beta for a 0.x-beta plugin', async () => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN, DISABLED_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(screen.getByText('Stable')).toBeInTheDocument();
    expect(screen.getByText('Beta')).toBeInTheDocument();
  });
});

describe('PluginsPage upload dialog', () => {
  it('uploads multipart and refetches the list on success', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:upload']);
    mockPluginList([ACTIVE_PLUGIN]);
    mockUpload.mockResolvedValue({ data: { data: DISABLED_PLUGIN } });

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
    const listCalls = () => mockApiGet.mock.calls.filter(([path]) => path === '/api/v1/plugins').length;
    expect(listCalls()).toBe(1);

    await user.click(screen.getByRole('button', { name: 'Operator tools' }));
    await user.click(await screen.findByRole('menuitem', { name: /Upload plugin/i }));

    const dialog = await screen.findByRole('dialog');
    const file = new File(['<?php'], 'demo.php', { type: 'text/x-php' });
    const input = dialog.querySelector(
      'input[type="file"]'
    ) as HTMLInputElement;
    expect(input).toBeTruthy();
    expect(input).toHaveAttribute('accept', '.zip,.php');
    await user.upload(input, file);

    await user.click(
      within(dialog).getByRole('button', { name: /^Upload$/i })
    );

    await waitFor(() => expect(mockUpload).toHaveBeenCalledTimes(1));
    expect(mockUpload).toHaveBeenCalledWith(file);
    // Success refetches the plugin list (the staged plugin lands disabled).
    await waitFor(() => expect(listCalls()).toBe(2));
  });

  it('rejects an oversized file before uploading', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:upload']);
    mockPluginList([ACTIVE_PLUGIN]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    await user.click(screen.getByRole('button', { name: 'Operator tools' }));
    await user.click(await screen.findByRole('menuitem', { name: /Upload plugin/i }));
    const dialog = await screen.findByRole('dialog');

    // 33 MiB > the 32 MiB cap.
    const huge = new File(['x'], 'huge.zip', { type: 'application/zip' });
    Object.defineProperty(huge, 'size', { value: 33 * 1024 * 1024 });
    const input = dialog.querySelector(
      'input[type="file"]'
    ) as HTMLInputElement;
    await user.upload(input, huge);
    await user.click(
      within(dialog).getByRole('button', { name: /^Upload$/i })
    );

    expect(mockUpload).not.toHaveBeenCalled();
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(
        expect.stringMatching(/too large|32/i),
        'error'
      )
    );
  });
});

describe('PluginsPage optimistic sidenav on disable', () => {
  it('optimistically removes the plugin nav links and refreshes the sidebar', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read', 'plugins:disable']);
    mockPluginList([ACTIVE_PLUGIN]);
    // The active plugin contributes one feature -> /admin/x/hello-greetings.
    mockUsePluginFeatures.mockReturnValue({
      features: [
        {
          id: 'hello-greetings',
          plugin: 'HelloWorld',
          label: 'Greetings',
          icon: null,
          group: 'plugins',
          order: 10,
          screen: 'crud',
          resource: { basePath: '/api/v1/hello/greetings', titleField: 'message' },
          action: null,
          embed: null,
          requiredPermission: 'hello:view',
          capabilities: { canCreate: true, canEdit: true, canDelete: true },
        },
      ],
      dropped: [],
      isLoading: false,
    });
    mockApiPost.mockResolvedValue({ data: {}, error: undefined });

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    const menu = await openRowMenu(user, 'HelloWorld');
    await user.click(within(menu).getByRole('menuitem', { name: 'Turn off' }));

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled());
    // Optimistic removal targets the plugin's contributed nav hrefs...
    await waitFor(() =>
      expect(removeItemsByHref).toHaveBeenCalledWith([
        '/admin/x/hello-greetings',
      ])
    );
    // ...and a refresh reconciles with the server.
    await waitFor(() => expect(refresh).toHaveBeenCalled());
  });
});

/**
 * #953 — a frontend feature the host REFUSED must be visible to the
 * administrator, not merely absent from the navigation.
 *
 * The refusal rules are right and are not relaxed; what changes is that the
 * reason, which the server already had, now reaches a screen. The console is
 * already gated on `plugins:read` — the same permission the server applies
 * before it will send `dropped` at all — so no extra gate is needed here.
 */
describe('PluginsPage store Verified marks (WHIT-1014)', () => {
  it('shows Verified and who reviewed it only when the server says so', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read']);
    mockPluginList([
      {
        ...ACTIVE_PLUGIN,
        trust: 'verified',
        mark: { host: 'app.whity.dev', reviewed_at: '2026-11-07T10:00:00+00:00', checked_at: '2026-11-08T07:00:00+00:00', offline: false },
      } as PluginRow,
      { ...DISABLED_PLUGIN, trust: 'unverified', mark: null } as PluginRow,
    ]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
    expect(screen.getByText('Verified')).toBeInTheDocument();
    expect(screen.getByText('Not verified')).toBeInTheDocument();

    const menu = await openRowMenu(user, 'HelloWorld');
    await user.click(within(menu).getByRole('menuitem', { name: 'Details' }));
    expect(await screen.findByText(/^Reviewed on app\.whity\.dev on .+; its code runs on your server\.$/)).toBeInTheDocument();
  });

  it('never shows Verified for a plugin without a mark', async () => {
    setCapabilities(['plugins:read']);
    mockPluginList([{ ...ACTIVE_PLUGIN, trust: 'unverified', mark: null } as PluginRow]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
    expect(screen.queryByText('Verified')).toBeNull();
  });
});

describe('PluginsPage rejected screens (#953)', () => {
  const DROPPED = {
    plugin: 'HelloWorld',
    featureId: 'hello-blocks',
    reason:
      "data-bound block source '/api/v1/hello/things?status=open' is not a GET route this plugin registered",
  };

  function withDropped(dropped: (typeof DROPPED)[]): void {
    mockUsePluginFeatures.mockReturnValue({ features: [], dropped, isLoading: false });
  }

  it('marks the owning plugin in the list, so the hunt does not start at zero', async () => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN]);
    withDropped([DROPPED]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(screen.getByText('1 rejected screen(s)')).toBeInTheDocument();
  });

  it('names the screen and the exact reason in the detail panel', async () => {
    const user = userEvent.setup();
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN]);
    withDropped([DROPPED]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');
    const menu = await openRowMenu(user, 'HelloWorld');
    await user.click(within(menu).getByRole('menuitem', { name: 'Details' }));

    expect(await screen.findByText('Rejected screens')).toBeInTheDocument();
    // The screen's id is a technical detail, shown only when asked for.
    expect(screen.queryByText('hello-blocks')).toBeNull();
    // Verbatim: this is the string that used to exist only in a container log.
    expect(screen.getByText(DROPPED.reason)).toBeInTheDocument();
  });

  it('says nothing at all when the plugin had nothing refused', async () => {
    setCapabilities(['plugins:read']);
    mockPluginList([ACTIVE_PLUGIN]);
    // A refusal belonging to a DIFFERENT plugin must not be attributed here.
    withDropped([{ ...DROPPED, plugin: 'SomeOtherPlugin' }]);

    render(<PluginsPage />);
    await screen.findByText('HelloWorld');

    expect(screen.queryByText(/rejected screen/i)).toBeNull();
  });

  describe('install-wide lifecycle (Q4)', () => {
    it('offers a workspace no changes, whatever its role holds', async () => {
      const user = userEvent.setup();
      setCapabilities(['plugins:read', 'plugins:upload', 'plugins:reload', 'plugins:uninstall', 'plugins:disable']);
      mockPluginList([ACTIVE_PLUGIN], false);

      render(<PluginsPage />);
      await screen.findByText('HelloWorld');

      expect(screen.getByText(/the platform operator manages them/)).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Operator tools' })).not.toBeInTheDocument();
      const menu = await openRowMenu(user, 'HelloWorld');
      expect(within(menu).queryByRole('menuitem', { name: 'Uninstall' })).not.toBeInTheDocument();
      expect(within(menu).queryByRole('menuitem', { name: 'Turn off' })).not.toBeInTheDocument();
    });
  });

  describe('recently removed (S.7)', () => {
    it('lists a kept plugin and reinstalls it', async () => {
      const user = userEvent.setup();
      setCapabilities(['plugins:read', 'plugins:install']);
      mockPluginList([], true, [
        { name: 'CakeOrders', version: '1.0.0', removed_by: 'Rana', removed_at: '2026-11-01 10:00:00', purge_after: '2026-12-01 10:00:00', purge_failed: false },
      ]);
      mockApiPost.mockResolvedValue({ data: { data: { message: 'ok' } } });

      render(<PluginsPage />);
      await screen.findByText('CakeOrders');
      expect(screen.getByText(/Removed by Rana/)).toBeInTheDocument();

      await user.click(screen.getByRole('button', { name: 'Reinstall' }));

      await waitFor(() =>
        expect(mockApiPost).toHaveBeenCalledWith('/api/v1/plugins/{name}/reinstall', {
          params: { path: { name: 'CakeOrders' } },
        }),
      );
    });

    it('shows a workspace nothing removed', async () => {
      setCapabilities(['plugins:read', 'plugins:install']);
      mockPluginList([ACTIVE_PLUGIN], false, [
        { name: 'CakeOrders', version: null, removed_by: null, removed_at: 'x', purge_after: 'y', purge_failed: false },
      ]);

      render(<PluginsPage />);
      await screen.findByText('HelloWorld');

      expect(screen.queryByText('CakeOrders')).not.toBeInTheDocument();
    });
  });

  describe('update approval (S.6)', () => {
    it('names what is new and approves only when asked', async () => {
      const user = userEvent.setup();
      setCapabilities(['plugins:read', 'plugins:install']);
      mockPluginList([
        { ...ACTIVE_PLUGIN, install: { update_waiting: { version: '2.0.0', new_permissions: ['hello:delete'], new_domains: ['api.example.com'] } } },
      ]);
      mockApiPost.mockResolvedValue({ data: { data: { status: 'updated' } } });

      render(<PluginsPage />);
      await screen.findByText(/HelloWorld 2\.0\.0 asks for more access/);
      expect(mockApiPost).not.toHaveBeenCalled();

      await user.click(screen.getByRole('button', { name: 'Review access' }));
      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByText(/sends data to api\.example\.com/)).toBeInTheDocument();
      expect(within(dialog).getByText('New permissions it asks for: 1')).toBeInTheDocument();

      await user.click(within(dialog).getByRole('button', { name: 'Allow and update' }));

      await waitFor(() =>
        expect(mockApiPost).toHaveBeenCalledWith('/api/v1/plugins/{name}/update/approve', {
          params: { path: { name: 'HelloWorld' } },
        }),
      );
    });
  });
});
