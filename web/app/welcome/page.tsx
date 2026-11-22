'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { api } from '@/lib/api/client';
import { continueSignupPlugin } from '@/lib/signup-plugin';
import { templatePickerPath } from '@/lib/workspace-template';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Icon } from '@whity/ui/icon';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/components/auth-shell';
import { OutcomeIcon } from '@/components/auth/public-outcome';
import { MAIN_COLOUR_SWATCHES } from '@/components/branding-main-colour';
import { useLanguageCode, useTranslation, type TranslateFn } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { useHomePath } from '@/lib/branding-context';

/**
 * "No workspace yet" (WHIT-994, decision M.29): where somebody lands after
 * signing in with the operator's own provider (Whity ID on app.whity.dev)
 * when they belong to no workspace. SSO_NO_WORKSPACE_URL points here.
 *
 * It used to be a `no_membership` error toast on the sign-in page. Now the
 * backend says which of three things is true, and this page says it plainly:
 *
 *   create       → "Create your workspace": its name (their own name to start
 *                  with), its main language and main colour, as the onboarding
 *                  card's step 2 asks (TplAuthInvite). Creating it signs them
 *                  straight into it as its owner.
 *   waiting      → they already have a place somewhere that isn't active yet
 *                  (an invitation, an approval, a pause): name it, never offer
 *                  a second workspace that would route around that decision.
 *   needs_invite → this instance doesn't let people create workspaces: say
 *                  they need an invitation, and which address to give.
 *
 * The page holds no session, only the short-lived workspace_setup cookie, so
 * it talks to the backend with plain fetch rather than the API client (whose
 * 401 handling would try to refresh a session that doesn't exist).
 *
 * Its card is TplWelcomeWorkspace (design 3.4): the three states, timed out,
 * and the create form's own refusals, inside the signed-out AuthShell.
 */

type Setup = components['schemas']['SsoWorkspaceSetupResponse']['data'];

type Status = 'loading' | 'ready' | 'expired' | 'error';

type FieldError = 'name_required' | 'name_too_long' | null;

const NAME_MAX = 120;


function fieldErrorMessage(t: TranslateFn, code: FieldError): string | null {
  if (code === 'name_required') {
    return t('welcome.name.required', 'Type a name for your workspace.');
  }
  if (code === 'name_too_long') {
    return t('welcome.name.tooLong', 'Use {max} characters or fewer.', { max: NAME_MAX });
  }
  return null;
}

/** The plain-words status of a membership that is not active yet. */
function waitingLabel(t: TranslateFn, status: string): string {
  return status === 'suspended'
    ? t('welcome.waiting.status.suspended', 'Paused by an administrator')
    : t('welcome.waiting.status.invited', 'Waiting for an administrator');
}

