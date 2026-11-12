'use client';

import { useCallback, useEffect, useState } from 'react';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { ApprovalGatingTabs } from '@/components/admin/approval-gating-tabs';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { AccessDenied } from '@/components/ui/access-denied';
import { Pagination } from '@whity/ui/pagination';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { useDateDisplay } from '@whity/features/datetime';
import { IconCheck, IconInbox, IconX } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';

/**
 * Pending self-service password-reset requests
 * (WC-password-reset-2fa-recovery) — the "Password reset" tab of the unified
 * Approval Gating admin surface. Appears only when
 * `auth.password_reset_approval_required` is on for the operator/tenant;
 * otherwise a self-service reset always applies immediately and this queue
 * stays empty.
 *
 * UNLIKE the Signup tab (system-tenant only — see ../../registrations/page.tsx),
 * this is scoped to the CALLER'S OWN tenant: the requesting user's account and
 * its tenant admin already exist, so any tenant's admin reviews its own
 * tenant's queue. Gated on password_resets:approve only (no system-tenant
 * check), mirroring the backend (PasswordResetApprovalsApiHandler).
 *
 * Mirrors the Signup tab's structure/conventions (fetch via raw `fetch` + the
 * CSRF header, not the generated OpenAPI client, matching that page's existing
 * precedent for this admin-queue shape).
 */
const PASSWORD_RESETS_APPROVE = 'password_resets:approve';

const PER_PAGE = 25;

interface PendingPasswordReset {
  id: number;
  profile_id: number;
  email: string;
  display_name: string;
  /** Why they asked, in their own words. Optional, so often null. */
  reason: string | null;
  created_at: string;
}

