'use client';

/**
 * Signing on a `sign` step (WHIT-922, #1726).
 *
 * An electronic signature with an audit trail, not a handwritten one, and the
 * copy here says so and nothing more. The person sees what they sign (the
 * document on this page, and its content hash), the capacity and on whose
 * behalf the route asks them to sign, ticks the consent line, draws their
 * signature (or uses the one they saved), and confirms with the check their
 * account uses: a code from their authenticator app, a code by email, or
 * signing in again through their sign-in provider.
 *
 * The server checks every one of these again, and its refusal is shown as it
 * comes. Declining is an ordinary act on the route, with a reason.
 */

import { useCallback, useEffect, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@whity/ui/input';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import type { DocumentRoute } from './routing-wire';
import {
  SignatureDrawing,
  SignaturePad,
  pointCount,
  type SignatureAppearance,
} from './signature-drawing';

type SecondFactorMethod = 'totp' | 'email_code' | 'fresh_sign_in';

interface SigningContext {
  route_id: number;
  can_sign: boolean;
  step: {
    id: number;
    position: number;
    label: string | null;
    capacity: string | null;
    on_behalf_of: string | null;
    quorum: 'all' | 'any';
  } | null;
  subject: { type: string; id: number; title: string | null; version: number; content_hash: string } | null;
  consent_version: string;
  second_factor: {
    method: SecondFactorMethod;
    provider_key: string | null;
    email_hint: string | null;
    signed_in_again: boolean;
  };
  saved_signature: SignatureAppearance | null;
}

export interface SignPanelProps {
  documentId: number;
  route: DocumentRoute;
  onActed: () => void;
}

async function errorOf(response: Response, fallback: string): Promise<string> {
  const body: unknown = await response.json().catch(() => null);
  const error = typeof body === 'object' && body !== null ? (body as { error?: unknown }).error : undefined;
  return typeof error === 'string' && error !== '' ? error : fallback;
}

export function SignPanel({ documentId, route, onActed }: SignPanelProps) {
  const t = useTranslation('documents');
  const { apiClient } = useAuth();
  const { addToast } = useToast();

  const [context, setContext] = useState<SigningContext | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [drawing, setDrawing] = useState<SignatureAppearance | null>(null);
  const [useSaved, setUseSaved] = useState(false);
  const [saveForNext, setSaveForNext] = useState(false);
  const [consent, setConsent] = useState(false);
  const [code, setCode] = useState('');
  const [codeHint, setCodeHint] = useState<string | null>(null);
  const [busy, setBusy] = useState<'sign' | 'code' | 'decline' | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const [returnOutcome, setReturnOutcome] = useState<string | null>(null);

  const base = `/api/v1/documents/${documentId}/routes/${route.id}`;

  const load = useCallback(async () => {
    try {
      const response = await apiClient(`${base}/signing`);
      if (!response.ok) {
        setLoadError(true);
        return;
      }
      const body = (await response.json()) as { data: SigningContext };
      setContext(body.data);
      setUseSaved(body.data.saved_signature !== null);
    } catch {
      setLoadError(true);
    }
  }, [apiClient, base]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads once per route
    void load();
    // Back from signing in again through the provider: say how it went.
    if (typeof window !== 'undefined') {
      const outcome = new URLSearchParams(window.location.search).get('signing');
      if (outcome !== null) setReturnOutcome(outcome);
    }
  }, [load]);

  if (loadError) {
    return (
      <Alert variant="destructive" data-slot="sign-panel-error">
        <AlertDescription>{t('signing.loadError', 'The signing details could not be loaded.')}</AlertDescription>
      </Alert>
    );
  }
  if (context === null) {
    return <p className="text-sm text-muted-foreground">{t('signing.loading', 'Loading…')}</p>;
  }

  const factor = context.second_factor;
  const appearanceReady = useSaved ? context.saved_signature !== null : pointCount(drawing) >= 5;
  const factorReady =
    factor.method === 'fresh_sign_in' ? factor.signed_in_again : /^\d{6}$/.test(code.replace(/\s+/g, ''));
  const canSubmit = context.can_sign && consent && appearanceReady && factorReady && busy === null;

  const sendCode = async () => {
    setBusy('code');
    setRefusal(null);
    try {
      const response = await apiClient(`${base}/signing/code`, { method: 'POST' });
      if (!response.ok) {
        setRefusal(await errorOf(response, t('signing.code.error', 'The code could not be sent.')));
        return;
      }
      const body = (await response.json()) as { data: { email_hint: string | null } };
      setCodeHint(body.data.email_hint);
    } catch {
      setRefusal(t('signing.code.error', 'The code could not be sent.'));
    } finally {
      setBusy(null);
    }
  };

  const signInAgain = () => {
    if (factor.provider_key === null) return;
    const returnTo = window.location.pathname;
    window.location.href =
      `/api/v1/auth/sso/${encodeURIComponent(factor.provider_key)}/start?reauth=sign&route_id=${route.id}&return_to=` +
      encodeURIComponent(returnTo);
  };

  const sign = async () => {
    if (context.subject === null) return;
    setBusy('sign');
    setRefusal(null);
    try {
      const response = await apiClient(`${base}/sign`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          content_hash: context.subject.content_hash,
          consent,
          consent_version: context.consent_version,
          ...(useSaved ? { use_saved_signature: true } : { appearance: drawing }),
          save_signature: !useSaved && saveForNext,
          second_factor:
            factor.method === 'fresh_sign_in'
              ? { method: factor.method }
              : { method: factor.method, code: code.replace(/\s+/g, '') },
        }),
      });
      if (!response.ok) {
        const message = await errorOf(response, t('signing.error', 'The signature could not be recorded.'));
        setRefusal(message);
        addToast(message, 'error');
        if (response.status === 409) void load();
        return;
      }
      addToast(t('signing.done', 'Signed. Your electronic signature is recorded with the audit trail.'), 'success');
      onActed();
    } catch {
      setRefusal(t('signing.error', 'The signature could not be recorded.'));
    } finally {
      setBusy(null);
    }
  };

  const decline = async () => {
    setBusy('decline');
    setRefusal(null);
    try {
      const response = await apiClient(`${base}/actions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'declined', note: reason.trim() }),
      });
      if (!response.ok) {
        setRefusal(await errorOf(response, t('signing.decline.error', 'Your decline could not be recorded.')));
        return;
      }
      addToast(t('signing.decline.done', 'You declined to sign. Your reason is in the trail.'), 'info');
      onActed();
    } catch {
      setRefusal(t('signing.decline.error', 'Your decline could not be recorded.'));
    } finally {
      setBusy(null);
    }
  };

  const step = context.step;

  return (
    <div className="space-y-4" data-slot="sign-panel">
      <div>
        <h4 className="text-sm font-semibold text-foreground">
          {t('signing.heading', 'Your signature on this route')}
        </h4>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            'signing.intro',
            'This is an electronic signature with an audit trail. It is recorded against this exact version of the document.'
          )}
        </p>
      </div>

      {returnOutcome !== null && (
        <Alert variant={returnOutcome === 'signed_in' ? 'success' : 'warning'} data-slot="sign-panel-return">
          <AlertDescription>
            {returnOutcome === 'signed_in'
              ? t('signing.freshSignIn.done', 'You signed in again. Sign within five minutes.')
              : returnOutcome === 'sign_in_mismatch'
                ? t('signing.freshSignIn.mismatch', 'You signed in as someone else. Sign in again with your own account.')
                : t('signing.freshSignIn.failed', 'Signing in again did not work. Try once more.')}
          </AlertDescription>
        </Alert>
      )}

      {step !== null && (
        <dl className="grid gap-2 rounded-md border border-border p-3 text-sm sm:grid-cols-2" data-slot="sign-panel-facts">
          <div>
            <dt className="text-xs text-muted-foreground">{t('signing.capacity', 'Signing as')}</dt>
            <dd className="text-foreground">{step.capacity ?? t('signing.capacity.self', 'Yourself')}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">{t('signing.onBehalfOf', 'On behalf of')}</dt>
            <dd className="text-foreground">{step.on_behalf_of ?? t('signing.onBehalfOf.self', 'Yourself')}</dd>
          </div>
          {context.subject !== null && (
            <div className="sm:col-span-2">
              <dt className="text-xs text-muted-foreground">
                {t('signing.version', 'Version {version}, content fingerprint', { version: context.subject.version })}
              </dt>
              <dd className="font-mono text-xs text-foreground" dir="ltr">
                {context.subject.content_hash}
              </dd>
            </div>
          )}
          <div className="sm:col-span-2 text-xs text-muted-foreground">
            {step.quorum === 'any'
              ? t('signing.quorum.any', 'One signature from any of the people asked completes this step.')
              : t('signing.quorum.all', 'Everyone asked at this step must sign.')}
          </div>
        </dl>
      )}

      {refusal !== null && (
        <Alert variant="destructive" data-slot="sign-panel-refusal">
          <AlertDescription>{refusal}</AlertDescription>
        </Alert>
      )}

      {!declining && (
        <>
          <section className="space-y-2">
            <h5 className="text-sm font-medium text-foreground">{t('signing.draw.heading', 'Your signature')}</h5>
            {context.saved_signature !== null && (
              <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox checked={useSaved} onCheckedChange={(v) => setUseSaved(v === true)} />
                {t('signing.useSaved', 'Use my saved signature')}
              </label>
            )}
            {useSaved && context.saved_signature !== null ? (
              <SignatureDrawing
                appearance={context.saved_signature}
                label={t('signing.saved.label', 'Your saved signature')}
                className="rounded-md border border-border p-2"
              />
            ) : (
              <>
                <SignaturePad value={drawing} onChange={setDrawing} disabled={busy !== null} />
                <label className="flex items-center gap-2 text-sm text-foreground">
                  <Checkbox checked={saveForNext} onCheckedChange={(v) => setSaveForNext(v === true)} />
                  {t('signing.saveForNext', 'Save this signature for next time')}
                </label>
              </>
            )}
          </section>

          <label className="flex items-start gap-2 text-sm text-foreground" data-slot="sign-panel-consent">
            <Checkbox checked={consent} onCheckedChange={(v) => setConsent(v === true)} className="mt-0.5" />
            <span>
              {t(
                'signing.consent.v20261101',
                'I have read this document and I sign it electronically, in the capacity shown. I agree that my electronic signature, with this audit trail, records that I signed this version.'
              )}
            </span>
          </label>

          <section className="space-y-2" data-slot="sign-panel-check" data-method={factor.method}>
            <h5 className="text-sm font-medium text-foreground">{t('signing.check.heading', 'Confirm it is you')}</h5>
            {factor.method === 'totp' && (
              <>
                <p className="text-sm text-muted-foreground">
                  {t('signing.check.totp', 'Enter the 6-digit code from your authenticator app.')}
                </p>
                <Input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={8}
                  dir="ltr"
                  className="max-w-40 font-mono"
                  aria-label={t('signing.check.codeLabel', 'Code')}
                />
              </>
            )}
            {factor.method === 'email_code' && (
              <>
                <p className="text-sm text-muted-foreground">
                  {codeHint !== null
                    ? t('signing.check.emailSent', 'We sent a 6-digit code to {email}. It expires in 10 minutes.', {
                        email: codeHint,
                      })
                    : t('signing.check.email', 'We will send a 6-digit code to your email address.')}
                </p>
                <div className="flex flex-wrap items-center gap-2">
                  <Button type="button" variant="outline" onClick={() => void sendCode()} disabled={busy !== null}>
                    {codeHint !== null
                      ? t('signing.check.resend', 'Send a new code')
                      : t('signing.check.send', 'Send me a code')}
                  </Button>
                  <Input
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={8}
                    dir="ltr"
                    className="max-w-40 font-mono"
                    aria-label={t('signing.check.codeLabel', 'Code')}
                  />
                </div>
              </>
            )}
            {factor.method === 'fresh_sign_in' && (
              <>
                <p className="text-sm text-muted-foreground">
                  {factor.signed_in_again
                    ? t('signing.check.freshDone', 'You signed in again. You can sign now.')
                    : t('signing.check.fresh', 'Sign in again with your sign-in provider, then come back here to sign.')}
                </p>
                {!factor.signed_in_again && (
                  <Button type="button" variant="outline" onClick={signInAgain} disabled={busy !== null}>
                    {t('signing.check.signInAgain', 'Sign in again')}
                  </Button>
                )}
              </>
            )}
          </section>

          <div className="flex flex-wrap gap-2">
            <Button type="button" onClick={() => void sign()} disabled={!canSubmit} data-testid="sign-panel-sign">
              {busy === 'sign' ? t('signing.signing', 'Signing…') : t('signing.sign', 'Sign')}
            </Button>
            <Button type="button" variant="outline" onClick={() => setDeclining(true)} disabled={busy !== null}>
              {t('signing.decline', 'Decline to sign')}
            </Button>
          </div>
        </>
      )}

      {declining && (
        <section className="space-y-2" data-slot="sign-panel-decline">
          <label htmlFor={`decline-reason-${route.id}`} className="text-sm font-medium text-foreground">
            {t('signing.decline.reason', 'Why are you declining? The reason goes in the trail.')}
          </label>
          <Textarea
            id={`decline-reason-${route.id}`}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
          />
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              onClick={() => void decline()}
              disabled={busy !== null || reason.trim() === ''}
            >
              {t('signing.decline.confirm', 'Decline to sign')}
            </Button>
            <Button type="button" variant="outline" onClick={() => setDeclining(false)} disabled={busy !== null}>
              {t('signing.decline.cancel', 'Back to signing')}
            </Button>
          </div>
        </section>
      )}
    </div>
  );
}
