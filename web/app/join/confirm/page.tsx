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

type Phase = 'loading' | 'form' | 'joined' | 'pending' | 'already' | 'refused' | 'closed' | 'invalid';

interface Preview {
  workspaceName: string;
  email: string;
  requiresPassword: boolean;
}

function ConfirmInner() {
  const token = useSearchParams().get('token');
  const t = useTranslation('auth');

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
        const res = await fetch(`/api/v1/public/signup/confirm?token=${encodeURIComponent(token)}`, {
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
          setPhase('invalid');
          return;
        }
        const body = (await res.json()) as {
          data: { workspace_name: string; email: string; requires_password: boolean };
        };
        setPreview({
          workspaceName: body.data.workspace_name,
          email: body.data.email,
          requiresPassword: body.data.requires_password === true,
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
      if (password.length < 8) {
        setError(t('joinConfirm.error.tooShort', 'Password must be at least 8 characters'));
        return;
      }
      if (password !== confirmPassword) {
        setError(t('joinConfirm.error.mismatch', 'Passwords do not match'));
        return;
      }
    }

    setSubmitting(true);
    try {
      const res = await fetch('/api/v1/public/signup/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(preview.requiresPassword ? { token, password } : { token }),
      });

      if (res.status === 200) {
        const body = (await res.json()) as { data: { status: string } };
        setPhase(
          body.data.status === 'joined' ? 'joined' : body.data.status === 'pending_approval' ? 'pending' : 'already'
        );
        return;
      }
      if (res.status === 409) {
        const body = (await res.json().catch(() => ({}))) as { details?: { reason?: string } };
        setPhase(body.details?.reason === 'closed' ? 'closed' : 'refused');
        return;
      }
      if (res.status === 422) {
        setError(t('joinConfirm.error.weak', 'Choose a password of at least 8 characters.'));
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

  const outcome = (testId: string, title: string, text: string, tone: 'default' | 'destructive' = 'default') => (
    <Card className="border-t-4 border-t-primary" data-testid={testId}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <Alert variant={tone}>
          <AlertDescription>{text}</AlertDescription>
        </Alert>
        <Button asChild variant={tone === 'destructive' ? 'outline' : 'default'} className="w-full">
          <Link href="/login">{t('joinConfirm.continue', 'Continue to sign in')}</Link>
        </Button>
      </CardContent>
    </Card>
  );

  if (phase === 'loading') {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          {t('joinConfirm.loading', 'Checking your link…')}
        </CardContent>
      </Card>
    );
  }

  if (phase === 'joined') {
    return outcome(
      'join-confirm-joined',
      t('joinConfirm.joined.title', 'You’ve joined {workspace}', { workspace }),
      t('joinConfirm.joined.text', 'Your email is confirmed. Sign in to start.')
    );
  }
  if (phase === 'pending') {
    return outcome(
      'join-confirm-pending',
      t('joinConfirm.pending.title', 'Waiting for approval'),
      t(
        'joinConfirm.pending.text',
        'Your email is confirmed. {workspace} will review your request, and you can sign in once it’s approved.',
        { workspace }
      )
    );
  }
  if (phase === 'already') {
    return outcome(
      'join-confirm-already',
      t('joinConfirm.already.title', 'You already have access'),
      t('joinConfirm.already.text', 'This account already belongs to {workspace}. Sign in to continue.', { workspace })
    );
  }
  if (phase === 'refused' || phase === 'closed') {
    return outcome(
      'join-confirm-refused',
      t('joinConfirm.refused.title', 'You can’t join with this link'),
      phase === 'closed'
        ? t('joinConfirm.closed.text', '{workspace} isn’t taking sign-ups right now.', { workspace })
        : t(
            'joinConfirm.refused.text',
            'This account can’t join {workspace} by signing up. Contact the workspace if you think this is wrong.',
            { workspace }
          ),
      'destructive'
    );
  }
  if (phase === 'invalid' || preview === null) {
    return outcome(
      'join-confirm-invalid',
      t('joinConfirm.invalid.title', 'This link doesn’t work'),
      t(
        'joinConfirm.invalid.text',
        'The link is invalid, has expired or has already been used. Sign up again to get a new one.'
      ),
      'destructive'
    );
  }

  return (
    <Card className="border-t-4 border-t-primary">
      <CardHeader>
        <CardTitle>{t('joinConfirm.title', 'Finish joining {workspace}', { workspace })}</CardTitle>
        <CardDescription>
          {t('joinConfirm.as', 'Signing up as')} <span dir="ltr">{preview.email}</span>
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
                  {t('joinConfirm.password.label', 'Choose a password')}
                </label>
                <Input
                  id="join-password"
                  type="password"
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={submitting}
                />
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

          <Button type="submit" className="w-full" loading={submitting} data-testid="join-confirm-submit">
            {t('joinConfirm.submit', 'Confirm and join')}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function JoinConfirmPage() {
  const t = useTranslation('auth');

  return (
    <PublicShell>
      <Suspense
        fallback={
          <Card>
            <CardContent className="py-10 text-center text-sm text-muted-foreground">
              {t('joinConfirm.loading', 'Checking your link…')}
            </CardContent>
          </Card>
        }
      >
        <ConfirmInner />
      </Suspense>
    </PublicShell>
  );
}
