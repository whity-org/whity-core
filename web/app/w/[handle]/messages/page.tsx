'use client';

/**
 * Messages (WHIT-1416, design section 41 §8.4, CA.16): the customer's
 * conversations with the workspace, newest first. With none yet, and the
 * workspace taking messages, the composer starts the first one here.
 */

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Card, CardContent } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { UserText } from '@/components/ui/user-text';
import { LoadState, Outcome, PageTitle, serverText, useAreaLoad } from '@/components/customer-area/area-parts';
import { MessageThread } from '@/components/customer-area/message-thread';

type Conversations = {
  can_start: boolean;
  conversations: { id: number; subject?: string | null; status: string; last_message_at: string }[];
};

export default function CustomerMessages() {
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const router = useRouter();
  const { handle, phase } = useCustomerArea();
  const business = phase.kind === 'ready' ? phase.area.workspace.name : '';
  const [state, retry] = useAreaLoad<Conversations>(() => api.GET('/api/v1/customer-area/{handle}/messages', { params: { path: { handle } } }), [handle]);

  const start = async (text: string): Promise<string | null> => {
    const { data, error, response } = await api.POST('/api/v1/customer-area/{handle}/messages', { params: { path: { handle } }, body: { message: text } });
    if (response.ok && data) {
      const id = (data as { data: { conversation_id: number } }).data.conversation_id;
      router.push(areaHref(handle, `messages/${id}`));
      return null;
    }
    return serverText(error) ?? t('messages.failed', 'Your message wasn’t sent. Try again.');
  };

  return (
    <div className="space-y-5">
      <PageTitle>{t('tab.messages', 'Messages')}</PageTitle>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && state.data.conversations.length > 0 && (
        <Card>
          <CardContent className="divide-y divide-border p-0">
            {state.data.conversations.map((c) => (
              <Link
                key={c.id}
                href={areaHref(handle, `messages/${c.id}`)}
                className="flex min-h-12 items-center gap-3 px-4 py-3 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                data-testid="customer-conversation-row"
              >
                <Icon name="comment" size={20} className="shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <UserText className="block truncate font-medium">{c.subject || t('messages.with', 'With {business}', { business })}</UserText>
                  <span className="text-sm text-muted-foreground">{dates.dateTime(c.last_message_at)}</span>
                </div>
                <Icon name="chevron-right" size={18} className="shrink-0 text-muted-foreground" />
              </Link>
            ))}
          </CardContent>
        </Card>
      )}
      {state.kind === 'ready' && state.data.conversations.length === 0 && state.data.can_start && (
        <MessageThread messages={[]} business={business} onSend={start} />
      )}
      {state.kind === 'ready' && state.data.conversations.length === 0 && !state.data.can_start && (
        <Outcome icon="comment" tone="muted" title={t('messages.none', 'No messages yet')} body={t('messages.noneBody', '{business} doesn’t take messages here yet.', { business })} />
      )}
    </div>
  );
}
