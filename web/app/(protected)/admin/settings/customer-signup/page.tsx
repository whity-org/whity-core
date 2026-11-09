'use client';

/**
 * Settings → Sign-up for a workspace (WHIT-915): whether people may join this
 * workspace as customers on their own, the role they get, which email domains
 * may sign up, and the people waiting for an admin.
 *
 * The platform's own sign-up (creating a workspace) is /admin/settings/signup
 * and stays the operator's. The server only offers this page outside the
 * system workspace, to people with `signup:manage`.
 *
 * No design card yet (design ask in DESIGN-ASKS.md). Built from section 21:
 * edit in place with the save bar, the six states, and the Public forms page's
 * card layout.
 */

import { useMemo, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { SIGNUP_MANAGE } from '@/lib/capabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { AccessDenied } from '@whity/ui/access-denied';
import { ActorChip } from '@whity/ui/actor-chip';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { CopyBlock } from '@whity/ui/copy-block';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import { Textarea } from '@whity/ui/textarea';
import { IconUserCheck } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useDateDisplay } from '@whity/features/datetime';

type Mode = 'off' | 'approval' | 'open';

interface EligibleRole {
  id: number;
  name: string;
  description: string;
  global: boolean;
}

interface SignupSettings {
  mode: Mode;
  role_id: number | null;
  role_name: string | null;
  role_customer_safe: boolean | null;
  allowed_domains: string[];
  eligible_roles: EligibleRole[];
  pending_count: number;
  workspace_slug: string | null;
  bot_check_ready: boolean;
  /** Whether the email form can work: a bot check is set up, or going without is allowed (WHIT-917). */
  email_signup_ready?: boolean;
  /** The instance's sign-in providers people can join with instead. */
  join_providers?: { provider_key: string; display_name: string }[];
}

interface SignupRequest {
  id: number;
  email: string;
  display_name: string;
  requested_at: string;
  verified_at: string | null;
  decided_at: string | null;
  decided_by_name: string | null;
}

type QueueStatus = 'pending' | 'rejected';

interface Draft {
  mode: Mode;
  roleId: string;
  domains: string;
}

function draftOf(settings: SignupSettings): Draft {
  return {
    mode: settings.mode,
    roleId: settings.role_id === null ? '' : String(settings.role_id),
    domains: settings.allowed_domains.join('\n'),
  };
}

function splitDomains(text: string): string[] {
  return text
    .split(/[\s,]+/)
    .map((d) => d.trim())
    .filter((d) => d !== '');
}

/**
 * The server's refusal in the reader's language (WHIT-916). The server sends a
 * stable `code` (and, for a bad domain, the line at fault) beside its English
 * sentence; the sentence is only a fallback for a code this page doesn't know.
 */
