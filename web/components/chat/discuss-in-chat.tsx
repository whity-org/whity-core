'use client';

/**
 * "Discuss in chat" (C2, WHIT-1137, `TplChatParts`): one button on a document,
 * a form submission, a booking and a meeting. It finds the group chat linked
 * to that record or makes it, adds the person to it, and opens it. The chat
 * shows the record as a block at its top (`LinkedRecord`).
 *
 * A record's link id is chosen by its page, with the kind in front so a
 * booking 7 and a document 7 never meet: "booking:7", "document:12",
 * "form_submission:5". A meeting or a board uses its own link type and its
 * plain id.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { apiFetch } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import type { ChatLink } from '@/lib/chat-wire';
import { cn } from '@/lib/utils';
import { chatApi, ChatError } from './chat-api';

/** A record's link: `kind:id` for a record, the plain id for a meeting. */
export function recordLink(kind: 'booking' | 'document' | 'form_submission' | 'meeting', id: string | number, label: string): ChatLink {
  return kind === 'meeting'
    ? { type: 'meeting', id: String(id), label }
    : { type: 'record', id: `${kind}:${id}`, label };
}

/** Where the linked record is opened, or null for a link the web has no page for. */
export function linkedRecordHref(link: ChatLink): string | null {
  if (link.type === 'meeting') return `/calendar/meetings/${encodeURIComponent(link.id)}`;
  if (link.type !== 'record') return null;
  const [kind, ...rest] = link.id.split(':');
  const id = encodeURIComponent(rest.join(':'));
  if (id === '') return null;
  switch (kind) {
    case 'document':
      return `/admin/document-library/${id}`;
    case 'form_submission':
      return `/admin/routes/form_submission/${id}`;
    case 'booking':
      return '/calendar';
    default:
      return null;
  }
}

export function DiscussInChat({
  link,
  variant = 'outline',
  size,
  className,
}: {
  link: ChatLink;
  variant?: 'outline' | 'ghost';
  size?: 'sm' | 'default';
  className?: string;
}) {
  const t = useTranslation('chat');
  const router = useRouter();
  const { addToast } = useToast();
  const [busy, setBusy] = useState(false);
  const api = useMemo(() => chatApi(apiFetch), []);

  const open = async () => {
    setBusy(true);
    try {
      const { data } = await api.discuss(link);
      router.push(`/chat?c=${data.id}`);
    } catch (error) {
      const plain = error instanceof ChatError && error.message !== '' ? error.message : '';
      addToast(plain || t('discuss.error', 'The chat could not be opened. Try again.'), 'error');
      setBusy(false);
    }
  };

  return (
    <Button
      type="button"
      variant={variant}
      size={size}
      className={className}
      disabled={busy}
      onClick={() => void open()}
      data-testid="discuss-in-chat"
    >
      <Icon name="comment" />
      {t('discuss.button', 'Discuss in chat')}
    </Button>
  );
}

/** The record a chat is about, as a block at the top of the chat (`TplChatParts`). */
export function LinkedRecord({ link, className }: { link: ChatLink; className?: string }) {
  const t = useTranslation('chat');
  const href = linkedRecordHref(link);
  const kind =
    link.type === 'meeting'
      ? t('discuss.kind.meeting', 'Meeting')
      : link.type === 'board'
        ? t('discuss.kind.board', 'Board')
        : link.id.startsWith('booking:')
          ? t('discuss.kind.booking', 'Booking')
          : link.id.startsWith('document:')
            ? t('discuss.kind.document', 'Document')
            : link.id.startsWith('form_submission:')
              ? t('discuss.kind.submission', 'Form submission')
              : t('discuss.kind.record', 'Record');
  return (
    <div
      className={cn('flex min-h-12 items-center gap-3 rounded-card border border-border bg-card px-3 py-2', className)}
      data-testid="chat-linked-record"
    >
      <Icon name={link.type === 'meeting' ? 'calendar' : 'page'} />
      <span className="min-w-0 flex-1">
        <span className="block text-xs text-muted-foreground">{t('discuss.about', 'This chat is about')}</span>
        <span className="block truncate text-sm font-medium text-foreground">
          {kind} · {link.label}
        </span>
      </span>
      {href && (
        <Button asChild variant="outline" size="sm">
          <Link href={href}>{t('discuss.open', 'Open')}</Link>
        </Button>
      )}
    </div>
  );
}
