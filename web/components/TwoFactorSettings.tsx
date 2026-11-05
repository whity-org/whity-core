'use client';

import React, { useCallback, useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@whity/ui/button';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { IconCheck } from '@tabler/icons-react';
import QRCode from 'react-qr-code';

interface TwoFactorStatus {
  enabled: boolean;
  backup_codes_available: number;
  password_available: boolean;
}

/**
 * A wizard failure is either text the BACKEND supplied — already a sentence,
 * in whatever language it speaks — or one of OURS, recorded as a variant and
 * translated at render time.
 *
 * Recording ours rather than resolving it immediately is what keeps the setup
 * effect off `t`: `t`'s identity changes the moment the translation bundle
 * arrives, so listing it as a dependency would re-run the effect and mint a
 * SECOND TOTP secret under a QR code the user may already have scanned.
 */
type WizardError =
  | { kind: 'none' }
  | { kind: 'backend'; text: string }
  | { kind: 'setup' }
  | { kind: 'fetch' }
  | { kind: 'codeRequired' }
  | { kind: 'verify' };

/** Resolve a {@link WizardError} to the sentence to show. */
function wizardErrorText(t: TranslateFn, error: WizardError): string {
  switch (error.kind) {
    case 'none':
      return '';
    case 'backend':
      return error.text;
    case 'setup':
      return t('twoFactorSetup.error.setup', 'Failed to setup 2FA');
    case 'fetch':
      return t('twoFactorSetup.error.fetch', 'Failed to fetch setup data');
    case 'codeRequired':
      return t('twoFactorSetup.error.codeRequired', 'Please enter the verification code');
    case 'verify':
      return t('twoFactorSetup.error.verify', 'Failed to verify code');
  }
}

/**
 * The same treatment for the settings panel, and for the same reason: its
 * status fetch is a `useCallback` a mount effect depends on, so pulling `t`
 * into it would re-run the fetch — and flash the loading spinner over an
 * already-rendered panel — as soon as the translation bundle arrived.
 */
type SettingsError =
  | { kind: 'none' }
  | { kind: 'backend'; text: string }
  | { kind: 'status' };

/** Resolve a {@link SettingsError} to the sentence to show. */
function settingsErrorText(t: TranslateFn, error: SettingsError): string {
  switch (error.kind) {
    case 'none':
      return '';
    case 'backend':
      return error.text;
    case 'status':
      return t('twoFactorSettings.error.status', 'Failed to fetch 2FA status');
  }
}

interface TwoFactorSetupWizardProps {
  onComplete: (codes: string[]) => void;
  onCancel: () => void;
  /**
   * Bearer token to authenticate setup()/confirm() with INSTEAD of the normal
   * session (via apiClient). Used by the login-time mandatory-enrollment flow
   * (WC-525), where the caller holds a narrowly-scoped `two_factor_enrollment`
   * token (see TokenValidator::validateTwoFactorEnrollmentToken) rather than a
   * full session — there is no session to attach cookies/headers from yet.
   */
  bearerToken?: string;
}

export const TwoFactorSetupWizard: React.FC<TwoFactorSetupWizardProps> = ({ onComplete, onCancel, bearerToken }) => {
  const { apiClient } = useAuth();
  const t = useTranslation('auth');
  const [step, setStep] = useState<'setup' | 'verify'>('setup');
  const [secret, setSecret] = useState<string>('');
  const [qrCodeUrl, setQrCodeUrl] = useState<string>('');
  const [code, setCode] = useState<string>('');
  const [error, setError] = useState<WizardError>({ kind: 'none' });
  const [loading, setLoading] = useState<boolean>(false);

  const doFetch = useCallback((path: string, init?: RequestInit) => {
    if (bearerToken) {
      return fetch(path, {
        ...init,
        headers: {
          ...(init?.headers ?? {}),
          'Content-Type': 'application/json',
          Authorization: `Bearer ${bearerToken}`,
        },
      });
    }
    return apiClient(path, init);
  }, [apiClient, bearerToken]);

  useEffect(() => {
    const fetchSetup = async () => {
      try {
        const response = await doFetch('/api/v1/auth/2fa/setup', {
          method: 'POST',
        });

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          setError(
            errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'setup' }
          );
          return;
        }

        const data = await response.json();
        setSecret(data.secret);
        setQrCodeUrl(data.qrCodeUrl);
      } catch {
        setError({ kind: 'fetch' });
      }
    };

    fetchSetup();
  }, [doFetch]);

  const handleVerify = async () => {
    if (!code.trim()) {
      setError({ kind: 'codeRequired' });
      return;
    }

    setLoading(true);
    setError({ kind: 'none' });

    try {
      const response = await doFetch('/api/v1/auth/2fa/confirm', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ code, secret }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'verify' }
        );
        setLoading(false);
        return;
      }

      const data = await response.json();
      onComplete(data.backup_codes);
    } catch {
      setError({ kind: 'verify' });
      setLoading(false);
    }
  };

  const errorText = wizardErrorText(t, error);

  return (
    <Dialog open={true} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="w-[90vw] max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {t('twoFactorSetup.title', 'Enable Two-Factor Authentication')}
          </DialogTitle>
          <DialogDescription>
            {t('twoFactorSetup.subtitle', 'Secure your account with two-factor authentication')}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium mb-2">
                {t('twoFactorSetup.scan.label', 'Scan with your authenticator app:')}
              </p>
              {qrCodeUrl && (
                <div className="flex justify-center">
                  <QRCode
                    value={qrCodeUrl}
                    size={200}
                    level="H"
                    className="border border-border rounded p-2"
                  />
                </div>
              )}
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-2">
                {t('twoFactorSetup.manual.label', "Can't scan? Enter this code manually:")}
              </p>
              <div className="flex items-center gap-2">
                <code
                  className="flex-1 bg-muted p-2 rounded text-sm font-mono break-all cursor-pointer hover:bg-muted/80 select-all"
                  title={secret}>
                  {secret}
                </code>
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(secret);
                  }}
                  className="px-3 py-2 bg-primary hover:bg-primary/80 text-primary-foreground rounded text-sm whitespace-nowrap shrink-0"
                >
                  {t('twoFactorSetup.copy', 'Copy')}
                </button>
              </div>
            </div>
            <Button
              onClick={() => setStep('verify')}
              className="w-full"
            >
              {t('twoFactorSetup.next', 'Next')}
            </Button>
            <Button
              variant="outline"
              onClick={onCancel}
              className="w-full"
            >
              {t('twoFactorSetup.cancel', 'Cancel')}
            </Button>
          </div>
        )}

        {step === 'verify' && (
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-2">
                {t(
                  'twoFactorSetup.code.label',
                  'Enter the 6-digit code from your authenticator app:'
                )}
              </label>
              <Input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                placeholder="000000"
                maxLength={6}
                className="text-center text-2xl tracking-widest h-12"
              />
            </div>
            {errorText && <Alert variant="destructive"><AlertDescription>{errorText}</AlertDescription></Alert>}
            <Button
              onClick={handleVerify}
              disabled={loading || code.length !== 6}
              className="w-full"
            >
              {loading
                ? t('twoFactorSetup.submit.pending', 'Verifying...')
                : t('twoFactorSetup.submit', 'Verify')}
            </Button>
            <Button
              variant="outline"
              onClick={() => setStep('setup')}
              className="w-full"
            >
              {t('twoFactorSetup.back', 'Back')}
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
};

/** What the person typed to prove it is them. */
type TwoFactorProof = { code: string } | { password: string };

/** Why the server refused a proof, or `failed` for anything else that went wrong. */
type ProofRefusal = 'required' | 'invalid' | 'throttled' | 'failed';

/** Read the server's refusal into the words the dialog shows. */
async function proofRefusalOf(response: Response): Promise<ProofRefusal> {
  const body = await response.json().catch(() => ({}));
  switch (body?.details?.reason) {
    case 'proof_required':
      return 'required';
    case 'proof_invalid':
      return 'invalid';
    case 'throttled':
      return 'throttled';
    default:
      return 'failed';
  }
}

interface TwoFactorProofDialogProps {
  title: string;
  /** Names what will happen, so nobody confirms this without knowing. */
  consequence: string;
  submitLabel: string;
  pendingLabel: string;
  /** Said when the request failed for a reason other than the proof. */
  failureText: string;
  destructive: boolean;
  /** Offer the password as well as a code. False for accounts that have none. */
  passwordAvailable: boolean;
  /** Send the proof; resolves to null when the change went through. */
  onSubmit: (proof: TwoFactorProof) => Promise<ProofRefusal | null>;
  onCancel: () => void;
}

/**
 * Asks for fresh proof before a change to the second factor. The server refuses
 * these changes without it; this is where the person gives it.
 */
const TwoFactorProofDialog: React.FC<TwoFactorProofDialogProps> = ({
  title,
  consequence,
  submitLabel,
  pendingLabel,
  failureText,
  destructive,
  passwordAvailable,
  onSubmit,
  onCancel,
}) => {
  const t = useTranslation('auth');
  const [mode, setMode] = useState<'code' | 'password'>('code');
  const [value, setValue] = useState<string>('');
  const [pending, setPending] = useState<boolean>(false);
  const [refusal, setRefusal] = useState<ProofRefusal | null>(null);

  const switchMode = (next: 'code' | 'password') => {
    setMode(next);
    setValue('');
    setRefusal(null);
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setPending(true);
    setRefusal(null);
    const outcome = await onSubmit(mode === 'code' ? { code: value.trim() } : { password: value });
    // On success the parent closes this dialog, so only a refusal has more to do.
    if (outcome !== null) {
      setRefusal(outcome);
      setValue('');
      setPending(false);
    }
  };

  const refusalText = (() => {
    switch (refusal) {
      case null:
        return '';
      case 'required':
        return t('twoFactorSettings.proof.error.required', 'Enter a code or your password to continue.');
      case 'invalid':
        return mode === 'code'
          ? t(
              'twoFactorSettings.proof.error.invalidCode',
              'That code is not correct. A code works only once, so if you just used this one, wait for the next.'
            )
          : t('twoFactorSettings.proof.error.invalidPassword', 'That password is not correct.');
      case 'throttled':
        return t('twoFactorSettings.proof.error.throttled', 'Too many wrong tries. Wait a while and try again.');
      case 'failed':
        return failureText;
    }
  })();

  const fieldLabel =
    mode === 'code'
      ? t('twoFactorSettings.proof.code.label', 'Authenticator code or backup code')
      : t('twoFactorSettings.proof.password.label', 'Your password');

  return (
    <Dialog open={true} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{consequence}</DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="two-factor-proof" className="block text-sm font-medium mb-2">
              {fieldLabel}
            </label>
            <Input
              id="two-factor-proof"
              key={mode}
              type={mode === 'code' ? 'text' : 'password'}
              autoComplete={mode === 'code' ? 'one-time-code' : 'current-password'}
              autoFocus
              dir="ltr"
              value={value}
              onChange={(e) => setValue(e.target.value)}
            />
            {passwordAvailable && (
              <button
                type="button"
                className="mt-2 text-sm text-primary underline-offset-4 hover:underline"
                onClick={() => switchMode(mode === 'code' ? 'password' : 'code')}
              >
                {mode === 'code'
                  ? t('twoFactorSettings.proof.usePassword', 'Use my password instead')
                  : t('twoFactorSettings.proof.useCode', 'Use a code instead')}
              </button>
            )}
          </div>
          {refusalText && (
            <Alert variant="destructive">
              <AlertDescription>{refusalText}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onCancel} disabled={pending}>
              {t('twoFactorSettings.confirm.cancel', 'Cancel')}
            </Button>
            <Button
              type="submit"
              variant={destructive ? 'destructive' : 'default'}
              disabled={pending || value.trim() === ''}
            >
              {pending ? pendingLabel : submitLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export const TwoFactorSettings: React.FC = () => {
  const { apiClient } = useAuth();
  const t = useTranslation('auth');
  const [enabled, setEnabled] = useState<boolean>(false);
  const [backupCodesAvailable, setBackupCodesAvailable] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(true);
  const [showWizard, setShowWizard] = useState<boolean>(false);
  const [error, setError] = useState<SettingsError>({ kind: 'none' });
  const [statusMessage, setStatusMessage] = useState<string>('');
  const [passwordAvailable, setPasswordAvailable] = useState<boolean>(false);
  const [proofFor, setProofFor] = useState<'disable' | 'regenerate' | null>(null);

  const fetchStatus = useCallback(async () => {
    setLoading(true);
    setError({ kind: 'none' });

    try {
      const response = await apiClient('/api/v1/auth/2fa/status', {
        method: 'GET',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        setError(
          errorData.message ? { kind: 'backend', text: errorData.message } : { kind: 'status' }
        );
        return;
      }

      const data: TwoFactorStatus = await response.json();
      setEnabled(data.enabled);
      setBackupCodesAvailable(data.backup_codes_available);
      setPasswordAvailable(data.password_available);
    } catch {
      setError({ kind: 'status' });
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  const sendProof = (path: string, proof: TwoFactorProof) =>
    apiClient(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(proof),
    });

  const handleDisable = async (proof: TwoFactorProof): Promise<ProofRefusal | null> => {
    setError({ kind: 'none' });
    setStatusMessage('');

    try {
      const response = await sendProof('/api/v1/auth/2fa/disable', proof);

      if (!response.ok) {
        return await proofRefusalOf(response);
      }

      setProofFor(null);
      setEnabled(false);
      setBackupCodesAvailable(0);
      setStatusMessage(
        t('twoFactorSettings.disable.success', 'Two-factor authentication has been disabled.')
      );
      return null;
    } catch {
      return 'failed';
    }
  };

  const handleRegenerateCodes = async (proof: TwoFactorProof): Promise<ProofRefusal | null> => {
    setError({ kind: 'none' });
    setStatusMessage('');

    try {
      const response = await sendProof('/api/v1/auth/2fa/regenerate-codes', proof);

      if (!response.ok) {
        return await proofRefusalOf(response);
      }

      const data = await response.json();

      // Auto-download backup codes
      const text = data.backup_codes.join('\n');
      const element = document.createElement('a');
      element.setAttribute(
        'href',
        'data:text/plain;charset=utf-8,' + encodeURIComponent(text)
      );
      element.setAttribute('download', 'whity-backup-codes.txt');
      element.click();

      setProofFor(null);
      setBackupCodesAvailable(data.backup_codes.length);
      setStatusMessage(
        t('twoFactorSettings.regenerate.success', 'Backup codes regenerated and downloaded.')
      );
      return null;
    } catch {
      return 'failed';
    }
  };

  const handleWizardComplete = async (codes: string[]) => {
    setShowWizard(false);

    // Auto-download backup codes
    const text = codes.join('\n');
    const element = document.createElement('a');
    element.setAttribute(
      'href',
      'data:text/plain;charset=utf-8,' + encodeURIComponent(text)
    );
    element.setAttribute('download', 'whity-backup-codes.txt');
    element.click();

    // Refetch status
    await fetchStatus();
  };

  // Fetch status on mount
  useEffect(() => {
    void (async () => {
      await fetchStatus();
    })();
  }, [fetchStatus]);

  const errorText = settingsErrorText(t, error);

  if (loading) {
    return (
      <div className="max-w-md mx-auto p-6">
        <div className="flex justify-center">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-md mx-auto p-6">
      {/* aria-live region for status announcements */}
      <div aria-live="polite" aria-atomic="true" className="sr-only">
        {statusMessage}
      </div>

      {errorText && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{errorText}</AlertDescription>
        </Alert>
      )}

      <div className="bg-muted rounded-lg p-4 mb-4 border border-border">
        <div className="flex items-center gap-2 mb-2">
          {enabled ? (
            <>
              <IconCheck className="w-5 h-5 text-success" />
              <span className="font-semibold text-success">
                {t('twoFactorSettings.status.enabled', 'Enabled')}
              </span>
            </>
          ) : (
            <span className="font-semibold text-foreground">
              {t('twoFactorSettings.status.notEnabled', 'Not Enabled')}
            </span>
          )}
        </div>
        <p className="text-sm text-muted-foreground">
          {enabled
            ? t(
                'twoFactorSettings.status.description.enabled',
                'Two-Factor Authentication is currently enabled'
              )
            : t(
                'twoFactorSettings.status.description.disabled',
                'Two-Factor Authentication is not currently enabled'
              )}
        </p>
      </div>

      {enabled && (
        <div className="bg-card rounded-lg p-4 mb-4 border border-border">
          {/*
            NOT translated, deliberately: the count is wrapped in <strong> mid-
            sentence, and `t()` returns a string — so keeping the emphasis would
            mean handing a translator three fragments in English word order,
            which is worse than leaving the sentence in English. It also has no
            plural form ("You have 1 backup codes available"). Both need the
            markup pulled out of the sentence first; tracked separately.
          */}
          <p className="text-sm text-card-foreground">
            You have <strong>{backupCodesAvailable}</strong> backup codes available
          </p>
          <p className="text-xs text-muted-foreground mt-1">
            {t(
              'twoFactorSettings.backupCodes.hint',
              'Use these codes if you lose access to your authenticator app'
            )}
          </p>
        </div>
      )}

      <div className="space-y-3">
        {!enabled && (
          <Button
            onClick={() => setShowWizard(true)}
            className="w-full"
          >
            {t('twoFactorSettings.enable', 'Enable 2FA')}
          </Button>
        )}

        {enabled && (
          <>
            <Button
              variant="secondary"
              onClick={() => setProofFor('regenerate')}
              className="w-full"
            >
              {t('twoFactorSettings.regenerate', 'Regenerate Backup Codes')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => setProofFor('disable')}
              className="w-full"
            >
              {t('twoFactorSettings.disable', 'Disable 2FA')}
            </Button>
          </>
        )}
      </div>

      {proofFor === 'disable' && (
        <TwoFactorProofDialog
          title={t('twoFactorSettings.disable.confirm.title', 'Disable Two-Factor Authentication?')}
          consequence={t(
            'twoFactorSettings.disable.proof.body',
            'Anyone who has your password will be able to sign in without a code, and your backup codes stop working. Confirm it is you to continue.'
          )}
          submitLabel={t('twoFactorSettings.disable', 'Disable 2FA')}
          pendingLabel={t('twoFactorSettings.disable.pending', 'Disabling...')}
          failureText={t('twoFactorSettings.error.disable', 'Failed to disable 2FA')}
          destructive
          passwordAvailable={passwordAvailable}
          onSubmit={handleDisable}
          onCancel={() => setProofFor(null)}
        />
      )}

      {proofFor === 'regenerate' && (
        <TwoFactorProofDialog
          title={t('twoFactorSettings.regenerate.confirm.title', 'Regenerate Backup Codes?')}
          consequence={t(
            'twoFactorSettings.regenerate.proof.body',
            'Your current backup codes stop working and new ones download to this device. Confirm it is you to continue.'
          )}
          submitLabel={t('twoFactorSettings.regenerate.confirm.submit', 'Regenerate Codes')}
          pendingLabel={t('twoFactorSettings.regenerate.pending', 'Regenerating...')}
          failureText={t('twoFactorSettings.error.regenerate', 'Failed to regenerate backup codes')}
          destructive={false}
          passwordAvailable={passwordAvailable}
          onSubmit={handleRegenerateCodes}
          onCancel={() => setProofFor(null)}
        />
      )}

      {showWizard && (
        <TwoFactorSetupWizard
          onComplete={handleWizardComplete}
          onCancel={() => setShowWizard(false)}
        />
      )}
    </div>
  );
};
