'use client';

/**
 * The front door of a workspace's customer area (WHIT-1416, design section
 * 41 §4 and §5, CA.1, CA.5, CA.6): what anyone who isn't (yet) a signed-in
 * customer sees at `/@handle` or any page under it.
 *
 *  - Signed out: Sign in, which comes back to the page they asked for.
 *  - Signed in, not a customer: Join in one tap when sign-up is open, Ask to
 *    join when it needs approval, and a plain message when it is off.
 *  - Waiting for approval: the business will let them know.
 *  - The area off: only what the workspace offers in public (Book, Chat).
 *
 * In the workspace's brand, through PublicShell (SU.1, SU.10).
 */

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState } from 'react';
import { PublicShell } from '@/components/auth/public-shell';
import { UserText } from '@/components/ui/user-text';
import { api } from '@/lib/api/client';
import { rememberCustomerAreaReturn } from '@/lib/customer-area-return';
import { areaHref, type CustomerAreaData } from '@/lib/customer-area';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { serverText } from './area-parts';

export function AreaDoor({ area, onChanged }: { area: CustomerAreaData; onChanged: () => void }) {
  const t = useTranslation('customer');
  const pathname = usePathname() ?? '';
  const router = useRouter();
  const handle = area.workspace.handle;
  const business = area.workspace.name;
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  // The address the person asked for, as they typed it (`/@handle/...`).
  const asked = pathname.startsWith('/w/') ? `/@${pathname.slice(3)}` : pathname;
  const back = asked === areaHref(handle) || asked.endsWith('/sign-in') ? areaHref(handle, 'home') : asked;

  const signIn = () => {
    rememberCustomerAreaReturn(back);
    router.push(`/login?next=${encodeURIComponent(back)}`);
  };

  const join = async () => {
    setBusy(true);
    setProblem(null);
    try {
      const { data, error, response } = await api.POST('/api/v1/customer-area/{handle}/join', { params: { path: { handle } } });
      if (response.ok && data) {
        onChanged();
        return;
      }
      setProblem(serverText(error) ?? t('door.joinFailed', 'That didn’t work. Try again.'));
    } catch {
      setProblem(t('door.joinFailed', 'That didn’t work. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  const publicActions = (
    <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
      {area.booking && (
        <Button asChild variant="outline" size="lg">
          <Link href={`/book/${handle}`}>{t('door.book', 'Book')}</Link>
        </Button>
      )}
      {area.chat && (
        <Button asChild variant="outline" size="lg">
          <Link href={`/chat/${handle}`}>{t('door.chat', 'Write to us')}</Link>
        </Button>
      )}
    </div>
  );

  let title: string;
  let body: string | null = null;
  let main: React.ReactNode = null;
  const me = area.me;

  if (!area.enabled) {
    title = business;
    body = area.booking || area.chat ? t('door.offBody', 'You can book or write to {business} here.', { business }) : null;
  } else if (me === null) {
    title = t('door.signInTitle', 'Your account at {business}', { business });
    body = t('door.signInBody', 'Sign in to see your bookings, bills and messages in one place.');
    main = (
      <Button size="lg" className="w-full sm:w-auto" onClick={signIn} data-testid="customer-sign-in">
        {t('door.signIn', 'Sign in')}
      </Button>
    );
  } else if (me.state === 'pending') {
    title = t('door.pendingTitle', 'Waiting for {business}', { business });
    body = t('door.pendingBody', '{business} will let you know by email when your account is ready.', { business });
  } else if (area.signup === 'open' || area.signup === 'approval') {
    title = t('door.joinTitle', 'Join {business}', { business });
    body =
      area.signup === 'open'
        ? t('door.joinBody', 'You’re signed in as {email}. Join to see your bookings, bills and messages here.', { email: me.email })
        : t('door.askBody', 'You’re signed in as {email}. {business} looks at each request before letting you in.', { email: me.email, business });
    main = (
      <Button size="lg" className="w-full sm:w-auto" onClick={() => void join()} disabled={busy} data-testid="customer-join">
        {area.signup === 'open' ? t('door.join', 'Join {business}', { business }) : t('door.ask', 'Ask to join')}
      </Button>
    );
  } else {
    title = t('door.closedTitle', '{business} hasn’t opened accounts', { business });
    body = t('door.closedBody', 'Ask them for an invitation.');
  }

  return (
    <PublicShell brand={area.workspace.brand}>
      <Card>
        <CardContent className="space-y-5 py-8 text-center" data-testid="customer-door">
          <h1 className="text-2xl font-semibold">
            <UserText>{title}</UserText>
          </h1>
          {body && <p className="text-muted-foreground">{body}</p>}
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          {main}
          {publicActions}
        </CardContent>
      </Card>
    </PublicShell>
  );
}
