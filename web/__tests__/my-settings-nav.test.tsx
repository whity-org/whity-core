/**
 * WHIT-1081: your own settings are pages in the one Settings area.
 *
 * - The sidebar's Settings entry is there for everyone, members who can change
 *   nothing of the workspace's included, and opens the area's root.
 * - Old `/settings?tab=…` links land on the page that holds what the tab held.
 */

import { renderHook, waitFor } from '@testing-library/react';
import { redirectForOldTab } from '@/lib/settings/my-settings';

jest.mock('next/navigation', () => ({ usePathname: () => '/dashboard' }));
let mockTenant = 1;
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ user: { tenant_id: mockTenant }, memberships: [] }) }));

let mockItems: { id: string; label: string; href: string; group?: string; icon?: string; order?: number }[] = [];
jest.mock('@/lib/navigation-context', () => ({ useNavigation: () => ({ items: mockItems }) }));
jest.mock('@/lib/api/client', () => ({ api: { GET: jest.fn().mockResolvedValue({ data: { data: [] } }) } }));

import { useShellNav } from '@/components/shell/use-shell-nav';

describe('the Settings entry', () => {
  it('is there for a member with no workspace settings, and opens /settings', async () => {
    mockItems = [
      { id: 'dashboard', label: 'Dashboard', href: '/dashboard', group: 'overview' },
      { id: 'settings', label: 'Settings', href: '/settings' },
    ];
    const { result } = renderHook(() => useShellNav());
    await waitFor(() => expect(result.current.groups.length).toBeGreaterThan(0));
    const settings = result.current.groups.find((group) => group.id === 'settings');
    expect(settings?.items).toEqual([expect.objectContaining({ id: 'settings-area', href: '/settings' })]);
    // The account page is not a sidebar item of its own: it is inside the area.
    expect(result.current.groups.flatMap((group) => group.items).filter((item) => item.href === '/settings')).toHaveLength(1);
  });

  it('opens the same place for an admin', async () => {
    mockItems = [
      { id: 'dashboard', label: 'Dashboard', href: '/dashboard', group: 'overview' },
      { id: 'website-settings', label: 'Workspace settings', href: '/admin/settings', group: 'settings' },
    ];
    const { result } = renderHook(() => useShellNav());
    await waitFor(() => expect(result.current.groups.length).toBeGreaterThan(0));
    const settings = result.current.groups.find((group) => group.id === 'settings');
    expect(settings?.items[0]).toEqual(expect.objectContaining({ href: '/settings' }));
  });
});

describe('fewer sidebar headings (WHIT-1082)', () => {
  const workspaceItems = [
    { id: 'dashboard', label: 'Dashboard', href: '/dashboard', group: 'overview' },
    { id: 'tasks', label: 'Tasks', href: '/tasks', group: 'work', order: 1 },
    { id: 'contacts', label: 'Contacts', href: '/contacts', group: 'operations', order: 4 },
    { id: 'plugins', label: 'Plugins', href: '/admin/plugins', group: 'platform', order: 2 },
    { id: 'tenant-billing', label: 'Billing', href: '/billing', group: 'platform', order: 5 },
    { id: 'audit-logs', label: 'Audit Logs', href: '/admin/audit-logs', group: 'platform', order: 7 },
    { id: 'notifications', label: 'Notification log', href: '/admin/notifications', group: 'platform', order: 9 },
  ];

  it('puts a plugin’s Operations page in Work and folds the Workspace group into Settings', async () => {
    mockTenant = 1;
    mockItems = workspaceItems;
    const { result } = renderHook(() => useShellNav());
    await waitFor(() => expect(result.current.groups.length).toBeGreaterThan(0));
    const ids = result.current.groups.map((group) => group.id);
    expect(ids).not.toContain('operations');
    expect(ids).not.toContain('platform');
    const work = result.current.groups.find((group) => group.id === 'work');
    expect(work?.items.map((item) => item.id)).toEqual(['tasks', 'contacts']);
  });

  it('keeps the Platform group for the system tenant', async () => {
    mockTenant = 0;
    mockItems = workspaceItems;
    const { result } = renderHook(() => useShellNav());
    await waitFor(() => expect(result.current.groups.length).toBeGreaterThan(0));
    const platform = result.current.groups.find((group) => group.id === 'platform');
    expect(platform?.items.map((item) => item.id)).toEqual(['plugins', 'tenant-billing', 'audit-logs', 'notifications']);
    mockTenant = 1;
  });
});

describe('old /settings?tab= links', () => {
  it.each([
    ['profile', '', '/settings/profile'],
    ['security', '', '/settings/security'],
    ['sessions', '', '/settings/signed-in'],
    ['preferences', '', '/settings/display'],
    ['preferences', '#notifications', '/settings/notifications'],
    ['nonsense', '', '/settings/profile'],
  ])('?tab=%s%s goes to %s', (tab, hash, target) => {
    expect(redirectForOldTab(tab, hash)).toBe(target);
  });

  it('leaves /settings with no tab alone', () => {
    expect(redirectForOldTab(null, '')).toBeNull();
  });
});
