'use client';

/**
 * Account (WHIT-1416, design section 41 §8.5): who they are here, their
 * language, the forms they sent, the other places they're a customer of,
 * Sign out and Leave. "Built on Whity" sits at the foot (CA.2).
 */

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { useDateDisplay } from '@whity/features/datetime';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { UserText } from '@/components/ui/user-text';
import { LoadState, PageTitle, serverText, useAreaLoad, useLocalized } from '@/components/customer-area/area-parts';

type Account = components['schemas']['CustomerAreaAccount'];

export default function CustomerAccount() {
  const t = useTranslation('customer');
  const dates = useDateDisplay();
  const name = useLocalized();
  const router = useRouter();
  const { logout } = useAuth();
  const { handle, phase, reload } = useCustomerArea();
  const business = phase.kind === 'ready' ? phase.area.workspace.name : '';
  const [state, retry] = useAreaLoad<Account>(() => api.GET('/api/v1/customer-area/{handle}/account', { params: { path: { handle } } }), [handle]);
  const [leaving, setLeaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const signOut = async () => {
    await logout();
    router.replace(areaHref(handle));
  };

  const leave = async () => {
    setProblem(null);
    const { error, response } = await api.POST('/api/v1/customer-area/{handle}/leave', { params: { path: { handle } } });
    if (response.ok) {
      reload();
      return;
    }
    setProblem(serverText(error) ?? t('account.leaveFailed', 'That didn’t work. Try again.'));
  };

  return (
    <div className="space-y-5">
      <PageTitle>{t('tab.account', 'Account')}</PageTitle>
      <LoadState state={state} onRetry={retry} />
      {state.kind === 'ready' && (
        <>
          <Card>
            <CardContent className="space-y-1 py-5">
              <UserText className="block text-lg font-semibold">{state.data.name || state.data.email}</UserText>
              <bdi dir="ltr" className="block text-muted-foreground">
                {state.data.email}
              </bdi>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('account.language', 'Language')}</CardTitle>
            </CardHeader>
            <CardContent>
              <LanguageSwitcher
                variant="dropdown"
                className="h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
              />
            </CardContent>
          </Card>

          {state.data.forms.length > 0 && (
            <Card data-testid="customer-account-forms">
              <CardHeader>
                <CardTitle>{t('account.forms', 'Your forms')}</CardTitle>
              </CardHeader>
              <CardContent className="divide-y divide-border p-0">
                {state.data.forms.map((f) => (
                  <div key={f.id} className="flex min-h-12 items-center justify-between gap-3 px-4 py-3">
                    <UserText className="min-w-0 truncate">{name(f.form_name as Record<string, string>)}</UserText>
                    <span className="shrink-0 text-sm text-muted-foreground">
                      {f.withdrawn ? t('account.withdrawn', 'Withdrawn') : dates.date(f.submitted_at ?? '')}
                    </span>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}

          {state.data.other_places.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>{t('account.otherPlaces', 'Your other places')}</CardTitle>
              </CardHeader>
              <CardContent className="divide-y divide-border p-0">
                {state.data.other_places.map((p) => (
                  <Link key={p.handle} href={areaHref(p.handle, 'home')} className="flex min-h-12 items-center px-4 py-3 hover:bg-muted">
                    <UserText>{p.name}</UserText>
                  </Link>
                ))}
              </CardContent>
            </Card>
          )}

          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}

          <div className="flex flex-col gap-2 sm:flex-row">
            <Button size="lg" variant="outline" onClick={() => void signOut()} data-testid="customer-sign-out">
              {t('nav.signOut', 'Sign out')}
            </Button>
            {state.data.staff ? (
              <Button asChild size="lg" variant="outline">
                <Link href="/dashboard">{t('account.teamApp', 'Open the team app')}</Link>
              </Button>
            ) : leaving ? (
              <Card className="w-full">
                <CardContent className="space-y-3 py-5">
                  <p>{t('account.leaveAsk', 'Leave {business}? Your account here closes. {business} keeps its own records of your bookings and bills.', { business })}</p>
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <Button variant="destructive" onClick={() => void leave()} data-testid="customer-leave-confirm">
                      {t('account.leaveConfirm', 'Leave {business}', { business })}
                    </Button>
                    <Button variant="outline" onClick={() => setLeaving(false)}>
                      {t('account.stay', 'Stay')}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ) : (
              <Button size="lg" variant="outline" onClick={() => setLeaving(true)} data-testid="customer-leave">
                {t('account.leave', 'Leave {business}', { business })}
              </Button>
            )}
          </div>
        </>
      )}
    </div>
  );
}
