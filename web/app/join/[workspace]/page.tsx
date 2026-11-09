'use client';

/**
 * A workspace's own sign-up page (WHIT-915): somebody asks to join ONE
 * workspace as a customer. Its admins turn this on in Settings → Sign-up.
 * Platform sign-up, which creates a workspace, is /register and is not this.
 *
 * Outside `(protected)`: the visitor has no session. The workspace comes from
 * the slug in the address; on a custom domain the server also checks that the
 * host and the slug name the same workspace.
 *
 * The server answers the same "check your email" for every address, so this
 * page shows the same thing too. Whether the address already has an account is
 * only shown on the confirm page, to whoever holds the emailed link.
 */

import { use, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useThemeMode } from '@/lib/theme-mode-context';
import { PublicShell } from '@/components/auth/public-shell';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { ProviderIcon } from '@/components/sso-login-buttons';

interface JoinProvider {
  provider_key: string;
  display_name: string;
}

interface SignupInfo {
  workspaceName: string;
  approvalRequired: boolean;
  allowedDomains: string[];
  botCheck: { provider: string; siteKey: string } | null;
  /** Whether the email form can work here (WHIT-917), said up front. */
  emailSignup: boolean;
  /** The instance's sign-in providers a visitor may join with. */
  providers: JoinProvider[];
}

/**
 * `notReady`: sign-up is on, but nothing a visitor could use works yet (no bot
 * check for the email form, and no sign-in provider). `pending`: back from a
 * provider, waiting for the workspace's approval.
 */
type Phase = 'loading' | 'closed' | 'notReady' | 'form' | 'sent' | 'pending' | 'error';

/** What a provider round trip came back with: `?sso=` on this page. */
type SsoOutcome = 'pending' | 'domain' | 'refused' | 'closed' | 'email_unverified' | 'link_conflict' | 'provider_unavailable' | 'failed';

const SSO_OUTCOMES: readonly SsoOutcome[] = [
  'pending', 'domain', 'refused', 'closed', 'email_unverified', 'link_conflict', 'provider_unavailable', 'failed',
];

/** A full-page navigation: the backend 302s to the provider and back. */
function joinStartUrl(providerKey: string, workspace: string): string {
  return `/api/v1/auth/sso/${encodeURIComponent(providerKey)}/start?join=${encodeURIComponent(workspace)}`;
}

