'use client';

import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Input } from '@/components/ui/input';
import { Badge } from '@whity/ui/badge';
import { AccessDenied } from '@/components/ui/access-denied';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { ConfirmAction } from '@whity/ui/confirm-action';
import {
  IconDeviceFloppy,
  IconMail,
  IconSend,
} from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import {
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  RegistrySettingControl,
  enumOptionLabel,
  errorMessage,
  fieldErrorsFrom,
  type RegistryEntry,
  type SettingsMap,
} from '../settings-shared';

/**
 * Outgoing email (SMTP) settings — WC-3ac81b7e. Operator-only: the non-secret
 * fields are GLOBAL settings (system-tenant, `settings:manage`) read/written via
 * GET/PATCH /api/v1/settings/global; the SMTP password is a secret managed via
 * dedicated endpoints and NEVER round-trips. A dedicated page (not the generic
 * global form) so transport can conditionally reveal the SMTP fields, the
 * write-only password has its own control, and a test message can be sent.
 *
 * Built to the backend contract; while that backend is in flight the page still
 * renders (client-side field defaults) and the secret/test endpoints degrade to
 * a clear error toast rather than crashing.
 */

const MAIL_KEYS = {
  transport: 'mail.transport',
  host: 'mail.smtp.host',
  port: 'mail.smtp.port',
  encryption: 'mail.smtp.encryption',
  username: 'mail.smtp.username',
  authMode: 'mail.smtp.auth_mode',
  oauthProvider: 'mail.oauth.provider',
  oauthClientId: 'mail.oauth.client_id',
  oauthMicrosoftTenant: 'mail.oauth.microsoft_tenant',
  fromAddress: 'mail.from_address',
  fromName: 'mail.from_name',
  welcome: 'mail.events.welcome_enabled',
  approval: 'mail.events.approval_enabled',
  invitation: 'mail.events.invitation_enabled',
} as const;

// Client-side fallbacks so the page renders before the backend publishes these
// keys in the registry. When the real registry entry exists it takes precedence
// (including its enum `options`); these only fill the gap.
const FALLBACK_ENTRIES: Record<string, RegistryEntry> = {
  [MAIL_KEYS.transport]: { key: MAIL_KEYS.transport, type: 'enum', default: 'none', options: ['none', 'log', 'smtp'] },
  [MAIL_KEYS.host]: { key: MAIL_KEYS.host, type: 'string', default: '' },
  [MAIL_KEYS.port]: { key: MAIL_KEYS.port, type: 'string', default: '' },
  [MAIL_KEYS.encryption]: { key: MAIL_KEYS.encryption, type: 'enum', default: 'tls', options: ['none', 'tls', 'ssl'] },
  [MAIL_KEYS.username]: { key: MAIL_KEYS.username, type: 'string', default: '' },
  [MAIL_KEYS.authMode]: { key: MAIL_KEYS.authMode, type: 'enum', default: 'password', options: ['password', 'oauth2'] },
  [MAIL_KEYS.oauthProvider]: { key: MAIL_KEYS.oauthProvider, type: 'enum', default: 'google', options: ['google', 'microsoft'] },
  [MAIL_KEYS.oauthClientId]: { key: MAIL_KEYS.oauthClientId, type: 'string', default: '' },
  [MAIL_KEYS.oauthMicrosoftTenant]: { key: MAIL_KEYS.oauthMicrosoftTenant, type: 'string', default: 'common' },
  [MAIL_KEYS.fromAddress]: { key: MAIL_KEYS.fromAddress, type: 'string', default: '' },
  [MAIL_KEYS.fromName]: { key: MAIL_KEYS.fromName, type: 'string', default: '' },
  [MAIL_KEYS.welcome]: { key: MAIL_KEYS.welcome, type: 'bool', default: 'false' },
  [MAIL_KEYS.approval]: { key: MAIL_KEYS.approval, type: 'bool', default: 'false' },
  [MAIL_KEYS.invitation]: { key: MAIL_KEYS.invitation, type: 'bool', default: 'false' },
};

const SMTP_FIELD_KEYS = [
  MAIL_KEYS.host,
  MAIL_KEYS.port,
  MAIL_KEYS.encryption,
  MAIL_KEYS.username,
  MAIL_KEYS.fromAddress,
  MAIL_KEYS.fromName,
] as const;

