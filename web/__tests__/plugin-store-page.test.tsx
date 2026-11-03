import React from 'react';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Behaviour tests for the Plugin Store (`admin/plugins/store/page.tsx`) and the
 * install dialog it opens.
 *
 * The page consumes the store-browse backend through the typed client
 * (`api.GET /plugins/store/allowed`, `api.GET /plugins/store/catalog`,
 * `api.POST /plugins/install-from-store`) and gates on:
 *   - plugins:read   → may view the page at all (else Access Denied)
 *   - plugins:install, in the system tenant → may Install
 */

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const refresh = jest.fn(() => Promise.resolve());
jest.mock('@/lib/navigation-context', () => ({
  useNavigation: () => ({ items: [], isLoading: false, refresh, removeItemsByHref: jest.fn() }),
}));

const push = jest.fn();
jest.mock('next/navigation', () => ({
  usePathname: () => '/admin/plugins/store',
  useRouter: () => ({ push }),
}));

const hasPermission = jest.fn<boolean, [string]>();
const mockCapabilities = { loading: false, permissions: [] as string[], hasPermission };
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => mockCapabilities,
}));

import PluginStorePage from '@/app/(protected)/admin/plugins/store/page';

const CATALOG = [
  { slug: 'quote-of-day', name: 'Quote of the Day', description: 'A rotating quote.', author: 'Demos', tags: ['demo', 'fun'], latest_version: '1.0.0' },
  { slug: 'system-ping', name: 'System Ping', description: 'Health pong.', author: 'Demos', tags: ['demo', 'ops'], latest_version: '1.0.0' },
];

function allowedResponse(enabled = true, hosts = ['store.example.com'], canInstall = true) {
  return { data: { data: { enabled, hosts, can_install: canInstall } }, error: undefined };
}

function catalogResponse(plugins = CATALOG) {
  return { data: { data: plugins, store_url: 'https://store.example.com', count: plugins.length }, error: undefined };
}

function routeGet(allowed: unknown = allowedResponse(), catalog: unknown = catalogResponse()) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/plugins/store/allowed') return Promise.resolve(allowed);
    if (path === '/api/v1/plugins/store/catalog') return Promise.resolve(catalog);
    return Promise.resolve({ data: undefined, error: 'unrouted' });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true); // both plugins:read and plugins:install
  mockApiPost.mockResolvedValue({ data: { data: { id: 'quote-of-day', name: 'Quote' } }, error: undefined });
});

test('shows Access Denied without plugins:read', () => {
  hasPermission.mockImplementation((p: string) => p !== 'plugins:read');
  routeGet();
  render(<PluginStorePage />);
  expect(screen.getByText('You cannot browse the plugin store')).toBeInTheDocument();
});

test('shows the disabled state when no stores are configured', async () => {
  routeGet(allowedResponse(false, []));
  render(<PluginStorePage />);
  expect(await screen.findByText(/Plugin store disabled/i)).toBeInTheDocument();
});

test('lists the store plugins as soon as the store is known', async () => {
  routeGet();
  render(<PluginStorePage />);
  expect(await screen.findByText('Quote of the Day')).toBeInTheDocument();
  expect(screen.getByText('System Ping')).toBeInTheDocument();
  expect(mockApiGet).toHaveBeenCalledWith(
    '/api/v1/plugins/store/catalog',
    expect.objectContaining({ params: { query: expect.objectContaining({ store_url: 'https://store.example.com' }) } }),
  );
});

test('a category chip narrows the tiles', async () => {
  routeGet();
  render(<PluginStorePage />);
  await screen.findByText('Quote of the Day');
  fireEvent.click(screen.getByRole('button', { name: 'ops' }));
  expect(screen.queryByText('Quote of the Day')).not.toBeInTheDocument();
  expect(screen.getByText('System Ping')).toBeInTheDocument();
});

test('search passes the q term to the catalogue query', async () => {
  routeGet();
  render(<PluginStorePage />);
  await screen.findByText('Quote of the Day');
  const searchInput = screen.getByRole('searchbox');
  fireEvent.change(searchInput, { target: { value: 'ops' } });
  fireEvent.keyDown(searchInput, { key: 'Enter' });
  await waitFor(() =>
    expect(mockApiGet).toHaveBeenCalledWith(
      '/api/v1/plugins/store/catalog',
      expect.objectContaining({ params: { query: expect.objectContaining({ q: 'ops' }) } }),
    ),
  );
});

test('opening a tile goes to the plugin page', async () => {
  routeGet();
  render(<PluginStorePage />);
  fireEvent.click(await screen.findByRole('button', { name: 'Quote of the Day' }));
  expect(push).toHaveBeenCalledWith(
    '/admin/plugins/store/quote-of-day?store=https%3A%2F%2Fstore.example.com',
  );
});

