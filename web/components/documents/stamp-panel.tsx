'use client';

/**
 * Applying a company stamp on a `stamp` step (#1729).
 *
 * A company stamp is held by named people (or roles). On a stamp step one of
 * them applies it: the stamp's image goes on this version of the document,
 * with an audit trail of who applied it and how they confirmed it was them.
 * That is an authorised person applying the image of the stamp, NOT a
 * certified electronic seal, and the copy here says exactly that.
 *
 * Modelled on {@link SignPanel}: the same consent tick, the same second check
 * (authenticator code, email code or signing in again), and declining is the
 * same ordinary act on the route, with a reason. There is nothing to draw: the
 * stamp's own image is what is applied.
 *
 * The server checks every one of these again, including that the person holds
 * the stamp, and its refusal is shown as it comes.
 */

import { useCallback, useEffect, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@whity/ui/input';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import type { DocumentRoute } from './routing-wire';
import { errorOf } from './sign-panel';

type SecondFactorMethod = 'totp' | 'email_code' | 'fresh_sign_in';

/** The consent wording version this build shows in full (`SigningConsent::STAMP_CURRENT`). */
export const STAMP_CONSENT_VERSION = '2026-11-stamp-1';

interface StampingContext {
  route_id: number;
  can_stamp: boolean;
  is_holder: boolean;
  step: { id: number; position: number; label: string | null; quorum: 'all' | 'any' } | null;
  stamp: { id: number; name: string; legal_name: string; image: string } | null;
  subject: { type: string; id: number; title: string | null; version: number; content_hash: string } | null;
  consent_version: string;
  second_factor: {
    method: SecondFactorMethod;
    provider_key: string | null;
    email_hint: string | null;
    signed_in_again: boolean;
  };
}

export interface StampPanelProps {
  documentId: number;
  route: DocumentRoute;
  onActed: () => void;
  /** Called once the stamp is recorded, before `onActed` refetches. */
  onStamped?: () => void;
}

export function StampPanel({ documentId, route, onActed, onStamped }: StampPanelProps) {
  const t = useTranslation('documents');
  const { addToast } = useToast();

  const [context, setContext] = useState<StampingContext | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [consent, setConsent] = useState(false);
  const [code, setCode] = useState('');
  const [codeHint, setCodeHint] = useState<string | null>(null);
  const [busy, setBusy] = useState<'stamp' | 'code' | 'decline' | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const [returnOutcome, setReturnOutcome] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, response } = await api.GET('/api/v1/documents/{id}/routes/{routeId}/stamping', {
        params: { path: { id: documentId, routeId: route.id } },
      });
      if (!response.ok) {
        setLoadError(true);
        return;
      }
      const body = data as unknown as { data: StampingContext };
      setContext(body.data);
    } catch {
      setLoadError(true);
    }
  }, [documentId, route.id]);

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
      <Alert variant="destructive" data-slot="stamp-panel-error">
        <AlertDescription>{t('stamping.loadError', 'The stamp details could not be loaded.')}</AlertDescription>
      </Alert>
    );
  }
  if (context === null) {
    return <p className="text-sm text-muted-foreground">{t('signing.loading', 'Loading…')}</p>;
  }

  const factor = context.second_factor;
  const factorReady =
    factor.method === 'fresh_sign_in' ? factor.signed_in_again : /^\d{6}$/.test(code.replace(/\s+/g, ''));
  const canSubmit = context.can_stamp && consent && factorReady && busy === null;

  const sendCode = async () => {
    setBusy('code');
    setRefusal(null);
    try {
      const { data, error, response } = await api.POST('/api/v1/documents/{id}/routes/{routeId}/signing/code', {
        params: { path: { id: documentId, routeId: route.id } },
      });
      if (!response.ok) {
        setRefusal(errorOf(error, t('signing.code.error', 'The code could not be sent.')));
        return;
      }
      const body = data as unknown as { data: { email_hint: string | null } };
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

  const applyStamp = async () => {
    if (context.subject === null) return;
    setBusy('stamp');
    setRefusal(null);
    try {
      const { error, response } = await api.POST('/api/v1/documents/{id}/routes/{routeId}/stamp', {
        params: { path: { id: documentId, routeId: route.id } },
        body: {
          content_hash: context.subject.content_hash,
          consent,
          consent_version: context.consent_version,
          second_factor:
            factor.method === 'fresh_sign_in'
              ? { method: factor.method }
              : { method: factor.method, code: code.replace(/\s+/g, '') },
        } as never,
      });
      if (!response.ok) {
        const message = errorOf(error, t('stamping.error', 'The stamp could not be recorded.'));
        setRefusal(message);
        addToast(message, 'error');
        if (response.status === 409 || response.status === 403) void load();
        return;
      }
      addToast(t('stamping.done', 'Stamp applied. It is recorded with the audit trail.'), 'success');
      onStamped?.();
      onActed();
    } catch {
      setRefusal(t('stamping.error', 'The stamp could not be recorded.'));
    } finally {
      setBusy(null);
    }
  };

  const decline = async () => {
    setBusy('decline');
    setRefusal(null);
    try {
      const { error, response } = await api.POST('/api/v1/documents/{id}/routes/{routeId}/actions', {
        params: { path: { id: documentId, routeId: route.id } },
        body: { action: 'declined', note: reason.trim() } as never,
      });
      if (!response.ok) {
        setRefusal(errorOf(error, t('signing.decline.error', 'Your decline could not be recorded.')));
        return;
      }
      addToast(t('stamping.decline.done', 'You declined to apply the stamp. Your reason is in the trail.'), 'info');
      onActed();
    } catch {
      setRefusal(t('signing.decline.error', 'Your decline could not be recorded.'));
    } finally {
      setBusy(null);
    }
  };

  const { step, stamp } = context;

  return (
    <div className="space-y-4" data-slot="stamp-panel" data-holder={context.is_holder ? 'true' : 'false'}>
      <div>
        <h4 className="text-sm font-semibold text-foreground">
          {t('stamping.heading', 'The company stamp on this route')}
        </h4>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            'stamping.intro',
            'An authorised person applies the image of this company stamp, with an audit trail. It is not a certified electronic seal.'
          )}
        </p>
      </div>

      {returnOutcome !== null && (
        <Alert variant={returnOutcome === 'signed_in' ? 'success' : 'warning'} data-slot="stamp-panel-return">
          <AlertDescription>
            {returnOutcome === 'signed_in'
              ? t('stamping.freshSignIn.done', 'You signed in again. Apply the stamp within five minutes.')
              : returnOutcome === 'sign_in_mismatch'
                ? t('signing.freshSignIn.mismatch', 'You signed in as someone else. Sign in again with your own account.')
                : t('signing.freshSignIn.failed', 'Signing in again did not work. Try once more.')}
          </AlertDescription>
        </Alert>
      )}

      {stamp !== null && (
        <div
          className="flex flex-wrap items-center gap-4 rounded-md border border-border p-3"
          data-slot="stamp-panel-stamp"
        >
          {/* A data URL the server checked (PNG or JPEG); next/image adds nothing here. */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={stamp.image}
            alt={t('stamping.imageAlt', 'The image of the stamp {name}', { name: stamp.name })}
            className="size-24 shrink-0 object-contain"
          />
          <div className="min-w-0 space-y-1">
            <p className="text-sm font-semibold text-foreground">{stamp.name}</p>
            <p className="text-sm text-muted-foreground">
              {t('stamping.legalName', 'Stands for {legalName}', { legalName: stamp.legal_name })}
            </p>
          </div>
        </div>
      )}

      {step !== null && context.subject !== null && (
        <dl className="grid gap-2 rounded-md border border-border p-3 text-sm" data-slot="stamp-panel-facts">
          <div>
            <dt className="text-xs text-muted-foreground">
              {t('signing.version', 'Version {version}, content fingerprint', { version: context.subject.version })}
            </dt>
            <dd className="font-mono text-xs text-foreground" dir="ltr">
              {context.subject.content_hash}
            </dd>
          </div>
          <div className="text-xs text-muted-foreground">
            {step.quorum === 'any'
              ? t('stamping.quorum.any', 'One stamp from any of the holders completes this step.')
              : t('stamping.quorum.all', 'Every holder asked at this step must apply the stamp.')}
          </div>
        </dl>
      )}

      {!context.is_holder && (
        <Alert variant="warning" data-slot="stamp-panel-not-holder">
          <AlertDescription>
            {t(
              'stamping.notHolder',
              'Only a holder of this company stamp can apply it, and you are not one. People who manage company stamps in Settings choose who holds each stamp. You can still decline, with a reason.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {refusal !== null && (
        <Alert variant="destructive" data-slot="stamp-panel-refusal">
          <AlertDescription>{refusal}</AlertDescription>
        </Alert>
      )}

      {!declining && (
        <>
          {context.is_holder && (
            <>
              <label className="flex items-start gap-2 text-sm text-foreground" data-slot="stamp-panel-consent">
                <Checkbox checked={consent} onCheckedChange={(v) => setConsent(v === true)} className="mt-0.5" />
                <span>
                  {t(
                    'stamping.consent.v2026stamp1',
                    'I hold this company stamp and I am authorised to apply it to this document. I agree that this audit trail records that I applied it to this version. This is an authorised person applying the image of the stamp, not a certified electronic seal.'
                  )}
                </span>
              </label>

              <section className="space-y-2" data-slot="stamp-panel-check" data-method={factor.method}>
                <h5 className="text-sm font-medium text-foreground">
                  {t('signing.check.heading', 'Confirm it is you')}
                </h5>
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
                        ? t('stamping.check.freshDone', 'You signed in again. You can apply the stamp now.')
                        : t(
                            'stamping.check.fresh',
                            'Sign in again with your sign-in provider, then come back here to apply the stamp.'
                          )}
                    </p>
                    {!factor.signed_in_again && (
                      <Button type="button" variant="outline" onClick={signInAgain} disabled={busy !== null}>
                        {t('signing.check.signInAgain', 'Sign in again')}
                      </Button>
                    )}
                  </>
                )}
              </section>
            </>
          )}

          <div className="flex flex-wrap gap-2">
            {context.is_holder && (
              <Button
                type="button"
                onClick={() => void applyStamp()}
                disabled={!canSubmit}
                data-testid="stamp-panel-apply"
              >
                {busy === 'stamp' ? t('stamping.applying', 'Applying…') : t('stamping.apply', 'Apply stamp')}
              </Button>
            )}
            <Button type="button" variant="outline" onClick={() => setDeclining(true)} disabled={busy !== null}>
              {t('stamping.decline', 'Decline to stamp')}
            </Button>
          </div>
        </>
      )}

      {declining && (
        <section className="space-y-2" data-slot="stamp-panel-decline">
          <label htmlFor={`stamp-decline-reason-${route.id}`} className="text-sm font-medium text-foreground">
            {t('signing.decline.reason', 'Why are you declining? The reason goes in the trail.')}
          </label>
          <Textarea
            id={`stamp-decline-reason-${route.id}`}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
          />
          <div className="flex flex-wrap gap-2">
            <Button type="button" onClick={() => void decline()} disabled={busy !== null || reason.trim() === ''}>
              {t('stamping.decline.confirm', 'Decline to stamp')}
            </Button>
            <Button type="button" variant="outline" onClick={() => setDeclining(false)} disabled={busy !== null}>
              {t('stamping.decline.cancel', 'Back to the stamp')}
            </Button>
          </div>
        </section>
      )}
    </div>
  );
}