function codeText(t: TranslateFn, details: Record<string, unknown>): string | null {
  const value = typeof details.value === 'string' ? details.value : '';
  const max = typeof details.max === 'number' ? String(details.max) : '';
  switch (details.code) {
    case 'domain_invalid':
      return t(
        'settings.customerSignup.error.domainInvalid',
        '“{value}” isn’t a domain name. Enter domains such as example-bakery.com, one per line.',
        { value }
      );
    case 'domains_too_many':
      return t('settings.customerSignup.error.domainsTooMany', 'List at most {max} domains.', { max });
    case 'mode_invalid':
      return t('settings.customerSignup.error.modeInvalid', 'Choose Off, Approval required or Open.');
    case 'role_required':
      return t('settings.customerSignup.error.roleRequired', 'Choose the role people get when they join.');
    case 'role_not_customer_safe':
      return t(
        'settings.customerSignup.error.roleUnsafe',
        'That role has admin permissions, so it can’t be given to customers. Choose another role.'
      );
    case 'workspace_slug_missing':
      return t(
        'settings.customerSignup.error.noSlug',
        'This workspace has no web address yet, so nobody could open its sign-up page. Ask the person who runs this Whity to give it one, then turn sign-up on.'
      );
    case 'membership_conflict':
      return t(
        'settings.customerSignup.error.membershipConflict',
        'This person already has a suspended membership or an open invitation here. Sort that out in People first.'
      );
    case 'request_not_found':
      return t('settings.customerSignup.error.requestGone', 'This request was already decided or no longer exists.');
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

export default function CustomerSignupSettingsPage() {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { has, loading: capabilitiesLoading } = useCapabilities();
  const canManage = has(SIGNUP_MANAGE);

  const {
    data: settings,
    error: loadError,
    refetch,
  } = useFetch<SignupSettings>(async () => {
    const res = await apiClient('/api/v1/signup/settings');
    if (!res.ok) {
      throw new Error(await readError(res, t('settings.customerSignup.loadFailed', 'Sign-up settings could not be loaded'), t));
    }
    return ((await res.json()) as { data: SignupSettings }).data;
  }, []);

  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  const saved = settings ? draftOf(settings) : null;
  const current = draft ?? saved;
  const dirty =
    saved !== null &&
    current !== null &&
    (current.mode !== saved.mode ||
      current.roleId !== saved.roleId ||
      splitDomains(current.domains).join(',') !== splitDomains(saved.domains).join(','));

  if (!capabilitiesLoading && !canManage) {
    return (
      <AccessDenied
        description={t(
          'settings.customerSignup.accessDenied',
          'You need permission to manage sign-up to open this page.'
        )}
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            {t('settings.customerSignup.goBack', 'Go back')}
          </Button>
        }
      />
    );
  }

  const save = async () => {
    if (current === null) return;
    setSaving(true);
    setSaveError(null);
    const res = await apiClient('/api/v1/signup/settings', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        mode: current.mode,
        role_id: current.roleId === '' ? null : Number(current.roleId),
        allowed_domains: splitDomains(current.domains),
      }),
    });
    setSaving(false);
    if (!res.ok) {
      setSaveError(await readError(res, t('settings.customerSignup.saveFailed', 'The settings could not be saved'), t));
      return;
    }
    setDraft(null);
    addToast(t('settings.customerSignup.saved', 'Sign-up settings saved'), 'success');
    refetch();
  };

  const update = (patch: Partial<Draft>) => {
    if (current === null) return;
    setDraft({ ...current, ...patch });
    setSaveError(null);
  };

  const joinLink =
    settings?.workspace_slug && typeof window !== 'undefined'
      ? `${window.location.origin}/join/${settings.workspace_slug}`
      : null;

  const changed: string[] = [];
  if (saved && current) {
    if (current.mode !== saved.mode) changed.push(t('settings.customerSignup.mode', 'Who can sign up'));
    if (current.roleId !== saved.roleId) changed.push(t('settings.customerSignup.role', 'Starting role'));
    if (splitDomains(current.domains).join(',') !== splitDomains(saved.domains).join(','))
      changed.push(t('settings.customerSignup.domains', 'Allowed email domains'));
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.customerSignup.title', 'Sign-up')}
        description={t(
          'settings.customerSignup.description',
          'Let people join this workspace as customers on their own. Customers never get admin access, whatever their role.'
        )}
      />

      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {settings === null || current === null ? (
        !loadError && (
          <div className="space-y-3" data-testid="customer-signup-loading">
            {[0, 1].map((i) => (
              <div key={i} className="h-32 animate-pulse rounded-lg bg-muted/40" />
            ))}
          </div>
        )
      ) : (
        <>
          {settings.workspace_slug === null && (
            <Alert data-testid="customer-signup-no-slug">
              <AlertDescription>
                {t(
                  'settings.customerSignup.error.noSlug',
                  'This workspace has no web address yet, so nobody could open its sign-up page. Ask the person who runs this Whity to give it one, then turn sign-up on.'
                )}
              </AlertDescription>
            </Alert>
          )}
          {settings.mode !== 'off' && settings.role_customer_safe === false && (
            <Alert variant="destructive" data-testid="customer-signup-unsafe-role">
              <AlertDescription>
                {t(
                  'settings.customerSignup.unsafeRole',
                  'Sign-ups are paused: the starting role now has admin permissions. Choose another role, or remove those permissions.'
                )}
              </AlertDescription>
            </Alert>
          )}
          {/* WHIT-917: warn while choosing, not only after saving, so nobody
              shares a sign-up link that can't work. */}
          {current.mode !== 'off' && settings.email_signup_ready === false && (
            <Alert variant="destructive" data-testid="customer-signup-no-bot-check">
              <AlertDescription className="space-y-1">
                <span className="block font-medium">
                  {t('settings.customerSignup.noBotCheck.title', 'Sign-up won’t work until the operator sets up a bot check')}
                </span>
                <span className="block">
                  {(settings.join_providers ?? []).length > 0
                    ? t(
                        'settings.customerSignup.noBotCheck.withProviders',
                        'Signing up with email stays closed until then. People can still join with {providers}.',
                        { providers: (settings.join_providers ?? []).map((p) => p.display_name).join(', ') }
                      )
                    : t(
                        'settings.customerSignup.noBotCheck.description',
                        'Until then, your sign-up page tells visitors this workspace isn’t taking sign-ups yet. The person who runs this Whity sets it up in Settings, Public forms.'
                      )}
                </span>
                <a href="/admin/settings/public-forms" className="block font-medium underline" data-testid="customer-signup-bot-check-link">
                  {t('settings.customerSignup.noBotCheck.link', 'Bot check settings (for the operator)')}
                </a>
              </AlertDescription>
            </Alert>
          )}

          <Card className="border border-border bg-card shadow-sm">
            <CardHeader>
              <CardTitle>{t('settings.customerSignup.mode', 'Who can sign up')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.customerSignup.mode.hint',
                  'Everyone confirms their email first. Signing up never creates a new workspace.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <ChangedField changed={current.mode !== saved?.mode}>
                <RadioGroup
                  value={current.mode}
                  onValueChange={(value) => update({ mode: value as Mode })}
                  className="space-y-3"
                  data-testid="customer-signup-mode"
                >
                  {(
                    [
                      ['off', t('settings.customerSignup.mode.off', 'Off'), t('settings.customerSignup.mode.off.hint', 'Nobody can sign up. People join only when invited.')],
                      ['approval', t('settings.customerSignup.mode.approval', 'Approval required'), t('settings.customerSignup.mode.approval.hint', 'People sign up and wait. An admin lets each one in below.')],
                      ['open', t('settings.customerSignup.mode.open', 'Open'), t('settings.customerSignup.mode.open.hint', 'People join as soon as they confirm their email.')],
                    ] as const
                  ).map(([value, label, hint]) => (
                    <label key={value} htmlFor={`signup-mode-${value}`} className="flex cursor-pointer items-start gap-3">
                      <RadioGroupItem id={`signup-mode-${value}`} value={value} className="mt-1" />
                      <span className="space-y-0.5">
                        <span className="block text-sm font-medium text-foreground">{label}</span>
                        <span className="block text-sm text-muted-foreground">{hint}</span>
                      </span>
                    </label>
                  ))}
                </RadioGroup>
              </ChangedField>

              <ChangedField changed={current.roleId !== saved?.roleId}>
                <label htmlFor="signup-role" className="text-sm font-medium text-foreground">
                  {t('settings.customerSignup.role', 'Starting role')}
                </label>
                <FieldSelect
                  id="signup-role"
                  value={current.roleId}
                  onChange={(value) => update({ roleId: value })}
                  placeholder={t('settings.customerSignup.role.placeholder', 'Choose a role')}
                  options={settings.eligible_roles.map((role) => ({ value: String(role.id), label: role.name }))}
                />
                <p className="text-sm text-muted-foreground" data-testid="customer-signup-role-hint">
                  {settings.eligible_roles.length === 0
                    ? t(
                        'settings.customerSignup.role.none',
                        'No role qualifies yet. In Roles, create one with only what customers need, such as submitting forms.'
                      )
                    : t(
                        'settings.customerSignup.role.hint',
                        'Only roles with no admin permissions are listed.'
                      )}
                </p>
              </ChangedField>

              <ChangedField
                changed={splitDomains(current.domains).join(',') !== splitDomains(saved?.domains ?? '').join(',')}
              >
                <label htmlFor="signup-domains" className="text-sm font-medium text-foreground">
                  {t('settings.customerSignup.domains', 'Allowed email domains')}
                </label>
                <Textarea
                  id="signup-domains"
                  dir="ltr"
                  rows={3}
                  value={current.domains}
                  placeholder="example-bakery.com"
                  onChange={(e) => update({ domains: e.target.value })}
                />
                <p className="text-sm text-muted-foreground">
                  {t(
                    'settings.customerSignup.domains.hint',
                    'Optional. One per line, like example-bakery.com. Leave empty to accept any email address.'
                  )}
                </p>
              </ChangedField>

              {saveError && (
                <Alert variant="destructive">
                  <AlertDescription>{saveError}</AlertDescription>
                </Alert>
              )}
            </CardContent>
          </Card>

          {settings.mode !== 'off' && joinLink && (
            <Card className="border border-border bg-card shadow-sm" data-testid="customer-signup-link">
              <CardHeader>
                <CardTitle>{t('settings.customerSignup.link', 'Your sign-up page')}</CardTitle>
                <CardDescription>
                  {t(
                    'settings.customerSignup.link.hint',
                    'Share this link, or put it on your website. It also works on your own domain once it is set up in Branding.'
                  )}
                </CardDescription>
              </CardHeader>
              {/* A link is read left to right in both languages; without this the
                  copy button sits on top of the URL in Arabic. */}
              <CardContent dir="ltr">
                <CopyBlock
                  value={joinLink}
                  copyLabel={t('settings.customerSignup.copy', 'Copy')}
                  copiedLabel={t('settings.customerSignup.copied', 'Copied')}
                />
              </CardContent>
            </Card>
          )}

          <PendingQueue pendingCount={settings.pending_count} onDecided={refetch} />
        </>
      )}

      <SaveBar
        dirty={dirty}
        summary={t('settings.customerSignup.changed', '{what} changed', { what: changed.join(', ') })}
        discardLabel={t('settings.customerSignup.discard', 'Discard')}
        saveLabel={t('settings.customerSignup.save', 'Save changes')}
        saving={saving}
        onDiscard={() => {
          setDraft(null);
          setSaveError(null);
        }}
        onSave={() => void save()}
      />
    </div>
  );
}