test('Install steps through Access and posts install-from-store, then offers Turn on', async () => {
  const user = userEvent.setup();
  routeGet();
  render(<PluginStorePage />);
  await screen.findByText('Quote of the Day');
  await user.click(screen.getAllByRole('button', { name: /^Install$/ })[0]);

  const dialog = await screen.findByRole('dialog');
  expect(within(dialog).getByText('Install Quote of the Day')).toBeInTheDocument();
  await user.click(within(dialog).getByRole('button', { name: 'Allow and continue' }));

  await waitFor(() =>
    expect(mockApiPost).toHaveBeenCalledWith(
      '/api/v1/plugins/install-from-store',
      expect.objectContaining({
        body: expect.objectContaining({ store_url: 'https://store.example.com', slug: 'quote-of-day', version: '1.0.0' }),
      }),
    ),
  );
  expect(await within(dialog).findByText(/installed and turned off/i)).toBeInTheDocument();
  await user.click(within(dialog).getByRole('button', { name: 'Turn on' }));
  await waitFor(() =>
    expect(mockApiPost).toHaveBeenCalledWith(
      '/api/v1/plugins/{name}/enable',
      expect.objectContaining({ params: { path: { name: 'quote-of-day' } } }),
    ),
  );
});

/**
 * Regression: a failed install used to show one generic message for every
 * reason. The backend's real `error` message must surface verbatim.
 */
test('Install surfaces the real backend error message on failure', async () => {
  const user = userEvent.setup();
  routeGet();
  mockApiPost.mockResolvedValue({
    data: undefined,
    error: { error: 'A valid store download token is required.' },
  });
  render(<PluginStorePage />);
  await screen.findByText('Quote of the Day');
  await user.click(screen.getAllByRole('button', { name: /^Install$/ })[0]);
  const dialog = await screen.findByRole('dialog');
  await user.click(within(dialog).getByRole('button', { name: 'Allow and continue' }));
  expect(await within(dialog).findByRole('alert')).toHaveTextContent('A valid store download token is required.');
});

test('a catalogue failure shows the real reason with Try again', async () => {
  routeGet(allowedResponse(), { data: undefined, error: { error: 'The store host is not in the trusted allowlist.' } });
  render(<PluginStorePage />);
  expect(await screen.findByText('The store host is not in the trusted allowlist.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
});

describe('mint token', () => {
  const originalFetch = global.fetch;

  afterEach(() => {
    global.fetch = originalFetch;
  });

  async function openInstall() {
    const user = userEvent.setup();
    routeGet();
    render(<PluginStorePage />);
    await screen.findByText('Quote of the Day');
    await user.click(screen.getAllByRole('button', { name: /^Install$/ })[0]);
    await screen.findByRole('dialog');
    return user;
  }

  test('mints a token from the selected store and fills the field', async () => {
    const mockFetch = jest.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { token: 'wps_abc123', label: 'admin-store-page' } }),
    });
    global.fetch = mockFetch as unknown as typeof fetch;
    const user = await openInstall();

    await user.click(screen.getByTitle(/Mint a download token/i));

    await waitFor(() =>
      expect(mockFetch).toHaveBeenCalledWith(
        'https://store.example.com/api/v1/plugin-store/tokens',
        expect.objectContaining({ method: 'POST', credentials: 'include' }),
      ),
    );
    await waitFor(() => expect(screen.getByPlaceholderText(/required to install/i)).toHaveValue('wps_abc123'));
    expect(addToast).toHaveBeenCalledWith('Store token minted and filled in below.', 'success');
  });

  test('surfaces the real error when minting fails (e.g. not an admin on that store)', async () => {
    const mockFetch = jest.fn().mockResolvedValue({
      ok: false,
      json: () => Promise.resolve({ error: 'Forbidden' }),
    });
    global.fetch = mockFetch as unknown as typeof fetch;
    const user = await openInstall();

    await user.click(screen.getByTitle(/Mint a download token/i));

    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Forbidden', 'error'));
    expect(screen.getByPlaceholderText(/required to install/i)).toHaveValue('');
  });
});

describe('who may install (Q4, S.3)', () => {
  it('disables Install in a workspace and says who to ask, even with the permission', async () => {
    routeGet(allowedResponse(true, ['store.example.com'], false));
    render(<PluginStorePage />);

    await screen.findByText('Quote of the Day');

    const install = screen.getAllByRole('button', { name: /Install/ })[0];
    expect(install).toBeDisabled();
    expect(screen.getAllByText('Ask someone with permission to install plugins').length).toBeGreaterThan(0);
  });

  it('disables Install without plugins:install, even in the system tenant', async () => {
    hasPermission.mockImplementation((slug: string) => slug !== 'plugins:install');
    routeGet();
    render(<PluginStorePage />);

    await screen.findByText('Quote of the Day');

    expect(screen.getAllByRole('button', { name: /Install/ })[0]).toBeDisabled();
  });

  it('never tells someone a plugin is isolated when it is not', async () => {
    const user = userEvent.setup();
    routeGet();
    render(<PluginStorePage />);

    await screen.findByText('Quote of the Day');
    await user.click(screen.getAllByRole('button', { name: /Install/ })[0]);

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByText(/isolated/i)).not.toBeInTheDocument();
    expect(within(dialog).getByText(/runs on this server for every workspace/)).toBeInTheDocument();
  });
});
