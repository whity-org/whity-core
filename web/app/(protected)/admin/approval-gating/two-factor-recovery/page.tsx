'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { ApprovalGatingTabs } from '@/components/admin/approval-gating-tabs';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Badge } from '@whity/ui/badge';
import { Avatar } from '@whity/ui/avatar';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { AccessDenied } from '@/components/ui/access-denied';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { OtpInput } from '@whity/ui/otp-input';
import { EmptyState } from '@whity/ui/empty-state';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { cn } from '@whity/ui/utils';

/**
 * Two-step resets (AG.1, `TplGatingTwoStep`): the "Two-step resets" tab of
 * Approval gating.
 *
 * Clearing someone's two-step needs TWO admins. Each one confirms it's them
 * first (their authenticator code, or a code emailed to them; the platform has
 * no passkeys yet). The first approval is recorded and changes nothing; the
 * second, by a different admin, clears the two-step and emails a reset link.
 * The person asking can never approve their own. The server enforces all of
 * it; this screen says it in plain words and shows who has approved.
 *
 * Layout: the requests on the start side; the chosen one on the end side, with
 * what approving does, where the request came from, the checks admins made,
 * the two approval slots and the one warning that there is no Undo.
 */
const TWO_FACTOR_RECOVERY_APPROVE = 'two_factor_recovery:approve';

interface Check {
  text: string;
  by_profile_id: number;
  by_name: string;
  at: string;
}

interface PendingTwoFactorRecovery {
  id: number;
  profile_id: number;
  email: string;
  display_name: string;
  reason: string | null;
  created_at: string;
  requested_from: string | null;
  last_signed_in_at: string | null;
  checks: Check[];
  first_approval: { profile_id: number; display_name: string; at: string; method: string } | null;
}

interface IdentityCheck {
  method: 'totp' | 'email_code';
  email_hint: string | null;
}

