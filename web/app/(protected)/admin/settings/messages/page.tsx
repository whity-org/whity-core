'use client';

/**
 * Settings → Messages (ADR 0016): web chat on or off and its greeting, and
 * where replies to the workspace's emails come back.
 *
 * Inbound email needs a forwarder in front of Whity (Cloudflare Email Routing,
 * Postmark, Mailgun, SES and the like) that posts each message to the webhook
 * shown here, signed with the secret. The secret is shown once, when it is
 * made; making a new one stops the old one at once, so it asks first.
 */

import { useState } from 'react';
import { AccessDenied } from '@/components/ui/access-denied';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { CopyBlock } from '@whity/ui/copy-block';
import { Input } from '@whity/ui/input';
import { SaveBar } from '@whity/ui/save-bar';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { MESSAGES_MANAGE } from '@/lib/capabilities';
import { useToast } from '@/lib/toast-context';
import { errorText, type MessagingSettings } from '@/lib/messages-wire';

interface Draft {
  webchat_enabled: boolean;
  webchat_greeting: string;
  inbound_email_address: string;
}

export default function MessagesSettingsPage() {
  const t = useTranslation('admin');
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { has, loading: capsLoading } = useCapabilities();
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [secret, setSecret] = useState<string | null>(null);
  const [confirmRotate, setConfirmRotate] = useState(false);

  const settings = useFetch<MessagingSettings>(async () => {
    const response = await apiClient('/api/v1/messages/settings');
    if (!response.ok) throw new Error(t('settings.messages.error.load', 'The Messages settings could not be loaded.'));
    return ((await response.json()) as { data: MessagingSettings }).data;
  }, [apiClient]);

  if (!capsLoading && !has(MESSAGES_MANAGE)) {
    return (
      <AccessDenied
        description={t('settings.messages.accessDenied', 'You need permission to manage Messages to open this page.')}
      />
    );
  }

  const saved = settings.data;
  const current: Draft | null =
    draft ??
    (saved
      ? {
          webchat_enabled: saved.webchat_enabled,
          webchat_greeting: saved.webchat_greeting ?? '',
          inbound_email_address: saved.inbound_email_address ?? '',
        }
      : null);

  const changed: string[] = [];
  if (saved && current) {
    if (current.webchat_enabled !== saved.webchat_enabled) changed.push(t('settings.messages.field.webchat', 'Web chat'));
    if (current.webchat_greeting !== (saved.webchat_greeting ?? '')) changed.push(t('settings.messages.field.greeting', 'Greeting'));
    if (current.inbound_email_address !== (saved.inbound_email_address ?? ''))
      changed.push(t('settings.messages.field.address', 'Reply address'));
  }

  const update = (patch: Partial<Draft>): void => {
    if (current) setDraft({ ...current, ...patch });
  };

  const save = async (): Promise<void> => {
    if (!current) return;
    setSaving(true);
    try {
      const response = await apiClient('/api/v1/messages/settings', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          webchat_enabled: current.webchat_enabled,
          webchat_greeting: current.webchat_greeting.trim() === '' ? null : current.webchat_greeting.trim(),
          inbound_email_address: current.inbound_email_address.trim() === '' ? null : current.inbound_email_address.trim(),
        }),
      });
      if (!response.ok) {
        addToast(await errorText(response, t('settings.messages.error.save', 'Your changes were not saved.')), 'error');
        return;
      }
      setDraft(null);
      settings.refetch();
      addToast(t('settings.messages.saved', 'Messages settings saved'), 'success');
    } finally {
      setSaving(false);
    }
  };

  const makeSecret = async (): Promise<void> => {
    const response = await apiClient('/api/v1/messages/settings/inbound-secret', { method: 'POST' });
    if (!response.ok) {
      addToast(t('settings.messages.error.secret', 'A new secret could not be made.'), 'error');
      return;
    }
    setSecret(((await response.json()) as { data: { secret: string } }).data.secret);
    settings.refetch();
  };

  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const slug = saved?.workspace_slug ?? null;

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('settings.messages.title', 'Messages')}
        description={t('settings.messages.description', 'How customers reach you: the chat on your pages, and replies to your emails.')}
      />

      {settings.error ? (
        <AreaError
          message={t('settings.messages.error.load', 'The Messages settings could not be loaded.')}
          retryLabel={t('settings.messages.retry', 'Try again')}
          onRetry={() => settings.refetch()}
        />
      ) : !current ? (
        <div aria-busy="true" className="h-40 animate-pulse rounded-card bg-muted/40" />
      ) : (
        <>
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.messages.webchat.title', 'Web chat')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.messages.webchat.description',
                  'A chat box on your public pages. Visitors and signed-in customers can write to you, and their messages arrive in Messages.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center gap-3">
                <Switch
                  id="webchat-enabled"
                  checked={current.webchat_enabled}
                  onCheckedChange={(checked: boolean) => update({ webchat_enabled: checked })}
                />
                <label htmlFor="webchat-enabled" className="text-sm font-medium text-foreground">{t('settings.messages.webchat.toggle', 'Turn on web chat')}</label>
              </div>
              <div className="space-y-1.5">
                <label htmlFor="webchat-greeting" className="text-sm font-medium text-foreground">{t('settings.messages.field.greeting', 'Greeting')}</label>
                <Textarea
                  id="webchat-greeting"
                  rows={2}
                  maxLength={500}
                  value={current.webchat_greeting}
                  onChange={(event) => update({ webchat_greeting: event.target.value })}
                  placeholder={t('settings.messages.webchat.greetingHint', 'Hello! How can we help?')}
                />
              </div>
              {slug && current.webchat_enabled && (
                <CopyBlock
                  value={`${origin}/chat/${slug}`}
                  caption={t('settings.messages.webchat.link', 'Share this link, or link to it from your website.')}
                  copyLabel={t('settings.messages.copy', 'Copy')}
                  copiedLabel={t('settings.messages.copied', 'Copied')}
                />
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.messages.email.title', 'Email replies')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.messages.email.description',
                  'Emails you send from Messages ask customers to reply to this address. Point its forwarder at the address below so their replies arrive in the same conversation.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-1.5">
                <label htmlFor="inbound-address" className="text-sm font-medium text-foreground">{t('settings.messages.field.address', 'Reply address')}</label>
                <Input
                  id="inbound-address"
                  type="email"
                  dir="ltr"
                  value={current.inbound_email_address}
                  onChange={(event) => update({ inbound_email_address: event.target.value })}
                  placeholder="hello@example-bakery.com"
                />
              </div>
              {slug && (
                <CopyBlock
                  value={`${origin}/api/v1/public/messages/inbound/email/${slug}`}
                  caption={t(
                    'settings.messages.email.webhook',
                    'Your forwarder posts each email here, with an X-Whity-Signature header made with the secret.'
                  )}
                  copyLabel={t('settings.messages.copy', 'Copy')}
                  copiedLabel={t('settings.messages.copied', 'Copied')}
                />
              )}
              {secret !== null ? (
                <Alert>
                  <AlertDescription className="space-y-2">
                    <p>{t('settings.messages.secret.once', 'Copy this secret now. It will not be shown again.')}</p>
                    <CopyBlock
                      value={secret}
                      copyLabel={t('settings.messages.copy', 'Copy')}
                      copiedLabel={t('settings.messages.copied', 'Copied')}
                    />
                  </AlertDescription>
                </Alert>
              ) : (
                <div className="flex flex-wrap items-center gap-3">
                  <p className="text-sm text-muted-foreground">
                    {saved?.has_inbound_secret
                      ? t('settings.messages.secret.set', 'A secret is set.')
                      : t('settings.messages.secret.none', 'No secret yet. Make one to receive email replies.')}
                  </p>
                  {saved?.has_inbound_secret ? (
                    <Button type="button" variant="outline" onClick={() => setConfirmRotate(true)}>
                      {t('settings.messages.secret.rotate', 'Make a new secret')}
                    </Button>
                  ) : (
                    <Button type="button" variant="outline" onClick={() => void makeSecret()}>
                      {t('settings.messages.secret.make', 'Make a secret')}
                    </Button>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}

      <ConfirmAction
        open={confirmRotate}
        onOpenChange={setConfirmRotate}
        title={t('settings.messages.secret.confirmTitle', 'Make a new secret?')}
        description={t(
          'settings.messages.secret.confirmBody',
          'The current secret stops working at once. Replies stop arriving until your forwarder uses the new one.'
        )}
        confirmLabel={t('settings.messages.secret.confirm', 'Make a new secret')}
        cancelLabel={t('settings.messages.cancel', 'Cancel')}
        onConfirm={async () => {
          setConfirmRotate(false);
          await makeSecret();
        }}
      />

      <SaveBar
        dirty={changed.length > 0}
        summary={t('settings.messages.changed', '{what} changed', { what: changed.join(', ') })}
        discardLabel={t('settings.messages.discard', 'Discard')}
        saveLabel={t('settings.messages.save', 'Save changes')}
        saving={saving}
        onDiscard={() => setDraft(null)}
        onSave={() => void save()}
      />
    </div>
  );
}