export default function PasswordResetApprovalsPage() {
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canApprove = hasPermission(PASSWORD_RESETS_APPROVE);

  const [items, setItems] = useState<PendingPasswordReset[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [busyId, setBusyId] = useState<number | null>(null);
  // Approving is security-sensitive: type the email to confirm (tier 3).
  const [approving, setApproving] = useState<typeof items[number] | null>(null);
  const dates = useDateDisplay();

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await fetch(`/api/v1/password-resets/pending?page=${page}&per_page=${PER_PAGE}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        setLoadError(true);
        setItems([]);
        return;
      }
      const body = await res.json().catch(() => ({}));
      const rows = Array.isArray(body?.data) ? (body.data as PendingPasswordReset[]) : [];
      setItems(rows);
      setTotal(typeof body?.pagination?.total === 'number' ? body.pagination.total : rows.length);
    } catch {
      setLoadError(true);
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    if (!isCapabilitiesLoading && canApprove) {
      void Promise.resolve().then(load);
    }
  }, [isCapabilitiesLoading, canApprove, load]);

  const act = async (item: PendingPasswordReset, action: 'approve' | 'reject') => {
    setBusyId(item.id);
    try {
      const res = await fetch(`/api/v1/password-resets/${item.id}/${action}`, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'include',
      });
      if (!res.ok) {
        addToast(
          action === 'approve'
            ? t(
                'passwordResets.approve.failedStatus',
                'Failed to approve the request for {email} ({status}).',
                { email: item.email, status: res.status }
              )
            : t(
                'passwordResets.reject.failedStatus',
                'Failed to reject the request for {email} ({status}).',
                { email: item.email, status: res.status }
              ),
          'error'
        );
        void load();
        return;
      }
      addToast(
        action === 'approve'
          ? t('passwordResets.approve.success', 'Approved the password reset for {email}.', {
              email: item.email,
            })
          : t('passwordResets.reject.success', 'Rejected the password reset for {email}.', {
              email: item.email,
            }),
        'success'
      );
      setItems((prev) => prev.filter((i) => i.id !== item.id));
    } catch {
      addToast(
        action === 'approve'
          ? t('passwordResets.approve.failed', 'Failed to approve the request for {email}.', {
              email: item.email,
            })
          : t('passwordResets.reject.failed', 'Failed to reject the request for {email}.', {
              email: item.email,
            }),
        'error'
      );
    } finally {
      setBusyId(null);
    }
  };

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-100">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canApprove) {
    return (
      <div className="space-y-6 max-w-4xl mx-auto px-4 md:px-0 pb-16">
        <ApprovalGatingTabs active="password-resets" />
        <AccessDenied
          data-testid="password-resets-access-denied"
          description={t(
            'passwordResets.accessDenied',
            "You don't have permission to review pending password-reset requests."
          )}
          action={
            <Button onClick={() => window.history.back()} variant="outline">
              {t('passwordResets.goBack', 'Go Back')}
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <ApprovalGatingTabs active="password-resets" />
      <AdminHeader
        title={t('passwordResets.title', 'Password Reset Approvals')}
        description={t(
          'passwordResets.description',
          'Review self-service password-reset requests staged for approval before the new ' +
            'password takes effect.'
        )}
      />

      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-semibold font-heading">
            <h2>{t('passwordResets.list.title', 'Awaiting approval')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'passwordResets.list.description',
              'Approving applies the requester’s new password immediately and signs them out of ' +
                'every existing session. Rejecting discards the staged password; the account ' +
                'keeps its current one.'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <LoadingRows
              label={t('passwordResets.loading', 'Loading requests')}
              headers={[t('passwordResets.column.person', 'Person'), t('passwordResets.column.asked', 'Asked')]}
              rows={3}
            />
          ) : loadError ? (
            <div data-testid="password-resets-load-error">
              <AreaError
                message={t('passwordResets.loadError', 'Failed to load the requests.')}
                retryLabel={t('passwordResets.retry', 'Try again')}
                onRetry={() => void load()}
              />
            </div>
          ) : items.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground"
              data-testid="password-resets-empty"
            >
              <IconInbox size={40} className="mb-3 opacity-60" />
              <p className="text-sm">
                {t('passwordResets.empty', 'No pending password-reset requests.')}
              </p>
            </div>
          ) : (
            <ul className="divide-y divide-border" data-testid="password-resets-list">
              {items.map((item) => (
                <li
                  key={item.id}
                  className="flex flex-col gap-3 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                  data-testid={`password-reset-row-${item.id}`}
                >
                  <div className="min-w-0">
                    <p className="font-medium text-foreground truncate">{item.email}</p>
                    <p className="text-sm text-foreground" data-testid={`passwordResets-reason-${item.id}`}>
                      {item.reason ?? t('passwordResets.reason.default', 'Asked to reset their password')}
                    </p>
                    <p className="text-sm text-muted-foreground truncate">
                      {[
                        item.display_name,
                        dates.dateTime(item.created_at) !== null
                          ? t('passwordResets.asked', 'Asked on {date}', { date: dates.dateTime(item.created_at) ?? '' })
                          : null,
                      ]
                        .filter((part) => part)
                        .join(' · ')}
                    </p>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      type="button"
                      size="sm"
                      disabled={busyId === item.id}
                      onClick={() => setApproving(item)}
                      className="gap-1"
                      data-testid={`password-reset-approve-${item.id}`}
                    >
                      <IconCheck className="w-4 h-4" />
                      {t('passwordResets.action.approve', 'Approve…')}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busyId === item.id}
                      onClick={() => void act(item, 'reject')}
                      className="gap-1"
                      data-testid={`password-reset-reject-${item.id}`}
                    >
                      <IconX className="w-4 h-4" />
                      {t('passwordResets.action.reject', 'Reject')}
                    </Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
          {!loading && !loadError && total > PER_PAGE && (
            <Pagination className="mt-4" page={page} perPage={PER_PAGE} total={total} onPageChange={setPage} />
          )}
        </CardContent>
      </Card>
      <ConfirmAction
        open={approving !== null}
        onOpenChange={(open) => {
          if (!open) setApproving(null);
        }}
        title={t('passwordResets.approve.title', 'Approve the password reset for {email}?', { email: approving?.email ?? '' })}
        description={t(
          'passwordResets.approve.description',
          'Their new password takes effect now and they are signed out everywhere. Check that the request really came from them.'
        )}
        confirmLabel={t('passwordResets.approve.confirm', 'Approve request')}
        cancelLabel={t('passwordResets.approve.cancel', 'Cancel')}
        typeToConfirm={{
          value: approving?.email ?? '',
          label: t('passwordResets.approve.typeLabel', 'Type their email to confirm'),
        }}
        variant="default"
        onConfirm={async () => {
          if (approving !== null) await act(approving, 'approve');
        }}
      />
    </div>
  );
}