export default function WelcomePage() {
  const t = useTranslation('auth');
  const homePath = useHomePath();
  const uiLanguage = useLanguageCode();
  const router = useRouter();
  const { refreshAuth } = useAuth();

  const [status, setStatus] = useState<Status>('loading');
  const [setup, setSetup] = useState<Setup | null>(null);
  const [name, setName] = useState('');
  // Follows the page's language (which resolves after the first render)
  // until the person picks one themselves.
  const [chosenLocale, setChosenLocale] = useState<string | null>(null);
  const locale = chosenLocale ?? (uiLanguage === 'ar' ? 'ar' : 'en');
  const [colour, setColour] = useState<string>(MAIN_COLOUR_SWATCHES[0]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldError, setFieldError] = useState<FieldError>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const started = useRef(false);

  const load = async () => {
    try {
      const { data: setupBody, response } = await api.GET('/api/v1/auth/sso/workspace', { cache: 'no-store' });
      if (response.status === 401) {
        setStatus('expired');
        return;
      }
      if (!response.ok) {
        setStatus('error');
        return;
      }
      const body = (setupBody ?? {}) as unknown as { data?: Setup };
      if (!body.data) {
        setStatus('error');
        return;
      }
      setSetup(body.data);
      setName((current) => (current === '' ? (body.data?.suggested_name ?? '') : current));
      setStatus('ready');
    } catch {
      setStatus('error');
    }
  };

  // Declared inside the effect's reach and guarded by a ref, as on the
  // invitation page: a second mount in development must not ask twice.
  useEffect(() => {
    if (started.current) {
      return;
    }
    started.current = true;
    void load();
  }, []);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setFieldError(null);
    setFormError(null);

    const trimmed = name.trim();
    if (trimmed === '') {
      setFieldError('name_required');
      return;
    }
    if (trimmed.length > NAME_MAX) {
      setFieldError('name_too_long');
      return;
    }

    setSubmitting(true);
    try {
      const request: components['schemas']['SsoWorkspaceCreateRequest'] = {
        name: trimmed,
        locale,
        // Whity Blue is the default: leave the setting empty for it.
        main_color: colour === MAIN_COLOUR_SWATCHES[0] ? '' : colour,
      };
      // The client sends the CSRF header (WC-160) on state-changing requests.
      const { data: createdBody, error: createError, response } = await api.POST('/api/v1/auth/sso/workspace', {
        body: request,
      });
      const body = ((createdBody ?? createError ?? {}) as unknown) as {
        user?: unknown;
        requires_tenant_selection?: boolean;
        details?: { code?: string };
      };

      if (response.ok && body.user) {
        // The session cookies are set: load them, as sign-in does, and go in.
        // A plugin sign-up link (/register?plugin=salons) started this, through
        // the identity provider: start its trial as registering does (WHIT-1168).
        await refreshAuth();
        router.push((await continueSignupPlugin()) ?? (await templatePickerPath()) ?? homePath);
        return;
      }
      if (response.ok && body.requires_tenant_selection) {
        router.push('/login?sso=select');
        return;
      }
      if (response.status === 401) {
        setStatus('expired');
        return;
      }
      if (response.status === 422) {
        const code = body.details?.code;
        if (code === 'name_required' || code === 'name_too_long') {
          setFieldError(code);
        } else {
          setFormError(t('welcome.error.choice', 'Pick a language and a colour from the choices.'));
        }
        return;
      }
      if (response.status === 403) {
        // Something changed since the page opened (an invitation arrived, or
        // creating workspaces was switched off): show what is true now.
        setFormError(t('welcome.error.changed', 'Something changed since you signed in. Here is where things stand now.'));
        await load();
        return;
      }
      setFormError(t('welcome.error.busy', 'We couldn’t make the workspace. Nothing was saved. Try again in a minute.'));
    } catch {
      setFormError(t('welcome.error.busy', 'We couldn’t make the workspace. Nothing was saved. Try again in a minute.'));
    } finally {
      setSubmitting(false);
    }
  };

  const signInAgain = (
    <Button asChild size="lg" className="w-full">
      <Link href="/login">{t('welcome.signInAgain', 'Sign in again')}</Link>
    </Button>
  );
  // The page holds no session, only the short-lived setup cookie: leaving is
  // going back to sign-in, as any account (the card's "Sign out").
  const signOut = (label: string) => (
    <Button asChild variant="outline" className="w-full">
      <Link href="/login">{label}</Link>
    </Button>
  );

  if (status === 'loading') {
    return (
      <AuthShell title={t('welcome.loading.title', 'One moment')}>
        <p className="text-center text-muted-foreground" role="status" data-testid="welcome-loading">
          {t('welcome.loading', 'Checking your sign-in…')}
        </p>
      </AuthShell>
    );
  }

  if (status === 'expired' || status === 'error' || setup === null) {
    return (
      <AuthShell
        title={
          status === 'error'
            ? t('welcome.error.title', 'We couldn’t check your sign-in')
            : t('welcome.expired.title', 'This page timed out')
        }
        subtitle={
          status === 'error'
            ? t('welcome.error.subtitle', 'Nothing was made. Sign in again to carry on.')
            : t('welcome.expired.subtitle', 'Sign in again to carry on; nothing was made.')
        }
      >
        <div className="space-y-4" data-testid={status === 'error' ? 'welcome-error' : 'welcome-expired'}>
          {signInAgain}
        </div>
      </AuthShell>
    );
  }

  if (setup.state === 'waiting') {
    const places = setup.workspaces ?? [];
    const only = places.length === 1 ? places[0] : null;
    return (
      <AuthShell
        icon={<OutcomeIcon name="clock" tone="dash" />}
        title={
          only
            ? t('welcome.waiting.titleNamed', 'You’re waiting to join {workspace}', { workspace: only.name })
            : t('welcome.waiting.title', 'Your place is waiting')
        }
      >
        <div className="space-y-4" data-testid="welcome-waiting">
          {only ? (
            <>
              <div className="flex justify-center">
                <Badge variant="outline">{waitingLabel(t, only.status)}</Badge>
              </div>
              <p className="text-center text-muted-foreground">
                {t(
                  'welcome.waiting.bodyNamed',
                  '{workspace}’s administrators decide who joins. You can sign in with {email} once they let you in.',
                  { workspace: only.name, email: setup.email }
                )}
              </p>
            </>
          ) : (
            <>
              <p className="text-center text-muted-foreground">
                {t(
                  'welcome.waiting.body',
                  'Their administrators decide who joins. You can sign in with {email} once they let you in.',
                  { email: setup.email }
                )}
              </p>
              <ul className="divide-y divide-border rounded-card border border-border">
                {places.map((w, i) => (
                  <li key={`${w.name}-${i}`} className="flex min-h-12 flex-col justify-center px-4 py-2">
                    <span className="font-medium text-foreground">{w.name}</span>
                    <span className="text-sm text-muted-foreground">{waitingLabel(t, w.status)}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
          {signOut(t('welcome.signOut', 'Sign out'))}
          <p className="text-center text-sm text-muted-foreground">
            {t('welcome.waiting.noSecond', 'You can’t make another workspace while this one decides.')}
          </p>
        </div>
      </AuthShell>
    );
  }

  if (setup.state === 'needs_invite') {
    return (
      <AuthShell icon={<OutcomeIcon name="mail" tone="warn" />} title={t('welcome.invite.title', 'You need an invitation')}>
        <div className="space-y-4" data-testid="welcome-needs-invite">
          <p className="text-center text-muted-foreground">
            {t(
              'welcome.invite.body',
              'This Whity doesn’t let people make their own workspace. Ask your company’s Whity administrator to invite this address:'
            )}
          </p>
          <CopyAddress
            value={setup.email}
            copyLabel={t('welcome.invite.copy', 'Copy')}
            copiedLabel={t('welcome.invite.copied', 'Copied')}
          />
          {signOut(t('welcome.useAnotherAccount', 'Use another account'))}
        </div>
      </AuthShell>
    );
  }

  const nameError = fieldErrorMessage(t, fieldError);

  return (
    <AuthShell
      title={t('welcome.create.title', 'Create your workspace')}
      subtitle={t(
        'welcome.create.subtitle',
        'You’re signed in as {email}, and you don’t belong to a workspace yet.',
        { email: setup.email }
      )}
    >
      <form onSubmit={handleSubmit} className="space-y-5" data-testid="welcome-create-form" noValidate>
        {formError && (
          <Alert variant="destructive">
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        )}

        <div className="space-y-2">
          <label htmlFor="workspace-name" className="font-medium text-foreground">
            {t('welcome.name.label', 'Workspace name')}
          </label>
          <Input
            id="workspace-name"
            value={name}
            maxLength={NAME_MAX + 20}
            autoComplete="organization"
            aria-invalid={nameError !== null}
            aria-describedby={nameError !== null ? 'workspace-name-error' : 'workspace-name-hint'}
            onChange={(e) => {
              setName(e.target.value);
              if (fieldError) setFieldError(null);
            }}
            disabled={submitting}
          />
          {nameError !== null ? (
            <p id="workspace-name-error" className="text-sm text-destructive">
              {nameError}
            </p>
          ) : (
            <p id="workspace-name-hint" className="text-sm text-muted-foreground">
              {t('welcome.name.hint', 'Your company’s name. You can change it later.')}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <span id="workspace-language-label" className="block font-medium text-foreground">
            {t('welcome.language.label', 'Main language')}
          </span>
          {/* Two choices side by side, as the card's segmented control. */}
          <div data-testid="welcome-language">
            <ToggleGroup
              type="single"
              required
              label={t('welcome.language.label', 'Main language')}
              value={locale}
              onChange={(next) => {
                if (typeof next === 'string' && !submitting) setChosenLocale(next);
              }}
              options={[
                { value: 'en', label: 'English' },
                { value: 'ar', label: 'العربية' },
              ]}
            />
          </div>
        </div>

        <fieldset className="space-y-2">
          <legend className="font-medium text-foreground">{t('welcome.colour.label', 'Main colour')}</legend>
          <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('welcome.colour.label', 'Main colour')}>
            {MAIN_COLOUR_SWATCHES.map((swatch, i) => (
              <button
                key={swatch}
                type="button"
                role="radio"
                aria-checked={colour === swatch}
                aria-label={
                  i === 0
                    ? t('welcome.colour.default', 'Whity Blue (default)')
                    : t('welcome.colour.option', 'Colour {n}', { n: i + 1 })
                }
                onClick={() => setColour(swatch)}
                disabled={submitting}
                className="size-10 rounded-control border border-border outline-offset-2 aria-checked:outline aria-checked:outline-2 aria-checked:outline-foreground disabled:cursor-not-allowed disabled:opacity-50"
                style={{ background: swatch }}
                data-testid={`welcome-colour-${swatch.slice(1)}`}
              />
            ))}
          </div>
        </fieldset>

        <Button type="submit" size="lg" className="w-full" disabled={submitting} data-testid="welcome-create-submit">
          {submitting ? t('welcome.create.pending', 'Creating your workspace…') : t('welcome.create.submit', 'Create workspace')}
        </Button>

        <p className="text-center text-sm text-muted-foreground">
          {t('welcome.create.inviteInstead', 'Waiting for an invitation instead? Ask for it at {email}.', {
            email: setup.email,
          })}{' '}
          <Link href="/login" className="font-medium text-primary hover:underline">
            {t('welcome.signOut', 'Sign out')}
          </Link>
        </p>
      </form>
    </AuthShell>
  );
}

/** The exact address to give an administrator, with Copy (the card's note 4). */
function CopyAddress({ value, copyLabel, copiedLabel }: { value: string; copyLabel: string; copiedLabel: string }) {
  const [copied, setCopied] = useState(false);
  useEffect(() => {
    if (!copied) return;
    const timer = setTimeout(() => setCopied(false), 2000);
    return () => clearTimeout(timer);
  }, [copied]);

  return (
    <div className="flex items-center justify-between gap-3 rounded-card bg-muted px-4 py-3" data-testid="welcome-invite-address">
      <span dir="ltr" className="min-w-0 truncate text-foreground">
        {value}
      </span>
      <Button
        type="button"
        size="sm"
        variant="outline"
        onClick={() => {
          void navigator.clipboard
            ?.writeText(value)
            .then(() => setCopied(true))
            .catch(() => setCopied(false));
        }}
      >
        <Icon name={copied ? 'check' : 'copy'} />
        <span aria-live="polite">{copied ? copiedLabel : copyLabel}</span>
      </Button>
    </div>
  );
}