const NOTIFICATION_KEYS = [MAIL_KEYS.welcome, MAIL_KEYS.approval, MAIL_KEYS.invitation] as const;

type OAuthState =
  | 'not_connected'
  | 'not_yet_used'
  | 'connected'
  | 'needs_reconnect'
  | 'client_rejected'
  | 'token_service_unavailable'
  | 'smtp_rejected'
  | 'credentials_unreadable'
  | 'smtp_host_not_allowed'
  | 'insecure_connection';

interface MailStatus {
  has_smtp_password: boolean;
  oauth: {
    has_client_secret: boolean;
    has_refresh_token: boolean;
    state: OAuthState;
  };
}

const NO_MAIL_STATUS: MailStatus = {
  has_smtp_password: false,
  oauth: { has_client_secret: false, has_refresh_token: false, state: 'not_connected' },
};

/** Where the connection stands, in plain words, with what to do about it. */
function oauthStateMessage(
  state: OAuthState,
  t: TranslateFn
): { variant: 'info' | 'success' | 'warning' | 'destructive'; text: string } {
  switch (state) {
    case 'connected':
      return {
        variant: 'success',
        text: t('settings.email.oauth.state.connected', 'Connected. The last email went out using this account.'),
      };
    case 'not_yet_used':
      return {
        variant: 'info',
        text: t(
          'settings.email.oauth.state.notYetUsed',
          'Ready to try. Send a test email below to check that the connection works.'
        ),
      };
    case 'needs_reconnect':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.needsReconnect',
          'Email is not going out. The provider says the refresh token was revoked or has expired. Get a new refresh token and save it here.'
        ),
      };
    case 'client_rejected':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.clientRejected',
          'Email is not going out. The provider refused the client ID or client secret. Check both, because client secrets can expire.'
        ),
      };
    case 'token_service_unavailable':
      return {
        variant: 'warning',
        text: t(
          'settings.email.oauth.state.tokenServiceUnavailable',
          'The provider could not be reached a moment ago. Email will try again on its own.'
        ),
      };
    case 'smtp_rejected':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.smtpRejected',
          'Email is not going out. The mail server refused the sign-in. Check that the SMTP username is the mailbox that granted access, and that SMTP sign-in is turned on for it.'
        ),
      };
    case 'credentials_unreadable':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.credentialsUnreadable',
          'Email is not going out. The saved credentials can no longer be read, usually because the encryption key changed. Enter them again.'
        ),
      };
    case 'smtp_host_not_allowed':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.hostNotAllowed',
          'Email is not going out. The SMTP host is not a mail server of this provider, so the sign-in token is not sent to it.'
        ),
      };
    case 'insecure_connection':
      return {
        variant: 'destructive',
        text: t(
          'settings.email.oauth.state.insecure',
          'Email is not going out. OAuth needs an encrypted connection. Set Encryption to TLS or SSL.'
        ),
      };
    default:
      return {
        variant: 'info',
        text: t(
          'settings.email.oauth.state.notConnected',
          'Not connected yet. Enter the client ID, client secret and refresh token.'
        ),
      };
  }
}

export default function EmailSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canManage = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (capsLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    );
  }

  if (!isSystemTenant || !canManage) {
    return (
      <AccessDenied
        description={t(
          'settings.email.accessDenied',
          'Outgoing email is an instance-wide setting managed by the system tenant with the {permission} permission.',
          { permission: SETTINGS_MANAGE }
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.email.goBack', 'Go Back')}
          </Button>
        }
      />
    );
  }

  return (
    <EmailSettingsForm
      adminEmail={user?.email ?? ''}
      addToast={addToast}
    />
  );
}

