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
  method: 'totp' | 'email_code';
  email_hint: string | null;
}

/** The answer when the code was missing, wrong or used. */
export const IDENTITY_CHECK_FAILED = 'identity_check_failed';

/** True when an approval was refused only because the check failed. */
export function isIdentityCheckFailure(body: unknown): boolean {
  return typeof body === 'object' && body !== null && (body as { reason?: unknown }).reason === IDENTITY_CHECK_FAILED;
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