const JSON_HEADERS = { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' };

export default function TwoFactorRecoveryApprovalsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canApprove = hasPermission(TWO_FACTOR_RECOVERY_APPROVE);

  const [items, setItems] = useState<PendingTwoFactorRecovery[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [checkText, setCheckText] = useState('');
  // The "confirm it's you" step before an approval.
  const [confirming, setConfirming] = useState<IdentityCheck | null>(null);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await fetch('/api/v1/2fa-recovery/pending?page=1&per_page=100', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        setLoadError(true);
        setItems([]);
        return;
      }
      const body = await res.json().catch(() => ({}));
      const rows = Array.isArray(body?.data) ? (body.data as PendingTwoFactorRecovery[]) : [];
      setItems(rows);
      setSelectedId((current) => (current !== null && rows.some((r) => r.id === current) ? current : rows[0]?.id ?? null));
    } catch {
      setLoadError(true);
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!isCapabilitiesLoading && canApprove) {
      void Promise.resolve().then(load);
    }
  }, [isCapabilitiesLoading, canApprove, load]);

  const selected = useMemo(() => items.find((i) => i.id === selectedId) ?? null, [items, selectedId]);
  const me = user?.id ?? null;
  const isRequester = selected !== null && selected.profile_id === me;
  const gaveFirst = selected?.first_approval?.profile_id === me && me !== null;

  const post = async (path: string, body: unknown) =>
    fetch(path, { method: 'POST', headers: JSON_HEADERS, credentials: 'include', body: JSON.stringify(body) });

  /** Step 1: find out the check, and have the code emailed when that is the check. */
  const startApproval = async () => {
    if (selected === null) return;
    setBusy(true);
    try {
      const res = await post('/api/v1/2fa-recovery/identity-check', { request_id: selected.id });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        addToast(body?.error ?? t('twoStep.identity.failed', 'We could not start the check. Try again in a few minutes.'), 'error');
        return;
      }
      setCode('');
      setCodeError(null);
      setConfirming(body.data as IdentityCheck);
    } finally {
      setBusy(false);
    }
  };

  /** Step 2: approve with the code. */
  const approve = async (value: string) => {
    if (selected === null) return;
    setBusy(true);
    try {
      const res = await post(`/api/v1/2fa-recovery/${selected.id}/approve`, { code: value });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (body?.reason === 'identity_check_failed') {
          setCodeError(t('twoStep.identity.wrong', 'That code is not right or has expired.'));
          return;
        }
        setConfirming(null);
        addToast(body?.error ?? t('twoStep.approve.failed', 'The approval could not be saved.'), 'error', 8000);
        void load();
        return;
      }
      setConfirming(null);
      addToast(
        body?.data?.status === 'approved'
          ? t('twoStep.approve.cleared', "{name}'s two-step is cleared and a reset link is on its way.", { name: selected.display_name })
          : t('twoStep.approve.first', 'Approved. It now needs a second admin.'),
        'success',
      );
      void load();
    } finally {
      setBusy(false);
    }
  };

  const reject = async () => {
    if (selected === null) return;
    setBusy(true);
    try {
      const res = await post(`/api/v1/2fa-recovery/${selected.id}/reject`, {});
      if (!res.ok) {
        addToast(t('twoStep.reject.failed', 'The request could not be rejected.'), 'error');
      } else {
        addToast(t('twoStep.reject.done', 'Rejected. Their account is unchanged.'), 'success');
      }
      void load();
    } finally {
      setBusy(false);
    }
  };

  const addCheck = async () => {
    if (selected === null || checkText.trim() === '') return;
    setBusy(true);
    try {
      const res = await post(`/api/v1/2fa-recovery/${selected.id}/checks`, { text: checkText.trim() });
      if (!res.ok) {
        addToast(t('twoStep.check.failed', 'The check could not be saved.'), 'error');
        return;
      }
      setCheckText('');
      void load();
    } finally {
      setBusy(false);
    }
  };

  if (isCapabilitiesLoading) {
    return <LoadingRows label={t('twoStep.loading', 'Loading requests')} headers={[t('twoStep.person', 'Person')]} rows={2} />;
  }

  if (!canApprove) {
    return (
      <div className="space-y-6">
        <ApprovalGatingTabs active="two-factor-recovery" />
        <AccessDenied
          data-testid="two-factor-recovery-access-denied"
          description={t('twoFactorRecovery.accessDenied', "You don't have permission to review pending 2FA-recovery requests.")}
          action={
            <Button onClick={() => window.history.back()} variant="outline">
              {t('twoFactorRecovery.goBack', 'Go Back')}
            </Button>
          }
        />
      </div>
    );
  }

  const time = (value: string | null) => (value ? (dates.dateTime(value) ?? '') : '');

  return (
    <div className="space-y-6 pb-16">
      <AdminHeader
        title={t('approvalGating.title', 'Approval gating')}
        description={t('approvalGating.description', 'Sign-ups, password resets and two-step resets that need an admin')}
      />
      <ApprovalGatingTabs active="two-factor-recovery" />

      {loading && items.length === 0 ? (
        <LoadingRows label={t('twoStep.loading', 'Loading requests')} headers={[t('twoStep.person', 'Person')]} rows={2} />
      ) : loadError ? (
        <div data-testid="two-factor-recovery-load-error">
          <AreaError
            message={t('twoFactorRecovery.loadError', 'Failed to load the requests.')}
            retryLabel={t('twoFactorRecovery.retry', 'Try again')}
            onRetry={() => void load()}
          />
        </div>
      ) : items.length === 0 ? (
        <div data-testid="two-factor-recovery-empty">
          <EmptyState
            title={t('twoStep.empty.title', 'No two-step resets waiting')}
            description={t('twoStep.empty.description', 'When someone loses their phone and codes, their request comes here for two admins.')}
          />
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
          <ul className="grid h-fit gap-2" data-testid="two-factor-recovery-list" aria-label={t('twoStep.list', 'Two-step resets')}>
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => setSelectedId(item.id)}
                  aria-current={item.id === selectedId}
                  data-testid={`two-factor-recovery-row-${item.id}`}
                  className={cn(
                    'flex w-full items-center gap-3 rounded-control-lg border border-border bg-card p-3 text-start',
                    item.id === selectedId && 'border-primary/40 bg-info-tint',
                  )}
                >
                  <Avatar name={item.display_name || item.email} size={32} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate">{item.display_name || item.email}</span>
                    <span className="block truncate text-sm text-muted-foreground" data-testid={`twoFactorRecovery-reason-${item.id}`}>
                      {[item.reason ?? t('twoFactorRecovery.reason.default', 'Lost access to their second step'), dates.time(item.created_at)]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  </span>
                  <Icon name="chevron-right" size={16} className="rtl:rotate-180" aria-hidden />
                </button>
              </li>
            ))}
          </ul>

          {selected !== null && (
            <Card className="gap-0 p-0" data-testid={`two-factor-recovery-detail-${selected.id}`}>
              <section className="space-y-2 p-5">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="destructive">
                    <Icon name="lock" size={12} aria-hidden />
                    {t('approvals.kind.verify', 'Needs verifying')}
                  </Badge>
                  <span className="text-sm text-muted-foreground">
                    {t('twoStep.asked', 'Asked {when}', { when: time(selected.created_at) })}
                  </span>
                </div>
                <h2 className="text-lg font-semibold">
                  {t('twoStep.title', "Clear {name}'s two-step sign-in", { name: selected.display_name || selected.email })}
                </h2>
                <p className="text-muted-foreground">
                  {t(
                    'twoStep.what',
                    'They lost their phone and their recovery codes. Approving lets their password alone sign them in, until they set two-step up again.',
                  )}
                </p>
              </section>

              <section className="border-t border-border p-5">
                <h3 className="mb-2 text-sm font-semibold">{t('twoStep.request', 'Request')}</h3>
                <dl className="divide-y divide-border">
                  {[
                    [t('twoStep.from', 'From'), selected.requested_from === 'admin'
                      ? t('twoStep.fromAdmin', 'Started by an admin')
                      : (selected.requested_from ?? t('twoStep.unknown', 'Not known'))],
                    [t('twoStep.account', 'Their account'), selected.email],
                    [t('twoStep.lastSignedIn', 'Last signed in'), selected.last_signed_in_at ? time(selected.last_signed_in_at) : t('twoStep.never', 'Not yet in this workspace')],
                  ].map(([term, value]) => (
                    <div key={term} className="grid grid-cols-[minmax(0,200px)_minmax(0,1fr)] gap-4 py-2.5">
                      <dt className="text-muted-foreground">{term}</dt>
                      <dd className="min-w-0 break-words">{value}</dd>
                    </div>
                  ))}
                </dl>
              </section>

              <section className="border-t border-border p-5">
                <h3 className="mb-2 text-sm font-semibold">{t('twoStep.checks', 'Checks')}</h3>
                {(selected.checks ?? []).length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    {t('twoStep.checks.none', 'No checks yet. Say what you did to make sure it is really them.')}
                  </p>
                ) : (
                  <ul className="grid gap-3">
                    {(selected.checks ?? []).map((check, index) => (
                      <li key={index} className="flex gap-3">
                        <Icon name="success" size={16} className="mt-1 text-success-text" aria-hidden />
                        <div>
                          <div>{check.text}</div>
                          <div className="text-sm text-muted-foreground">{[check.by_name, time(check.at)].filter(Boolean).join(', ')}</div>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
                <form
                  className="mt-3 flex gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    void addCheck();
                  }}
                >
                  <Input
                    value={checkText}
                    maxLength={300}
                    onChange={(e) => setCheckText(e.target.value)}
                    placeholder={t('twoStep.check.placeholder', 'Called them on the number on file')}
                    aria-label={t('twoStep.check.label', 'A check you made')}
                  />
                  <Button type="submit" variant="outline" disabled={busy || checkText.trim() === ''}>
                    {t('twoStep.check.add', 'Add check')}
                  </Button>
                </form>
              </section>

              <section className="space-y-4 border-t border-border p-5">
                <h3 className="text-sm font-semibold">{t('twoStep.twoAdmins', 'Two admins approve')}</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                  {selected.first_approval ? (
                    <ApproverSlot
                      done
                      name={selected.first_approval.display_name}
                      line={t('twoStep.slot.approved', 'Approved {when} with {how}', {
                        when: time(selected.first_approval.at),
                        how: selected.first_approval.method === 'totp' ? t('twoStep.how.totp', 'their authenticator') : t('twoStep.how.email', 'an emailed code'),
                      })}
                    />
                  ) : (
                    <ApproverSlot name={t('twoStep.slot.first', 'First admin')} line={t('twoStep.slot.waiting', 'Waiting')} />
                  )}
                  <ApproverSlot
                    avatar={gaveFirst ? undefined : (user?.display_name ?? user?.email)}
                    name={gaveFirst ? t('twoStep.slot.another', 'Another admin') : t('twoStep.slot.you', 'You')}
                    line={
                      isRequester
                        ? t('twoStep.slot.ownRequest', 'This is your own request, so another admin decides')
                        : gaveFirst
                          ? t('twoStep.slot.needsSomeoneElse', 'A different admin has to give the second approval')
                          : t('twoStep.slot.waitingForYou', 'Waiting for you')
                    }
                  />
                </div>
                <Alert variant="warning">
                  <Icon name="alert" size={16} aria-hidden />
                  <AlertTitle>{t('twoStep.noUndo.title', "There's no Undo for this")}</AlertTitle>
                  <AlertDescription>
                    {t(
                      'twoStep.noUndo.description',
                      "If it wasn't really {name}, someone could sign in with their password. They'll be asked to set up two-step at their next sign-in.",
                      { name: selected.display_name || selected.email },
                    )}
                  </AlertDescription>
                </Alert>
                <div className="flex flex-wrap justify-end gap-2">
                  <Button variant="outline" disabled={busy} onClick={() => void reject()} data-testid={`two-factor-recovery-reject-${selected.id}`}>
                    {t('twoFactorRecovery.action.reject', 'Reject')}
                  </Button>
                  <Button
                    disabled={busy || isRequester || gaveFirst}
                    onClick={() => void startApproval()}
                    data-testid={`two-factor-recovery-approve-${selected.id}`}
                  >
                    <Icon name="two-step" size={16} aria-hidden />
                    {t('twoStep.approve', 'Confirm it’s you and approve')}
                  </Button>
                </div>
              </section>
            </Card>
          )}
        </div>
      )}

      <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('twoStep.identity.title', 'Confirm it’s you')}</DialogTitle>
            <DialogDescription>
              {confirming?.method === 'totp'
                ? t('twoStep.identity.totp', 'Enter the code from your authenticator app.')
                : t('twoStep.identity.email', 'We sent a code to {email}. Enter it here.', { email: confirming?.email_hint ?? '' })}
            </DialogDescription>
          </DialogHeader>
          <OtpInput
            label={t('twoStep.identity.code', 'Code')}
            value={code}
            onChange={(value) => {
              setCode(value);
              setCodeError(null);
            }}
            onComplete={(value) => void approve(value)}
            errorText={codeError ?? undefined}
            disabled={busy}
            autoFocus
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirming(null)}>
              {t('twoStep.identity.cancel', 'Cancel')}
            </Button>
            <Button disabled={busy || code.length < 6} onClick={() => void approve(code)}>
              {t('twoStep.identity.approve', 'Approve')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function ApproverSlot({ name, line, done = false, avatar }: { name: string; line: string; done?: boolean; avatar?: string }) {
  return (
    <div
      className={cn(
        'rounded-control-lg border p-3',
        done ? 'border-success-text/40 bg-success-tint' : 'border-dashed border-border',
      )}
    >
      <div className="flex items-center gap-2">
        <Avatar name={avatar ?? name} size={28} />
        <span>{name}</span>
      </div>
      <div className="mt-2 text-sm">{line}</div>
    </div>
  );
}