function EmailSettingsForm({
  adminEmail,
  addToast,
}: {
  adminEmail: string;
  addToast: ReturnType<typeof useToast>['addToast'];
}) {
  const t = useTranslation('admin');

  const { data, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(errorMessage(getError, t('settings.email.error.load', 'Failed to load settings')));
    }
    return body.data;
  }, []);

  const { data: status, refetch: refetchStatus } = useFetch<MailStatus>(async () => {
    const res = await apiClient('/api/v1/settings/mail/status');
    if (!res.ok) return NO_MAIL_STATUS;
    const body: unknown = await res.json();
    // The API wraps payloads in a { data: ... } envelope.
    const payload =
      body && typeof body === 'object' ? (body as { data?: unknown }).data : undefined;
    if (!payload || typeof payload !== 'object') return NO_MAIL_STATUS;
    const { has_smtp_password, oauth } = payload as { has_smtp_password?: unknown; oauth?: Partial<MailStatus['oauth']> };
    return {
      has_smtp_password: Boolean(has_smtp_password),
      oauth: {
        has_client_secret: Boolean(oauth?.has_client_secret),
        has_refresh_token: Boolean(oauth?.has_refresh_token),
        state: oauth?.state ?? 'not_connected',
      },
    };
  }, []);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  const byKey = useMemo(() => new Map(registry.map((e) => [e.key, e])), [registry]);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const [passwordInput, setPasswordInput] = useState('');
  const [savingPassword, setSavingPassword] = useState(false);

  const [clientSecretInput, setClientSecretInput] = useState('');
  const [refreshTokenInput, setRefreshTokenInput] = useState('');
  const [savingOAuth, setSavingOAuth] = useState(false);
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);

  const [testTo, setTestTo] = useState(adminEmail);
  const [testing, setTesting] = useState(false);

  const entryFor = (key: string): RegistryEntry => byKey.get(key) ?? FALLBACK_ENTRIES[key];
  const valueOf = (key: string): string => draft[key] ?? global?.[key] ?? entryFor(key).default;

  const setField = (key: string, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const transport = valueOf(MAIL_KEYS.transport);
  const dirty = Object.keys(draft).length > 0;
  const hasPassword = status?.has_smtp_password ?? false;
  const authMode = valueOf(MAIL_KEYS.authMode);
  const provider = valueOf(MAIL_KEYS.oauthProvider);
  const oauth = status?.oauth ?? NO_MAIL_STATUS.oauth;
  const oauthMessage = oauthStateMessage(oauth.state, t);

  useEffect(() => {
    // Surface a load failure; the page still renders with client defaults.
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const optionLabel = (option: string): string => {
    switch (option) {
      case 'password':
        return t('settings.email.authMode.password', 'Password');
      case 'oauth2':
        return t('settings.email.authMode.oauth2', 'OAuth');
      case 'google':
        return t('settings.email.provider.google', 'Google');
      case 'microsoft':
        return t('settings.email.provider.microsoft', 'Microsoft');
      default:
        return enumOptionLabel(option);
    }
  };

  const control = (key: string, disabled = false) => (
    <RegistrySettingControl
      key={key}
      entry={entryFor(key)}
      idPrefix="email"
      value={valueOf(key)}
      error={fieldErrors[key]}
      disabled={disabled}
      optionLabel={optionLabel}
      onChange={(v) => setField(key, v)}
    />
  );

  const handleSave = async () => {
    if (!dirty) return;
    const settings: Record<string, string> = {};
    for (const key of Object.keys(draft)) settings[key] = draft[key].trim();

    setSaving(true);
    setFieldErrors({});
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', { body: { settings } });
      if (patchError) {
        setFieldErrors(fieldErrorsFrom(patchError));
        throw new Error(
          errorMessage(patchError, t('settings.email.error.save', 'Failed to save email settings'))
        );
      }
      addToast(t('settings.email.saved', 'Email settings saved.'), 'success');
      setDraft({});
      refetch();
      refetchStatus();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.email.error.save', 'Failed to save email settings'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  const handleSavePassword = async () => {
    setSavingPassword(true);
    try {
      const res = await apiClient('/api/v1/settings/mail/smtp-password', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: passwordInput }),
      });
      if (!res.ok && res.status !== 204) {
        addToast(
          await readError(
            res,
            t('settings.email.smtp.password.error.save', 'Could not save the SMTP password'),
            t
          ),
          'error'
        );
        return;
      }
      addToast(
        passwordInput === ''
          ? t('settings.email.smtp.password.cleared', 'SMTP password cleared.')
          : t('settings.email.smtp.password.saved', 'SMTP password saved.'),
        'success'
      );
      setPasswordInput('');
      refetchStatus();
    } finally {
      setSavingPassword(false);
    }
  };

  /** Send only what was typed: a blank field keeps what is stored. */
  const putOAuthCredentials = async (body: Record<string, string | null>, successMessage: string) => {
    setSavingOAuth(true);
    try {
      const res = await apiClient('/api/v1/settings/mail/oauth-credentials', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok && res.status !== 204) {
        addToast(
          await readError(res, t('settings.email.oauth.error.save', 'Could not save the credentials'), t),
          'error'
        );
        return;
      }
      addToast(successMessage, 'success');
      setClientSecretInput('');
      setRefreshTokenInput('');
      refetchStatus();
    } finally {
      setSavingOAuth(false);
    }
  };

  const handleSaveOAuth = async () => {
    const body: Record<string, string> = {};
    if (clientSecretInput.trim() !== '') body.client_secret = clientSecretInput.trim();
    if (refreshTokenInput.trim() !== '') body.refresh_token = refreshTokenInput.trim();
    if (Object.keys(body).length === 0) return;
    await putOAuthCredentials(body, t('settings.email.oauth.saved', 'Credentials saved.'));
  };

  const handleDisconnect = async () => {
    await putOAuthCredentials(
      { client_secret: null, refresh_token: null },
      t('settings.email.oauth.disconnected', 'Mail account disconnected.')
    );
    setConfirmDisconnect(false);
  };

  const secretStatusBadge = (isSet: boolean, testId: string) => (
    <Badge data-testid={testId} variant={isSet ? 'secondary' : 'outline'} className="text-xs">
      {isSet
        ? t('settings.email.oauth.status.set', 'Saved')
        : t('settings.email.oauth.status.unset', 'Not set')}
    </Badge>
  );

  const handleSendTest = async () => {
    if (testTo.trim() === '') {
      addToast(
        t('settings.email.test.recipientRequired', 'Enter a recipient address for the test email.'),
        'error'
      );
      return;
    }
    setTesting(true);
    try {
      const res = await apiClient('/api/v1/settings/mail/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to: testTo.trim() }),
      });
      if (!res.ok) {
        addToast(await readError(res, t('settings.email.test.error', 'Test email failed'), t), 'error');
        return;
      }
      addToast(
        t(
          'settings.email.test.sent',
          'Test email sent — check the inbox (Mailpit at http://localhost:8025 in dev).'
        ),
        'success'
      );
    } finally {
      setTesting(false);
      // A test send is what moves the OAuth connection's status, either way.
      refetchStatus();
    }
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.email.title', 'Email')}
        description={t(
          'settings.email.description',
          'Outgoing email (SMTP) for this instance. Managed by the system tenant.'
        )}
      />

      {/* Transport */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-transport-card">
        <CardHeader>
          <div className="flex items-center gap-2">
            <span className="p-2 bg-primary/10 rounded-lg text-primary">
              <IconMail className="w-5 h-5" />
            </span>
            <div>
              <CardTitle className="text-lg font-semibold font-heading">
                <h2>{t('settings.email.delivery.title', 'Delivery')}</h2>
              </CardTitle>
              <CardDescription className="text-sm">
                {t('settings.email.delivery.description', 'How outgoing email leaves this instance.')}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {control(MAIL_KEYS.transport)}
          {transport === 'none' && (
            <Alert variant="info" data-testid="email-transport-note">
              <AlertDescription>
                {t('settings.email.transport.none.note', 'Email is disabled — nothing is sent.')}
              </AlertDescription>
            </Alert>
          )}
          {transport === 'log' && (
            <Alert variant="info" data-testid="email-transport-note">
              <AlertDescription>
                {t(
                  'settings.email.transport.log.note',
                  'Emails are written to the server log (dev only) — not delivered.'
                )}
              </AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>

      {/* SMTP connection (only when transport = smtp) */}
      {transport === 'smtp' && (
        <Card className="border border-border bg-card shadow-sm" data-testid="email-smtp-card">
          <CardHeader>
            <CardTitle className="text-lg font-semibold font-heading">
              <h2>{t('settings.email.smtp.title', 'SMTP connection')}</h2>
            </CardTitle>
            <CardDescription className="text-sm">
              {t(
                'settings.email.smtp.description',
                'The server that relays your mail. In dev, point this at Mailpit.'
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {control(MAIL_KEYS.host)}
            {control(MAIL_KEYS.port)}
            {control(MAIL_KEYS.encryption)}
            {control(MAIL_KEYS.username)}
            {control(MAIL_KEYS.authMode)}

            {authMode === 'oauth2' && (
              <div className="space-y-5" data-testid="email-oauth-fields">
                <Alert variant={oauthMessage.variant} data-testid="email-oauth-state" data-state={oauth.state}>
                  <AlertDescription>{oauthMessage.text}</AlertDescription>
                </Alert>
                {control(MAIL_KEYS.oauthProvider)}
                <p className="text-xs text-muted-foreground" data-testid="email-oauth-host-help">
                  {provider === 'microsoft'
                    ? t(
                        'settings.email.oauth.hostHelp.microsoft',
                        'Set the SMTP host to smtp.office365.com, or smtp-mail.outlook.com for an Outlook.com mailbox. Use the mailbox address as the SMTP username.'
                      )
                    : t(
                        'settings.email.oauth.hostHelp.google',
                        'Set the SMTP host to smtp.gmail.com. Use the mailbox address as the SMTP username.'
                      )}
                </p>
                {provider === 'microsoft' && control(MAIL_KEYS.oauthMicrosoftTenant)}
                {control(MAIL_KEYS.oauthClientId)}

                {/* Write-only secrets: they show only whether one is saved. */}
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between gap-2">
                    <label htmlFor="email-oauth-client-secret" className="text-sm font-medium text-foreground">
                      {t('settings.email.oauth.clientSecret.label', 'Client secret')}
                    </label>
                    {secretStatusBadge(oauth.has_client_secret, 'email-oauth-client-secret-status')}
                  </div>
                  <Input
                    id="email-oauth-client-secret"
                    type="password"
                    autoComplete="new-password"
                    placeholder={
                      oauth.has_client_secret
                        ? t('settings.email.oauth.placeholder.set', '•••••••• (unchanged)')
                        : undefined
                    }
                    value={clientSecretInput}
                    disabled={savingOAuth}
                    onChange={(e) => setClientSecretInput(e.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between gap-2">
                    <label htmlFor="email-oauth-refresh-token" className="text-sm font-medium text-foreground">
                      {t('settings.email.oauth.refreshToken.label', 'Refresh token')}
                    </label>
                    {secretStatusBadge(oauth.has_refresh_token, 'email-oauth-refresh-token-status')}
                  </div>
                  <Input
                    id="email-oauth-refresh-token"
                    type="password"
                    autoComplete="new-password"
                    placeholder={
                      oauth.has_refresh_token
                        ? t('settings.email.oauth.placeholder.set', '•••••••• (unchanged)')
                        : undefined
                    }
                    value={refreshTokenInput}
                    disabled={savingOAuth}
                    onChange={(e) => setRefreshTokenInput(e.target.value)}
                  />
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'settings.email.oauth.help',
                      'Both are stored encrypted and never shown again. Whity keeps the short-lived access token up to date by itself.'
                    )}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Button
                    variant="outline"
                    onClick={() => void handleSaveOAuth()}
                    disabled={
                      savingOAuth || (clientSecretInput.trim() === '' && refreshTokenInput.trim() === '')
                    }
                    data-testid="email-oauth-save"
                  >
                    {savingOAuth
                      ? t('settings.email.oauth.saving', 'Saving…')
                      : t('settings.email.oauth.save', 'Save credentials')}
                  </Button>
                  {(oauth.has_client_secret || oauth.has_refresh_token) && (
                    <Button
                      variant="outline"
                      onClick={() => setConfirmDisconnect(true)}
                      disabled={savingOAuth}
                      data-testid="email-oauth-disconnect"
                    >
                      {t('settings.email.oauth.disconnect', 'Disconnect')}
                    </Button>
                  )}
                </div>
                <ConfirmAction
                  open={confirmDisconnect}
                  onOpenChange={setConfirmDisconnect}
                  title={t('settings.email.oauth.disconnect.title', 'Disconnect this mail account?')}
                  description={t(
                    'settings.email.oauth.disconnect.description',
                    'Email stops going out until you enter a new client secret and refresh token. The provider keeps its own record of this access until you remove it there.'
                  )}
                  confirmLabel={t('settings.email.oauth.disconnect', 'Disconnect')}
                  cancelLabel={t('settings.email.oauth.disconnect.cancel', 'Cancel')}
                  onConfirm={handleDisconnect}
                />
              </div>
            )}

            {/* Write-only password: shows only whether one is set. */}
            {authMode !== 'oauth2' && (
            <div className="space-y-1.5" data-testid="email-password-field">
              <div className="flex items-center justify-between gap-2">
                <label htmlFor="email-smtp-password" className="text-sm font-medium text-foreground">
                  {t('settings.email.smtp.password.label', 'Password')}
                </label>
                <Badge
                  data-testid="email-password-status"
                  variant={hasPassword ? 'secondary' : 'outline'}
                  className="text-xs"
                >
                  {hasPassword
                    ? t('settings.email.smtp.password.status.set', 'Password is set')
                    : t('settings.email.smtp.password.status.unset', 'Not set')}
                </Badge>
              </div>
              <div className="flex items-center gap-2">
                <Input
                  id="email-smtp-password"
                  type="password"
                  autoComplete="new-password"
                  placeholder={
                    hasPassword
                      ? t('settings.email.smtp.password.placeholder.set', '•••••••• (unchanged)')
                      : t('settings.email.smtp.password.placeholder.unset', 'Enter SMTP password')
                  }
                  value={passwordInput}
                  disabled={savingPassword}
                  onChange={(e) => setPasswordInput(e.target.value)}
                />
                <Button
                  variant="outline"
                  onClick={() => void handleSavePassword()}
                  disabled={savingPassword}
                  data-testid="email-save-password"
                >
                  {savingPassword
                    ? t('settings.email.smtp.password.saving', 'Saving…')
                    : t('settings.email.smtp.password.save', 'Save password')}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {t(
                  'settings.email.smtp.password.help',
                  'Stored encrypted; never shown again. Leave blank and save to clear it.'
                )}
              </p>
            </div>
            )}

            {control(MAIL_KEYS.fromAddress)}
            {control(MAIL_KEYS.fromName)}
          </CardContent>
        </Card>
      )}

      {/* Notifications */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-notifications-card">
        <CardHeader>
          <CardTitle className="text-lg font-semibold font-heading">
            <h2>{t('settings.email.notifications.title', 'Notifications')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {transport === 'none'
              ? t(
                  'settings.email.notifications.description.noTransport',
                  'Which transactional emails this instance sends (requires a transport above).'
                )
              : t(
                  'settings.email.notifications.description',
                  'Which transactional emails this instance sends.'
                )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {NOTIFICATION_KEYS.map((key) => control(key))}
        </CardContent>
      </Card>

      {/* Save non-secret fields */}
      <div className="flex justify-end">
        <Button onClick={() => void handleSave()} disabled={saving || !dirty} className="gap-2" data-testid="email-save">
          <IconDeviceFloppy className="w-4 h-4" />
          {saving
            ? t('settings.email.saving', 'Saving…')
            : t('settings.email.save', 'Save email settings')}
        </Button>
      </div>

      {/* Send test */}
      <Card className="border border-border bg-card shadow-sm" data-testid="email-test-card">
        <CardHeader>
          <CardTitle className="text-lg font-semibold font-heading">
            <h2>{t('settings.email.test.title', 'Send a test email')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'settings.email.test.description',
              'Sends a message using the current (saved) configuration to confirm it works.'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            {/* The placeholder is an address literal, not prose: `example.com`
                is the reserved documentation domain and a translated local part
                would show an address that is not a valid example — the same
                reason the sign-in screen left its `000000` mask alone. */}
            <Input
              type="email"
              aria-label={t('settings.email.test.recipient.label', 'Test recipient')}
              placeholder="you@example.com"
              value={testTo}
              disabled={testing}
              onChange={(e) => setTestTo(e.target.value)}
              className="sm:max-w-xs"
            />
            <Button
              onClick={() => void handleSendTest()}
              disabled={testing || transport === 'none'}
              className="gap-2"
              data-testid="email-send-test"
            >
              <IconSend className="w-4 h-4" />
              {testing
                ? t('settings.email.test.sending', 'Sending…')
                : t('settings.email.test.send', 'Send test email')}
            </Button>
          </div>
          {transport === 'none' && (
            <p className="mt-2 text-xs text-muted-foreground">
              {t(
                'settings.email.test.transportRequired',
                'Choose a transport above to enable test sends.'
              )}
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/** Read the `{ error }` envelope from a failed response; friendly 404 fallback. */
async function readError(res: Response, fallback: string, t: TranslateFn): Promise<string> {
  if (res.status === 404) {
    return t('settings.email.error.unavailable', 'Email is not available on this server yet.');
  }
  try {
    const body: unknown = await res.json();
    if (body && typeof body === 'object' && 'error' in body) {
      const value = (body as { error?: unknown }).error;
      if (typeof value === 'string' && value !== '') return value;
    }
  } catch {
    // no JSON body
  }
  return fallback;
}
