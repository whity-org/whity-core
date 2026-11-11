'use client';

/**
 * A signed-in customer's conversation with the workspace (ADR 0016 §5):
 * `/messages`, shown in their menu when the workspace has web chat on or they
 * already have a conversation. Ownership is the whole check, server side.
 *
 * One thread: writing continues the customer's open chat, or starts one.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { ChatThread, type ChatTransport } from '@/components/messages/chat-thread';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useBranding } from '@/lib/branding-context';
import type { CustomerMessage } from '@/lib/messages-wire';

interface MyConversation {
  id: number;
  status: string;
}

export default function MyMessagesPage() {
  const t = useTranslation('common');
  const { apiClient } = useAuth();
  const branding = useBranding();
  const [conversationId, setConversationId] = useState<number | null>(null);
  const known = useRef<number | null>(null);

  const list = useFetch<MyConversation[]>(async () => {
    const response = await apiClient('/api/v1/me/messages');
    if (!response.ok) throw new Error(t('chat.error.load', 'The chat could not be loaded. Refresh the page to try again.'));
    return ((await response.json()) as { data: MyConversation[] }).data;
  }, [apiClient]);

  const current = conversationId ?? list.data?.find((c) => c.status !== 'closed')?.id ?? list.data?.[0]?.id ?? null;
  useEffect(() => {
    known.current = current;
  }, [current]);

  const plainError = useCallback(
    async (response: Response): Promise<Error> => {
      const body = (await response.json().catch(() => null)) as { error?: string } | null;
      return new Error(response.status === 422 && body?.error ? body.error : t('chat.error.send', 'Your message was not sent. Please try again.'));
    },
    [t]
  );

  const transport = useMemo<ChatTransport>(
    () => ({
      started: () => known.current !== null,
      load: async (afterId) => {
        const response = await apiClient(`/api/v1/me/messages/${known.current}${afterId !== null ? `?after=${afterId}` : ''}`);
        if (!response.ok) throw await plainError(response);
        return ((await response.json()) as { data: { messages: CustomerMessage[] } }).data.messages;
      },
      send: async (text) => {
        const path = known.current === null ? '/api/v1/me/messages' : `/api/v1/me/messages/${known.current}`;
        const response = await apiClient(path, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: text }),
        });
        if (!response.ok) throw await plainError(response);
        const body = (await response.json()) as { data: { conversation_id: number; message: CustomerMessage } | CustomerMessage | null };
        if (body.data && 'conversation_id' in body.data) {
          setConversationId(body.data.conversation_id);
          known.current = body.data.conversation_id;
          return body.data.message;
        }
        return body.data;
      },
    }),
    [apiClient, plainError]
  );

  const workspaceName = branding.siteName || t('chat.workspace', 'The team');

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('chat.mine.title', 'Messages')}
        description={t('chat.mine.description', 'Write to {name}. Replies appear here.', { name: workspaceName })}
      />
      {list.error ? (
        <AreaError
          message={t('chat.error.load', 'The chat could not be loaded. Refresh the page to try again.')}
          retryLabel={t('chat.retry', 'Try again')}
          onRetry={() => list.refetch()}
        />
      ) : list.loading ? (
        <div aria-busy="true" className="h-64 animate-pulse rounded-card bg-muted/40" />
      ) : (
        <section className="overflow-hidden rounded-card border border-border bg-card">
          <ChatThread
            key={current ?? 'new'}
            className="h-[32rem]"
            transport={transport}
            workspaceName={workspaceName}
            greeting={null}
            maxLength={4000}
          />
        </section>
      )}
    </div>
  );
}
