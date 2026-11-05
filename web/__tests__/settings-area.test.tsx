/**
 * WHIT-806 — the Settings area (owner decision D1, 31 Oct 2026).
 *
 * One area with a grouped sub-nav: Workspace, Sign-in and security, Email,
 * Data, Advanced. Which pages show comes from the server (the RBAC-filtered
 * tabs list, and the caller's navigation for the data pages), never computed
 * here; a group with nothing in it is left out.
 */

import React from 'react';
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

import { SettingsArea } from '@/components/settings/settings-area';

function tabs(ids: string[]) {
  const href = (id: string) => (id === 'general' ? '/admin/settings' : `/admin/settings/${id}`);
  return { data: { data: ids.map((id) => ({ id, href: href(id), label: id.charAt(0).toUpperCase() + id.slice(1) })) } };
}

beforeEach(() => {
  mockPathname = '/admin/settings';
  mockNavItems = [];
  mockCaps = [];
  mockGet.mockReset();
});

describe('SettingsArea', () => {
  it('groups the pages the server allows, in the decided order', async () => {
    mockGet.mockResolvedValue(tabs(['security', 'general', 'branding', 'email', 'feature-flags', 'storage']));
    mockNavItems = [{ id: 'tags', href: '/admin/tags', label: 'Tags' }];
    render(<SettingsArea>page</SettingsArea>);

    const nav = await screen.findByTestId('settings-area-nav');
    await waitFor(() => expect(within(nav).getByText('Workspace')).toBeInTheDocument());
    const headings = Array.from(nav.querySelectorAll('[data-settings-group]')).map((p) => p.textContent);
    expect(headings).toEqual(['Workspace', 'Sign-in and security', 'Data']);
    const links = within(nav).getAllByRole('link').map((a) => a.getAttribute('href'));
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

    expect(within(nav).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['/admin/settings']);
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
  it('lists Licensed devices under Devices only for someone who may view them', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    mockCaps = ['licensing:view'];
    render(<SettingsArea>page</SettingsArea>);
    const link = await screen.findByTestId('settings-area-link-devices');
    expect(link).toHaveAttribute('href', '/licensing');
    expect(screen.getByText('Devices')).toBeInTheDocument();
  });

  it('leaves the Devices group out without the permission', async () => {
    mockGet.mockResolvedValue(tabs(['general']));
    render(<SettingsArea>page</SettingsArea>);
    await screen.findByTestId('settings-area-link-general');
    expect(screen.queryByTestId('settings-area-link-devices')).toBeNull();
  });
});
