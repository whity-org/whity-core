'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Input } from '@/components/ui/input';
import { AuthShell } from '@/components/auth-shell';
import { MAIN_COLOUR_SWATCHES } from '@/components/branding-main-colour';
import { useLanguageCode, useTranslation, type TranslateFn } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

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
 * No card covers this exact screen; it is built to the nearest one (the
 * onboarding step in TplAuthInvite) inside the signed-out AuthShell.
 */

type Setup = components['schemas']['SsoWorkspaceSetupResponse']['data'];

type Status = 'loading' | 'ready' | 'expired' | 'error';

type FieldError = 'name_required' | 'name_too_long' | null;

const NAME_MAX = 120;

const ENDPOINT = '/api/v1/auth/sso/workspace';

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
      const response = await fetch(ENDPOINT, { credentials: 'include', cache: 'no-store' });
      if (response.status === 401) {
        setStatus('expired');
        return;
      }
      if (!response.ok) {
        setStatus('error');
        return;
      }
      const body = (await response.json()) as { data?: Setup };
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
      const response = await fetch(ENDPOINT, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on state-changing POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(request),
        credentials: 'include',
      });
      const body = (await response.json().catch(() => ({}))) as {
        user?: unknown;
        requires_tenant_selection?: boolean;
        details?: { code?: string };
      };

      if (response.ok && body.user) {
        // The session cookies are set: load them, as sign-in does, and go in.
        await refreshAuth();
        router.push('/dashboard');
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
          setFormError(t('welcome.error.choice', 'Pick a language and a colour from the lists.'));
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
      setFormError(t('welcome.error.busy', 'Your workspace could not be created just now. Nothing was saved. Please try again.'));
    } catch {
      setFormError(t('welcome.error.busy', 'Your workspace could not be created just now. Nothing was saved. Please try again.'));
    } finally {
      setSubmitting(false);
    }
  };

  const signInAgain = (
    <Button asChild className="w-full">
      <Link href="/login">{t('welcome.signInAgain', 'Back to sign in')}</Link>
    </Button>
  );
  const differentAccount = (
    <p className="pt-2 text-center text-sm text-muted-foreground">
      <Link href="/login" className="font-medium text-primary hover:underline">
        {t('welcome.differentAccount', 'Sign in with a different account')}
      </Link>
    </p>
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
            ? t('welcome.error.title', 'Something went wrong')
            : t('welcome.expired.title', 'Please sign in again')
        }
        subtitle={
          status === 'error'
            ? t('welcome.error.subtitle', 'We could not check your sign-in. Please sign in again.')
            : t('welcome.expired.subtitle', 'This sign-in has expired. It only lasts a few minutes.')
        }
      >
        <div className="space-y-4" data-testid={status === 'error' ? 'welcome-error' : 'welcome-expired'}>
          {signInAgain}
        </div>
      </AuthShell>
    );
  }

  if (setup.state === 'waiting') {
    return (
      <AuthShell
        title={t('welcome.waiting.title', 'Your place is waiting')}
        subtitle={t('welcome.waiting.subtitle', 'You signed in as {email}.', { email: setup.email })}
      >
        <div className="space-y-4" data-testid="welcome-waiting">
          <p className="text-foreground">
            {t(
              'welcome.waiting.body',
              'You already belong to a workspace, but you cannot use it yet. An administrator of that workspace can let you in.'
            )}
          </p>
          <ul className="divide-y divide-border rounded-card border border-border">
            {(setup.workspaces ?? []).map((w, i) => (
              <li key={`${w.name}-${i}`} className="flex min-h-12 flex-col justify-center px-4 py-2">
                <span className="font-medium text-foreground">{w.name}</span>
                <span className="text-sm text-muted-foreground">{waitingLabel(t, w.status)}</span>
              </li>
            ))}
          </ul>
          {signInAgain}
        </div>
      </AuthShell>
    );
  }

  if (setup.state === 'needs_invite') {
    return (
      <AuthShell
        title={t('welcome.invite.title', 'You need an invitation')}
        subtitle={t('welcome.invite.subtitle', 'You signed in as {email}.', { email: setup.email })}
      >
        <div className="space-y-4" data-testid="welcome-needs-invite">
          <p className="text-foreground">
            {t(
              'welcome.invite.body',
              'You are not part of a workspace here yet. Ask an administrator of your company’s workspace to invite {email}, then sign in again.',
              { email: setup.email }
            )}
          </p>
          {signInAgain}
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
        'Your company’s own space. You will be its owner, and you can invite your team next.'
      )}
    >
      <form onSubmit={handleSubmit} className="space-y-5" data-testid="welcome-create-form" noValidate>
        {formError && (
          <Alert variant="destructive">
            <AlertDescription>{formError}</AlertDescription>
          </Alert>
        )}

        <p className="text-sm text-muted-foreground" data-testid="welcome-email">
          {t('welcome.create.signedInAs', 'You signed in as {email}.', { email: setup.email })}
        </p>

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
              {t('welcome.name.hint', 'Usually your company’s name. You can change it later.')}
            </p>
          )}
        </div>

        <div className="space-y-2">
          <label htmlFor="workspace-language" className="font-medium text-foreground">
            {t('welcome.language.label', 'Main language')}
          </label>
          <Select value={locale} onValueChange={setChosenLocale} disabled={submitting}>
            <SelectTrigger id="workspace-language" className="w-full" data-testid="welcome-language">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="en">English</SelectItem>
              <SelectItem value="ar" lang="ar">
                العربية
              </SelectItem>
            </SelectContent>
          </Select>
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
          <p className="text-sm text-muted-foreground">
            {t('welcome.colour.hint', 'It appears on every screen, email and document. You can change it later.')}
          </p>
        </fieldset>

        <Button type="submit" className="w-full" disabled={submitting} data-testid="welcome-create-submit">
          {submitting ? t('welcome.create.pending', 'Creating your workspace…') : t('welcome.create.submit', 'Create workspace')}
        </Button>

        {differentAccount}
      </form>
    </AuthShell>
  );
}
