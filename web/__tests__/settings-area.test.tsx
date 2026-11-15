/**
 * WHIT-806 — the Settings area (owner decision D1, 31 Oct 2026).
 *
 * One area with a grouped sub-nav: Workspace, Sign-in and security, Email,
 * Data, Advanced. Which pages show comes from the server (the RBAC-filtered
 * tabs list, and the caller's navigation for the data pages), never computed
 * here; a group with nothing in it is left out.
 */

import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';

let mockPathname = '/admin/settings';
jest.mock('next/navigation', () => ({ usePathname: () => mockPathname }));

jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

let mockNavItems: { id: string; href: string; label: string }[] = [];
jest.mock('@/lib/navigation-context', () => ({ useNavigation: () => ({ items: mockNavItems }) }));

let mockCaps: string[] = [];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (slug: string) => mockCaps.includes(slug) }),
}));

const mockGet = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...args: unknown[]) => mockGet(...args) } }));

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { tenant_id: 1, role: 'admin' },
    memberships: [
      { tenant_id: 1, tenant_name: 'Example Bakery', role: 'admin' },
      { tenant_id: 2, tenant_name: 'Amman Coffee Co.', role: 'member' },
    ],
  }),
}));

let mockLanguage = 'en';
jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  useLanguageCode: () => mockLanguage,
}));

import { SettingsArea } from '@/components/settings/settings-area';

/** The workspace's own links, leaving out your own settings that always lead the list. */
const workspaceHrefs = (nav: HTMLElement) =>
  within(nav)
    .getAllByRole('link')
    .map((a) => a.getAttribute('href'))
    .filter((href) => !href?.startsWith('/settings/'));

function tabs(ids: string[]) {
  const href = (id: string) => (id === 'general' ? '/admin/settings' : `/admin/settings/${id}`);
  return { data: { data: ids.map((id) => ({ id, href: href(id), label: id.charAt(0).toUpperCase() + id.slice(1) })) } };
}

beforeEach(() => {
  mockPathname = '/admin/settings';
  mockNavItems = [];
  mockCaps = [];
  mockLanguage = 'en';
  mockGet.mockReset();
});

describe('SettingsArea', () => {
  it('asks for the tabs again when the language changes, with no reload', async () => {
    mockGet.mockResolvedValueOnce({ data: { data: [{ id: 'branding', href: '/admin/settings/branding', label: 'Branding' }] } });
    const { rerender } = render(<SettingsArea>page</SettingsArea>);
    expect(await screen.findByText('Branding')).toBeInTheDocument();

    mockGet.mockResolvedValueOnce({ data: { data: [{ id: 'branding', href: '/admin/settings/branding', label: 'الهوية' }] } });
    mockLanguage = 'ar';
    rerender(<SettingsArea>page</SettingsArea>);

    expect(await screen.findByText('الهوية')).toBeInTheDocument();
    expect(screen.queryByText('Branding')).toBeNull();
    expect(mockGet).toHaveBeenCalledTimes(2);
  });

  it('groups the pages the server allows, in the decided order', async () => {
    mockGet.mockResolvedValue(tabs(['security', 'general', 'branding', 'email', 'feature-flags', 'storage']));
    mockNavItems = [{ id: 'tags', href: '/admin/tags', label: 'Tags' }];
    render(<SettingsArea>page</SettingsArea>);

    const nav = await screen.findByTestId('settings-area-nav');
    await waitFor(() => expect(within(nav).getByText('Workspace')).toBeInTheDocument());
    const headings = Array.from(nav.querySelectorAll('[data-settings-group]')).map((p) => p.textContent);
    expect(headings).toEqual(['My settings', 'You in Example Bakery', 'Workspace', 'Security', 'Data']);
    expect(nav.querySelector('[data-settings-section]')).toHaveTextContent('Workspace settings · Example Bakery');
    const links = workspaceHrefs(nav);
    expect(links).toEqual([
      '/admin/settings',
      '/admin/settings/branding',
      '/admin/settings/security',
      '/admin/tags',
    ]);
  });

  it('leaves the pages that moved to Platform out of its own list', async () => {
    mockGet.mockResolvedValue(tabs(['general', 'email', 'storage', 'feature-flags']));
    mockNavItems = [{ id: 'languages', href: '/admin/languages', label: 'Languages' }];
    render(<SettingsArea>page</SettingsArea>);
    const nav = await screen.findByTestId('settings-area-nav');
    await waitFor(() => expect(within(nav).getByText('Workspace')).toBeInTheDocument());

    expect(workspaceHrefs(nav)).toEqual(['/admin/settings']);
  });

  it('leaves out a group with nothing the person may open', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    const nav = await screen.findByTestId('settings-area-nav');
    await waitFor(() => expect(within(nav).getByText('Workspace')).toBeInTheDocument());
    expect(within(nav).queryByText('Advanced')).not.toBeInTheDocument();
  });

  it('marks the current page, General only on its exact route', async () => {
    mockPathname = '/admin/translations/7';
    mockGet.mockResolvedValue(tabs(['general']));
    mockNavItems = [{ id: 'translations', href: '/admin/translations', label: 'Translations' }];
    render(<SettingsArea>page</SettingsArea>);
    const general = await screen.findByTestId('settings-area-link-general');
    expect(screen.getByTestId('settings-area-link-translations')).toHaveAttribute('aria-current', 'page');
    expect(general).not.toHaveAttribute('aria-current');
  });
});