export default function JoinWorkspacePage({ params }: { params: Promise<{ workspace: string }> }) {
  const { workspace } = use(params);
  const t = useTranslation('auth');
  const { currentLanguage } = useCurrentLanguage();
  const { resolved: theme } = useThemeMode();

  const [phase, setPhase] = useState<Phase>('loading');
  const [info, setInfo] = useState<SignupInfo | null>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [botToken, setBotToken] = useState<string | null>(null);
  const [botFailed, setBotFailed] = useState(false);
  const [resetKey, setResetKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ssoOutcome, setSsoOutcome] = useState<SsoOutcome | null>(null);

  useEffect(() => {
    let cancelled = false;
    const returned = new URLSearchParams(window.location.search).get('sso');
    const outcome = SSO_OUTCOMES.find((o) => o === returned) ?? null;
    fetch(`/api/v1/public/signup/workspaces/${encodeURIComponent(workspace)}`, {
      headers: { Accept: 'application/json' },
    })
      .then(async (res) => {
        if (cancelled) return;
        if (res.status === 404) {
          setPhase('closed');
          return;
        }
        if (!res.ok) {
          setPhase('error');
          return;
        }
        const body = (await res.json()) as {
          data: {
            workspace_name: string;
            approval_required: boolean;
            allowed_domains: string[];
            bot_check: { provider: string; site_key: string } | null;
            email_signup?: boolean;
            providers?: JoinProvider[];
          };
        };
        const next: SignupInfo = {
          workspaceName: body.data.workspace_name,
          approvalRequired: body.data.approval_required,
          allowedDomains: body.data.allowed_domains,
          botCheck: body.data.bot_check
            ? { provider: body.data.bot_check.provider, siteKey: body.data.bot_check.site_key }
            : null,
          emailSignup: body.data.email_signup !== false,
          providers: Array.isArray(body.data.providers) ? body.data.providers : [],
        };
        setInfo(next);
        if (outcome === 'pending') {
          setPhase('pending');
        } else if (!next.emailSignup && next.providers.length === 0) {
          setPhase('notReady');
        } else {
          setSsoOutcome(outcome);
          setPhase('form');
        }
      })
      .catch(() => {
        if (!cancelled) setPhase('error');
      });
    return () => {
      cancelled = true;
    };
  }, [workspace]);

  const onToken = useCallback((token: string | null) => {
    setBotToken(token);
    if (token !== null) setBotFailed(false);
  }, []);
  const onBotError = useCallback(() => setBotFailed(true), []);

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (name.trim() === '') {
      setError(t('join.error.name', 'Enter your name.'));
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError(t('join.error.email', 'Enter a valid email address.'));
      return;
    }
    if (info?.botCheck && botToken === null) {
      setError(t('join.error.botPending', 'Wait for the check below to finish, then try again.'));
      return;
    }

    setSubmitting(true);
    try {
      const res = await fetch(`/api/v1/public/signup/workspaces/${encodeURIComponent(workspace)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ email: email.trim(), display_name: name.trim(), bot_token: botToken ?? '' }),
      });

      if (res.status === 202) {
        setPhase('sent');
        return;
      }

      // A bot token is single use: ask for a fresh one whatever happened.
      setBotToken(null);
      setResetKey((k) => k + 1);

      if (res.status === 404) {
        setPhase('closed');
        return;
      }
      if (res.status === 409) {
        // No bot check on the instance: a setting, not an outage (WHIT-917).
        setPhase('notReady');
        return;
      }
      if (res.status === 429) {
        setError(t('join.error.tooMany', 'Too many attempts. Wait a while and try again.'));
        return;
      }
      if (res.status === 422) {
        const body = (await res.json().catch(() => ({}))) as { details?: Record<string, string> };
        const field = body.details ? Object.keys(body.details)[0] : undefined;
        if (field === 'email' && body.details?.email === 'domain') {
          setError(t('join.error.domain', 'This workspace only accepts sign-ups from the email domains listed above.'));
        } else if (field === 'bot_token') {
          setError(t('join.error.bot', 'We couldn’t confirm you are a person. Try again.'));
        } else if (field === 'display_name') {
          setError(t('join.error.name', 'Enter your name.'));
        } else {
          setError(t('join.error.email', 'Enter a valid email address.'));
        }
        return;
      }
      setError(t('join.error.unavailable', 'Sign-up is unavailable right now. Try again later.'));
    } catch {
      setError(t('join.error.unavailable', 'Sign-up is unavailable right now. Try again later.'));
    } finally {
      setSubmitting(false);
    }
  };

  if (phase === 'loading') {
    return (
      <PublicShell>
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            {t('join.loading', 'Loading…')}
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'closed' || phase === 'error' || info === null) {
    return (
      <PublicShell>
        <Card className="border-t-4 border-t-primary" data-testid="join-closed">
          <CardHeader>
            <CardTitle>{t('join.closed.title', 'Sign-up isn’t open')}</CardTitle>
            <CardDescription>
              {phase === 'error'
                ? t('join.closed.error', 'This page could not be loaded. Check your connection and try again.')
                : t(
                    'join.closed.description',
                    'This workspace isn’t taking sign-ups right now. If you were invited, use the link in your invitation.'
                  )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Link href="/login" className="text-sm font-medium text-primary hover:underline">
              {t('join.signIn', 'Already have access? Sign in')}
            </Link>
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'notReady') {
    return (
      <PublicShell>
        <Card className="border-t-4 border-t-primary" data-testid="join-not-ready">
          <CardHeader>
            <CardTitle>{t('join.notReady.title', 'This workspace isn’t taking sign-ups yet')}</CardTitle>
            <CardDescription>
              {t(
                'join.notReady.description',
                'Sign-up is being set up here. Please come back later, or contact the workspace if you were told to join now.'
              )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Link href="/login" className="text-sm font-medium text-primary hover:underline">
              {t('join.signIn', 'Already have access? Sign in')}
            </Link>
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'pending') {
    return (
      <PublicShell>
        <Card className="border-t-4 border-t-primary" data-testid="join-sso-pending">
          <CardHeader>
            <CardTitle>{t('joinConfirm.pending.title', 'Waiting for approval')}</CardTitle>
            <CardDescription>
              {t(
                'join.sso.pending',
                '{workspace} will review your request. You can sign in once it’s approved.',
                { workspace: info.workspaceName }
              )}
            </CardDescription>
          </CardHeader>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'sent') {
    return (
      <PublicShell>
        <Card className="border-t-4 border-t-primary" data-testid="join-sent">
          <CardHeader>
            <CardTitle>{t('join.sent.title', 'Check your email')}</CardTitle>
            <CardDescription>
              {t(
                'join.sent.description',
                'If {email} can join {workspace}, we’ve sent it a link to finish signing up. The link works for 24 hours.',
                { email: email.trim(), workspace: info.workspaceName }
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3 text-sm text-muted-foreground">
            <p>{t('join.sent.spam', 'Nothing there after a few minutes? Check your spam folder.')}</p>
            <Button variant="outline" onClick={() => setPhase('form')}>
              {t('join.sent.again', 'Use a different email')}
            </Button>
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  return (
    <PublicShell>
      <Card className="border-t-4 border-t-primary">
        <CardHeader>
          <CardTitle>{t('join.title', 'Join {workspace}', { workspace: info.workspaceName })}</CardTitle>
          <CardDescription>
            {!info.emailSignup
              ? t('join.description.providerOnly', 'Sign up to use {workspace} with the account below.', {
                  workspace: info.workspaceName,
                })
              : info.approvalRequired
              ? t(
                  'join.description.approval',
                  'Sign up to use {workspace}. After you confirm your email, the workspace reviews your request.',
                  { workspace: info.workspaceName }
                )
              : t('join.description.open', 'Sign up to use {workspace}. You’ll confirm your email first.', {
                  workspace: info.workspaceName,
                })}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {ssoOutcome !== null && (
            <Alert variant="destructive" data-testid="join-sso-outcome">
              <AlertDescription>{ssoOutcomeText(ssoOutcome, t)}</AlertDescription>
            </Alert>
          )}

          {info.providers.length > 0 && (
            <div className="space-y-3" data-testid="join-providers">
              {info.providers.map((provider) => (
                <Button
                  key={provider.provider_key}
                  asChild
                  variant="outline"
                  className="w-full gap-2"
                  data-testid={`join-provider-${provider.provider_key}`}
                >
                  <a href={joinStartUrl(provider.provider_key, workspace)}>
                    <ProviderIcon providerKey={provider.provider_key} />
                    {t('sso.signInWith', 'Continue with {provider}', { provider: provider.display_name })}
                  </a>
                </Button>
              ))}
              {info.emailSignup && (
                <div className="flex items-center gap-3">
                  <span className="h-px flex-1 bg-border" />
                  <span className="text-sm text-muted-foreground">{t('sso.divider', 'or with email')}</span>
                  <span className="h-px flex-1 bg-border" />
                </div>
              )}
            </div>
          )}

          {!info.emailSignup ? (
            <p className="text-center text-sm text-muted-foreground">
              <Link href="/login" className="font-medium text-primary hover:underline">
                {t('join.signIn', 'Already have access? Sign in')}
              </Link>
            </p>
          ) : (
          <form onSubmit={submit} className="space-y-4" data-testid="join-form" noValidate>
            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <div className="space-y-1.5">
              <label htmlFor="join-name" className="text-sm font-medium text-foreground">
                {t('join.name.label', 'Your name')}
              </label>
              <Input
                id="join-name"
                autoComplete="name"
                value={name}
                maxLength={120}
                onChange={(e) => setName(e.target.value)}
                disabled={submitting}
              />
            </div>

            <div className="space-y-1.5">
              <label htmlFor="join-email" className="text-sm font-medium text-foreground">
                {t('join.email.label', 'Email')}
              </label>
              <Input
                id="join-email"
                type="email"
                dir="ltr"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={submitting}
              />
              {info.allowedDomains.length > 0 && (
                <p className="text-sm text-muted-foreground" data-testid="join-domains">
                  {t('join.email.domains', 'Use an address ending in')}{' '}
                  <span dir="ltr">{info.allowedDomains.map((d) => `@${d}`).join(', ')}</span>
                </p>
              )}
            </div>

            {info.botCheck && (
              <div className="space-y-1.5">
                <TurnstileWidget
                  siteKey={info.botCheck.siteKey}
                  language={currentLanguage ?? 'en'}
                  theme={theme === 'dark' ? 'dark' : 'light'}
                  resetKey={resetKey}
                  onToken={onToken}
                  onError={onBotError}
                />
                {botFailed && (
                  <p className="text-sm text-destructive">
                    {t('join.error.botLoad', 'The check didn’t load. Refresh the page and try again.')}
                  </p>
                )}
              </div>
            )}

            <Button type="submit" className="w-full" loading={submitting} data-testid="join-submit">
              {t('join.submit', 'Sign up')}
            </Button>

            <p className="text-center text-sm text-muted-foreground">
              <Link href="/login" className="font-medium text-primary hover:underline">
                {t('join.signIn', 'Already have access? Sign in')}
              </Link>
            </p>
          </form>
          )}
        </CardContent>
      </Card>
    </PublicShell>
  );
}

/** What came back from the provider, in the reader's words. */
function ssoOutcomeText(outcome: SsoOutcome, t: ReturnType<typeof useTranslation>): string {
  switch (outcome) {
    case 'domain':
      return t('join.sso.domain', 'This workspace only accepts sign-ups from some email domains, and that account’s email isn’t on one of them.');
    case 'refused':
      return t('join.sso.refused', 'That account can’t join this workspace by signing up. Contact the workspace if you think this is wrong.');
    case 'closed':
      return t('join.sso.closed', 'This workspace isn’t taking sign-ups right now.');
    case 'email_unverified':
      return t('join.sso.unverified', 'That account’s email isn’t verified yet. Verify it with the provider, then try again.');
    case 'link_conflict':
      return t('join.sso.conflict', 'An account with that email already exists but its email isn’t confirmed. Sign in with email and confirm it first.');
    case 'provider_unavailable':
      return t('join.sso.unavailable', 'The sign-in service couldn’t be reached. Try again later.');
    default:
      return t('join.sso.failed', 'Signing in didn’t work. Try again.');
  }
}
