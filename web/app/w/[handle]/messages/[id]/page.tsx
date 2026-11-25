'use client';

/**
 * One conversation (WHIT-1416, design section 41 §8.4): signed in, so there
 * is no chat token to lose and the whole history stays.
 */

import Link from 'next/link';
import { use } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { useTranslation } from '@whity/features/i18n';
import { LoadState, PageTitle, serverText, useAreaLoad } from '@/components/customer-area/area-parts';
import { MessageThread, type CustomerMessage } from '@/components/customer-area/message-thread';

type Conversation = { id: number; subject?: string | null; status: string; messages: CustomerMessage[] };

export default function CustomerConversation({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const conversationId = Number(id);
  const t = useTranslation('customer');
  const { handle, phase } = useCustomerArea();
  const business = phase.kind === 'ready' ? phase.area.workspace.name : '';
  const [state, retry] = useAreaLoad<Conversation>(
    () => api.GET('/api/v1/customer-area/{handle}/messages/{id}', { params: { path: { handle, id: conversationId } } }),
    [handle, conversationId],
  );

  const send = async (text: string): Promise<string | null> => {
    const { error, response } = await api.POST('/api/v1/customer-area/{handle}/messages/{id}', {
      params: { path: { handle, id: conversationId } },
      body: { message: text },
    });
    if (response.ok) {
      retry();
      return null;
    }
    return serverText(error) ?? t('messages.failed', 'Your message wasn’t sent. Try again.');
  };

  return (
    <div className="space-y-5">
      <Link href={areaHref(handle, 'messages')} className="text-sm text-muted-foreground hover:underline">
        {t('messages.back', 'All messages')}
      </Link>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && (
        <>
          <PageTitle>{state.data.subject || t('messages.with', 'With {business}', { business })}</PageTitle>
          <MessageThread messages={state.data.messages} business={business} onSend={send} />
        </>
      )}
    </div>
  );
}
