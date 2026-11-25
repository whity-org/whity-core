'use client';

import { useState, useSyncExternalStore } from 'react';
import Image from 'next/image';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';

/**
 * The one-time sign-in link `whity-cli auth operator-link <email>` prints for a
 * platform operator with no Whity ID linked (WHIT-1350).
 *
 * The token rides in the URL fragment, so it never reaches a server log or a
 * Referer header, and it is sent only when the operator presses the button: a
 * chat app previewing the link can't use it up or receive the session. The
 * server opens a session in the system workspace and the page goes on to
 * Settings › Security, where the operator links Whity ID.
 *
 * Public: the person has no session yet.
 */

type Status = 'ready' | 'signing-in' | 'refused';

function tokenFromHash(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  return token && token.length > 0 ? token : null;
}

const noSubscription = () => () => {};

export default function OperatorSignInPage() {
  const branding = useBranding();
  const t = useTranslation('auth');
  // Read on the client only; the server render has no fragment.
  const hash = useSyncExternalStore<string | null>(noSubscription, () => window.location.hash, () => null);
  const token = hash === null ? null : tokenFromHash(hash);
  const [status, setStatus] = useState<Status>('ready');

  const signIn = async () => {
    if (!token) return;
    setStatus('signing-in');
    try {
      const { data, response } = await api.POST('/api/v1/auth/operator-link', { body: { token } });
      // Spent either way: take it out of the address bar and history.
      window.history.replaceState(null, '', window.location.pathname);
      if (response.ok && data && typeof data.next === 'string' && data.next.startsWith('/')) {
        // A full navigation, so the app starts from the new session.
        window.location.assign(data.next);
        return;
      }
      setStatus('refused');
    } catch {
      setStatus('refused');
    }
  };

  const logo = branding.logoWideUrl ? (
    <Image
      src={branding.logoWideUrl}
      alt={branding.siteName}
      width={220}
      height={40}
      className="h-10 w-auto max-w-[220px] object-contain mx-auto mb-2"
    />
  ) : null;

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {logo}
          <CardTitle className="text-2xl">{t('operatorSignIn.title', 'Sign in as a platform operator')}</CardTitle>
          <CardDescription>
            {t(
              'operatorSignIn.description',
              'This one-time link signs you in to the system workspace so you can link your Whity ID.',
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {token !== null && status !== 'refused' && (
            <>
              <p className="text-sm text-muted-foreground">
                {t(
                  'operatorSignIn.next',
                  'Next you will see Settings › Security. Link your Whity ID there, then sign in with it from now on.',
                )}
              </p>
              <Button className="w-full" onClick={() => void signIn()} disabled={status === 'signing-in'}>
                {status === 'signing-in'
                  ? t('operatorSignIn.signingIn', 'Signing in…')
                  : t('operatorSignIn.button', 'Sign in')}
              </Button>
            </>
          )}

          {hash !== null && (token === null || status === 'refused') && (
            <Alert data-testid="operator-sign-in-refused">
              <AlertDescription>
                {t(
                  'operatorSignIn.refused',
                  'This link has expired or was already used. Each link works once, for 15 minutes. Ask whoever runs the server for a new one.',
                )}
              </AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
