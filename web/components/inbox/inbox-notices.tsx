'use client';

/**
 * The Inbox's Mentions and Updates tabs (M.43, merge map N2): the person's
 * notices, split by `GET /api/me/notifications?tab=`. Mentions are someone
 * naming them in a chat or a comment. Updates are everything else that isn't
 * already in Waiting for me, grouped by day. A row is marked read when it's
 * opened, and opens what it's about when the notice says where.
 */

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { EmptyState } from '@whity/ui/empty-state';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { Pagination } from '@whity/ui/pagination';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { cn } from '@/lib/utils';

export interface Notice {
  id: number;
  type: string;
  subject: string;
  body: string;
  data: Record<string, unknown>;
  read: boolean;
  created_at: string | null;
}

interface NoticePage {
  data: Notice[];
  pagination: { page: number; perPage: number; total: number; totalPages: number };
  unread: { mentions: number; updates: number };
}

const PER_PAGE = 25;

/** Where a notice leads, when it says: an in-app path only, never another site. */
export function noticeHref(notice: Notice): string | null {
  const url = notice.data?.url;
  if (typeof url === 'string') {
    try {
      const parsed = new URL(url, window.location.origin);
      if (parsed.origin === window.location.origin) return parsed.pathname + parsed.search + parsed.hash;
    } catch {
      return null;
    }
  }
  const conversation = notice.data?.chat_id ?? notice.data?.conversation_id;
  if (typeof conversation === 'number' || typeof conversation === 'string') return `/chat?c=${encodeURIComponent(String(conversation))}`;
  const document = notice.data?.document_id;
  if (typeof document === 'number' || typeof document === 'string') return `/admin/documents/${encodeURIComponent(String(document))}`;
  return null;
}

export function InboxNotices({ tab, onChanged }: { tab: 'mentions' | 'updates'; onChanged: () => void }) {
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const router = useRouter();
  const [page, setPage] = useState(1);

  const notices = useFetch<NoticePage>(async () => {
    const { data, response } = await api.GET('/api/v1/me/notifications', {
      params: { query: { tab, page, per_page: PER_PAGE } as never },
    });
    if (!response.ok) throw new Error(t('inbox.notices.error', 'These could not be loaded.'));
    return data as unknown as NoticePage;
  }, [tab, page]);

  const rows = useMemo(() => notices.data?.data ?? [], [notices.data]);

  /** Updates are grouped by day (M.43); mentions are one list. */
  const groups = useMemo(() => {
    if (tab === 'mentions') return [{ day: '', rows }];
    const out: { day: string; rows: Notice[] }[] = [];
    for (const row of rows) {
      const day = row.created_at ? (dates.date(row.created_at) ?? '') : '';
      const last = out[out.length - 1];
      if (last && last.day === day) last.rows.push(row);
      else out.push({ day, rows: [row] });
    }
    return out;
  }, [rows, tab, dates]);

  const open = async (notice: Notice) => {
    if (!notice.read) {
      await api.POST('/api/v1/me/notifications/{id}/read', { params: { path: { id: notice.id } } }).catch(() => null);
      notices.refetch();
      onChanged();
    }
    const href = noticeHref(notice);
    if (href !== null) router.push(href);
  };

  const markAll = async () => {
    await api.POST('/api/v1/me/notifications/read-all').catch(() => null);
    notices.refetch();
    onChanged();
  };

  if (notices.error !== null) {
    return <AreaError message={notices.error} retryLabel={t('inbox.tryAgain', 'Try again')} onRetry={() => notices.refetch()} />;
  }
  if (notices.loading && notices.data === null) {
    return (
      <LoadingRows
        label={t('inbox.notices.loading', 'Loading')}
        headers={[t('inbox.notices.what', 'What'), t('inbox.notices.when', 'When')]}
        rows={4}
      />
    );
  }
  if (rows.length === 0) {
    return tab === 'mentions' ? (
      <EmptyState
        title={t('inbox.mentions.empty.title', 'No one has mentioned you')}
        description={t('inbox.mentions.empty.description', 'When someone names you in a chat or a comment, it shows here.')}
      />
    ) : (
      <EmptyState
        title={t('inbox.updates.empty.title', 'No updates yet')}
        description={t('inbox.updates.empty.description', 'What happens to your work, such as decisions, signatures and changes, shows here.')}
      />
    );
  }

  const unread = tab === 'mentions' ? (notices.data?.unread.mentions ?? 0) : (notices.data?.unread.updates ?? 0);
  const pagination = notices.data?.pagination;

  return (
    <div className="space-y-4">
      {unread > 0 && (
        <div className="flex justify-end">
          <Button variant="outline" onClick={() => void markAll()}>
            {t('inbox.notices.markAll', 'Mark all as read')}
          </Button>
        </div>
      )}
      {groups.map((group) => (
        <section key={group.day || 'all'} aria-label={group.day || undefined} className="space-y-1">
          {group.day !== '' && <h2 className="px-1 text-sm font-semibold text-muted-foreground">{group.day}</h2>}
          <ul className="divide-y divide-border rounded-card border border-border bg-card">
            {group.rows.map((notice) => (
              <li key={notice.id}>
                <button
                  type="button"
                  onClick={() => void open(notice)}
                  className="flex min-h-12 w-full items-start gap-3 px-4 py-3 text-start hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  data-testid={`inbox-notice-${notice.id}`}
                >
                  <span
                    aria-hidden="true"
                    className={cn('mt-2 size-2 shrink-0 rounded-full', notice.read ? 'bg-transparent' : 'bg-primary')}
                  />
                  <span className="min-w-0 flex-1">
                    <span className={cn('block truncate', notice.read ? 'text-foreground' : 'font-semibold text-foreground')}>
                      {notice.subject}
                    </span>
                    {notice.body !== '' && <span className="line-clamp-2 block text-sm text-muted-foreground">{notice.body}</span>}
                  </span>
                  {notice.created_at && dates.dateTime(notice.created_at) !== null && (
                    <span className="shrink-0 text-sm text-muted-foreground">
                      {tab === 'updates' ? dates.time(notice.created_at) : dates.dateTime(notice.created_at)}
                    </span>
                  )}
                  {!notice.read && <span className="sr-only">{t('inbox.notices.unread', 'Unread')}</span>}
                </button>
              </li>
            ))}
          </ul>
        </section>
      ))}
      {pagination && pagination.totalPages > 1 && (
        <Pagination page={pagination.page} perPage={pagination.perPage} total={pagination.total} onPageChange={setPage} />
      )}
    </div>
  );
}
