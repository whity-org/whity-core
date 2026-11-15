'use client';

/**
 * A customer's side of a conversation (ADR 0016 §5, card `TplChatBox`): the
 * messages, newest at the bottom, and a box to write in. Used by the public
 * chat page (visitors, through a chat token) and by a signed-in customer's
 * Messages page.
 *
 * Bubbles follow the staff inbox: the customer at the start, the workspace at
 * the end on the tint, with who and when under each. The first message of a
 * visitor's chat is a small form (labelled fields, a full-width Send); after
 * that the box is one line with a send button.
 *
 * It polls rather than holding a connection: every 5 seconds while something
 * is happening, slowing to 30 seconds when nothing is, and not at all while
 * the tab is hidden.
 */

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
import type { CustomerMessage } from '@/lib/messages-wire';

const FAST_MS = 5000;
const SLOW_MS = 30000;

/** How the thread reaches the server. Errors are thrown as plain-words messages. */
export interface ChatTransport {
  /** Whether there is a conversation to read yet. */
  started: () => boolean;
  /** Messages after the given id (all when null). */
  load: (afterId: number | null) => Promise<CustomerMessage[]>;
  /** Write a message; returns what was filed (null when nothing visible came back). */
  send: (text: string) => Promise<CustomerMessage | null>;
}

/** A send that was refused because of too many messages: shown as "Wait a minute", the words kept. */
export class TooManyMessages extends Error {}

