'use client';

/**
 * "Confirm it's you" before a gated approval (AG.1): approving a two-step
 * reset, a password reset, or an agent's Needs verifying proposal. The server
 * names the check when it is started (an authenticator code, or a code it has
 * just emailed), and the code goes with the approval, where the server checks
 * it again. One dialog for every gated approval, so they all look and behave
 * alike.
 */

import { useEffect, useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { OtpInput } from '@whity/ui/otp-input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';

/** What the server said the approver confirms with. */
export interface IdentityCheck {
  method: 'totp' | 'email_code' | 'fresh_sign_in';
  email_hint: string | null;
  /** fresh_sign_in: the provider to sign in again at, and the approval it is for. */
  provider_key?: string | null;
  purpose?: string | null;
}

/** Where to sign in again for a fresh_sign_in check, coming back to this page. */
export function freshSignInUrl(check: IdentityCheck): string | null {
  if (check.method !== 'fresh_sign_in' || !check.provider_key || !check.purpose) return null;
  return (
    `/api/v1/auth/sso/${encodeURIComponent(check.provider_key)}/start?reauth=gate` +
    `&purpose=${encodeURIComponent(check.purpose)}&return_to=${encodeURIComponent(window.location.pathname)}`
  );
}

/**
 * Coming back from signing in again (WHIT-1147). Going to the provider unloads the page, so the
 * approval being confirmed is kept in this tab's session storage until the person returns, and the
 * server's outcome (`?identity_check=signed_in`, `sign_in_not_fresh`, `sign_in_mismatch`,
 * `sign_in_failed`) comes back on the address. Without both, the dialog was gone on return and every
 * outcome ended as "That code is not right".
 */
const RESUME_KEY = 'whity.identity-check.resume';
/** A kept approval is offered back only this long; the fresh sign-in itself lasts five minutes. */
const RESUME_TTL_MS = 10 * 60 * 1000;

export type FreshSignInOutcome = 'signed_in' | 'sign_in_not_fresh' | 'sign_in_mismatch' | 'sign_in_failed';

function rememberForReturn(state: unknown): void {
  try {
    window.sessionStorage.setItem(
      RESUME_KEY,
      JSON.stringify({ path: window.location.pathname, at: Date.now(), state }),
    );
  } catch {
    // Storage blocked: the person picks the approval again after signing in.
  }
}

/**
 * Read once: the outcome on the address and the approval this page kept before leaving. Both are
 * cleared, so a reload does not show them again. Null when this is not a return.
 */
export function takeFreshSignInReturn<T>(): { outcome: FreshSignInOutcome; state: T | null } | null {
  if (typeof window === 'undefined') return null;
  const url = new URL(window.location.href);
  const raw = url.searchParams.get('identity_check');
  if (raw === null) return null;
  url.searchParams.delete('identity_check');
  window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);

  const outcome: FreshSignInOutcome =
    raw === 'signed_in' || raw === 'sign_in_not_fresh' || raw === 'sign_in_mismatch' ? raw : 'sign_in_failed';
  let state: T | null = null;
  try {
    const kept = window.sessionStorage.getItem(RESUME_KEY);
    window.sessionStorage.removeItem(RESUME_KEY);
    const parsed = kept ? (JSON.parse(kept) as { path?: unknown; at?: unknown; state?: T | null }) : null;
    if (
      parsed &&
      parsed.path === url.pathname &&
      typeof parsed.at === 'number' &&
      Date.now() - parsed.at < RESUME_TTL_MS &&
      parsed.state !== undefined &&
      parsed.state !== null
    ) {
      state = parsed.state;
    }
  } catch {
    state = null;
  }
  return { outcome, state };
}

/**
 * For a page with a gated approval: back from signing in again, hand the kept approval to
 * `restore` (which reopens the dialog) and return the outcome for the dialog to show.
 */
export function useFreshSignInReturn<T>(restore: (state: T) => void): FreshSignInOutcome | null {
  const [outcome, setOutcome] = useState<FreshSignInOutcome | null>(null);
  const restoreRef = useRef(restore);
  useEffect(() => {
    restoreRef.current = restore;
  });
  useEffect(() => {
    const back = takeFreshSignInReturn<T>();
    if (back === null) return;
    // The address and session storage are outside React; read them once, on mount.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setOutcome(back.outcome);
    if (back.state !== null) restoreRef.current(back.state);
  }, []);
  return outcome;
}

/** The answer when the code was missing, wrong or used. */
export const IDENTITY_CHECK_FAILED = 'identity_check_failed';

/** True when an approval was refused only because the check failed. */
export function isIdentityCheckFailure(body: unknown): boolean {
  if (typeof body !== 'object' || body === null) return false;
  // The server sends it under `details` (Response::error); older answers had it on top.
  const { reason, details } = body as { reason?: unknown; details?: { reason?: unknown } };
  return reason === IDENTITY_CHECK_FAILED || details?.reason === IDENTITY_CHECK_FAILED;
}

/**
 * Start the check at one of the gated `identity-check` routes. Returns the
 * check, or the server's words for why it could not start.
 */
export async function startIdentityCheck(
  start: () => Promise<{ data?: unknown; error?: unknown; response: Response }>,
): Promise<{ check: IdentityCheck } | { error: string | null }> {
  try {
    const { data, error, response } = await start();
    const body = (data ?? error ?? {}) as { data?: IdentityCheck; error?: string };
    if (!response.ok || body.data === undefined) {
      return { error: body.error ?? null };
    }
    return { check: body.data };
  } catch {
    return { error: null };
  }
}

