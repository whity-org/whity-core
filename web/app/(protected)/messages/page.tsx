'use client';

/**
 * A signed-in customer's conversation with the workspace (ADR 0016 §5, card
 * `TplChatBox`, signed in: the thread and the box, their name at the foot):
 * `/messages`, shown in their menu when the workspace has web chat on or they
 * already have a conversation. Ownership is the whole check, server side.
 *
 * One thread: writing continues the customer's open chat, or starts one.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { ChatHeader, ChatThread, type ChatTransport } from '@/components/messages/chat-thread';
import { useAuth } from '@/lib/auth-context';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useBranding } from '@/lib/branding-context';
import type { CustomerMessage } from '@/lib/messages-wire';

interface MyConversation {
  id: number;
  status: string;
}

export default function MyMessagesPage() {
  const t = useTranslation('common');
  const branding = useBranding();
  const { user } = useAuth();
  const me = user?.display_name || user?.email || '';
  const [conversationId, setConversationId] = useState<number | null>(null);
  const known = useRef<number | null>(null);

  const list = useFetch<MyConversation[]>(async () => {
    const { data: body, response } = await api.GET('/api/v1/me/messages');
    if (!response.ok) throw new Error(t('chat.error.load', 'The chat could not be loaded. Refresh the page to try again.'));
    return (body as unknown as { data: MyConversation[] }).data;
  }, []);

  const current = conversationId ?? list.data?.find((c) => c.status !== 'closed')?.id ?? list.data?.[0]?.id ?? null;
  useEffect(() => {
    known.current = current;
  }, [current]);

  const plainError = useCallback(
    (response: Response, errorBody: unknown): Error => {
      const body = (errorBody ?? null) as { error?: string } | null;
      return new Error(response.status === 422 && body?.error ? body.error : t('chat.error.send', 'Your message was not sent. Please try again.'));
    },
    [t]
  );

  const transport = useMemo<ChatTransport>(
    () => ({
      started: () => known.current !== null,
      load: async (afterId) => {
        const { data: body, error, response } = await api.GET('/api/v1/me/messages/{id}', {
          params: { path: { id: known.current as number }, query: afterId !== null ? { after: afterId } : {} },
        });
        if (!response.ok) throw plainError(response, error);
        return (body as unknown as { data: { messages: CustomerMessage[] } }).data.messages;
      },
      send: async (text) => {
        const sent =
          known.current === null
            ? await api.POST('/api/v1/me/messages', { body: { message: text } as never })
            : await api.POST('/api/v1/me/messages/{id}', {
                params: { path: { id: known.current } },
                body: { message: text } as never,
              });
        const response = sent.response;
        if (!response.ok) throw plainError(response, sent.error);
        const body = (sent.data as unknown) as { data: { conversation_id: number; message: CustomerMessage } | CustomerMessage | null };
        if (body.data && 'conversation_id' in body.data) {
          setConversationId(body.data.conversation_id);
          known.current = body.data.conversation_id;
          return body.data.message;
        }
        return body.data;
      },
    }),
    [plainError]
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
        <section className="mx-auto max-w-2xl overflow-hidden rounded-card border border-border bg-card">
          <ChatThread
            key={current ?? 'new'}
            className="h-[32rem]"
            transport={transport}
            workspaceName={workspaceName}
            greeting={null}
            maxLength={4000}
            header={<ChatHeader name={workspaceName} logo={<TenantLogo name={workspaceName} src={branding.logoSquareUrl} size={40} showName={false} />} />}
            footer={me ? t('chat.signedInAs', 'Signed in as {name}', { name: me }) : undefined}
          />
        </section>
      )}
    </div>
  );
}
