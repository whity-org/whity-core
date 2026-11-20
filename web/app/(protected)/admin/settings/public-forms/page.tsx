'use client';

import { useState, type ReactNode } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { PUBLIC_FORM_KEYS_MANAGE } from '@/lib/capabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { Input } from '@/components/ui/input';
import { AccessDenied } from '@/components/ui/access-denied';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Switch } from '@whity/ui/switch';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { EmptyState } from '@whity/ui/empty-state';
import { Icon } from '@whity/ui/icon';
import { ChipsInput, splitChips } from '@/components/chips-input';
import { cn } from '@/lib/utils';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { SETTINGS_MANAGE, SYSTEM_TENANT_ID } from '../settings-shared';

/** What a key opens: a form takes submissions, a read shows something. Null: its plugin isn't loaded. */
type RouteKind = 'form' | 'read' | null;

interface FormKey {
  id: number;
  plugin: string;
  form: string;
  kind: RouteKind;
  label: string;
  key_hint: string;
  allowed_origins: string[];
  created_at: string;
  revoked_at: string | null;
}

interface FormRoute {
  plugin: string;
  form: string;
  kind: RouteKind;
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
  kind: RouteKind;
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

function readError(parsed: unknown, fallback: string, t: TranslateFn): string {
  try {
    const body = (parsed ?? {}) as { error?: unknown; details?: Record<string, unknown> };
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

/** A key's kind in words, on its card and in the list of what a key can open. */
function kindLabel(t: TranslateFn, kind: 'form' | 'read'): string {
  return kind === 'read'
    ? t('settings.publicForms.kind.read', 'Read-only link')
    : t('settings.publicForms.kind.form', 'Form');
}


/**
 * Public forms (WHIT-906; card TplSettingsPublicForms, WHIT-1044): the keys that open a plugin's form to anonymous visitors
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
    const { data: body, response: res, error } = await api.GET('/api/v1/public-form-keys');
    if (!res.ok) {
      throw new Error(readError(error, t('settings.publicForms.loadFailed', 'The keys could not be loaded'), t));
    }
    const payload = body as unknown as {
      data: FormKey[];
      meta: { forms: FormRoute[]; bot_check_ready: boolean };
    };
    return { keys: payload.data, forms: payload.meta.forms, botCheckReady: payload.meta.bot_check_ready };
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
    const { response: res, error } = await api.POST('/api/v1/public-form-keys/{id}/revoke', {
      params: { path: { id: revoking.id } },
    });
    if (!res.ok && res.status !== 204) {
      addToast(readError(error, t('settings.publicForms.revoke.failed', 'The key could not be revoked'), t), 'error');
      return;
    }
    addToast(
      revoking.kind === 'read'
        ? t('settings.publicForms.revoke.doneRead', 'Key revoked. Its link no longer works.')
        : t('settings.publicForms.revoke.done', 'Key revoked. Its form no longer takes submissions.'),
      'success'
    );
    setRevoking(null);
    refetch();
  };

  const canIssue = (data?.forms.length ?? 0) > 0;
  const issueButton = (
    <Button size="sm" className="gap-1" data-testid="public-forms-issue" onClick={() => setIssuing(true)}>
      <Icon name="plus" size={16} />
      {t('settings.publicForms.issue', 'Issue a key')}
    </Button>
  );

  return (
    <div className="space-y-6 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.publicForms.title', 'Public forms')}
        description={t('settings.publicForms.descriptionShort', 'Keys that open plugins’ public forms on your own sites.')}
      />

      {data !== null && !data.botCheckReady && (
        <Alert variant="destructive" data-testid="public-forms-closed">
          <AlertTitle>{t('settings.publicForms.closed.title', 'Public forms are closed')}</AlertTitle>
          <AlertDescription>
            {isOperator
              ? t(
                  'settings.publicForms.closed.operatorAll',
                  'Every public form, public chat and online booking is closed until you set up the bot check below, or choose to allow them without one.'
                )
              : t(
                  'settings.publicForms.closed.memberKeys',
                  'This Whity has no bot check. Ask the operator to set one up. Keys keep working once it’s back.'
                )}
          </AlertDescription>
        </Alert>
      )}

      {issued !== null && <IssuedCard issued={issued} onDone={() => setIssued(null)} />}

      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {editing !== null && data !== null && data.keys.some((key) => key.id === editing) && (
        <EditCard
          formKey={data.keys.find((key) => key.id === editing)!}
          onCancel={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            refetch();
          }}
        />
      )}

      <Card className="border border-border bg-card shadow-sm" data-testid="public-forms-keys">
        <CardHeader>
          <div className="flex items-start gap-3">
            <div className="min-w-0 flex-1 space-y-1.5">
              <CardTitle>{t('settings.publicForms.keys', 'Keys')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.publicForms.keys.hint',
                  'A key lets a plugin’s public form take answers for this workspace on the sites you list.'
                )}
              </CardDescription>
            </div>
            {canIssue && (data?.keys.length ?? 0) > 0 && issueButton}
          </div>
        </CardHeader>
        <CardContent>
          {data === null ? (
            !loadError && (
              <div className="space-y-3" data-testid="public-forms-loading">
                {[0, 1].map((i) => (
                  <div key={i} className="h-12 animate-pulse rounded-lg bg-muted/40" />
                ))}
              </div>
            )
          ) : data.keys.length === 0 ? (
            <div data-testid="public-forms-empty">
              {data.forms.length === 0 ? (
                <EmptyState
                  icon={<Icon name="form" size={24} />}
                  title={t('settings.publicForms.empty.noFormsTitle', 'No public forms yet')}
                  description={t(
                    'settings.publicForms.empty.noFormsHint',
                    'When a plugin with a public form is on, its keys are made here.'
                  )}
                />
              ) : (
                <EmptyState
                  icon={<Icon name="key" size={24} />}
                  title={t('settings.publicForms.empty.noKeysTitle', 'No keys yet')}
                  description={t('settings.publicForms.empty.noKeysHint', 'Issue a key to put a form on your site.')}
                  action={issueButton}
                />
              )}
            </div>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full text-sm">
                <thead className="bg-muted text-start text-xs text-muted-foreground">
                  <tr>
                    <th scope="col" className="px-4 py-2.5 text-start font-medium">{t('settings.publicForms.col.name', 'Name')}</th>
                    <th scope="col" className="px-4 py-2.5 text-start font-medium">{t('settings.publicForms.col.key', 'Key')}</th>
                    <th scope="col" className="px-4 py-2.5 text-start font-medium">{t('settings.publicForms.col.sites', 'Allowed sites')}</th>
                    <th scope="col" className="px-4 py-2.5 text-start font-medium">{t('settings.publicForms.col.status', 'Status')}</th>
                    <th scope="col" className="w-12 px-2 py-2.5">
                      <span className="sr-only">{t('settings.publicForms.col.actions', 'Actions')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {data.keys.map((key) => (
                    <KeyRow
                      key={key.id}
                      formKey={key}
                      onEdit={() => setEditing(key.id)}
                      onRevoke={() => setRevoking(key)}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {isOperator && <BotCheckCard onSaved={refetch} />}

      {data !== null && (
        <IssueDialog
          open={issuing}
          forms={data.forms}
          onCancel={() => setIssuing(false)}
          onIssued={(key) => {
            setIssuing(false);
            setIssued(key);
            refetch();
          }}
        />
      )}

      <ConfirmAction
        open={revoking !== null}
        onOpenChange={(open) => {
          if (!open) setRevoking(null);
        }}
        title={t('settings.publicForms.revoke.title', 'Revoke {{label}}?').replace('{{label}}', revoking?.label ?? '')}
        description={
          revoking?.kind === 'read'
            ? t(
                'settings.publicForms.revoke.descriptionRead',
                'Its link stops working straight away, on every site using this key. This can’t be undone: to share it again you’ll issue a new key and update those sites.'
              )
            : t(
                'settings.publicForms.revoke.description',
                'Its form stops taking submissions straight away, on every site using this key. This can’t be undone: to open the form again you’ll issue a new key and update those sites.'
              )
        }
        confirmLabel={t('settings.publicForms.revoke.action', 'Revoke key')}
        cancelLabel={t('settings.publicForms.cancel', 'Cancel')}
        onConfirm={handleRevoke}
      />
    </div>
  );
}

/** One key in the list: name and form, the short key, the sites, status, and ⋯ for Edit and Revoke. */
function KeyRow({
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
    <tr data-testid={`public-form-key-${formKey.id}`} className={cn('align-middle', revoked && 'text-muted-foreground')}>
      <td className="px-4 py-3">
        <p className={cn('text-sm', revoked ? 'text-muted-foreground' : 'text-foreground')}>{formKey.label}</p>
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
          <span dir="ltr">
            {formKey.plugin} · {formKey.form}
          </span>
          {formKey.kind !== null && (
            <Badge variant="outline" data-testid={`public-form-key-kind-${formKey.id}`}>
              {kindLabel(t, formKey.kind)}
            </Badge>
          )}
        </p>
      </td>
      <td className="px-4 py-3">
        <code dir="ltr" className="font-mono text-xs">
          {formKey.key_hint}…
        </code>
      </td>
      <td className="px-4 py-3">
        {formKey.allowed_origins.length === 0 ? (
          <span className="text-xs text-muted-foreground">
            {t('settings.publicForms.origins.instanceOnly', 'Only this instance’s own allowed sites')}
          </span>
        ) : (
          <span dir="ltr" className="break-all">
            {/* The address without its scheme, as the card lists it; the full origin is in the editor. */}
            {formKey.allowed_origins.map((origin) => origin.replace(/^https?:\/\//, '')).join(', ')}
          </span>
        )}
      </td>
      <td className="px-4 py-3">
        <Badge variant={revoked ? 'outline' : 'success'}>
          {revoked
            ? t('settings.publicForms.status.revoked', 'Revoked')
            : t('settings.publicForms.status.working', 'Working')}
        </Badge>
      </td>
      <td className="px-2 py-3 text-end">
        {!revoked && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t('settings.publicForms.more', 'More for {label}', { label: formKey.label })}
                data-testid={`public-form-key-more-${formKey.id}`}
              >
                <Icon name="more" size={16} />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem data-testid={`public-form-key-edit-${formKey.id}`} onSelect={onEdit}>
                {t('settings.publicForms.edit', 'Edit')}
              </DropdownMenuItem>
              <DropdownMenuItem
                variant="destructive"
                data-testid={`public-form-key-revoke-${formKey.id}`}
                onSelect={onRevoke}
              >
                {t('settings.publicForms.revoke.action', 'Revoke key')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </td>
    </tr>
  );
}

function FieldRow({ id, label, hint, children }: { id: string; label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/** The allowed sites as chips; what is still typed in the box counts too. */
function OriginsField({
  values,
  input,
  onChange,
  isRead,
}: {
  values: string[];
  input: string;
  onChange: (values: string[], input: string) => void;
  isRead: boolean;
}) {
  const t = useTranslation('admin');
  return (
    <FieldRow
      id="public-form-origins"
      label={
        isRead
          ? t('settings.publicForms.origins.labelRead', 'Sites that may read this')
          : t('settings.publicForms.origins.label', 'Sites that may send this form')
      }
      hint={t(
        'settings.publicForms.origins.chipsHint',
        'The form only works on these addresses, like https://www.example-bakery.com. Leave empty to allow only this instance’s own allowed sites.'
      )}
    >
      <ChipsInput
        id="public-form-origins"
        values={values}
        input={input}
        onChange={onChange}
        placeholder={t('settings.publicForms.origins.add', 'Add a site…')}
        removeLabel={(site) => t('settings.publicForms.origins.remove', 'Remove {site}', { site })}
      />
    </FieldRow>
  );
}

function IssueDialog({
  open,
  forms,
  onCancel,
  onIssued,
}: {
  open: boolean;
  forms: FormRoute[];
  onCancel: () => void;
  onIssued: (issued: IssuedKey) => void;
}) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const [form, setForm] = useState(forms.length === 1 ? `${forms[0].plugin}/${forms[0].form}` : '');
  const [label, setLabel] = useState('');
  const [origins, setOrigins] = useState<string[]>([]);
  const [originInput, setOriginInput] = useState('');
  const [busy, setBusy] = useState(false);
  const chosen = forms.find((f) => `${f.plugin}/${f.form}` === form);

  const reset = () => {
    setLabel('');
    setOrigins([]);
    setOriginInput('');
  };

  const submit = async () => {
    const [plugin, name] = form.split('/');
    setBusy(true);
    const { data: issued, response: res, error } = await api.POST('/api/v1/public-form-keys', {
      body: { plugin, form: name, label, allowed_origins: [...origins, ...splitChips(originInput)] },
    });
    setBusy(false);
    if (!res.ok) {
      addToast(readError(error, t('settings.publicForms.issue.failed', 'The key could not be issued'), t), 'error');
      return;
    }
    const body = issued as unknown as { data: { key: string; plugin: string; form: string; kind: RouteKind } };
    const segment = body.data.kind === 'read' ? 'public' : 'forms';
    reset();
    onIssued({
      key: body.data.key,
      path: `/api/v1/${body.data.plugin}/${segment}/${body.data.form}/${body.data.key}`,
      kind: body.data.kind,
    });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onCancel();
      }}
    >
      <DialogContent data-testid="public-forms-issue-card" closeLabel={t('settings.publicForms.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('settings.publicForms.issue', 'Issue a key')}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          <FieldRow id="public-form-route" label={t('settings.publicForms.route', 'What this key opens')}>
            <FieldSelect
              id="public-form-route"
              value={form}
              onChange={setForm}
              placeholder={t('settings.publicForms.route.placeholder', 'Choose one')}
              options={forms.map((f) => ({
                value: `${f.plugin}/${f.form}`,
                label: `${f.plugin} · ${f.form}${f.kind === null ? '' : ` (${kindLabel(t, f.kind)})`}`,
              }))}
            />
          </FieldRow>
          <FieldRow
            id="public-form-label"
            label={t('settings.publicForms.label', 'Name')}
            hint={t('settings.publicForms.label.forYou', 'For you; visitors never see it.')}
          >
            <Input id="public-form-label" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} />
          </FieldRow>
          <OriginsField
            values={origins}
            input={originInput}
            onChange={(values, input) => {
              setOrigins(values);
              setOriginInput(input);
            }}
            isRead={chosen?.kind === 'read'}
          />
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onCancel}>
            {t('settings.publicForms.cancel', 'Cancel')}
          </Button>
          <Button
            data-testid="public-forms-issue-submit"
            disabled={busy || form === '' || label.trim() === ''}
            loading={busy}
            onClick={() => void submit()}
          >
            {t('settings.publicForms.issueSubmit', 'Issue key')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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
      <CardContent className="space-y-3 py-4">
        <Alert variant="warning">
          <AlertTitle>{t('settings.publicForms.issued.titleNow', 'Copy the key now')}</AlertTitle>
          <AlertDescription>
            {t(
              'settings.publicForms.issued.once',
              'You’ll only see it once. Paste it into the form’s snippet on your site. If it’s lost, revoke it and issue another.'
            )}
          </AlertDescription>
        </Alert>
        <div className="flex items-center gap-3 rounded-lg bg-muted px-4 py-3">
          <code dir="ltr" className="min-w-0 flex-1 break-all font-mono text-sm text-foreground">
            {issued.key}
          </code>
          <Button size="sm" className="shrink-0 gap-1" onClick={() => copy(issued.key)}>
            <Icon name="copy" size={16} />
            {t('settings.publicForms.copyKey', 'Copy key')}
          </Button>
        </div>
        <div className="space-y-1 rounded-lg border border-border px-4 py-3">
          <p className="text-xs text-muted-foreground">
            {issued.kind === 'read'
              ? t('settings.publicForms.issued.pathRead', 'Address to read it from')
              : t('settings.publicForms.issued.path', 'Address to send the form to')}
          </p>
          <div className="flex items-center gap-2">
            <code dir="ltr" className="min-w-0 flex-1 break-all font-mono text-sm text-foreground">
              {issued.path}
            </code>
            <Button variant="outline" size="sm" className="shrink-0 gap-1" onClick={() => copy(issued.path)}>
              <Icon name="copy" size={16} />
              {t('settings.publicForms.copy', 'Copy')}
            </Button>
          </div>
        </div>
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
  const [origins, setOrigins] = useState<string[]>(formKey.allowed_origins);
  const [originInput, setOriginInput] = useState('');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    const { response: res, error } = await api.PATCH('/api/v1/public-form-keys/{id}', {
      params: { path: { id: formKey.id } },
      body: { label, allowed_origins: [...origins, ...splitChips(originInput)] },
    });
    setBusy(false);
    if (!res.ok) {
      addToast(readError(error, t('settings.publicForms.edit.failed', 'The key could not be changed'), t), 'error');
      return;
    }
    addToast(t('settings.publicForms.edit.done', 'Key changed.'), 'success');
    onSaved();
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid={`public-form-key-editor-${formKey.id}`}>
      <CardHeader>
        <CardTitle>{t('settings.publicForms.editTitle', 'Edit {label}', { label: formKey.label })}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <FieldRow id="public-form-label" label={t('settings.publicForms.label', 'Name')}>
          <Input id="public-form-label" value={label} maxLength={120} onChange={(e) => setLabel(e.target.value)} />
        </FieldRow>
        <OriginsField
          values={origins}
          input={originInput}
          onChange={(values, input) => {
            setOrigins(values);
            setOriginInput(input);
          }}
          isRead={formKey.kind === 'read'}
        />
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
    const { data: body, response: res, error } = await api.GET('/api/v1/settings/public-forms/bot-check');
    if (!res.ok) {
      throw new Error(readError(error, t('settings.publicForms.bot.loadFailed', 'The bot check settings could not be loaded'), t));
    }
    return (body as unknown as { data: BotSettings }).data;
  }, []);

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="public-forms-bot-check">
      <CardHeader>
        <CardTitle>{t('settings.publicForms.bot.titleWhity', 'Bot check for this Whity')}</CardTitle>
        <CardDescription>
          {t(
            'settings.publicForms.bot.operatorsOnly',
            'Operators only. Every public form and the chat box use it. If the provider can’t be reached, forms refuse submissions until it’s back.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
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
  // A stored secret is never shown: Replace opens an empty box for a new one.
  const [replacing, setReplacing] = useState(!settings.has_secret);
  const [allowNone, setAllowNone] = useState(settings.allow_without_bot_check);
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    const { response: res, error } = await api.PUT('/api/v1/settings/public-forms/bot-check', {
      body: {
        provider: provider === NO_PROVIDER ? null : provider,
        site_key: siteKey,
        allow_without_bot_check: provider === NO_PROVIDER && allowNone,
        ...(secret.trim() !== '' ? { secret } : {}),
      },
    });
    setBusy(false);
    if (!res.ok) {
      addToast(readError(error, t('settings.publicForms.bot.saveFailed', 'The bot check settings could not be saved'), t), 'error');
      return;
    }
    addToast(t('settings.publicForms.bot.saved', 'Bot check settings saved.'), 'success');
    setSecret('');
    onSaved();
  };

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2">
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
          <FieldRow
            id="public-form-bot-site-key"
            label={t('settings.publicForms.bot.siteKey', 'Site key')}
            hint={t('settings.publicForms.bot.siteKey.hint', 'Public. A form’s page reads it to show the check.')}
          >
            <Input id="public-form-bot-site-key" dir="ltr" value={siteKey} onChange={(e) => setSiteKey(e.target.value)} />
          </FieldRow>
        )}
      </div>
      {provider !== NO_PROVIDER &&
        (replacing ? (
          <FieldRow
            id="public-form-bot-secret"
            label={t('settings.publicForms.bot.secret', 'Secret key')}
            hint={
              settings.has_secret
                ? t('settings.publicForms.bot.secret.writeOnly', 'Write-only: nobody can read it back. The stored one stays until you save a new one.')
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
        ) : (
          <div className="space-y-1.5" data-testid="public-forms-secret-stored">
            <p className="text-sm font-medium text-foreground">{t('settings.publicForms.bot.secret', 'Secret key')}</p>
            <div className="flex items-center gap-3">
              <span dir="ltr" className="font-mono text-sm text-foreground" aria-hidden="true">
                ••••••••••••
              </span>
              <span className="text-sm text-muted-foreground">{t('settings.publicForms.bot.secret.set', 'Set')}</span>
              <Button variant="outline" size="sm" data-testid="public-forms-secret-replace" onClick={() => setReplacing(true)}>
                {t('settings.publicForms.bot.secret.replace', 'Replace')}
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              {t('settings.publicForms.bot.secret.writeOnlyShort', 'Write-only: nobody can read it back.')}
            </p>
          </div>
        ))}
      {provider === NO_PROVIDER && (
        <div className="space-y-1">
          <div className="flex items-center gap-3">
            <Switch
              id="public-form-bot-allow-none"
              data-testid="public-forms-allow-none"
              checked={allowNone}
              onCheckedChange={setAllowNone}
            />
            <label htmlFor="public-form-bot-allow-none" className="text-sm text-foreground">
              {t('settings.publicForms.bot.allowNoneShort', 'Let forms work with no bot check')}
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            {t(
              'settings.publicForms.bot.allowNone.riskAll',
              'Off: public forms, public chat and online booking close while no bot check is set. Turning it on lets spam through, unless something else in front of this Whity stops bots.'
            )}
          </p>
        </div>
      )}
      <Button variant="outline" data-testid="public-forms-bot-save" disabled={busy} onClick={() => void save()}>
        {t('settings.publicForms.save', 'Save')}
      </Button>
    </>
  );
}
