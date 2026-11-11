'use client';

/**
 * A customer's side of a conversation (ADR 0016 §5): the messages, newest at
 * the bottom, and a box to write in. Used by the public chat page (visitors,
 * through a chat token) and by a signed-in customer's Messages page.
 *
 * It polls rather than holding a connection: every 5 seconds while something
 * is happening, slowing to 30 seconds when nothing is, and not at all while
 * the tab is hidden.
 */

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Button } from '@whity/ui/button';
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

export function ChatThread({
  transport,
  workspaceName,
  greeting,
  maxLength,
  beforeComposer,
  canSend = true,
  className,
}: {
  transport: ChatTransport;
  workspaceName: string;
  greeting: string | null;
  maxLength: number;
  /** Extra fields shown above the message box (name, email, bot check). */
  beforeComposer?: ReactNode;
  canSend?: boolean;
  className?: string;
}) {
  const t = useTranslation('common');
  const { time } = useDateDisplay();
  const [messages, setMessages] = useState<CustomerMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
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
      setError(e instanceof Error ? e.message : t('chat.error.send', 'Your message was not sent. Please try again.'));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className={cn('flex min-h-0 flex-col', className)}>
      <ol className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite" aria-label={t('chat.thread', 'Conversation')}>
        <li className="flex justify-start">
          <p dir="auto" className="max-w-[85%] rounded-card border border-border bg-muted px-3 py-2 text-start text-foreground">
            {greeting || t('chat.greeting', 'Hello! Write to us and we will reply here.')}
          </p>
        </li>
        {messages.map((message) => {
          const mine = message.from === 'you';
          return (
            <li key={message.id} className={cn('flex', mine ? 'justify-end' : 'justify-start')}>
              <div className={cn('max-w-[85%] rounded-card border px-3 py-2', mine ? 'border-border bg-card' : 'border-border bg-muted')}>
                <p className="mb-1 text-xs text-muted-foreground">
                  {mine ? t('chat.you', 'You') : message.author_name || workspaceName}
                  {time(message.created_at) && <span className="ms-2">{time(message.created_at)}</span>}
                </p>
                <p dir="auto" className="whitespace-pre-wrap break-words text-start text-foreground">{message.body}</p>
              </div>
            </li>
          );
        })}
        <div ref={bottom} />
      </ol>

      <form
        className="space-y-2 border-t border-border p-3"
        onSubmit={(event) => {
          event.preventDefault();
          void send();
        }}
      >
        {beforeComposer}
        <Textarea
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          rows={2}
          maxLength={maxLength}
          aria-label={t('chat.message', 'Your message')}
          placeholder={t('chat.placeholder', 'Write your message')}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault();
              void send();
            }
          }}
        />
        {error && (
          <p role="alert" className="text-sm text-error-text">
            {error}
          </p>
        )}
        <div className="flex justify-end">
          <Button type="submit" disabled={sending || draft.trim() === '' || !canSend}>
            {t('chat.send', 'Send')}
          </Button>
        </div>
      </form>
    </div>
  );
}
