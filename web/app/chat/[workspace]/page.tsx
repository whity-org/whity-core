'use client';

/**
 * A workspace's public chat (ADR 0016 §5): `/chat/{workspace}`.
 *
 * Outside `(protected)`: the visitor has no session. The workspace comes from
 * the slug; the server answers the same 404 for an unknown workspace and one
 * with web chat off. Starting a chat returns a token once, kept in this
 * browser, so coming back to the page continues the same conversation.
 *
 * The hidden `website` field is a trap for bots: people never see it.
 */

import { use, useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Input } from '@/components/ui/input';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useThemeMode } from '@/lib/theme-mode-context';
import { PublicShell } from '@/components/auth/public-shell';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { ChatThread, type ChatTransport } from '@/components/messages/chat-thread';
import type { CustomerMessage, PublicChatInfo } from '@/lib/messages-wire';

type Phase = 'loading' | 'closed' | 'ready' | 'error';

function storageKey(workspace: string): string {
  return `whity.chat.${workspace}`;
}

function readToken(workspace: string): string | null {
  try {
    return window.localStorage.getItem(storageKey(workspace));
  } catch {
    return null;
  }
}

function writeToken(workspace: string, token: string | null): void {
  try {
    if (token === null) window.localStorage.removeItem(storageKey(workspace));
    else window.localStorage.setItem(storageKey(workspace), token);
  } catch {
    // Private windows may refuse storage; the chat still works for this visit.
  }
}

export default function PublicChatPage({ params }: { params: Promise<{ workspace: string }> }) {
  const { workspace } = use(params);
  const t = useTranslation('common');
  const { currentLanguage } = useCurrentLanguage();
  const { resolved: theme } = useThemeMode();

  const [phase, setPhase] = useState<Phase>('loading');
  const [info, setInfo] = useState<PublicChatInfo | null>(null);
  const [token, setToken] = useState<string | null>(() => (typeof window === 'undefined' ? null : readToken(workspace)));
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [botToken, setBotToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);

  const base = `/api/v1/public/chat/${encodeURIComponent(workspace)}`;

  useEffect(() => {
    fetch(base, { credentials: 'omit' })
      .then(async (response) => {
        if (response.status === 404) {
          setPhase('closed');
          return;
        }
        if (!response.ok) throw new Error('load');
        setInfo(((await response.json()) as { data: PublicChatInfo }).data);
        setPhase('ready');
      })
      .catch(() => setPhase('error'));
  }, [base, workspace]);

  const failure = useCallback(
    async (response: Response): Promise<Error> => {
      if (response.status === 429) return new Error(t('chat.error.tooMany', 'Too many messages. Please wait a moment and try again.'));
      if (response.status === 401) {
        writeToken(workspace, null);
        setToken(null);
        return new Error(t('chat.error.ended', 'This chat has ended. Send your message again to start a new one.'));
      }
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      return new Error(response.status === 422 && body?.error ? body.error : t('chat.error.send', 'Your message was not sent. Please try again.'));
    },
    [t, workspace]
  );

  const transport = useMemo<ChatTransport>(
    () => ({
      started: () => token !== null,
      load: async (afterId) => {
        const response = await fetch(`${base}/messages${afterId !== null ? `?after=${afterId}` : ''}`, {
          headers: { Authorization: `Bearer ${token ?? ''}` },
          credentials: 'omit',
        });
        if (!response.ok) throw await failure(response);
        return ((await response.json()) as { data: CustomerMessage[] }).data;
      },
      send: async (text) => {
        if (token === null) {
          const response = await fetch(`${base}/conversations`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'omit',
            body: JSON.stringify({ message: text, name, email, website, bot_token: botToken ?? '' }),
          });
          if (!response.ok) {
            setResetKey((k) => k + 1);
            throw await failure(response);
          }
          const data = ((await response.json()) as { data: { token: string; messages: CustomerMessage[] } }).data;
          writeToken(workspace, data.token);
          setToken(data.token);
          return data.messages[0] ?? null;
        }
        const response = await fetch(`${base}/messages`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          credentials: 'omit',
          body: JSON.stringify({ message: text, website }),
        });
        if (!response.ok) throw await failure(response);
        return ((await response.json()) as { data: CustomerMessage | null }).data;
      },
    }),
    [base, token, name, email, website, botToken, failure, workspace]
  );

  const firstMessage = token === null;
  const needsBotCheck = firstMessage && info?.bot_check != null;

  return (
    <PublicShell>
      {phase === 'loading' && <div aria-busy="true" className="h-64 animate-pulse rounded-card bg-muted/40" />}
      {phase === 'closed' && (
        <Alert>
          <AlertDescription>{t('chat.closed', 'Chat is not available here.')}</AlertDescription>
        </Alert>
      )}
      {phase === 'error' && (
        <Alert variant="destructive">
          <AlertDescription>{t('chat.error.load', 'The chat could not be loaded. Refresh the page to try again.')}</AlertDescription>
        </Alert>
      )}
      {phase === 'ready' && info && (
        <section className="overflow-hidden rounded-card border border-border bg-card" aria-label={t('chat.title', 'Chat with {name}', { name: info.workspace_name })}>
          <h1 className="border-b border-border px-4 py-3 text-base font-semibold text-foreground">
            {t('chat.title', 'Chat with {name}', { name: info.workspace_name })}
          </h1>
          <ChatThread
            className="h-[28rem]"
            transport={transport}
            workspaceName={info.workspace_name}
            greeting={info.greeting}
            maxLength={info.max_length}
            canSend={!needsBotCheck || botToken !== null}
            beforeComposer={
              firstMessage ? (
                <div className="space-y-2">
                  <div className="grid gap-2 sm:grid-cols-2">
                    <Input
                      value={name}
                      onChange={(event) => setName(event.target.value)}
                      maxLength={120}
                      aria-label={t('chat.name', 'Your name (optional)')}
                      placeholder={t('chat.name', 'Your name (optional)')}
                    />
                    <Input
                      type="email"
                      dir="ltr"
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      aria-label={t('chat.email', 'Your email (optional)')}
                      placeholder={t('chat.email', 'Your email (optional)')}
                    />
                  </div>
                  {/* Clipped rather than moved off-screen, so it never widens the page in either direction. */}
                  <div aria-hidden="true" className="sr-only">
                    <input
                      type="text"
                      name="website"
                      tabIndex={-1}
                      autoComplete="off"
                      value={website}
                      onChange={(event) => setWebsite(event.target.value)}
                    />
                  </div>
                  {needsBotCheck && info.bot_check && (
                    <TurnstileWidget
                      siteKey={info.bot_check.site_key}
                      language={currentLanguage ?? 'en'}
                      theme={theme === 'dark' ? 'dark' : 'light'}
                      resetKey={resetKey}
                      onToken={setBotToken}
                      onError={() => setBotToken(null)}
                    />
                  )}
                </div>
              ) : null
            }
          />
        </section>
      )}
    </PublicShell>
  );
}
