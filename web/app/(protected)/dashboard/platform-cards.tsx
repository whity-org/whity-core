'use client';

/**
 * Platform cards for operators: the headline numbers from `/api/admin/stats`.
 * Shown only to people who hold `stats:read`; the full statistics stay on
 * System (H.3).
 */

import Link from 'next/link';
import { Card } from '@whity/ui/card';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';

interface Totals {
  users: number;
  tenants: number;
  roles: number;
}

export function PlatformCards() {
  const t = useTranslation('common');
  const { apiClient } = useAuth();

  const stats = useFetch<Totals>(async () => {
    const response = await apiClient('/api/v1/admin/stats');
    if (!response.ok) throw new Error(t('dashboard.platform.error', 'The platform numbers could not be loaded.'));
    const body = (await response.json()) as { data?: { totals?: Totals }; totals?: Totals };
    const totals = body.data?.totals ?? body.totals;
    if (totals === undefined) throw new Error(t('dashboard.platform.error', 'The platform numbers could not be loaded.'));
    return totals;
  }, [apiClient]);

  const cards: Array<{ key: string; label: string; value: number | undefined }> = [
    { key: 'tenants', label: t('dashboard.platform.tenants', 'Workspaces'), value: stats.data?.tenants },
    { key: 'users', label: t('dashboard.platform.users', 'People'), value: stats.data?.users },
    { key: 'roles', label: t('dashboard.platform.roles', 'Roles'), value: stats.data?.roles },
  ];

  return (
    <section aria-labelledby="dashboard-platform" className="grid gap-3">
      <div className="flex items-baseline justify-between gap-3">
        <h2 id="dashboard-platform" className="text-lg font-semibold">
          {t('dashboard.platform.title', 'Platform')}
        </h2>
        <Link href="/admin/stats" className="text-sm font-medium text-primary underline-offset-2 hover:underline">
          {t('dashboard.platform.open', 'Open System')}
        </Link>
      </div>
      {stats.error !== null ? (
        <AreaError
          message={stats.error}
          retryLabel={t('dashboard.tryAgain', 'Try again')}
          onRetry={() => stats.refetch()}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-3">
          {cards.map((card) => (
            <Card key={card.key} className="gap-1 p-4">
              <p className="text-sm text-muted-foreground">{card.label}</p>
              <p className="text-2xl font-semibold tabular-nums">{card.value ?? '—'}</p>
            </Card>
          ))}
        </div>
      )}
    </section>
  );
}
