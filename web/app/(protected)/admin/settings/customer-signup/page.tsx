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
 * Card: TplSettingsSignup (WHIT-1044). Who can sign up with the starting role
 * and the allowed domains as chips side by side and the link with Copy under
 * them; Team invites as its own card with its switch; the queue with Approve,
 * Reject and Approve all; saving through the save bar (section 21).
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { SIGNUP_MANAGE } from '@/lib/capabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { ChipsInput, splitChips } from '@/components/chips-input';
import { AccessDenied } from '@/components/ui/access-denied';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import { Input } from '@whity/ui/input';
import { Switch } from '@whity/ui/switch';
import { Avatar } from '@whity/ui/avatar';
import { EmptyState } from '@whity/ui/empty-state';
import { Icon } from '@whity/ui/icon';
import { useAuth } from '@/lib/auth-context';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useDateDisplay, type RelativeAgeLabels } from '@whity/features/datetime';

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
  /** Whether customers may invite colleagues into their own role (#1872). */
  customer_invites?: boolean;
  /** How many people one customer may invite; 0 is no limit (WHIT-1043). */
  invites_per_customer?: number;
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
  /** The domains already turned into chips. */
  domains: string[];
  /** What is typed in the domain box and not yet a chip; it is saved too. */
  domainInput: string;
  customerInvites: boolean;
  /** Text as typed; empty is no limit. */
  perCustomer: string;
}

function draftOf(settings: SignupSettings): Draft {
  return {
    mode: settings.mode,
    roleId: settings.role_id === null ? '' : String(settings.role_id),
    domains: settings.allowed_domains,
    domainInput: '',
    customerInvites: settings.customer_invites === true,
    perCustomer: settings.invites_per_customer ? String(settings.invites_per_customer) : '',
  };
}