export function ChatThread({
  transport,
  workspaceName,
  greeting,
  maxLength,
  beforeComposer,
  canSend = true,
  firstMessage = false,
  header,
  footer,
  className,
}: {
  transport: ChatTransport;
  workspaceName: string;
  greeting: string | null;
  maxLength: number;
  /** Extra fields shown above the message box (name, email, bot check). */
  beforeComposer?: ReactNode;
  canSend?: boolean;
  /** The visitor's first message: a labelled box and a full-width Send. */
  firstMessage?: boolean;
  /** The workspace's logo, name and when it replies, above the thread. */
  header?: ReactNode;
  /** One small line under the box ("kept by", "signed in as"). */
  footer?: ReactNode;
  className?: string;
}) {
  const t = useTranslation('common');
  const { time } = useDateDisplay();
  const [messages, setMessages] = useState<CustomerMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<{ text: string; tooMany: boolean } | null>(null);
  const lastId = useRef<number | null>(null);
  const delay = useRef(FAST_MS);
  const bottom = useRef<HTMLDivElement>(null);

  const merge = useCallback((incoming: CustomerMessage[]) => {
    if (incoming.length === 0) return;
    setMessages((current) => {
      const seen = new Set(current.map((m) => m.id));
      const next = [...current, ...incoming.filter((m) => !seen.has(m.id))].sort((a, b) => a.id - b.id);
      lastId.current = next.length > 0 ? next[next.length - 1].id : null;
      return next;
    });
  }, []);

  const poll = useCallback(async (): Promise<boolean> => {
    if (!transport.started()) return false;
    try {
      const incoming = await transport.load(lastId.current);
      merge(incoming);
      return incoming.length > 0;
    } catch {
      return false;
    }
  }, [transport, merge]);

  useEffect(() => {
    let timer: number | undefined;
    let stopped = false;
    const tick = async (): Promise<void> => {
      if (!document.hidden) {
        const fresh = await poll();
        delay.current = fresh ? FAST_MS : Math.min(SLOW_MS, delay.current * 1.5);
      }
      if (!stopped) timer = window.setTimeout(() => void tick(), delay.current);
    };
    void tick();
    return () => {
      stopped = true;
      window.clearTimeout(timer);
    };
  }, [poll]);

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' });
  }, [messages.length]);

  const send = async (): Promise<void> => {
    const text = draft.trim();
    if (text === '' || sending || !canSend) return;
    setSending(true);
    setError(null);
    try {
      const filed = await transport.send(text);
      if (filed) merge([filed]);
      setDraft('');
      delay.current = FAST_MS;
    } catch (e) {
      setError({
        text: e instanceof Error ? e.message : t('chat.error.send', 'Your message was not sent. Please try again.'),
        tooMany: e instanceof TooManyMessages,
      });
    } finally {
      setSending(false);
    }
  };

  const meta = (when: string | null, who: string): string => [when, who].filter(Boolean).join(' · ');
  const disabled = sending || draft.trim() === '' || !canSend;

  return (
    <div className={cn('flex min-h-0 flex-col', className)}>
      {header}
      <ol
        className="min-h-0 flex-1 space-y-3.5 overflow-y-auto bg-background p-4"
        aria-live="polite"
        aria-label={t('chat.thread', 'Conversation')}
      >
        {(greeting || messages.length === 0) && (
          <li className="ms-auto grid max-w-[85%] justify-items-end gap-1">
            <p dir="auto" className="rounded-[14px] bg-primary-tint px-3.5 py-2.5 text-start text-foreground">
              {greeting || t('chat.greeting', 'Hello! Write to us and we will reply here.')}
            </p>
            <span className="text-xs text-muted-foreground">{workspaceName}</span>
          </li>
        )}
        {messages.map((message) => {
          const mine = message.from === 'you';
          return (
            <li key={message.id} className={cn('grid max-w-[85%] gap-1', mine ? 'justify-items-start' : 'ms-auto justify-items-end')}>
              <p
                dir="auto"
                className={cn(
                  'whitespace-pre-wrap break-words rounded-[14px] border px-3.5 py-2.5 text-start text-foreground',
                  mine ? 'border-border bg-card' : 'border-transparent bg-primary-tint'
                )}
              >
                {message.body}
              </p>
              <span className="text-xs text-muted-foreground">
                {meta(time(message.created_at), mine ? t('chat.you', 'You') : message.author_name || workspaceName)}
              </span>
            </li>
          );
        })}
        <div ref={bottom} />
      </ol>

      <form
        className="space-y-3 border-t border-border bg-card p-4"
        onSubmit={(event) => {
          event.preventDefault();
          void send();
        }}
      >
        {beforeComposer}
        {error &&
          (error.tooMany ? (
            <Alert variant="warning" role="alert">
              <Icon name="alert" size={16} />
              <AlertTitle>{t('chat.error.waitTitle', 'Wait a minute')}</AlertTitle>
              <AlertDescription>{error.text}</AlertDescription>
            </Alert>
          ) : (
            <p role="alert" className="text-sm text-error-text">
              {error.text}
            </p>
          ))}
        {firstMessage ? (
          <>
            <div className="space-y-1.5">
              <label htmlFor="chat-message" className="text-sm font-medium text-foreground">
                {t('chat.messageLabel', 'Message')}
              </label>
              <Textarea
                id="chat-message"
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                rows={3}
                maxLength={maxLength}
                aria-label={t('chat.message', 'Your message')}
              />
            </div>
            <Button type="submit" className="w-full" disabled={disabled}>
              <Icon name="send" size={16} className="rtl:-scale-x-100" />
              {t('chat.send', 'Send')}
            </Button>
          </>
        ) : (
          <div className="flex items-end gap-2">
            <Textarea
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              rows={1}
              maxLength={maxLength}
              aria-label={t('chat.message', 'Your message')}
              placeholder={t('chat.placeholder', 'Write a message…')}
              className="min-h-10 flex-1 resize-none"
              onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                  event.preventDefault();
                  void send();
                }
              }}
            />
            <Button type="submit" variant="ghost" size="icon" disabled={disabled} aria-label={t('chat.send', 'Send')} title={t('chat.send', 'Send')}>
              <Icon name="send" size={18} className="rtl:-scale-x-100" />
            </Button>
          </div>
        )}
        {footer && <p className="text-center text-xs text-muted-foreground">{footer}</p>}
      </form>
    </div>
  );
}

/** The chat box's head: the workspace's logo and name, and a line under it. */
export function ChatHeader({ name, logo, line }: { name: string; logo: ReactNode; line?: string | null }) {
  return (
    <header className="flex items-center gap-3 border-b border-border bg-card px-4 py-4">
      {logo}
      <div className="min-w-0">
        <h1 className="truncate text-base font-semibold text-foreground">{name}</h1>
        {line && <p className="truncate text-sm text-muted-foreground">{line}</p>}
      </div>
    </header>
  );
}
