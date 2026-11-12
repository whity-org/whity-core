/**
 * WHIT-994 — /welcome, where an SSO sign-in that belongs to no workspace
 * lands: create one, see that a place is waiting, or learn that an invitation
 * is needed. Never an error code.
 */

import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockPush = jest.fn();
const mockRefreshAuth = jest.fn(async () => {});

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: mockPush, replace: mockPush }),
}));

jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ refreshAuth: mockRefreshAuth }),
}));

jest.mock('@/components/auth-shell', () => ({
  AuthShell: ({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) => (
    <main>
      <h1>{title}</h1>
      {subtitle ? <p>{subtitle}</p> : null}
      {children}
    </main>
  ),
}));

import WelcomePage from '@/app/welcome/page';

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

async function renderPage() {
  await act(async () => {
    render(<WelcomePage />);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  global.fetch = jest.fn();
});

test('offers to create a workspace, prefilled with their name, and goes straight in', async () => {
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(json(200, { data: { state: 'create', email: 'amro@example.test', suggested_name: 'Amro Saleh' } }))
    .mockResolvedValueOnce(json(200, { user: { id: 7, tenant_id: 42 } }));

  await renderPage();

  expect(await screen.findByText('Create your workspace')).toBeInTheDocument();
  expect(screen.getByTestId('welcome-email')).toHaveTextContent('amro@example.test');
  const name = screen.getByLabelText('Workspace name') as HTMLInputElement;
  expect(name.value).toBe('Amro Saleh');

  fireEvent.change(name, { target: { value: 'Example Bakery' } });
  fireEvent.click(screen.getByTestId('welcome-colour-b74c05'));
  await act(async () => {
    fireEvent.click(screen.getByTestId('welcome-create-submit'));
  });

  await waitFor(() => expect(mockPush).toHaveBeenCalledWith('/dashboard'));
  expect(mockRefreshAuth).toHaveBeenCalled();
  const [url, init] = (global.fetch as jest.Mock).mock.calls[1];
  expect(url).toBe('/api/v1/auth/sso/workspace');
  expect(init.method).toBe('POST');
  expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
  expect(JSON.parse(init.body)).toEqual({ name: 'Example Bakery', locale: 'en', main_color: '#b74c05' });
});

test('an empty name is caught before anything is sent', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce(
    json(200, { data: { state: 'create', email: 'amro@example.test', suggested_name: '' } })
  );

  await renderPage();
  await act(async () => {
    fireEvent.click(await screen.findByTestId('welcome-create-submit'));
  });

  expect(screen.getByText('Type a name for your workspace.')).toBeInTheDocument();
  expect(global.fetch).toHaveBeenCalledTimes(1);
});

test('with creating switched off, it says plainly that they need an invitation, and for which address', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce(json(200, { data: { state: 'needs_invite', email: 'awss@example.test' } }));

  await renderPage();

  expect(await screen.findByText('You need an invitation')).toBeInTheDocument();
  expect(screen.getByTestId('welcome-needs-invite')).toHaveTextContent('awss@example.test');
  expect(screen.queryByTestId('welcome-create-form')).not.toBeInTheDocument();
});

test('a place waiting somewhere is named, and no workspace is offered', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce(
    json(200, { data: { state: 'waiting', email: 'ahmad@example.test', workspaces: [{ name: 'Example Bakery', status: 'invited' }] } })
  );

  await renderPage();

  expect(await screen.findByText('Your place is waiting')).toBeInTheDocument();
  expect(screen.getByTestId('welcome-waiting')).toHaveTextContent('Example Bakery');
  expect(screen.queryByTestId('welcome-create-form')).not.toBeInTheDocument();
});

test('an expired sign-in asks them to sign in again', async () => {
  (global.fetch as jest.Mock).mockResolvedValueOnce(json(401, { error: 'expired' }));

  await renderPage();

  expect(await screen.findByTestId('welcome-expired')).toBeInTheDocument();
});

test('when something changed since sign-in, the page shows where things stand now', async () => {
  (global.fetch as jest.Mock)
    .mockResolvedValueOnce(json(200, { data: { state: 'create', email: 'amro@example.test', suggested_name: 'Amro Saleh' } }))
    .mockResolvedValueOnce(json(403, { error: 'no', details: { code: 'not_allowed' } }))
    .mockResolvedValueOnce(json(200, { data: { state: 'needs_invite', email: 'amro@example.test' } }));

  await renderPage();
  await act(async () => {
    fireEvent.click(await screen.findByTestId('welcome-create-submit'));
  });

  expect(await screen.findByTestId('welcome-needs-invite')).toBeInTheDocument();
  expect(mockPush).not.toHaveBeenCalled();
});
