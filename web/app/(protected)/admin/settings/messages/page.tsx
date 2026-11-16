'use client';

/**
 * Settings → Messages (ADR 0016, card `TplSettingsMessages`): web chat on or
 * off with its greeting and link, the reply address, and incoming email.
 *
 * Inbound email needs a forwarder in front of Whity (Cloudflare Email Routing,
 * Postmark, Mailgun, SES and the like) that posts each message to the webhook
 * shown here, signed with the secret. The secret is shown once, when it is
 * made; making a new one stops the old one at once, so it asks first.
 */

import { useState } from 'react';
import { AccessDenied } from '@/components/ui/access-denied';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { SaveBar } from '@whity/ui/save-bar';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { AreaError, EmptyFirstTime } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { BlockedSendersCard, ChatBotsCard } from '@/components/chat/chat-settings';
import { api } from '@/lib/api/client';
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
  const { addToast } = useToast();
  const { has, loading: capsLoading } = useCapabilities();
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [secret, setSecret] = useState<string | null>(null);
  const [confirmRotate, setConfirmRotate] = useState(false);
  const [startedSetUp, setStartedSetUp] = useState(false);

  const settings = useFetch<MessagingSettings>(async () => {
    const { data, response } = await api.GET('/api/v1/messages/settings');
    if (!response.ok) throw new Error(t('settings.messages.error.load', 'The Messages settings could not be loaded.'));
    return (data as unknown as { data: MessagingSettings }).data;
  }, []);

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
      const { response, error } = await api.PUT('/api/v1/messages/settings', {
        body: {
          webchat_enabled: current.webchat_enabled,
          webchat_greeting: current.webchat_greeting.trim() === '' ? null : current.webchat_greeting.trim(),
          inbound_email_address: current.inbound_email_address.trim() === '' ? null : current.inbound_email_address.trim(),
        },
      });
      if (!response.ok) {
        addToast(await errorText(new Response(JSON.stringify(error ?? null), { status: response.status }), t('settings.messages.error.save', 'Your changes were not saved.')), 'error');
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
    const { data, response } = await api.POST('/api/v1/messages/settings/inbound-secret');
    if (!response.ok) {
      addToast(t('settings.messages.error.secret', 'A new secret could not be made.'), 'error');
      return;
    }
    setSecret((data as unknown as { data: { secret: string } }).data.secret);
    settings.refetch();
  };

  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const slug = saved?.workspace_slug ?? null;
  const chatLink = slug ? `${origin}/chat/${slug}` : null;
  const hookAddress = slug ? `${origin}/api/v1/public/messages/inbound/email/${slug}` : null;
  const nothingSetUp =
    !!saved && !saved.webchat_enabled && !saved.inbound_email_address && !saved.has_inbound_secret && draft === null && !startedSetUp;

  const copy = (value: string): void => {
    void navigator.clipboard?.writeText(value).then(
      () => addToast(t('settings.messages.copied', 'Copied'), 'success'),
      () => undefined
    );
  };

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('settings.messages.title', 'Chat')}
        description={t('settings.messages.description', 'Web chat and email for customers, the bots that answer them, and who is blocked.')}
      />

      {settings.error ? (
        <AreaError
          message={t('settings.messages.error.load', 'The Messages settings could not be loaded.')}
          retryLabel={t('settings.messages.retry', 'Try again')}
          onRetry={() => settings.refetch()}
        />
      ) : !current ? (
        <div aria-busy="true" className="h-40 animate-pulse rounded-card bg-muted/40" />
      ) : nothingSetUp ? (
        <EmptyFirstTime
          icon="comment"
          title={t('settings.messages.off.title', 'Messages are off')}
          description={t('settings.messages.off.description', 'Turn on web chat, or add an address customers can email.')}
          action={
            <div className="flex flex-wrap justify-center gap-2">
              <Button
                type="button"
                onClick={() => {
                  setStartedSetUp(true);
                  update({ webchat_enabled: true });
                }}
              >
                {t('settings.messages.off.chat', 'Turn on web chat')}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setStartedSetUp(true);
                  window.setTimeout(() => document.getElementById('inbound-address')?.focus(), 0);
                }}
              >
                {t('settings.messages.off.email', 'Add an email address')}
              </Button>
            </div>
          }
        />
      ) : (
        <>
          <Card>
            <CardHeader className="flex flex-row items-start gap-4">
              <div className="min-w-0 flex-1 space-y-1.5">
                <CardTitle>{t('settings.messages.webchat.title', 'Web chat')}</CardTitle>
                <CardDescription>
                  {t('settings.messages.webchat.description', 'A chat box on a page of its own that customers can open')}
                </CardDescription>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <Switch
                  id="webchat-enabled"
                  checked={current.webchat_enabled}
                  onCheckedChange={(checked: boolean) => update({ webchat_enabled: checked })}
                  aria-label={t('settings.messages.webchat.toggle', 'Turn on web chat')}
                />
                <label htmlFor="webchat-enabled" className="text-sm text-foreground">
                  {current.webchat_enabled ? t('settings.messages.switchOn', 'On') : t('settings.messages.switchOff', 'Off')}
                </label>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-1.5">
                <label htmlFor="webchat-greeting" className="text-sm font-medium text-foreground">{t('settings.messages.field.greeting', 'Greeting')}</label>
                <Textarea
                  id="webchat-greeting"
                  rows={3}
                  maxLength={500}
                  value={current.webchat_greeting}
                  onChange={(event) => update({ webchat_greeting: event.target.value })}
                  placeholder={t('settings.messages.webchat.greetingHint', 'Hello! How can we help?')}
                />
              </div>
              {chatLink && saved?.webchat_enabled && (
                <div className="flex flex-wrap items-center gap-2">
                  <code dir="ltr" className="min-w-0 truncate font-mono text-sm text-foreground">{chatLink.replace(/^https?:\/\//, '')}</code>
                  <Button type="button" variant="outline" size="sm" onClick={() => copy(chatLink)}>
                    <Icon name="copy" size={14} />
                    {t('settings.messages.copyLink', 'Copy link')}
                  </Button>
                  <Button asChild variant="ghost" size="sm">
                    <a href={chatLink} target="_blank" rel="noreferrer">
                      <Icon name="external" size={14} />
                      {t('settings.messages.open', 'Open')}
                    </a>
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.messages.email.title', 'Email')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.messages.email.description',
                  'Customers’ emails to this address arrive in Messages, and your replies come from it.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-1.5">
              <label htmlFor="inbound-address" className="text-sm font-medium text-foreground">{t('settings.messages.field.address', 'Reply address')}</label>
              <Input
                id="inbound-address"
                type="email"
                dir="ltr"
                value={current.inbound_email_address}
                onChange={(event) => update({ inbound_email_address: event.target.value })}
                placeholder="hello@example-bakery.com"
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.messages.incoming.title', 'Incoming email')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.messages.incoming.description',
                  'Forward email from your provider to this address. Whity checks the secret on every message.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {hookAddress && (
                <div className="flex items-center gap-4 rounded-control bg-muted px-3 py-2.5">
                  <span className="shrink-0 text-sm text-foreground">{t('settings.messages.incoming.address', 'Address')}</span>
                  <code dir="ltr" className="min-w-0 flex-1 truncate font-mono text-sm text-foreground">{hookAddress}</code>
                  <Button type="button" variant="outline" size="sm" onClick={() => copy(hookAddress)}>
                    <Icon name="copy" size={14} />
                    {t('settings.messages.copy', 'Copy')}
                  </Button>
                </div>
              )}
              {secret !== null && (
                <>
                  <Alert variant="warning">
                    <Icon name="alert" size={16} />
                    <AlertTitle>{t('settings.messages.secret.onceTitle', 'Copy the secret now')}</AlertTitle>
                    <AlertDescription>
                      {t('settings.messages.secret.once', 'You won’t see it again. If you lose it, make a new one.')}
                    </AlertDescription>
                  </Alert>
                  <div className="flex items-center gap-4 rounded-control bg-muted px-3 py-2.5">
                    <code dir="ltr" className="min-w-0 flex-1 break-all font-mono text-sm text-foreground">{secret}</code>
                    <Button type="button" size="sm" onClick={() => copy(secret)}>
                      <Icon name="copy" size={14} />
                      {t('settings.messages.secret.copy', 'Copy secret')}
                    </Button>
                  </div>
                </>
              )}
              {saved?.has_inbound_secret ? (
                <Button type="button" variant="outline" onClick={() => setConfirmRotate(true)}>
                  <Icon name="sync" size={16} />
                  {t('settings.messages.secret.rotate', 'Make a new secret')}
                </Button>
              ) : (
                <div className="flex flex-wrap items-center gap-3">
                  <p className="text-sm text-muted-foreground">
                    {t('settings.messages.secret.none', 'No secret yet. Make one to receive email replies.')}
                  </p>
                  <Button type="button" variant="outline" onClick={() => void makeSecret()}>
                    {t('settings.messages.secret.make', 'Make a secret')}
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}

      {/* Settings › Chat (design 3.5, UC.4, UC.12): the workspace's bots, and who it blocked. */}
      <ChatBotsCard />
      <BlockedSendersCard />

      <ConfirmAction
        open={confirmRotate}
        onOpenChange={setConfirmRotate}
        title={t('settings.messages.secret.confirmTitle', 'Make a new secret?')}
        description={t(
          'settings.messages.secret.confirmBody',
          'The old secret stops working at once. Update it with your email provider, or incoming email stops arriving.'
        )}
        confirmLabel={t('settings.messages.secret.confirm', 'Make a new secret')}
        variant="destructive"
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
