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
jest.mock('@/lib/navigation-context', () => ({ useNavigation: () => ({ items: mockNav }) }));
jest.mock('@/lib/theme-mode-context', () => ({ useThemeMode: () => ({ resolved: 'light', toggle: jest.fn() }) }));
jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string) => fallback,
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
}));

import { AppCommandPalette, OPEN_COMMAND_PALETTE_EVENT } from '@/components/shell/app-command-palette';
import { TopBar } from '@/components/shell/top-bar';

const fetchMock = jest.fn();

beforeEach(() => {
  push.mockReset();
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