describe('SettingsArea devices', () => {
  it('lists Licensed devices under Security only for someone who may view them', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    mockCaps = ['licensing:view'];
    render(<SettingsArea>page</SettingsArea>);
    const link = await screen.findByTestId('settings-area-link-devices');
    expect(link).toHaveAttribute('href', '/licensing');
    expect(link.parentElement?.querySelector('[data-settings-group]')).toHaveTextContent('Security');
  });

  it('leaves the Devices group out without the permission', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    await screen.findByTestId('settings-area-link-general');
    expect(screen.queryByTestId('settings-area-link-devices')).toBeNull();
  });
});

describe('SettingsArea on a phone (PwSettings)', () => {
  beforeEach(() => window.localStorage.clear());

  it('draws the menu as its own page, marks the last opened page and filters by search', async () => {
    window.localStorage.setItem('whity.settings.lastOpened', 'branding');
    mockPathname = '/settings';
    mockGet.mockResolvedValue(tabs(['general', 'branding', 'security']));
    render(<SettingsArea>page</SettingsArea>);

    const row = await screen.findByTestId('settings-menu-row-branding');
    expect(row).toHaveAttribute('href', '/admin/settings/branding');
    await waitFor(() => expect(within(row).getByText('Last opened')).toBeInTheDocument());
    expect(within(screen.getByTestId('settings-menu-row-general')).queryByText('Last opened')).toBeNull();

    const search = screen.getByTestId('settings-menu-search');
    await userEvent.type(search, 'sec');
    expect(screen.queryByTestId('settings-menu-row-branding')).toBeNull();
    expect(screen.getByTestId('settings-menu-row-security')).toBeInTheDocument();
  });

  it('remembers the page you opened, and shows a back control with the path on a page', async () => {
    mockPathname = '/admin/settings/branding';
    mockGet.mockResolvedValue(tabs(['general', 'branding']));
    render(<SettingsArea>page</SettingsArea>);

    const crumb = await screen.findByTestId('settings-crumb');
    expect(crumb).toHaveTextContent('SettingsWorkspace');
    expect(within(screen.getByTestId('settings-back')).getByRole('link', { name: 'Settings' })).toHaveAttribute(
      'href',
      '/settings',
    );
    expect(window.localStorage.getItem('whity.settings.lastOpened')).toBe('branding');
  });

  it('a deeper page goes back to its list page and adds it to the path', async () => {
    mockPathname = '/admin/settings/email-domains/7';
    mockGet.mockResolvedValue(tabs(['general', 'email-domains']));
    render(<SettingsArea>page</SettingsArea>);

    const crumb = await screen.findByTestId('settings-crumb');
    expect(crumb).toHaveTextContent('SettingsMessages and notificationsEmail-domains');
    expect(within(screen.getByTestId('settings-back')).getByRole('link')).toHaveAttribute(
      'href',
      '/admin/settings/email-domains',
    );
  });

  it('still works when storage is blocked', async () => {
    const spy = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    mockPathname = '/admin/settings/branding';
    mockGet.mockResolvedValue(tabs(['general', 'branding']));
    render(<SettingsArea>page</SettingsArea>);
    expect(await screen.findByTestId('settings-crumb')).toBeInTheDocument();
    spy.mockRestore();
  });
});

