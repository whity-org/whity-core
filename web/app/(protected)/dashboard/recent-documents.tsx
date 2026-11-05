'use client';

/**
 * Your recent documents (TplHomeMember): the person's drafts and what they sent this
 * week, with status. Left out when they have none, and on any failure: it is a
 * convenience, and the home mustn't open on an error for it.
 */

import { useRouter } from 'next/navigation';
import { Badge } from '@whity/ui/badge';
import { Card } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { useCapabilitiesContext } from '@/lib/capabilities-context';
import { useFetch } from '@/hooks/useFetch';

interface RecentDocument {
  kind: 'document' | 'draft';
  id: number;
  title: string;
  status: 'with' | 'issued' | 'draft';
  waitingWith: string | null;
  at: string;
}

export function RecentDocuments() {
  const t = useTranslation('common');
  const dates = useDateDisplay();
  const { apiClient } = useAuth();
  const capabilities = useCapabilitiesContext();
  const router = useRouter();

  const recent = useFetch<RecentDocument[]>(async () => {
    const response = await apiClient('/api/v1/me/recent-documents');
    if (!response.ok) return [];
    return ((await response.json()) as { data?: RecentDocument[] }).data ?? [];
  }, [apiClient]);

  const rows = recent.data ?? [];
  if (rows.length === 0) return null;

  const badge = (row: RecentDocument) => {
    if (row.status === 'draft') return <Badge variant="secondary">{t('dashboard.recent.draft', 'Draft')}</Badge>;
    if (row.status === 'with') {
      return <Badge variant="info">{t('dashboard.recent.with', 'With {name}', { name: row.waitingWith ?? '' })}</Badge>;
    }
    return <Badge variant="success">{t('dashboard.recent.issued', 'Issued')}</Badge>;
  };

  // Rows open only for someone who may read them there; the list stays useful without.
  const href = (row: RecentDocument): string | null => {
    if (!capabilities.has('documents:read')) return null;
    return row.kind === 'draft' ? `/admin/document-sources/${row.id}` : `/admin/document-library/${row.id}`;
  };

  return (
    <Card className="gap-3 p-4">
      <div>
        <h2 className="text-lg font-semibold">{t('dashboard.recent.title', 'Your recent documents')}</h2>
        <p className="text-sm text-muted-foreground">{t('dashboard.recent.hint', 'Drafts and what you sent this week')}</p>
      </div>
      <ul className="grid gap-1">
        {rows.map((row) => {
          const target = href(row);
          const title = row.title || t('dashboard.recent.untitled', 'Untitled document');
          return (
            <li key={`${row.kind}-${row.id}`} className="flex flex-wrap items-center gap-3 py-2">
              {target !== null ? (
                <button
                  type="button"
                  onClick={() => router.push(target)}
                  className="min-w-0 flex-1 truncate text-start font-medium text-foreground underline-offset-2 hover:underline"
                >
                  {title}
                </button>
              ) : (
                <span className="min-w-0 flex-1 truncate font-medium text-foreground">{title}</span>
              )}
              {badge(row)}
              <span className="text-sm text-muted-foreground">{dates.date(row.at)}</span>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
