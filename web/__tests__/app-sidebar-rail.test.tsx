/**
 * The collapsed rail (AppShellCollapsed, register 4.5): 40px icons on one
 * centred column, each named for screen readers and by a tooltip beside the
 * rail, no native scrollbar eating the 56px, and the count kept in the name.
 */

import React from 'react';
import { act, render, screen } from '@testing-library/react';
import { AppSidebar, type AppSidebarNavGroup } from '@whity/ui/app-sidebar';

const groups: AppSidebarNavGroup[] = [
  {
    id: 'overview',
    items: [
      { id: 'home', label: 'Home', href: '/dashboard', icon: <svg /> },
      { id: 'inbox', label: 'Inbox', href: '/inbox', icon: <svg />, count: 3, countLabel: '3 waiting' },
    ],
  },
  {
    id: 'people',
    label: 'People',
    items: [{ id: 'users', label: 'Users', href: '/users', icon: <svg />, active: true }],
  },
];

describe('AppSidebar collapsed to the rail', () => {
  it('names every icon link, keeping the count in the name, with no title attribute', () => {
    render(<AppSidebar groups={groups} collapsed collapsibleGroups navLabel="Main" />);

    const home = screen.getByRole('link', { name: 'Home' });
    expect(home).not.toHaveAttribute('title');
    expect(screen.getByRole('link', { name: 'Inbox, 3 waiting' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('aria-current', 'page');
  });

  it('draws each link as a 40px square and hides the native scrollbar', () => {
    render(<AppSidebar groups={groups} collapsed navLabel="Main" />);

    for (const name of ['Home', 'Users']) {
      expect(screen.getByRole('link', { name: new RegExp(`^${name}`) })).toHaveClass('size-10');
    }
    const nav = screen.getByRole('navigation', { name: 'Main' });
    expect(nav.className).toContain('[scrollbar-width:none]');
    expect(nav.className).toContain('overflow-y-auto');
  });

  it('shows the name as a tooltip when an icon gets keyboard focus', async () => {
    render(<AppSidebar groups={groups} collapsed navLabel="Main" />);

    await act(async () => {
      screen.getByRole('link', { name: 'Home' }).focus();
    });
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Home');
  });

  it('keeps the expanded sidebar free of tooltips and its labels visible', () => {
    render(<AppSidebar groups={groups} navLabel="Main" />);

    const home = screen.getByRole('link', { name: 'Home' });
    expect(home).not.toHaveClass('size-10');
    expect(home).toHaveTextContent('Home');
    expect(screen.getByRole('navigation', { name: 'Main' }).className).not.toContain('[scrollbar-width:none]');
  });
});