describe('SettingsArea: your own settings (WHIT-1081)', () => {
  it('shows My settings and You in [workspace] to someone who can change nothing of the workspace', async () => {
    mockGet.mockResolvedValue({ data: { data: [] } });
    render(<SettingsArea>page</SettingsArea>);
    const nav = await screen.findByTestId('settings-area-nav');
    const headings = Array.from(nav.querySelectorAll('[data-settings-group]')).map((p) => p.textContent);
    expect(headings).toEqual(['My settings', 'You in Example Bakery']);
    expect(nav.querySelector('[data-settings-section]')).toBeNull();
    expect(within(nav).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual([
      '/settings/profile',
      '/settings/security',
      '/settings/display',
      '/settings/notifications',
      '/settings/signed-in',
      '/settings/role',
    ]);
  });

  it('marks your page and puts it under its block in the path', async () => {
    mockPathname = '/settings/notifications';
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    expect(await screen.findByTestId('settings-area-link-notifications')).toHaveAttribute('aria-current', 'page');
    expect(screen.getByTestId('settings-crumb')).toHaveTextContent('SettingsYou in Example Bakery');
  });

  it('keeps your Password and two-step apart from the workspace’s Security', async () => {
    mockGet.mockResolvedValue(tabs(['security']));
    render(<SettingsArea>page</SettingsArea>);
    expect(await screen.findByTestId('settings-area-link-password')).toHaveAttribute('href', '/settings/security');
    expect(screen.getByTestId('settings-area-link-security')).toHaveAttribute('href', '/admin/settings/security');
  });

  it('draws the phone menu with your settings first and says they follow you everywhere', async () => {
    mockPathname = '/settings';
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    const menu = await screen.findByTestId('settings-menu');
    expect(within(menu).getByTestId('settings-menu-row-notifications')).toHaveAttribute('href', '/settings/notifications');
    expect(within(menu).getByText('All your workspaces')).toBeInTheDocument();
    expect(within(menu).getByText('Workspace settings · Example Bakery')).toBeInTheDocument();
  });
});

describe('SettingsArea: the workspace’s pages in one place (WHIT-1082)', () => {
  it('links document themes, plan and billing, plugins, the logs and bookings by permission', async () => {
    mockGet.mockResolvedValue(tabs(['general', 'branding', 'security', 'email-domains']));
    mockCaps = ['documents:themes', 'calendar:manage'];
    mockNavItems = [
      { id: 'tenant-billing', href: '/billing', label: 'Billing' },
      { id: 'plugins', href: '/admin/plugins', label: 'Plugins' },
      { id: 'audit-logs', href: '/admin/audit-logs', label: 'Audit Logs' },
      { id: 'notifications', href: '/admin/notifications', label: 'Notification Delivery' },
    ];
    render(<SettingsArea>page</SettingsArea>);
    const nav = await screen.findByTestId('settings-area-nav');
    await waitFor(() => expect(within(nav).getByText('Plan and billing')).toBeInTheDocument());
    expect(workspaceHrefs(nav)).toEqual([
      '/admin/settings',
      '/admin/settings/branding',
      '/admin/document-themes',
      '/billing',
      '/admin/plugins',
      '/admin/settings/security',
      '/admin/audit-logs',
      '/admin/settings/email-domains',
      '/admin/notifications',
      '/calendar/settings',
    ]);
    const headings = Array.from(nav.querySelectorAll('[data-settings-group]')).map((p) => p.textContent);
    expect(headings).toEqual(['My settings', 'You in Example Bakery', 'Workspace', 'Security', 'Messages and notifications', 'Data']);
  });

  it('leaves document themes out without the permission to change them', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    await screen.findByTestId('settings-area-link-general');
    expect(screen.queryByTestId('settings-area-link-document-themes')).toBeNull();
  });

  it('says on a workspace page that a change reaches everyone in it, and not on yours', async () => {
    mockPathname = '/admin/settings/branding';
    mockGet.mockResolvedValue(tabs(['general', 'branding']));
    const { unmount } = render(<SettingsArea>page</SettingsArea>);
    expect(await screen.findByTestId('workspace-scope')).toHaveTextContent('Workspace settings for Example Bakery');
    unmount();

    mockPathname = '/settings/profile';
    render(<SettingsArea>page</SettingsArea>);
    await screen.findByTestId('settings-area-link-profile');
    expect(screen.queryByTestId('workspace-scope')).toBeNull();
  });
});

describe('SettingsArea: the phone menu search reads the settings index (WHIT-1084)', () => {
  it('finds a page by a word in either language, or by something on it', async () => {
    mockPathname = '/settings';
    mockGet.mockResolvedValue(tabs(['general', 'branding']));
    render(<SettingsArea>page</SettingsArea>);
    const search = await screen.findByTestId('settings-menu-search');

    await userEvent.type(search, 'إشعارات');
    expect(screen.getByTestId('settings-menu-row-notifications')).toBeInTheDocument();
    expect(screen.queryByTestId('settings-menu-row-branding')).toBeNull();

    await userEvent.clear(search);
    await userEvent.type(search, 'logo');
    expect(screen.getByTestId('settings-menu-row-branding')).toBeInTheDocument();
    expect(screen.queryByTestId('settings-menu-row-notifications')).toBeNull();
  });
});
