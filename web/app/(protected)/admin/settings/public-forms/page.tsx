'use client';

import { useState, type ReactNode } from 'react';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { PUBLIC_FORM_KEYS_MANAGE } from '@/lib/capabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { Input } from '@/components/ui/input';
import { AccessDenied } from '@whity/ui/access-denied';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { IconAlertCircle, IconCopy, IconForms, IconPlus } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { SETTINGS_MANAGE, SYSTEM_TENANT_ID } from '../settings-shared';

interface FormKey {
  id: number;
  plugin: string;
  form: string;
  label: string;
  key_hint: string;
  allowed_origins: string[];
  created_at: string;
  revoked_at: string | null;
}

interface FormRoute {
  plugin: string;
  form: string;
}

interface KeysPayload {
  keys: FormKey[];
  forms: FormRoute[];
  botCheckReady: boolean;
}

interface BotSettings {
  provider: string | null;
  providers: string[];
  site_key: string;
  has_secret: boolean;
  allow_without_bot_check: boolean;
  ready: boolean;
}

interface IssuedKey {
  key: string;
  path: string;
}

const PROVIDER_LABELS: Readonly<Record<string, string>> = { turnstile: 'Cloudflare Turnstile' };

/**
 * The server's refusal in the reader's language (WHIT-916): the server sends a
 * stable `code` (and, for a bad site, the line at fault) beside its English
 * sentence, which is only the fallback for a code this page doesn't know.
 */
function codeText(t: TranslateFn, details: Record<string, unknown>): string | null {
  const value = typeof details.value === 'string' ? details.value : '';
  const max = typeof details.max === 'number' ? String(details.max) : '';
  switch (details.code) {
    case 'origin_invalid':
      return t(
        'settings.publicForms.error.originInvalid',
        '“{value}” isn’t a site address. Each site must look like https://www.example-bakery.com, with no path.',
        { value }
      );
    case 'origins_too_many':
      return t('settings.publicForms.error.originsTooMany', 'List at most {max} sites.', { max });
    case 'label_invalid':
      return t('settings.publicForms.error.labelInvalid', 'Give this key a name of up to {max} characters.', { max });
    default:
      return null;
  }
}

async function readError(res: Response, fallback: string, t: TranslateFn): Promise<string> {
  try {
    const body = (await res.json()) as { error?: unknown; details?: Record<string, unknown> };
    if (body.details) {
      const translated = codeText(t, body.details);
      if (translated !== null) return translated;
      const detail = Object.entries(body.details).find(
        ([key, v]) => key !== 'code' && key !== 'value' && typeof v === 'string'
      )?.[1];
      if (typeof detail === 'string') return detail;
    }
    if (typeof body.error === 'string' && body.error !== '') return body.error;
  } catch {
    // no JSON body
  }
  return fallback;
}

function splitOrigins(text: string): string[] {
  return text
    .split(/[\s,]+/)
    .map((origin) => origin.trim())
    .filter((origin) => origin !== '');
}

/**
 * Public forms (WHIT-906): the keys that open a plugin's form to anonymous visitors
 * for this workspace, and, for an operator, the instance's bot check.
 *
 * A key is shown once, when issued. Everything after that is its name and hint.
 */
