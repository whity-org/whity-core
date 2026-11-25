'use client';

/**
 * A conversation with the workspace, from the customer's side (WHIT-1416,
 * design section 41 §8.4, CA.16): their messages and the business's, oldest
 * first, and a composer at the bottom. On a phone it fills the screen with
 * the composer above the tab bar (PW.3).
 */

import { useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Textarea } from '@whity/ui/textarea';
import { cn } from '@whity/ui/utils';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { UserText } from '@/components/ui/user-text';

export type CustomerMessage = components['schemas']['CustomerAreaMessage'];

export function MessageThread({
  messages,
  business,
  onSend,
}: {
  messages: CustomerMessage[];
  business: string;
  onSend: (text: string) => Promise<string | null>;
}) {
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);

  useEffect(() => {
    end.current?.scrollIntoView?.({ block: 'end' });
  }, [messages.length]);

  const send = async () => {
    const body = text.trim();
    if (body === '') return;
    setBusy(true);
    setProblem(null);
    const failed = await onSend(body);
    setBusy(false);
    if (failed === null) setText('');
    else setProblem(failed);
  };

  return (
    <div className="space-y-4">
      <ol className="space-y-3" data-testid="customer-thread">
        {messages.length === 0 && <li className="text-muted-foreground">{t('messages.first', 'Write your first message to {business}.', { business })}</li>}
        {messages.map((m) => (
          <li key={m.id} className={cn('flex', m.from === 'you' ? 'justify-end' : 'justify-start')}>
            <div className={cn('max-w-[85%] rounded-lg px-4 py-2.5', m.from === 'you' ? 'bg-primary text-primary-foreground' : 'bg-muted')}>
              <p className={cn('mb-1 text-xs', m.from === 'you' ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
                {m.from === 'you' ? t('messages.you', 'You') : m.author_name || business} · {dates.dateTime(m.created_at)}
              </p>
              <UserText className="block whitespace-pre-line">{m.body}</UserText>
            </div>
          </li>
        ))}
      </ol>
      <div ref={end} />
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}
      <form
        className="space-y-2 max-sm:sticky max-sm:bottom-20 max-sm:bg-background max-sm:py-2"
        onSubmit={(e) => {
          e.preventDefault();
          void send();
        }}
      >
        <Textarea
          value={text}
          onChange={(e) => setText(e.target.value)}
          maxLength={4000}
          dir="auto"
          rows={3}
          aria-label={t('messages.write', 'Your message')}
          placeholder={t('messages.write', 'Your message')}
          data-testid="customer-message-input"
        />
        <Button type="submit" size="lg" disabled={busy || text.trim() === ''} data-testid="customer-message-send">
          {t('messages.send', 'Send')}
        </Button>
      </form>
    </div>
  );
}
