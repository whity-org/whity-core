'use client';

import { Suspense, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';

/**
 * Where Whity ID returns an operator who pressed "Connect to Whity store" (WHIT-1003). The code
 * and state go to this installation's API, signed in as the same operator, which finishes the
 * connection; then back to the store.
 */
function StoreConnectInner() {
  const t = useTranslation('admin');
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
          ? t('pluginStore.connect.denied', 'Connecting was cancelled in Whity ID.')
          : t('pluginStore.connect.failed', 'Whity ID did not connect this installation.'),
      );
      return;
    }

    void (async () => {
      const { error: completeError } = await api.POST('/api/v1/plugins/store/connect/complete', {
        body: { code, state },
      });
      if (completeError) {
        setError(completeError.error || t('pluginStore.connect.failed', 'Whity ID did not connect this installation.'));
        return;
      }
      router.replace('/admin/plugins/store');
    })();
  }, [params, router, t]);

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader title={t('pluginStore.connect.title', 'Whity store')} />
      {error ? (
        <div className="space-y-4">
          <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-destructive">
            {error}
          </p>
          <Button asChild variant="outline">
            <Link href="/admin/plugins/store">{t('pluginStore.connect.back', 'Back to the store')}</Link>
          </Button>
        </div>
      ) : (
        <div className="space-y-3" role="status" aria-live="polite">
          <p>{t('pluginStore.connect.finishing', 'Connecting this installation to the store…')}</p>
          <Skeleton className="h-10 w-64" />
        </div>
      )}
    </div>
  );
}

export default function StoreConnectPage() {
  return (
    <Suspense fallback={<Skeleton className="mx-auto h-64 max-w-3xl" />}>
      <StoreConnectInner />
    </Suspense>
  );
}