export default function PublicFormsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { has, loading: capabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const [issuing, setIssuing] = useState(false);
  const [issued, setIssued] = useState<IssuedKey | null>(null);
  const [editing, setEditing] = useState<number | null>(null);
  const [revoking, setRevoking] = useState<FormKey | null>(null);

  const canManage = has(PUBLIC_FORM_KEYS_MANAGE);
  const isOperator = has(SETTINGS_MANAGE) && user?.tenant_id === SYSTEM_TENANT_ID;

  const { data, error: loadError, refetch } = useFetch<KeysPayload>(async () => {
    const res = await apiClient('/api/v1/public-form-keys');
    if (!res.ok) {
      throw new Error(await readError(res, t('settings.publicForms.loadFailed', 'The keys could not be loaded'), t));
    }
    const body = (await res.json()) as {
      data: FormKey[];
      meta: { forms: FormRoute[]; bot_check_ready: boolean };
    };
    return { keys: body.data, forms: body.meta.forms, botCheckReady: body.meta.bot_check_ready };
  }, []);

  if (!capabilitiesLoading && !canManage) {
    return (
      <AccessDenied
        description={t(
          'settings.publicForms.accessDenied',
          'You need permission to manage public form keys to open this page.'
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.publicForms.goBack', 'Go back')}
          </Button>
        }
      />
    );
  }

  const handleRevoke = async () => {
    if (revoking === null) return;
    const res = await apiClient(`/api/v1/public-form-keys/${revoking.id}/revoke`, { method: 'POST' });
    if (!res.ok && res.status !== 204) {
      addToast(await readError(res, t('settings.publicForms.revoke.failed', 'The key could not be revoked'), t), 'error');
      return;
    }
    addToast(t('settings.publicForms.revoke.done', 'Key revoked. Its form no longer takes submissions.'), 'success');
    setRevoking(null);
    refetch();
  };

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.publicForms.title', 'Public forms')}
        description={t(
          'settings.publicForms.description',
          'Let a plugin’s form take submissions from visitors with no account. Each key opens one form for this workspace, from the sites you list.'
        )}
        action={
          !issuing && (data?.forms.length ?? 0) > 0 ? (
            <Button className="gap-2" data-testid="public-forms-issue" onClick={() => setIssuing(true)}>
              <IconPlus className="w-4 h-4" />
              {t('settings.publicForms.issue', 'Issue a key')}
            </Button>
          ) : undefined
        }
      />

      {data !== null && !data.botCheckReady && (
        <Alert testId="public-forms-closed">
          {isOperator
            ? t(
                'settings.publicForms.closed.operator',
                'Every public form is closed until you set up the bot check below, or choose to allow forms without one.'
              )
            : t(
                'settings.publicForms.closed.member',
                'Public forms stay closed until the instance’s operator sets up a bot check. You can still issue keys.'
              )}
        </Alert>
      )}

      {issued !== null && <IssuedCard issued={issued} onDone={() => setIssued(null)} />}

      {issuing && data !== null && (
        <IssueCard
          forms={data.forms}
          onCancel={() => setIssuing(false)}
          onIssued={(key) => {
            setIssuing(false);
            setIssued(key);
            refetch();
          }}
        />
      )}

      {loadError && <Alert>{loadError}</Alert>}

      {data === null ? (
        !loadError && (
          <div className="space-y-3" data-testid="public-forms-loading">
            {[0, 1].map((i) => (
              <div key={i} className="h-24 animate-pulse rounded-lg bg-muted/40" />
            ))}
          </div>
        )
      ) : data.keys.length === 0 ? (
        <Card className="border border-dashed border-border bg-card/50" data-testid="public-forms-empty">
          <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
            <IconForms className="w-8 h-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
              {data.forms.length === 0
                ? t('settings.publicForms.empty.noForms', 'None of the plugins here has a public form yet.')
                : t(
                    'settings.publicForms.empty.noKeys',
                    'No keys yet. Issue one to open a plugin’s form for this workspace.'
                  )}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {data.keys.map((key) =>
            editing === key.id ? (
              <EditCard
                key={key.id}
                formKey={key}
                onCancel={() => setEditing(null)}
                onSaved={() => {
                  setEditing(null);
                  refetch();
                }}
              />
            ) : (
              <KeyCard
                key={key.id}
                formKey={key}
                onEdit={() => setEditing(key.id)}
                onRevoke={() => setRevoking(key)}
              />
            )
          )}
        </div>
      )}

      {isOperator && <BotCheckCard onSaved={refetch} />}

      <ConfirmAction
        open={revoking !== null}
        onOpenChange={(open) => {
          if (!open) setRevoking(null);
        }}
        title={t('settings.publicForms.revoke.title', 'Revoke {{label}}?').replace('{{label}}', revoking?.label ?? '')}
        description={t(
          'settings.publicForms.revoke.description',
          'Its form stops taking submissions straight away, on every site using this key. This can’t be undone: to open the form again you’ll issue a new key and update those sites.'
        )}
        confirmLabel={t('settings.publicForms.revoke.action', 'Revoke key')}
        cancelLabel={t('settings.publicForms.cancel', 'Cancel')}
        onConfirm={handleRevoke}
      />
    </div>
  );
}

