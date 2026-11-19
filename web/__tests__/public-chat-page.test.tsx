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

jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/fetch-like-api').fetchLikeClient());

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

  expect(await screen.findByText('Chat is closed right now')).toBeInTheDocument();
});

// WHIT-1298 (#2203): no new chat starts until the bot check is set up.
test('a bot check not set up says the workspace is still setting up, before anyone types', async () => {
  fetchMock.mockImplementation(() =>
    json(200, { data: { workspace_name: 'Example Bakery', greeting: 'Hi there', max_length: 4000, bot_check: null, bot_check_ready: false } })
  );
  await renderPage();

  expect(await screen.findByText('Example Bakery isn’t taking chats here yet')).toBeInTheDocument();
  expect(screen.queryByRole('textbox', { name: 'Your message' })).not.toBeInTheDocument();
});

test('a chat this browser already has carries on while the bot check is not set up', async () => {
  window.localStorage.setItem('whity.chat.bakery', 'tok-1');
  fetchMock.mockImplementation((url: string) =>
    url === '/api/v1/public/chat/bakery'
      ? json(200, { data: { workspace_name: 'Example Bakery', greeting: 'Hi there', max_length: 4000, bot_check: null, bot_check_ready: false } })
      : json(200, { data: [] })
  );
  await renderPage();

  expect(await screen.findByRole('textbox', { name: 'Your message' })).toBeInTheDocument();
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

test('too many messages says wait a minute and keeps what was written', async () => {
  fetchMock.mockImplementation((url: string) =>
    url === '/api/v1/public/chat/bakery'
      ? json(200, { data: { workspace_name: 'Example Bakery', greeting: null, max_length: 4000, bot_check: null } })
      : json(429, { error: 'Too many' })
  );
  await renderPage();

  const box = await screen.findByRole('textbox', { name: 'Your message' });
  await userEvent.type(box, 'Hello again');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));

  expect(await screen.findByText('Wait a minute')).toBeInTheDocument();
  expect(box).toHaveValue('Hello again');
});

test('a chat the workspace closed offers a new one', async () => {
  window.localStorage.setItem('whity.chat.bakery', 'tok-old');
  fetchMock.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/public/chat/bakery') {
      return json(200, { data: { workspace_name: 'Example Bakery', greeting: null, max_length: 4000, bot_check: null, brand: null } });
    }
    return init?.method === 'POST' ? json(401, { error: 'Ended' }) : json(200, { data: [] });
  });
  await renderPage();

  await userEvent.type(await screen.findByRole('textbox', { name: 'Your message' }), 'Still there?');
  await userEvent.click(screen.getByRole('button', { name: 'Send' }));

  expect(await screen.findByText('Example Bakery closed this chat. Your messages are kept.')).toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Start a new chat' }));
  expect(await screen.findByLabelText('Your name (optional)')).toBeInTheDocument();
  expect(window.localStorage.getItem('whity.chat.bakery')).toBeNull();
});
