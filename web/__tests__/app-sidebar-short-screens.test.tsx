/**
 * PwShortSidebar (PW.10, WHIT-827): on a short window the sidebar's ends stay
 * put. Pinned groups (Settings) render at the foot, outside the scrolling
 * list, so only the links between the workspace and the account row scroll.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { AppSidebar, type AppSidebarNavGroup } from '@whity/ui/app-sidebar';

const groups: AppSidebarNavGroup[] = [
  { id: 'overview', items: [{ id: 'home', label: 'Home', href: '/dashboard' }] },
  {
    id: 'money',
    label: 'Money',
    items: [{ id: 'invoices', label: 'Invoices', href: '/invoices', active: true }],
  },
  { id: 'settings', items: [{ id: 'settings-area', label: 'Settings', href: '/admin/settings' }] },
];

describe('AppSidebar on short screens', () => {
  it('renders pinned groups outside the scrolling list, above the footer', () => {
    render(
      <AppSidebar
        groups={groups}
        pinnedGroupIds={['settings']}
        footer={<span>Amro Saleh</span>}
        navLabel="Main"
      />,
    );

    const nav = screen.getByRole('navigation', { name: 'Main' });
    const settings = screen.getByRole('link', { name: 'Settings' });
    expect(nav).not.toContainElement(settings);
    expect(nav).toContainElement(screen.getByRole('link', { name: 'Invoices' }));
    expect(settings.closest('[data-slot="app-sidebar-pinned"]')).not.toBeNull();
  });

  it('scrolls only the links: the sidebar itself never scrolls', () => {
    const { container } = render(<AppSidebar groups={groups} pinnedGroupIds={['settings']} navLabel="Main" />);
    const aside = container.querySelector('[data-slot="app-sidebar"]') as HTMLElement;
    expect(aside.className).toContain('overflow-hidden');
    expect(aside.className).not.toContain('overflow-y-auto');
    expect(screen.getByRole('navigation', { name: 'Main' }).className).toContain('overflow-y-auto');
  });

  it('keeps every group in the list while searching, pinned ones included', () => {
    render(
      <AppSidebar groups={groups} pinnedGroupIds={['settings']} navLabel="Main" searchable searchValue="sett" />,
    );
    const nav = screen.getByRole('navigation', { name: 'Main' });
    expect(nav).toContainElement(screen.getByRole('link', { name: 'Settings' }));
  });
});