function KeyCard({
  formKey,
  onEdit,
  onRevoke,
}: {
  formKey: FormKey;
  onEdit: () => void;
  onRevoke: () => void;
}) {
  const t = useTranslation('admin');
  const revoked = formKey.revoked_at !== null;

  return (
    <Card data-testid={`public-form-key-${formKey.id}`} className="border border-border bg-card shadow-sm">
      <CardContent className="flex flex-col gap-4 py-4 md:flex-row md:items-start md:justify-between">
        <div className="min-w-0 space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-foreground">{formKey.label}</span>
            <Badge variant={revoked ? 'outline' : 'default'} className="text-xs">
              {revoked
                ? t('settings.publicForms.status.revoked', 'Revoked')
                : t('settings.publicForms.status.live', 'Live')}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground">
            {t('settings.publicForms.keyFor', 'Opens')}{' '}
            <span dir="ltr" className="font-mono text-sm">
              {formKey.plugin}/{formKey.form}
            </span>
            {' · '}
            {t('settings.publicForms.startsWith', 'Key starts')}{' '}
            <span dir="ltr" className="font-mono text-sm">
              {formKey.key_hint}
            </span>
          </p>
          <p className="text-sm text-muted-foreground">
            {formKey.allowed_origins.length === 0 ? (
              t('settings.publicForms.origins.instanceOnly', 'Only this instance’s own allowed sites')
            ) : (
              <span dir="ltr">{formKey.allowed_origins.join(', ')}</span>
            )}
          </p>
        </div>
        {!revoked && (
          <div className="flex shrink-0 items-center gap-2">
            <Button variant="outline" size="sm" data-testid={`public-form-key-edit-${formKey.id}`} onClick={onEdit}>
              {t('settings.publicForms.edit', 'Edit')}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="text-destructive"
              data-testid={`public-form-key-revoke-${formKey.id}`}
              onClick={onRevoke}
            >
              {t('settings.publicForms.revoke.action', 'Revoke key')}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function FieldRow({ id, label, hint, children }: { id: string; label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      {children}
      {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
    </div>
  );
}

function OriginsField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const t = useTranslation('admin');
  return (
    <FieldRow
      id="public-form-origins"
      label={t('settings.publicForms.origins.label', 'Sites that may send this form')}
      hint={t(
        'settings.publicForms.origins.hint',
        'One per line, like https://www.example-bakery.com, with no path. Leave empty to allow only this instance’s own allowed sites.'
      )}
    >
      <Textarea
        id="public-form-origins"
        dir="ltr"
        rows={3}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </FieldRow>
  );
}

function IssueCard({
  forms,
  onCancel,
  onIssued,
}: {
  forms: FormRoute[];
  onCancel: () => void;
  onIssued: (issued: IssuedKey) => void;
}) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [form, setForm] = useState(forms.length === 1 ? `${forms[0].plugin}/${forms[0].form}` : '');
  const [label, setLabel] = useState('');
  const [origins, setOrigins] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const [plugin, name] = form.split('/');
    setBusy(true);
    const res = await apiClient('/api/v1/public-form-keys', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ plugin, form: name, label, allowed_origins: splitOrigins(origins) }),
    });
    setBusy(false);
    if (!res.ok) {
      addToast(await readError(res, t('settings.publicForms.issue.failed', 'The key could not be issued'), t), 'error');
      return;
    }
    const body = (await res.json()) as { data: { key: string; plugin: string; form: string } };
    onIssued({ key: body.data.key, path: `/api/v1/${body.data.plugin}/forms/${body.data.form}/${body.data.key}` });
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="public-forms-issue-card">
      <CardHeader>
        <CardTitle>{t('settings.publicForms.issue', 'Issue a key')}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <FieldRow id="public-form-route" label={t('settings.publicForms.form', 'Form')}>
          <FieldSelect
            id="public-form-route"
            value={form}
            onChange={setForm}
            placeholder={t('settings.publicForms.form.placeholder', 'Choose a form')}
            options={forms.map((f) => ({ value: `${f.plugin}/${f.form}`, label: `${f.plugin} · ${f.form}` }))}
          />
        </FieldRow>
        <FieldRow
          id="public-form-label"
          label={t('settings.publicForms.label', 'Name')}
          hint={t('settings.publicForms.label.hint', 'So you can tell keys apart, like “Main website”.')}
        >
          <Input id="public-form-label" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} />
        </FieldRow>
        <OriginsField value={origins} onChange={setOrigins} />
        <div className="flex items-center gap-2">
          <Button
            data-testid="public-forms-issue-submit"
            disabled={busy || form === '' || label.trim() === ''}
            onClick={() => void submit()}
          >
            {t('settings.publicForms.issue', 'Issue a key')}
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            {t('settings.publicForms.cancel', 'Cancel')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function IssuedCard({ issued, onDone }: { issued: IssuedKey; onDone: () => void }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const copy = (value: string) => {
    void navigator.clipboard?.writeText(value).then(
      () => addToast(t('settings.publicForms.copied', 'Copied.'), 'success'),
      () => addToast(t('settings.publicForms.copyFailed', 'Could not copy. Select the text and copy it yourself.'), 'error')
    );
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="public-forms-issued">
      <CardHeader>
        <CardTitle>{t('settings.publicForms.issued.title', 'Copy this key now')}</CardTitle>
        <CardDescription>
          {t(
            'settings.publicForms.issued.description',
            'This is the only time you’ll see it. Give it to whoever runs the site. If it’s lost, revoke it and issue another.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {[
          { id: 'key', label: t('settings.publicForms.issued.key', 'Key'), value: issued.key },
          { id: 'path', label: t('settings.publicForms.issued.path', 'Address to send the form to'), value: issued.path },
        ].map((row) => (
          <div key={row.id} className="space-y-1 rounded-md border border-border bg-muted/20 p-3">
            <p className="text-sm text-muted-foreground">{row.label}</p>
            <div className="flex items-center gap-2">
              <code dir="ltr" className="min-w-0 flex-1 break-all font-mono text-sm text-foreground">
                {row.value}
              </code>
              <Button variant="outline" size="sm" className="gap-1 shrink-0" onClick={() => copy(row.value)}>
                <IconCopy className="w-3.5 h-3.5" />
                {t('settings.publicForms.copy', 'Copy')}
              </Button>
            </div>
          </div>
        ))}
        <Button variant="outline" data-testid="public-forms-issued-done" onClick={onDone}>
          {t('settings.publicForms.issued.done', 'I’ve copied it')}
        </Button>
      </CardContent>
    </Card>
  );
}

function EditCard({
  formKey,
  onCancel,
  onSaved,
}: {
  formKey: FormKey;
  onCancel: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [label, setLabel] = useState(formKey.label);
  const [origins, setOrigins] = useState(formKey.allowed_origins.join('\n'));
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    const res = await apiClient(`/api/v1/public-form-keys/${formKey.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ label, allowed_origins: splitOrigins(origins) }),
    });
    setBusy(false);
    if (!res.ok) {
      addToast(await readError(res, t('settings.publicForms.edit.failed', 'The key could not be changed'), t), 'error');
      return;
    }
    addToast(t('settings.publicForms.edit.done', 'Key changed.'), 'success');
    onSaved();
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid={`public-form-key-editor-${formKey.id}`}>
      <CardContent className="space-y-4 py-4">
        <FieldRow id="public-form-label" label={t('settings.publicForms.label', 'Name')}>
          <Input id="public-form-label" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} />
        </FieldRow>
        <OriginsField value={origins} onChange={setOrigins} />
        <div className="flex items-center gap-2">
          <Button variant="outline" disabled={busy || label.trim() === ''} onClick={() => void save()}>
            {t('settings.publicForms.save', 'Save')}
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            {t('settings.publicForms.cancel', 'Cancel')}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

const NO_PROVIDER = 'none';

/** The instance's bot check. Operators only, and the server checks that too. */
function BotCheckCard({ onSaved }: { onSaved: () => void }) {
  const t = useTranslation('admin');
  const { data, error, refetch } = useFetch<BotSettings>(async () => {
    const res = await apiClient('/api/v1/settings/public-forms/bot-check');
    if (!res.ok) {
      throw new Error(await readError(res, t('settings.publicForms.bot.loadFailed', 'The bot check settings could not be loaded'), t));
    }
    return ((await res.json()) as { data: BotSettings }).data;
  }, []);

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="public-forms-bot-check">
      <CardHeader>
        <CardTitle>{t('settings.publicForms.bot.title', 'Bot check')}</CardTitle>
        <CardDescription>
          {t(
            'settings.publicForms.bot.description',
            'Applies to every public form on this instance. If the provider can’t be reached, forms refuse submissions until it’s back.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {data === null ? (
          !error && <div className="h-24 animate-pulse rounded-lg bg-muted/40" />
        ) : (
          // Keyed on what the server holds, so a save starts the form again from it.
          <BotCheckForm
            key={JSON.stringify(data)}
            settings={data}
            onSaved={() => {
              refetch();
              onSaved();
            }}
          />
        )}
      </CardContent>
    </Card>
  );
}

function BotCheckForm({ settings, onSaved }: { settings: BotSettings; onSaved: () => void }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [provider, setProvider] = useState(settings.provider ?? NO_PROVIDER);
  const [siteKey, setSiteKey] = useState(settings.site_key);
  const [secret, setSecret] = useState('');
  const [allowNone, setAllowNone] = useState(settings.allow_without_bot_check);
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    const res = await apiClient('/api/v1/settings/public-forms/bot-check', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        provider: provider === NO_PROVIDER ? null : provider,
        site_key: siteKey,
        allow_without_bot_check: provider === NO_PROVIDER && allowNone,
        ...(secret.trim() !== '' ? { secret } : {}),
      }),
    });
    setBusy(false);
    if (!res.ok) {
      addToast(await readError(res, t('settings.publicForms.bot.saveFailed', 'The bot check settings could not be saved'), t), 'error');
      return;
    }
    addToast(t('settings.publicForms.bot.saved', 'Bot check settings saved.'), 'success');
    setSecret('');
    onSaved();
  };

  return (
    <>
      <FieldRow id="public-form-bot-provider" label={t('settings.publicForms.bot.provider', 'Provider')}>
        <FieldSelect
          id="public-form-bot-provider"
          value={provider}
          onChange={setProvider}
          options={[
            { value: NO_PROVIDER, label: t('settings.publicForms.bot.provider.none', 'None') },
            ...settings.providers.map((p) => ({ value: p, label: PROVIDER_LABELS[p] ?? p })),
          ]}
        />
      </FieldRow>
      {provider !== NO_PROVIDER && (
        <>
          <FieldRow
            id="public-form-bot-site-key"
            label={t('settings.publicForms.bot.siteKey', 'Site key')}
            hint={t('settings.publicForms.bot.siteKey.hint', 'Public. A form’s page reads it to show the check.')}
          >
            <Input id="public-form-bot-site-key" dir="ltr" value={siteKey} onChange={(e) => setSiteKey(e.target.value)} />
          </FieldRow>
          <FieldRow
            id="public-form-bot-secret"
            label={t('settings.publicForms.bot.secret', 'Secret key')}
            hint={
              settings.has_secret
                ? t('settings.publicForms.bot.secret.stored', 'A secret is stored and can’t be shown. Type a new one to replace it.')
                : t('settings.publicForms.bot.secret.empty', 'No secret stored yet. Forms stay closed until there is one.')
            }
          >
            <Input
              id="public-form-bot-secret"
              dir="ltr"
              type="password"
              autoComplete="off"
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
            />
          </FieldRow>
        </>
      )}
      {provider === NO_PROVIDER && (
        <div className="flex items-start justify-between gap-4 rounded-md border border-border p-3">
          <div className="min-w-0 space-y-1">
            <label htmlFor="public-form-bot-allow-none" className="text-sm font-medium text-foreground">
              {t('settings.publicForms.bot.allowNone', 'Allow public forms with no bot check')}
            </label>
            <p className="text-sm text-muted-foreground">
              {t(
                'settings.publicForms.bot.allowNone.hint',
                'Forms still have limits, but anything can send them. Leave this off unless something else in front of this instance stops bots.'
              )}
            </p>
          </div>
          <Switch
            id="public-form-bot-allow-none"
            data-testid="public-forms-allow-none"
            checked={allowNone}
            onCheckedChange={setAllowNone}
          />
        </div>
      )}
      <Button variant="outline" data-testid="public-forms-bot-save" disabled={busy} onClick={() => void save()}>
        {t('settings.publicForms.save', 'Save')}
      </Button>
    </>
  );
}

function Alert({ children, testId }: { children: ReactNode; testId?: string }) {
  return (
    <div
      role="alert"
      data-testid={testId}
      className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
    >
      <IconAlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{children}</span>
    </div>
  );
}
