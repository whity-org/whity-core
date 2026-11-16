'use client';

import { Suspense, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { api } from '@/lib/api/client';

/**
 * Where Taskaat returns a person who pressed "Connect Taskaat" (WHIT-962). The code and state go
 * to this workspace's API, signed in as the same person, which finishes the connection; then
 * back to Tasks.
 */
function TaskaatConnectInner() {
  const t = useTranslation('tasks');
  const router = useRouter();
  const params = useSearchParams();
  const [error, setError] = useState<string | null>(null);
  const sent = useRef(false);

  useEffect(() => {
    if (sent.current) return;
    sent.current = true;

    const code = params.get('code');
    const state = params.get('state');
    const refused = params.get('error');

    if (refused || !code || !state) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- runs once, on arrival
      setError(
        refused === 'access_denied'
          ? t('return.denied', 'Connecting was cancelled in Taskaat.')
          : t('return.failed', 'Taskaat did not connect this workspace.'),
      );
      return;
    }

    void (async () => {
      const { error: completeError } = await api.POST('/api/v1/taskaat/connect/complete', {
        body: { code, state },
      });
      if (completeError) {
        setError(completeError.error || t('return.failed', 'Taskaat did not connect this workspace.'));
        return;
      }
      router.replace('/tasks');
    })();
  }, [params, router, t]);

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader title={t('title', 'Tasks')} />
      {error ? (
        <div className="space-y-4">
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
          <Button asChild variant="outline">
            <Link href="/tasks">{t('return.back', 'Back to Tasks')}</Link>
          </Button>
        </div>
      ) : (
        <div className="space-y-3" role="status" aria-live="polite">
          <p>{t('return.finishing', 'Connecting this workspace to Taskaat…')}</p>
          <Skeleton className="h-10 w-64" />
        </div>
      )}
    </div>
  );
}

export default function TaskaatConnectPage() {
  return (
    <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-3xl" />}>
      <TaskaatConnectInner />
    </Suspense>
  );
}
