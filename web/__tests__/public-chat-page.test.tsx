/**
 * Messages (ADR 0016 §5): the public chat page.
 *
 * The first message starts a chat and keeps its token; later messages and
 * reads carry that token; a workspace with chat off says so; the hidden bot
 * trap is sent along so the server can drop a bot quietly.
 */

import React, { Suspense } from 'react';
import userEvent from '@testing-library/user-event';
import { act, render, screen, waitFor } from '@testing-library/react';

jest.mock('@whity/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, values?: Record<string, string | number>) =>
      Object.entries(values ?? {}).reduce((s, [k, v]) => s.replace(`{${k}}`, String(v)), fallback),
  useCurrentLanguage: () => ({ currentLanguage: 'en' }),
}));
jest.mock('@whity/features/datetime', () => ({ useDateDisplay: () => ({ time: () => null }) }));
jest.mock('@/lib/theme-mode-context', () => ({ useThemeMode: () => ({ resolved: 'light' }) }));
jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));
jest.mock('@/components/auth/turnstile-widget', () => ({ TurnstileWidget: () => <div data-testid="bot-check" /> }));

import PublicChatPage from '@/app/chat/[workspace]/page';

const fetchMock = jest.fn();

function json(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

async function renderPage(workspace = 'bakery') {
  const params = Promise.resolve({ workspace });
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <PublicChatPage params={params} />
      </Suspense>
    );
  });
}

beforeEach(() => {
  window.localStorage.clear();
  fetchMock.mockReset();
  global.fetch = fetchMock as unknown as typeof fetch;
});

test('chat off answers in plain words', async () => {
  fetchMock.mockImplementation(() => json(404, { error: 'Chat is not available here' }));
  await renderPage();

  expect(await screen.findByText('Chat is not available here.')).toBeInTheDocument();
});

test('the first message starts a chat, keeps the token, and later reads send it', async () => {
  fetchMock.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/public/chat/bakery') {
      return json(200, { data: { workspace_name: 'Example Bakery', greeting: 'Hi there', max_length: 4000, bot_check: null } });
    }
    if (url === '/api/v1/public/chat/bakery/conversations') {
      return json(201, { data: { token: 'tok-1', messages: [{ id: 5, from: 'you', author_name: null, body: JSON.parse(String(init?.body)).message, created_at: 'x' }] } });
    }
    return json(200, { data: [] });
  });
  await renderPage();

  expect(await screen.findByText('Hi there')).toBeInTheDocument();
  await userEvent.type(screen.getByRole('textbox', { name: 'Your message' }), 'Do you deliver?');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));

  expect(await screen.findByText('Do you deliver?')).toBeInTheDocument();
  const start = fetchMock.mock.calls.find(([url]) => url === '/api/v1/public/chat/bakery/conversations');
  expect(JSON.parse(String(start?.[1]?.body))).toMatchObject({ message: 'Do you deliver?', website: '' });
  expect(window.localStorage.getItem('whity.chat.bakery')).toBe('tok-1');

  await waitFor(
    () =>
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringMatching(/^\/api\/v1\/public\/chat\/bakery\/messages/),
        expect.objectContaining({ headers: { Authorization: 'Bearer tok-1' } })
      ),
    { timeout: 7000 }
  );
}, 10000);

test('the bot check has to be passed before the first message', async () => {
  fetchMock.mockImplementation(() =>
    json(200, { data: { workspace_name: 'Example Bakery', greeting: null, max_length: 4000, bot_check: { provider: 'turnstile', site_key: 'k' } } })
  );
  await renderPage();

  expect(await screen.findByTestId('bot-check')).toBeInTheDocument();
  await userEvent.type(screen.getByRole('textbox', { name: 'Your message' }), 'Hello');
  expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
});
