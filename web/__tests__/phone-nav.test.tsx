/**
 * The phone's navigation (PwNav, PW.1-PW.3): the tab bar's five places, the
 * counts on Inbox and Chat only, Create's sheet built from what this person
 * can reach, and More listing the sidebar without the places already tabs.
 */

import React from 'react';
import { render, screen, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';

let mockPathname = '/dashboard';
const mockPush = jest.fn();
jest.mock('next/navigation', () => ({
  usePathname: () => mockPathname,
  useRouter: () => ({ push: mockPush }),
}));

const NAV: Record<string, string> = {
  dashboard: '/dashboard',
  inbox: '/admin/inbox',
  chat: '/chat',
  calendar: '/calendar',
  'document-library': '/admin/document-library',
  'member-forms': '/forms',
};
let mockNav = { ...NAV };
jest.mock('@/components/shell/use-shell-nav', () => ({
  useShellNav: () => ({
    counts: { inbox: 3, chat: 120, approvals: 2 },
    hrefOf: (id: string) => mockNav[id],
    groups: [
      {
        id: 'overview',
        items: [
          { id: 'dashboard', label: 'Dashboard', href: '/dashboard' },
          { id: 'inbox', label: 'Inbox', href: '/admin/inbox' },
          { id: 'approvals', label: 'Approvals', href: '/admin/approvals', count: 2, countLabel: '2 waiting' },
        ],
      },
      { id: 'work', label: 'Work', items: [{ id: 'calendar', label: 'Calendar', href: '/calendar' }] },
    ],
  }),
}));

const mockLogout = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    logout: mockLogout,
    user: { id: 1, email: 'amro@examplebakery.jo', display_name: 'Amro Saleh', tenant_id: 4, role: 'owner' },
    memberships: [{ tenant_id: 4, tenant_name: 'Example Bakery', role: 'owner' }],
    switchTenant: jest.fn(),
  }),
}));

const mockToggle = jest.fn();
jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: 'light', toggle: mockToggle }),
}));

const mockSetLanguage = jest.fn();
jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string) => fallback,
  useI18nEnabled: () => true,
  useCurrentLanguage: () => ({
    currentLanguage: 'en',
    availableLanguages: [
      { code: 'en', name: 'English' },
      { code: 'ar', name: 'العربية' },
    ],
    setLanguage: mockSetLanguage,
  }),
}));

jest.mock('@/components/sidebar', () => ({ TenantSwitcher: () => null }));

import { PhoneNav } from '@/components/shell/phone-nav';
import MorePage from '@/app/(protected)/more/page';

beforeEach(() => {
  mockPathname = '/dashboard';
  mockNav = { ...NAV };
});

describe('PhoneNav', () => {
  it('shows Home, Inbox, Create, Chat and More, with counts on Inbox and Chat', () => {
    render(<PhoneNav />);
    const bar = screen.getByRole('navigation', { name: 'Main' });

    expect(within(bar).getByTestId('phone-tab-home')).toHaveAttribute('aria-current', 'page');
    expect(within(bar).getByTestId('phone-tab-inbox')).toHaveTextContent('3');
    // Capped, as on the card.
    expect(within(bar).getByTestId('phone-tab-chat')).toHaveTextContent('99+');
    expect(within(bar).getByTestId('phone-tab-more')).toHaveAttribute('href', '/more');
    expect(within(bar).getByRole('button', { name: 'Create' })).toBeInTheDocument();
  });

  it('marks More for a page reached from it', () => {
    mockPathname = '/admin/users';
    render(<PhoneNav />);
    expect(screen.getByTestId('phone-tab-more')).toHaveAttribute('aria-current', 'page');
    expect(screen.getByTestId('phone-tab-home')).not.toHaveAttribute('aria-current');
  });

  it('keeps Create centred when the person has no Chat', () => {
    delete mockNav.chat;
    render(<PhoneNav />);
    const row = screen.getByRole('navigation', { name: 'Main' }).firstElementChild as HTMLElement;
    expect(row.children).toHaveLength(5);
    expect(row.children[2]).toHaveAttribute('data-testid', 'phone-tab-create');
    expect(screen.queryByTestId('phone-tab-chat')).toBeNull();
  });

  it('opens the Create sheet with Ask an agent and tiles for the pages this person has', async () => {
    render(<PhoneNav />);
    await userEvent.click(screen.getByTestId('phone-tab-create'));

    const sheet = await screen.findByTestId('create-sheet');
    expect(within(sheet).getByRole('link', { name: /Ask an agent/ })).toHaveAttribute('href', '/chat');
    expect(within(sheet).getByTestId('create-tile-form')).toHaveAttribute('href', '/forms');
    expect(within(sheet).getByTestId('create-tile-document')).toHaveAttribute('href', '/admin/document-library');
    expect(within(sheet).getByTestId('create-tile-booking')).toHaveAttribute('href', '/calendar');
    // No Contacts page for this person, so no New contact tile.
    expect(within(sheet).queryByTestId('create-tile-contact')).toBeNull();
  });
});

describe('More', () => {
  it('lists the sidebar groups without the places already on the tab bar', () => {
    render(<MorePage />);
    expect(screen.getByRole('link', { name: /Approvals/ })).toHaveAttribute('href', '/admin/approvals');
    expect(screen.getByRole('link', { name: /Calendar/ })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^Dashboard/ })).toBeNull();
    expect(screen.queryByRole('link', { name: /^Inbox/ })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Work' })).toBeInTheDocument();
  });

  it('has a labelled Notifications row that opens the notifications card (WHIT-1078)', () => {
    render(<MorePage />);
    const row = screen.getByTestId('more-notifications');
    expect(row.textContent).toContain('Notifications');
    expect(row.getAttribute('href')).toBe('/settings?tab=preferences#notifications');
  });

  it('keeps appearance and signing out reachable on a phone (WHIT-827)', async () => {
    render(<MorePage />);
    await userEvent.click(screen.getByTestId('more-theme'));
    expect(mockToggle).toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'العربية' }));
    expect(mockSetLanguage).toHaveBeenCalledWith('ar');
    await userEvent.click(screen.getByTestId('more-sign-out'));
    expect(mockLogout).toHaveBeenCalled();
    expect(mockPush).toHaveBeenCalledWith('/login');
  });
});
