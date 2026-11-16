'use client';

/**
 * My settings › Language and display › Chat (UC.7): bubbles or compact rows,
 * with a live preview. Each person chooses for themselves; bubbles by default.
 */

import { useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { apiFetch } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { cn } from '@/lib/utils';
import type { ChatLayout, ChatMessage } from '@/lib/chat-wire';
import { chatApi, chatErrorText } from './chat-api';
import { MessageItem, type MessageActions } from './message-parts';

const noop = () => undefined;
const actions: MessageActions = {
  onReply: noop,
  onThread: noop,
  onReact: noop,
  onPin: noop,
  onCopy: noop,
  onEdit: noop,
  onDelete: noop,
  onQuote: noop,
};

function sample(id: number, author: { profile_id: number; name: string }, body: string): ChatMessage {
  return {
    id,
    chat_id: 0,
    kind: 'message',
    body,
    author: { type: 'person', ...author },
    mentions: [],
    status: 'posted',
    waiting_for: null,
    can_decide: false,
    proposal: null,
    event: null,
    created_at: null,
    reply_to: null,
    thread_root_id: null,
    in_chat: true,
    thread: null,
    reactions: [],
    attachments: [],
    block: null,
    pinned: false,
    edited: false,
    deleted: false,
    can_edit: false,
    can_delete: false,
  };
}

export function ChatLayoutSetting() {
  const t = useTranslation('chat');
  const { addToast } = useToast();
  const api = useMemo(() => chatApi(apiFetch), []);
  const [layout, setLayout] = useState<ChatLayout | null>(null);

  useEffect(() => {
    let live = true;
    api.layout().then(
      (pref) => live && setLayout(pref.layout),
      () => live && setLayout('bubbles')
    );
    return () => {
      live = false;
    };
  }, [api]);

  const choose = async (next: ChatLayout) => {
    const was = layout;
    setLayout(next);
    try {
      await api.setLayout(next);
    } catch (e) {
      setLayout(was);
      addToast(chatErrorText(e, t('layout.error', 'Your choice was not saved. Please try again.')), 'error');
    }
  };

  const shown = layout ?? 'bubbles';
  const messages = [
    sample(1, { profile_id: -1, name: t('layout.sampleName', 'Awss Haddad') }, t('layout.sampleTheirs', 'Oven 2 is fixed.')),
    sample(2, { profile_id: -2, name: t('layout.you', 'You') }, t('layout.sampleMine', 'Thank you! I will tell the team.')),
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('layout.title', 'Chat')}</CardTitle>
        <CardDescription>{t('layout.description', 'How messages look in your chats. Only you see this choice.')}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <div role="radiogroup" aria-label={t('layout.label', 'Messages as')} className="grid max-w-md grid-cols-2 gap-2">
          {(['bubbles', 'rows'] as const).map((value) => (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={shown === value}
              disabled={layout === null}
              onClick={() => void choose(value)}
              className={cn(
                'grid h-auto gap-0.5 rounded-control border px-3 py-2 text-start',
                shown === value ? 'border-primary bg-primary-tint' : 'border-border hover:bg-muted'
              )}
            >
              <span className="font-medium">{value === 'bubbles' ? t('layout.bubbles', 'Bubbles') : t('layout.rows', 'Compact rows')}</span>
              <span className="text-sm text-muted-foreground">
                {value === 'bubbles' ? t('layout.bubblesHint', 'Yours on one side, theirs on the other') : t('layout.rowsHint', 'Picture, name and time, one under another')}
              </span>
            </button>
          ))}
        </div>
        <div aria-hidden="true" className="pointer-events-none grid max-w-md gap-2 rounded-card border border-border bg-muted/40 p-3">
          {messages.map((m) => (
            <MessageItem key={m.id} t={t} message={m} layout={shown} myId={-2} readMarks={[]} actions={actions} />
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
