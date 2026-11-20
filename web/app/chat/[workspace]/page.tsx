'use client';

/**
 * A workspace's public chat (ADR 0016 §5, card `TplChatBox`): `/chat/{workspace}`.
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
import { Button } from '@whity/ui/button';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { Input } from '@/components/ui/input';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useThemeMode } from '@/lib/theme-mode-context';
import { PublicShell } from '@/components/auth/public-shell';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { ChatHeader, ChatThread, TooManyMessages, type ChatTransport } from '@/components/messages/chat-thread';
import type { CustomerMessage, PublicChatInfo } from '@/lib/messages-wire';
import { api } from '@/lib/api/client';

type Phase = 'loading' | 'closed' | 'notReady' | 'ready' | 'error';

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
  // The workspace closed the chat (401 on the token): say so, and offer a new one.
  const [ended, setEnded] = useState(false);
  const [threadKey, setThreadKey] = useState(0);

  useEffect(() => {
    api.GET('/api/v1/public/chat/{workspace}', { params: { path: { workspace } }, credentials: 'omit' })
      .then(({ data: body, response }) => {
        if (response.status === 404) {
          setPhase('closed');
          return;
        }
        if (!response.ok) throw new Error('load');
        const loaded = (body as unknown as { data: PublicChatInfo }).data;
        setInfo(loaded);
        // WHIT-1298 (#2203): no new chat can start until the bot check is set up.
        // A chat this browser already has carries on.
        setPhase(loaded.bot_check_ready === false && readToken(workspace) === null ? 'notReady' : 'ready');
      })
      .catch(() => setPhase('error'));
  }, [workspace]);

  const failure = useCallback(
    (response: Response, errorBody: unknown): Error => {
      if (response.status === 429) {
        return new TooManyMessages(t('chat.error.tooMany', 'You sent a lot of messages quickly. Try again in a minute; nothing you wrote is lost.'));
      }
      if (response.status === 401) {
        writeToken(workspace, null);
        setToken(null);
        setEnded(true);
        return new Error(t('chat.error.ended', 'This chat has ended. Send your message again to start a new one.'));
      }
      const body = (errorBody ?? null) as { error?: string } | null;
      return new Error(response.status === 422 && body?.error ? body.error : t('chat.error.send', 'Your message was not sent. Please try again.'));
    },
    [t, workspace]
  );

  const transport = useMemo<ChatTransport>(
    () => ({
      started: () => token !== null,
      load: async (afterId) => {
        const { data: body, error, response } = await api.GET('/api/v1/public/chat/{workspace}/messages', {
          params: { path: { workspace }, query: afterId !== null ? { after: afterId } : {} },
          headers: { Authorization: `Bearer ${token ?? ''}` },
          credentials: 'omit',
        });
        if (!response.ok) throw failure(response, error);
        return (body as unknown as { data: CustomerMessage[] }).data;
      },
      send: async (text) => {
        if (token === null) {
          const { data: body, error, response } = await api.POST('/api/v1/public/chat/{workspace}/conversations', {
            params: { path: { workspace } },
            body: { message: text, name, email, website, bot_token: botToken ?? '' } as never,
            credentials: 'omit',
          });
          if (!response.ok) {
            setResetKey((k) => k + 1);
            throw failure(response, error);
          }
          const data = (body as unknown as { data: { token: string; messages: CustomerMessage[] } }).data;
          writeToken(workspace, data.token);
          setToken(data.token);
          return data.messages[0] ?? null;
        }
        const { data: body, error, response } = await api.POST('/api/v1/public/chat/{workspace}/messages', {
          params: { path: { workspace } },
          headers: { Authorization: `Bearer ${token}` },
          credentials: 'omit',
          body: { message: text, website } as never,
        });
        if (!response.ok) throw failure(response, error);
        return (body as unknown as { data: CustomerMessage | null }).data;
      },
    }),
    [token, name, email, website, botToken, failure, workspace]
  );

  const firstMessage = token === null;
  // WHIT-1220: a check without a site key can never render, so it never blocks Send.
  const needsBotCheck = firstMessage && !!info?.bot_check?.site_key;
  const brand = info?.brand ?? null;
  const shownName = brand?.name || info?.workspace_name || '';

  return (
    <PublicShell brand={brand}>
      {phase === 'loading' && <div aria-busy="true" className="h-64 animate-pulse rounded-card bg-muted/40" />}
      {phase === 'closed' && (
        <EmptyFirstTime
          className="bg-card"
          icon="comment"
          title={t('chat.closed', 'Chat is closed right now')}
          description={t('chat.closedNext', 'Come back later, or reach the team another way.')}
        />
      )}
      {phase === 'notReady' && (
        <EmptyFirstTime
          className="bg-card"
          icon="comment"
          title={t('chat.notReady', '{name} isn’t taking chats here yet', { name: info?.brand?.name || info?.workspace_name || '' })}
          description={t('chat.notReadyNext', 'They’re still setting up this page. Come back later, or reach them another way.')}
        />
      )}
      {phase === 'error' && (
        <Alert variant="destructive">
          <AlertDescription>{t('chat.error.load', 'The chat could not be loaded. Refresh the page to try again.')}</AlertDescription>
        </Alert>
      )}
      {phase === 'ready' && info && (
        <section className="overflow-hidden rounded-card border border-border bg-card shadow-sm" aria-label={t('chat.title', 'Chat with {name}', { name: shownName })}>
          {ended ? (
            <div className="space-y-3 p-4">
              <p className="text-foreground">{t('chat.endedBy', '{name} closed this chat. Your messages are kept.', { name: shownName })}</p>
              <Button
                type="button"
                variant="outline"
                className="w-full"
                onClick={() => {
                  setEnded(false);
                  setThreadKey((k) => k + 1);
                }}
              >
                {t('chat.startNew', 'Start a new chat')}
              </Button>
            </div>
          ) : (
          <ChatThread
            key={threadKey}
            className="h-[36rem] max-h-[80dvh]"
            transport={transport}
            workspaceName={shownName}
            greeting={info.greeting}
            maxLength={info.max_length}
            canSend={!needsBotCheck || botToken !== null}
            firstMessage={firstMessage}
            header={
              <ChatHeader
                name={shownName}
                logo={<TenantLogo name={shownName} src={brand?.logo_square_url ?? null} size={40} showName={false} />}
              />
            }
            footer={t('chat.keptBy', 'Your messages are kept by {name}.', { name: shownName })}
            beforeComposer={
              firstMessage ? (
                <div className="space-y-3">
                  <div className="space-y-1.5">
                    <label htmlFor="chat-name" className="text-sm font-medium text-foreground">{t('chat.name', 'Your name (optional)')}</label>
                    <Input id="chat-name" value={name} onChange={(event) => setName(event.target.value)} maxLength={120} autoComplete="name" />
                  </div>
                  <div className="space-y-1.5">
                    <label htmlFor="chat-email" className="text-sm font-medium text-foreground">{t('chat.email', 'Email (optional)')}</label>
                    <Input
                      id="chat-email"
                      type="email"
                      dir="ltr"
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      placeholder="name@example.com"
                      autoComplete="email"
                      aria-describedby="chat-email-why"
                    />
                    <p id="chat-email-why" className="text-xs text-muted-foreground">{t('chat.emailWhy', 'So we can answer by email if you leave.')}</p>
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
          )}
        </section>
      )}
    </PublicShell>
  );
}
