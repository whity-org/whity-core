'use client';

/**
 * "Confirm it's you" before a gated approval (AG.1): approving a two-step
 * reset, a password reset, or an agent's Needs verifying proposal. The server
 * names the check when it is started (an authenticator code, or a code it has
 * just emailed), and the code goes with the approval, where the server checks
 * it again. One dialog for every gated approval, so they all look and behave
 * alike.
 */

import { useState } from 'react';
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
}

export function ConfirmItsYouDialog({ check, busy, error, onCancel, onConfirm, onClearError }: ConfirmItsYouDialogProps) {
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
          {error ? (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          ) : null}
          <DialogFooter>
            <Button variant="outline" onClick={onCancel}>
              {t('twoStep.identity.cancel', 'Cancel')}
            </Button>
            {url ? (
              <Button variant="outline" onClick={() => window.location.assign(url)}>
                {t('twoStep.identity.signInAgain', 'Sign in again')}
              </Button>
            ) : null}
            <Button disabled={busy} onClick={() => onConfirm('')}>
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