/** Start the check before approving an agent's Needs verifying proposal. */
export function startProposalIdentityCheck(proposalId: number) {
  return startIdentityCheck(() =>
    api.POST('/api/v1/me/agent-proposals/{id}/identity-check', { params: { path: { id: proposalId } } }),
  );
}

interface ConfirmItsYouDialogProps {
  /** The check to make; the dialog is open while it is set. */
  check: IdentityCheck | null;
  busy: boolean;
  /** Shown under the code, e.g. after a wrong code. */
  error: string | null;
  onCancel: () => void;
  onConfirm: (code: string) => void;
  /** Cleared when the person types again. */
  onClearError?: () => void;
  /**
   * fresh_sign_in: what the page needs to reopen this approval after signing in again
   * ({@link useFreshSignInReturn}). Kept in this tab only, until the person comes back.
   */
  resume?: unknown;
  /** fresh_sign_in: how signing in again went, from {@link useFreshSignInReturn}. */
  signInOutcome?: FreshSignInOutcome | null;
}

export function ConfirmItsYouDialog({
  check,
  busy,
  error,
  onCancel,
  onConfirm,
  onClearError,
  resume,
  signInOutcome = null,
}: ConfirmItsYouDialogProps) {
  const t = useTranslation('admin');
  const [code, setCode] = useState('');
  const [codeFor, setCodeFor] = useState<IdentityCheck | null>(check);

  // A new check starts with an empty code (reset while rendering, not in an effect).
  if (check !== codeFor) {
    setCodeFor(check);
    setCode('');
  }

  if (check?.method === 'fresh_sign_in') {
    // Signing in again at Whity ID (or the provider this person uses). Back here, Approve uses it.
    const url = freshSignInUrl(check);
    const signedIn = signInOutcome === 'signed_in' && !error;
    const outcomeText =
      signInOutcome === 'signed_in'
        ? t('twoStep.identity.freshSignIn.done', 'You signed in again. Approve within five minutes.')
        : signInOutcome === 'sign_in_not_fresh'
          ? t(
              'twoStep.identity.freshSignIn.notFresh',
              'That sign-in was not a new one, so it does not confirm it’s you. Sign in again and enter your details there.',
            )
          : signInOutcome === 'sign_in_mismatch'
            ? t('twoStep.identity.freshSignIn.mismatch', 'You signed in as someone else. Sign in again with your own account.')
            : signInOutcome === 'sign_in_failed'
              ? t('twoStep.identity.freshSignIn.failed', 'Signing in again did not work. Try once more.')
              : null;
    // There is no code here, so a refused approval is never "a wrong code".
    const refusal = error
      ? t('twoStep.identity.freshSignIn.refused', 'Sign in again first. It counts for five minutes and for this approval only.')
      : null;
    const signInAgain = () => {
      if (!url) return;
      rememberForReturn(resume ?? null);
      window.location.assign(url);
    };
    return (
      <Dialog open onOpenChange={(open) => !open && onCancel()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('twoStep.identity.title', 'Confirm it’s you')}</DialogTitle>
            <DialogDescription>
              {t(
                'twoStep.identity.freshSignIn',
                'Sign in again to confirm it’s you, then come back and approve within five minutes.',
              )}
            </DialogDescription>
          </DialogHeader>
          {outcomeText && !refusal ? (
            <p role="status" className={signInOutcome === 'signed_in' ? 'text-sm' : 'text-sm text-destructive'}>
              {outcomeText}
            </p>
          ) : null}
          {refusal ? (
            <p role="alert" className="text-sm text-destructive">
              {refusal}
            </p>
          ) : null}
          <DialogFooter>
            <Button variant="outline" onClick={onCancel}>
              {t('twoStep.identity.cancel', 'Cancel')}
            </Button>
            {url ? (
              <Button variant={signedIn ? 'outline' : 'default'} onClick={signInAgain}>
                {t('twoStep.identity.signInAgain', 'Sign in again')}
              </Button>
            ) : null}
            <Button variant={signedIn ? 'default' : 'outline'} disabled={busy} onClick={() => onConfirm('')}>
              {t('twoStep.identity.approve', 'Approve')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  return (
    <Dialog open={check !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('twoStep.identity.title', 'Confirm it’s you')}</DialogTitle>
          <DialogDescription>
            {check?.method === 'totp'
              ? t('twoStep.identity.totp', 'Enter the code from your authenticator app.')
              : t('twoStep.identity.email', 'We sent a code to {email}. Enter it here.', { email: check?.email_hint ?? '' })}
          </DialogDescription>
        </DialogHeader>
        <OtpInput
          label={t('twoStep.identity.code', 'Code')}
          value={code}
          onChange={(value) => {
            setCode(value);
            onClearError?.();
          }}
          onComplete={(value) => onConfirm(value)}
          errorText={error ?? undefined}
          disabled={busy}
          autoFocus
        />
        <DialogFooter>
          <Button variant="outline" onClick={onCancel}>
            {t('twoStep.identity.cancel', 'Cancel')}
          </Button>
          <Button disabled={busy || code.length < 6} onClick={() => onConfirm(code)}>
            {t('twoStep.identity.approve', 'Approve')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