function PendingQueue({ pendingCount, onDecided }: { pendingCount: number; onDecided: () => void }) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const dates = useDateDisplay();
  const [busy, setBusy] = useState<number | null>(null);
  const [status, setStatus] = useState<QueueStatus>('pending');
  const [rejecting, setRejecting] = useState<SignupRequest | null>(null);

  const { data, error, refetch } = useFetch<SignupRequest[]>(async () => {
    const res = await apiClient(`/api/v1/signup/requests?status=${status}&per_page=100`);
    if (!res.ok) {
      throw new Error(await readError(res, t('settings.customerSignup.queue.loadFailed', 'The requests could not be loaded'), t));
    }
    return ((await res.json()) as { data: SignupRequest[] }).data;
  }, [pendingCount, status]);

  const rows = useMemo(() => data ?? [], [data]);

  const decide = async (request: SignupRequest, approve: boolean) => {
    setBusy(request.id);
    const res = await apiClient(`/api/v1/signup/requests/${request.id}/${approve ? 'approve' : 'reject'}`, {
      method: 'POST',
    });
    setBusy(null);
    if (!res.ok) {
      addToast(await readError(res, t('settings.customerSignup.queue.failed', 'The request could not be updated'), t), 'error');
      return;
    }
    addToast(
      approve
        ? t('settings.customerSignup.queue.approved', '{name} can now sign in', { name: request.display_name })
        : t('settings.customerSignup.queue.rejected', '{name} was turned away', { name: request.display_name }),
      'success'
    );
    refetch();
    onDecided();
  };

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="customer-signup-queue">
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{t('settings.customerSignup.queue', 'Waiting to join')}</CardTitle>
          {rows.length > 0 && (
            <Badge variant="outline" className="text-xs">
              {rows.length}
            </Badge>
          )}
        </div>
        <CardDescription>
          {status === 'pending'
            ? t(
                'settings.customerSignup.queue.hint',
                'People who confirmed their email while sign-up needs approval. Oldest first.'
              )
            : t(
                'settings.customerSignup.queue.rejectedHint',
                'People you turned away. You can still let any of them in.'
              )}
        </CardDescription>
        <div role="group" aria-label={t('settings.customerSignup.queue.show', 'Show')} className="flex gap-2 pt-2">
          {(
            [
              ['pending', t('settings.customerSignup.queue.filter.pending', 'Waiting')],
              ['rejected', t('settings.customerSignup.queue.filter.rejected', 'Turned away')],
            ] as const
          ).map(([value, label]) => (
            <Button
              key={value}
              size="sm"
              variant={status === value ? 'secondary' : 'ghost'}
              aria-pressed={status === value}
              data-testid={`customer-signup-filter-${value}`}
              onClick={() => setStatus(value)}
            >
              {label}
            </Button>
          ))}
        </div>
      </CardHeader>
      <CardContent>
        {error ? (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : data === null ? (
          <div className="h-12 animate-pulse rounded-lg bg-muted/40" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-8 text-center" data-testid="customer-signup-queue-empty">
            <IconUserCheck className="h-8 w-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">
              {status === 'pending'
                ? t('settings.customerSignup.queue.empty', 'Nobody is waiting.')
                : t('settings.customerSignup.queue.emptyRejected', 'Nobody has been turned away.')}
            </p>
          </div>
        ) : (
          <ul className="divide-y divide-border">
            {rows.map((request) => (
              <li
                key={request.id}
                className="flex min-h-12 flex-col gap-3 py-3 md:flex-row md:items-center md:justify-between"
                data-testid={`customer-signup-request-${request.id}`}
              >
                <div className="min-w-0 space-y-1">
                  <ActorChip name={request.display_name} />
                  <p className="text-sm text-muted-foreground">
                    <span dir="ltr">{request.email}</span>
                    {status === 'pending' && dates.dateTime(request.verified_at ?? request.requested_at) !== null && (
                      <>
                        {' · '}
                        {t('settings.customerSignup.queue.since', 'Waiting since {when}', {
                          when: dates.dateTime(request.verified_at ?? request.requested_at) ?? '',
                        })}
                      </>
                    )}
                    {status === 'rejected' && dates.dateTime(request.decided_at) !== null && (
                      <>
                        {' · '}
                        {request.decided_by_name
                          ? t('settings.customerSignup.queue.rejectedBy', 'Turned away by {name} on {when}', {
                              name: request.decided_by_name,
                              when: dates.dateTime(request.decided_at) ?? '',
                            })
                          : t('settings.customerSignup.queue.rejectedOn', 'Turned away on {when}', {
                              when: dates.dateTime(request.decided_at) ?? '',
                            })}
                      </>
                    )}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={busy !== null}
                    loading={busy === request.id}
                    data-testid={`customer-signup-approve-${request.id}`}
                    onClick={() => void decide(request, true)}
                  >
                    {t('settings.customerSignup.queue.approve', 'Approve')}
                  </Button>
                  {status === 'pending' && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-destructive"
                      disabled={busy !== null}
                      data-testid={`customer-signup-reject-${request.id}`}
                      onClick={() => setRejecting(request)}
                    >
                      {t('settings.customerSignup.queue.reject', 'Reject')}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      {/* Reversible (a rejected request can still be approved under Turned
          away), so a short confirmation that says so is enough (section 21). */}
      <ConfirmAction
        open={rejecting !== null}
        onOpenChange={(open) => {
          if (!open) setRejecting(null);
        }}
        title={t('settings.customerSignup.queue.rejectTitle', 'Turn {name} away?', { name: rejecting?.display_name ?? '' })}
        description={t(
          'settings.customerSignup.queue.rejectDescription',
          'They won’t be able to join by signing up again. You can still let them in later from Turned away.'
        )}
        confirmLabel={t('settings.customerSignup.queue.reject', 'Reject')}
        cancelLabel={t('settings.customerSignup.queue.cancel', 'Cancel')}
        onConfirm={async () => {
          const request = rejecting;
          setRejecting(null);
          if (request !== null) await decide(request, false);
        }}
      />
    </Card>
  );
}
