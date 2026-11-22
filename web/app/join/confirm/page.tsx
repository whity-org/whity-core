'use client';

/**
 * Where a workspace sign-up email's link lands (WHIT-915; SIGNUP_CONFIRM_URL).
 *
 * The same two shapes as /accept-invitation: a new address chooses a
 * password, an existing account asks for nothing and keeps its password.
 * Confirming either joins at once (Open) or waits for the workspace's admins
 * (Approval required). It never signs anybody in: that still happens at /login.
 */

import { Suspense, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@whity/features/i18n';
import { PublicShell } from '@/components/auth/public-shell';
import { PublicOutcome } from '@/components/auth/public-outcome';
import type { WorkspaceBrand } from '@/lib/branding-context';
import { api } from '@/lib/api/client';
import { useOptionalAuth } from '@/lib/auth-context';
import { PASSWORD_MIN_LENGTH } from '@/lib/password-policy';

type Phase = 'loading' | 'form' | 'joined' | 'pending' | 'already' | 'refused' | 'closed' | 'invalid';

interface Preview {
  workspaceName: string;
  email: string;
  requiresPassword: boolean;
  /** The workspace's logo and colour (WHIT-1044); null wears the host's. */
  brand: WorkspaceBrand | null;
}

function ConfirmInner() {
  const token = useSearchParams().get('token');
  const t = useTranslation('auth');
  const auth = useOptionalAuth();

  const [phase, setPhase] = useState<Phase>(token ? 'loading' : 'invalid');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const started = useRef(false);

  useEffect(() => {
    if (!token || started.current) return;
    started.current = true;

    void (async () => {
      try {
        const { data: previewBody, response: res } = await api.GET('/api/v1/public/signup/confirm', {
          params: { query: { token } },
        });
        if (!res.ok) {
          setPhase('invalid');
          return;
        }
        const body = (previewBody as unknown) as {
          data: { workspace_name: string; email: string; requires_password: boolean; brand?: WorkspaceBrand | null };
        };
        setPreview({
          workspaceName: body.data.workspace_name,
          email: body.data.email,
          requiresPassword: body.data.requires_password === true,
          brand: body.data.brand ?? null,
        });
        setPhase('form');
      } catch {
        setPhase('invalid');
      }
    })();
  }, [token]);

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    if (!token || preview === null) return;

    if (preview.requiresPassword) {
      if (password.length < PASSWORD_MIN_LENGTH) {
        setError(t('joinConfirm.error.tooShort', 'Password must be at least 12 characters'));
        return;
      }
      if (password !== confirmPassword) {
        setError(t('joinConfirm.error.mismatch', 'Passwords do not match'));
        return;
      }
    }

    setSubmitting(true);
    try {
      const { data: joinBody, error: refusal, response: res } = await api.POST('/api/v1/public/signup/confirm', {
        body: (preview.requiresPassword ? { token, password } : { token }) as never,
      });

      if (res.status === 200) {
        const body = (joinBody as unknown) as { data: { status: string } };
        // Signed in already: the new workspace joins the switcher in every tab.
        if (body.data.status === 'joined' && auth?.user) void auth.sessionChanged({ here: false });
        setPhase(
          body.data.status === 'joined' ? 'joined' : body.data.status === 'pending_approval' ? 'pending' : 'already'
        );
        return;
      }
      if (res.status === 409) {
        const body = ((refusal ?? {}) as unknown) as { details?: { reason?: string } };
        setPhase(body.details?.reason === 'closed' ? 'closed' : 'refused');
        return;
      }
      if (res.status === 422) {
        setError(t('joinConfirm.error.weak', 'Choose a password of at least 12 characters.'));
        return;
      }
      if (res.status === 429) {
        setError(t('joinConfirm.error.tooMany', 'Too many attempts. Wait a while and try again.'));
        return;
      }
      setPhase('invalid');
    } catch {
      setError(t('joinConfirm.error.network', 'Something went wrong. Check your connection and try again.'));
    } finally {
      setSubmitting(false);
    }
  };

  const workspace = preview?.workspaceName ?? '';
  const shell = (children: React.ReactNode) => <PublicShell brand={preview?.brand ?? null}>{children}</PublicShell>;
  const signIn = (label: string) => (
    <Button asChild size="lg" className="w-full">
      <Link href="/login">{label}</Link>
    </Button>
  );

  if (phase === 'loading') {
    return shell(<LoadingCard text={t('joinConfirm.loading', 'Checking your link…')} />);
  }

  if (phase === 'joined') {
    return shell(
      <PublicOutcome
        testId="join-confirm-joined"
        icon="success"
        tone="ok"
        title={t('joinConfirm.joined.title', 'You’ve joined {workspace}', { workspace })}
        description={t('joinConfirm.joined.text', 'Your account is ready.')}
      >
        {signIn(t('joinConfirm.open', 'Open {workspace}', { workspace }))}
      </PublicOutcome>
    );
  }
  if (phase === 'pending') {
    return shell(
      <PublicOutcome
        testId="join-confirm-pending"
        icon="clock"
        tone="dash"
        title={t('joinConfirm.pending.title', 'Waiting for approval')}
        description={t('join.sso.pending', '{workspace} approves each new customer. We’ll email you when you’re in.', { workspace })}
      />
    );
  }
  if (phase === 'already') {
    return shell(
      <PublicOutcome
        testId="join-confirm-already"
        icon="user"
        title={t('joinConfirm.already.title', 'You’re already a member')}
        description={t('joinConfirm.already.text', '{email} already belongs to {workspace}.', {
          email: preview?.email ?? '',
          workspace,
        })}
      >
        {signIn(t('joinConfirm.signIn', 'Sign in'))}
      </PublicOutcome>
    );
  }
  if (phase === 'closed') {
    return shell(
      <PublicOutcome
        testId="join-confirm-refused"
        icon="lock"
        tone="warn"
        title={t('join.closed.title', 'This workspace isn’t taking sign-ups')}
        description={t('joinConfirm.closed.text', 'Ask {workspace} to invite you, or check the link you were given.', { workspace })}
      />
    );
  }
  if (phase === 'refused') {
    return shell(
      <PublicOutcome
        testId="join-confirm-refused"
        icon="ban"
        tone="warn"
        title={t('joinConfirm.refused.title', 'You can’t join {workspace}', { workspace })}
        description={t(
          'joinConfirm.refused.text',
          'Your request was declined or your access is paused. Contact {workspace} if you think this is wrong.',
          { workspace }
        )}
      />
    );
  }
  if (phase === 'invalid' || preview === null) {
    return shell(
      <PublicOutcome
        testId="join-confirm-invalid"
        icon="alert"
        tone="warn"
        title={t('joinConfirm.invalid.title', 'This link has expired or was used')}
        description={t(
          'joinConfirm.invalid.text',
          'Links work once, for 24 hours. Sign up again on the workspace’s page to get a new one.'
        )}
      />
    );
  }

  return shell(
    <Card size="lg" className="text-base">
      <CardHeader>
        <CardTitle className="text-2xl font-semibold">{t('joinConfirm.title', 'Finish joining')}</CardTitle>
        <CardDescription className="text-base">
          {preview.requiresPassword ? (
            t('joinConfirm.choosePassword', 'Choose a password for {email}.', { email: preview.email })
          ) : (
            <>
              {t('joinConfirm.as', 'Signing up as')} <span dir="ltr">{preview.email}</span>
            </>
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-4" data-testid="join-confirm-form">
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {preview.requiresPassword ? (
            <>
              <div className="space-y-1.5">
                <label htmlFor="join-password" className="text-sm font-medium text-foreground">
                  {t('joinConfirm.password.label', 'Password')}
                </label>
                <Input
                  id="join-password"
                  type="password"
                  autoComplete="new-password"
                  aria-describedby="join-password-hint"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={submitting}
                />
                <p id="join-password-hint" className="text-sm text-muted-foreground">
                  {t('joinConfirm.password.hint', 'At least 12 characters.')}
                </p>
              </div>
              <div className="space-y-1.5">
                <label htmlFor="join-password-confirm" className="text-sm font-medium text-foreground">
                  {t('joinConfirm.confirmPassword.label', 'Confirm password')}
                </label>
                <Input
                  id="join-password-confirm"
                  type="password"
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  disabled={submitting}
                />
              </div>
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              {t(
                'joinConfirm.existingAccount',
                'You already have an account with this email. Joining adds {workspace} to it, and your password doesn’t change.',
                { workspace }
              )}
            </p>
          )}

          <Button type="submit" size="lg" className="w-full" loading={submitting} data-testid="join-confirm-submit">
            {t('joinConfirm.submit', 'Join {workspace}', { workspace })}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function LoadingCard({ text }: { text: string }) {
  return (
    <Card>
      <CardContent className="py-10 text-center text-sm text-muted-foreground">{text}</CardContent>
    </Card>
  );
}

export default function JoinConfirmPage() {
  const t = useTranslation('auth');

  return (
    <Suspense
      fallback={
        <PublicShell>
          <LoadingCard text={t('joinConfirm.loading', 'Checking your link…')} />
        </PublicShell>
      }
    >
      <ConfirmInner />
    </Suspense>
  );
}