/** The chips and whatever is still typed in the box, as the list that is saved. */
function domainsOf(draft: Draft): string[] {
  return [...draft.domains, ...splitChips(draft.domainInput)];
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
    case 'invites_per_customer_invalid':
      return t('settings.customerSignup.error.perCustomerInvalid', 'Enter a whole number from 1 to 1000, or leave it empty for no limit.');
    case 'customer_invites_invalid':
      return t('settings.customerSignup.error.invitesInvalid', 'Turn customer invitations on or off.');
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

export default function CustomerSignupSettingsPage() {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const { has, loading: capabilitiesLoading } = useCapabilities();
  const canManage = has(SIGNUP_MANAGE);
  const { user, memberships } = useAuth();
  const workspaceName = memberships.find((m) => m.tenant_id === user?.tenant_id)?.tenant_name ?? null;

  const {
    data: settings,
    error: loadError,
    refetch,
  } = useFetch<SignupSettings>(async () => {
    const { data, response: res, error } = await api.GET('/api/v1/signup/settings');
    if (!res.ok) {
      throw new Error(readError(error, t('settings.customerSignup.loadFailed', 'Sign-up settings could not be loaded'), t));
    }
    return (data as unknown as { data: SignupSettings }).data;
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
      current.customerInvites !== saved.customerInvites ||
      current.perCustomer.trim() !== saved.perCustomer ||
      domainsOf(current).join(',') !== domainsOf(saved).join(','));

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
    const { response: res, error } = await api.PUT('/api/v1/signup/settings', {
      body: {
        mode: current.mode,
        role_id: current.roleId === '' ? null : Number(current.roleId),
        allowed_domains: domainsOf(current),
        customer_invites: current.customerInvites,
        // Empty is no limit (0); anything else the server checks is a whole number.
        invites_per_customer: current.perCustomer.trim() === '' ? 0 : Number(current.perCustomer.trim()),
      },
    });
    setSaving(false);
    if (!res.ok) {
      setSaveError(readError(error, t('settings.customerSignup.saveFailed', 'The settings could not be saved'), t));
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

  const copyLink = async (link: string) => {
    try {
      await navigator.clipboard.writeText(link);
      addToast(t('settings.customerSignup.copied', 'Copied'), 'success');
    } catch {
      addToast(t('settings.customerSignup.copyFailed', 'Could not copy. Select the link and copy it yourself.'), 'error');
    }
  };

  const changed: string[] = [];
  if (saved && current) {
    if (current.mode !== saved.mode) changed.push(t('settings.customerSignup.mode', 'Who can sign up'));
    if (current.roleId !== saved.roleId) changed.push(t('settings.customerSignup.role', 'Starting role'));
    if (domainsOf(current).join(',') !== domainsOf(saved).join(','))
      changed.push(t('settings.customerSignup.domains', 'Allowed email domains'));
    if (current.customerInvites !== saved.customerInvites)
      changed.push(t('settings.customerSignup.invites', 'Customers can invite their team'));
    if (current.perCustomer.trim() !== saved.perCustomer)
      changed.push(t('settings.customerSignup.perCustomer', 'People per customer'));
  }

  return (
    <div className="space-y-6 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.customerSignup.title', 'Sign-up')}
        description={
          workspaceName
            ? t('settings.customerSignup.descriptionNamed', 'How customers join {name}.', { name: workspaceName })
            : t('settings.customerSignup.descriptionPlain', 'How customers join this workspace.')
        }
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
              <AlertTitle>{t('settings.customerSignup.paused.title', 'Sign-ups are paused')}</AlertTitle>
              <AlertDescription>
                {t(
                  'settings.customerSignup.paused.description',
                  '“{role}” can now change settings, so new people can’t start with it. Choose another role, or remove those permissions from {role}.',
                  { role: settings.role_name ?? '' }
                )}
              </AlertDescription>
            </Alert>
          )}
          {/* WHIT-917: warn while choosing, not only after saving, so nobody
              shares a sign-up link that can't work. */}
          {current.mode !== 'off' && settings.email_signup_ready === false && (
            <Alert variant="destructive" data-testid="customer-signup-no-bot-check">
              <AlertTitle>
                {t('settings.customerSignup.noBotCheck.title', 'Sign-up won’t work until the operator sets up a bot check')}
              </AlertTitle>
              <AlertDescription className="space-y-1">
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
                  'settings.customerSignup.mode.forCustomers',
                  'For your customers. Staff join by invitation or by email domain.'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <ChangedField changed={current.mode !== saved?.mode}>
                <p id="signup-mode-label" className="text-sm font-medium text-foreground">
                  {t('settings.customerSignup.mode.label', 'Sign-up')}
                </p>
                <RadioGroup
                  value={current.mode}
                  onValueChange={(value) => update({ mode: value as Mode })}
                  aria-labelledby="signup-mode-label"
                  className="space-y-2"
                  data-testid="customer-signup-mode"
                >
                  {(
                    [
                      ['off', t('settings.customerSignup.mode.off', 'Off'), t('settings.customerSignup.mode.off.short', 'Only people you invite can join.')],
                      ['approval', t('settings.customerSignup.mode.approval', 'Approval required'), t('settings.customerSignup.mode.approval.short', 'Anyone can ask; an admin approves each one.')],
                      ['open', t('settings.customerSignup.mode.open', 'Open'), t('settings.customerSignup.mode.open.short', 'Anyone with the link joins as soon as they confirm their email.')],
                    ] as const
                  ).map(([value, label, hint]) => (
                    <label key={value} htmlFor={`signup-mode-${value}`} className="flex cursor-pointer items-start gap-3">
                      <RadioGroupItem id={`signup-mode-${value}`} value={value} className="mt-1" />
                      <span>
                        <span className="block text-sm text-foreground">{label}</span>
                        <span className="block text-xs text-muted-foreground">{hint}</span>
                      </span>
                    </label>
                  ))}
                </RadioGroup>
              </ChangedField>

              <div className="grid gap-4 sm:grid-cols-2">
                <ChangedField changed={current.roleId !== saved?.roleId}>
                  <label htmlFor="signup-role" className="text-sm font-medium text-foreground">
                    {t('settings.customerSignup.role.startAs', 'New people start as')}
                  </label>
                  <FieldSelect
                    id="signup-role"
                    value={current.roleId}
                    onChange={(value) => update({ roleId: value })}
                    placeholder={t('settings.customerSignup.role.placeholder', 'Choose a role')}
                    options={settings.eligible_roles.map((role) => ({ value: String(role.id), label: role.name }))}
                  />
                  {settings.eligible_roles.length === 0 ? (
                    <div className="space-y-2" data-testid="customer-signup-role-hint">
                      <p className="text-xs text-destructive">
                        {t('settings.customerSignup.role.noneShort', 'Make a role without admin permissions first.')}
                      </p>
                      <Button asChild variant="outline" size="sm">
                        <Link href="/admin/roles">{t('settings.customerSignup.role.openRoles', 'Open Roles')}</Link>
                      </Button>
                    </div>
                  ) : (
                    <p className="text-xs text-muted-foreground" data-testid="customer-signup-role-hint">
                      {t('settings.customerSignup.role.safe', 'Only roles safe for customers are listed.')}
                    </p>
                  )}
                </ChangedField>

                <ChangedField changed={domainsOf(current).join(',') !== (saved ? domainsOf(saved).join(',') : '')}>
                  <label htmlFor="signup-domains" className="text-sm font-medium text-foreground">
                    {t('settings.customerSignup.domains', 'Allowed email domains')}
                  </label>
                  <ChipsInput
                    id="signup-domains"
                    testId="customer-signup-domains"
                    values={current.domains}
                    input={current.domainInput}
                    onChange={(domains, domainInput) => update({ domains, domainInput })}
                    placeholder={t('settings.customerSignup.domains.add', 'Add a domain…')}
                    emptyChip={t('settings.customerSignup.domains.any', 'Any domain')}
                    removeLabel={(domain) => t('settings.customerSignup.domains.remove', 'Remove {domain}', { domain })}
                  />
                  <p className="text-xs text-muted-foreground">
                    {t('settings.customerSignup.domains.chipsHint', 'Like example-bakery.com. Leave empty to accept any email address.')}
                  </p>
                </ChangedField>
              </div>

              {joinLink && (
                <div
                  className="flex flex-wrap items-center gap-3 rounded-lg bg-muted px-3 py-2"
                  data-testid="customer-signup-link"
                >
                  <span className="text-sm text-muted-foreground">{t('settings.customerSignup.linkLabel', 'Link')}</span>
                  <code dir="ltr" className="min-w-0 flex-1 truncate font-mono text-sm text-foreground">
                    {joinLink.replace(/^https?:\/\//, '')}
                  </code>
                  <Button variant="outline" size="sm" className="gap-1" onClick={() => void copyLink(joinLink)}>
                    <Icon name="copy" size={16} />
                    {t('settings.customerSignup.copy', 'Copy')}
                  </Button>
                </div>
              )}

              {saveError && (
                <Alert variant="destructive">
                  <AlertDescription>{saveError}</AlertDescription>
                </Alert>
              )}
            </CardContent>
          </Card>

          <Card className="border border-border bg-card shadow-sm" data-testid="customer-signup-team-invites">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1 space-y-1.5">
                  <CardTitle>
                    <label htmlFor="signup-customer-invites">{t('settings.customerSignup.teamInvites', 'Team invites')}</label>
                  </CardTitle>
                  <CardDescription>
                    {t(
                      'settings.customerSignup.teamInvites.hint',
                      'A customer company can invite its own people, so you don’t have to. Invitations go by email, so this needs email set up for this instance.'
                    )}
                  </CardDescription>
                </div>
                <ChangedField changed={current.customerInvites !== saved?.customerInvites}>
                  <div className="flex items-center gap-2">
                    <Switch
                      id="signup-customer-invites"
                      checked={current.customerInvites}
                      onCheckedChange={(checked) => update({ customerInvites: checked })}
                    />
                    <span className="text-sm text-foreground" aria-hidden="true">
                      {current.customerInvites
                        ? t('settings.customerSignup.on', 'On')
                        : t('settings.customerSignup.off', 'Off')}
                    </span>
                  </div>
                </ChangedField>
              </div>
            </CardHeader>
            {current.customerInvites && (
              <CardContent>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <p className="text-sm font-medium text-foreground">
                      {t('settings.customerSignup.invitedStartAs', 'Invited people start as')}
                    </p>
                    <p className="text-sm text-foreground">
                      {t('settings.customerSignup.invitedRole', 'The same role as the customer who invited them')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {t(
                        'settings.customerSignup.invitedRole.hint',
                        'They join as customers, never as staff, without waiting for approval, even when sign-up is off.'
                      )}
                    </p>
                  </div>
                  <ChangedField changed={current.perCustomer.trim() !== saved?.perCustomer}>
                    <label htmlFor="signup-per-customer" className="text-sm font-medium text-foreground">
                      {t('settings.customerSignup.perCustomer', 'People per customer')}
                    </label>
                    <Input
                      id="signup-per-customer"
                      inputMode="numeric"
                      dir="ltr"
                      value={current.perCustomer}
                      placeholder={t('settings.customerSignup.perCustomer.none', 'No limit')}
                      onChange={(event) => update({ perCustomer: event.target.value })}
                    />
                    <p className="text-xs text-muted-foreground">
                      {t(
                        'settings.customerSignup.perCustomer.hint',
                        'Each customer can invite up to this many. Leave it empty for no limit. Withdrawn invitations free a place.'
                      )}
                    </p>
                  </ChangedField>
                </div>
              </CardContent>
            )}
          </Card>

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
  const [busy, setBusy] = useState<number | 'all' | null>(null);
  const [status, setStatus] = useState<QueueStatus>('pending');
  const [rejecting, setRejecting] = useState<SignupRequest | null>(null);

  const { data, error, refetch } = useFetch<SignupRequest[]>(async () => {
    const { data, response: res, error } = await api.GET('/api/v1/signup/requests', {
      params: { query: { status, per_page: 100 } },
    });
    if (!res.ok) {
      throw new Error(readError(error, t('settings.customerSignup.queue.loadFailed', 'The requests could not be loaded'), t));
    }
    return (data as unknown as { data: SignupRequest[] }).data;
  }, [pendingCount, status]);

  const rows = useMemo(() => data ?? [], [data]);
  const ageLabels: RelativeAgeLabels = {
    seconds: () => t('settings.customerSignup.ago.now', 'Just now'),
    minutes: (n) => t('settings.customerSignup.ago.minutes', '{n} minutes ago', { n }),
    hours: (n) => t('settings.customerSignup.ago.hours', '{n} hours ago', { n }),
    days: (n) => t('settings.customerSignup.ago.days', '{n} days ago', { n }),
  };

  /** One request, decided; true when the server took it. */
  const send = async (request: SignupRequest, approve: boolean): Promise<boolean> => {
    const options = { params: { path: { id: request.id } } };
    const { response: res, error } = approve
      ? await api.POST('/api/v1/signup/requests/{id}/approve', options)
      : await api.POST('/api/v1/signup/requests/{id}/reject', options);
    if (!res.ok) {
      addToast(readError(error, t('settings.customerSignup.queue.failed', 'The request could not be updated'), t), 'error');
      return false;
    }
    return true;
  };

  const decide = async (request: SignupRequest, approve: boolean) => {
    setBusy(request.id);
    const ok = await send(request, approve);
    setBusy(null);
    if (!ok) return;
    addToast(
      approve
        ? t('settings.customerSignup.queue.approved', '{name} can now sign in', { name: request.display_name })
        : t('settings.customerSignup.queue.rejected', '{name} was turned away', { name: request.display_name }),
      'success'
    );
    refetch();
    onDecided();
  };

  // Oldest first, one at a time, stopping at the first the server refuses.
  const approveAll = async () => {
    setBusy('all');
    let approved = 0;
    for (const request of rows) {
      if (!(await send(request, true))) break;
      approved += 1;
    }
    setBusy(null);
    if (approved > 0) {
      addToast(t('settings.customerSignup.queue.approvedAll', '{count} people can now sign in', { count: String(approved) }), 'success');
    }
    refetch();
    onDecided();
  };

  const waiting = status === 'pending' && rows.length > 0;

  return (
    <Card className="border border-border bg-card shadow-sm" data-testid="customer-signup-queue">
      <CardHeader>
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1 space-y-1.5">
            <CardTitle>
              {waiting
                ? t('settings.customerSignup.queue.titleCount', 'Waiting to join · {count}', { count: String(rows.length) })
                : t('settings.customerSignup.queue', 'Waiting to join')}
            </CardTitle>
            <CardDescription>
              {status === 'pending'
                ? t('settings.customerSignup.queue.oldestFirst', 'People who confirmed their email. Oldest first.')
                : t(
                    'settings.customerSignup.queue.rejectedHint',
                    'People you turned away. You can still let any of them in.'
                  )}
            </CardDescription>
          </div>
          {waiting && rows.length > 1 && (
            <Button
              variant="outline"
              size="sm"
              disabled={busy !== null}
              loading={busy === 'all'}
              data-testid="customer-signup-approve-all"
              onClick={() => void approveAll()}
            >
              {t('settings.customerSignup.queue.approveAll', 'Approve all {count}', { count: String(rows.length) })}
            </Button>
          )}
        </div>
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
          <EmptyState
            data-testid="customer-signup-queue-empty"
            icon={<Icon name="users" size={24} />}
            title={
              status === 'pending'
                ? t('settings.customerSignup.queue.emptyTitle', 'Nobody is waiting')
                : t('settings.customerSignup.queue.emptyRejected', 'Nobody has been turned away.')
            }
            description={
              status === 'pending'
                ? t('settings.customerSignup.queue.emptyHint', 'People who ask to join appear here.')
                : undefined
            }
          />
        ) : (
          <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
            {rows.map((request) => (
              <li
                key={request.id}
                className="flex min-h-12 flex-col gap-3 px-4 py-3 md:flex-row md:items-center"
                data-testid={`customer-signup-request-${request.id}`}
              >
                <div className="flex min-w-0 flex-1 items-center gap-3">
                  <Avatar name={request.display_name} pattern={request.email} size={36} />
                  <div className="min-w-0">
                    <p className="truncate text-sm text-foreground">{request.display_name}</p>
                    <p className="truncate text-xs text-muted-foreground" dir="ltr">
                      {request.email}
                    </p>
                  </div>
                </div>
                <p className="text-xs text-muted-foreground md:w-56">
                  {status === 'pending' &&
                    (dates.relative(request.verified_at ?? request.requested_at, ageLabels) ?? '')}
                  {status === 'rejected' && dates.dateTime(request.decided_at) !== null &&
                    (request.decided_by_name
                      ? t('settings.customerSignup.queue.rejectedBy', 'Turned away by {name} on {when}', {
                          name: request.decided_by_name,
                          when: dates.dateTime(request.decided_at) ?? '',
                        })
                      : t('settings.customerSignup.queue.rejectedOn', 'Turned away on {when}', {
                          when: dates.dateTime(request.decided_at) ?? '',
                        }))}
                </p>
                <div className="flex shrink-0 items-center gap-2">
                  {status === 'pending' && (
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={busy !== null}
                      data-testid={`customer-signup-reject-${request.id}`}
                      onClick={() => setRejecting(request)}
                    >
                      {t('settings.customerSignup.queue.reject', 'Reject')}
                    </Button>
                  )}
                  <Button
                    size="sm"
                    className="gap-1"
                    disabled={busy !== null}
                    loading={busy === request.id}
                    data-testid={`customer-signup-approve-${request.id}`}
                    onClick={() => void decide(request, true)}
                  >
                    <Icon name="check" size={16} />
                    {t('settings.customerSignup.queue.approve', 'Approve')}
                  </Button>
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
