'use client';

/**
 * Settings › Owners (WHIT-1170, WHIT-1171): who owns this workspace, the offers
 * waiting for an answer, and for owners: hand ownership over or add a
 * co-owner.
 *
 * Owners are equal (owner, 2026-11-16). Only an owner pays and changes the
 * plan, and decides who else owns the workspace. Handing over and adding a
 * co-owner are offers the other person accepts within 7 days; both confirm
 * it's them. Confirm by how undoable it is (section 21): every change here goes
 * through "Confirm it's you".
 *
 * No card yet (brand ask: brand-ask-workspace-owners.md §1); built from the
 * settings rows of 2.9, TmPeople's rows and ConfirmDialog.
 */

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { ForbiddenError } from '@/lib/forbidden';
import { useAuth } from '@/lib/auth-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import {
  ConfirmItsYouDialog,
  isIdentityCheckFailure,
  startIdentityCheck,
  type IdentityCheck,
} from '@/components/security/confirm-its-you-dialog';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { Skeleton } from '@whity/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@whity/ui/dialog';
import { useTranslation } from '@whity/features/i18n';
import { useRefusalText } from '@/lib/ownership-words';
import { useDateDisplay } from '@whity/features/datetime';

type Kind = 'transfer' | 'co_owner';

interface Owner {
  profile_id: number;
  name: string;
  email: string;
  since: string;
}

interface Offer {
  id: number;
  kind: Kind;
  from: { profile_id: number; name: string };
  to: { profile_id: number; name: string };
  leave_after: boolean;
  status: string;
  expires_at: string;
}

interface OwnersData {
  owners: Owner[];
  offers: Offer[];
  candidates: { profile_id: number; name: string; email: string }[];
  is_owner: boolean;
}

/** The offer waiting for "Confirm it's you". */
interface Pending {
  kind: Kind;
  to: number;
  leaveAfter: boolean;
  check: IdentityCheck;
}

export default function OwnersSettingsPage() {
  const t = useTranslation('admin');
  const refusalText = useRefusalText();
  const { addToast } = useToast();
  const { user } = useAuth();
  const dates = useDateDisplay();
  const formatDate = (value: string): string => dates.date(value) ?? '';
  const [version, setVersion] = useState(0);
  const [dialog, setDialog] = useState<Kind | null>(null);
  const [chosen, setChosen] = useState('');
  const [leaveAfter, setLeaveAfter] = useState(false);
  const [pending, setPending] = useState<Pending | null>(null);
  const [busy, setBusy] = useState(false);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, error, forbidden } = useFetch<OwnersData>(async () => {
    const { data: body, response } = await api.GET('/api/v1/workspace/owners', { cache: 'no-store' });
    if (response.status === 403) throw new ForbiddenError();
    if (!response.ok) throw new Error('owners');
    return (body as { data: OwnersData }).data;
  }, [version]);

  const reload = () => setVersion((v) => v + 1);
  const failed = t('owners.error.generic', 'That didn’t work. Nothing changed. Try again in a minute.');

  const openDialog = (kind: Kind) => {
    setChosen('');
    setLeaveAfter(false);
    setProblem(null);
    setDialog(kind);
  };

  /** Step 1: pick the person, then "Confirm it's you". */
  const startOffer = async () => {
    if (dialog === null) return;
    if (chosen === '') {
      setProblem(t('owners.offer.choose', 'Choose a person.'));
      return;
    }
    setBusy(true);
    setProblem(null);
    const to = Number(chosen);
    const started = await startIdentityCheck(() =>
      api.POST('/api/v1/workspace/owners/offers/identity-check', { body: { to_profile_id: to, kind: dialog } }),
    );
    setBusy(false);
    if ('error' in started) {
      setProblem(started.error ?? failed);
      return;
    }
    setPending({ kind: dialog, to, leaveAfter: dialog === 'transfer' && leaveAfter, check: started.check });
    setDialog(null);
  };

  /** Step 2: the code, then the change itself. */
  const confirm = async (code: string) => {
    if (pending === null) return;
    setBusy(true);
    setCodeError(null);
    try {
      const { error: err, response } = await api.POST('/api/v1/workspace/owners/offers', {
        body: { to_profile_id: pending.to, kind: pending.kind, leave_after: pending.leaveAfter, code },
      });
      if (!response.ok && isIdentityCheckFailure(err)) {
        setCodeError(t('twoStep.identity.wrong', 'That code is not right or has expired.'));
        return;
      }
      setPending(null);
      if (!response.ok) {
        const body = (err ?? {}) as { details?: { code?: string }; error?: string };
        addToast(refusalText(body.details?.code, body.error ?? failed), 'error');
        return;
      }
      addToast(t('owners.offer.sent', 'Offer sent. They have 7 days to accept.'), 'success');
      reload();
    } catch {
      addToast(failed, 'error');
    } finally {
      setBusy(false);
    }
  };

  const withdraw = async (offer: Offer) => {
    const { error: err, response } = await api.DELETE('/api/v1/workspace/owners/offers/{id}', {
      params: { path: { id: offer.id } },
    });
    if (!response.ok) {
      const body = (err ?? {}) as { details?: { code?: string }; error?: string };
      addToast(refusalText(body.details?.code, failed), 'error');
    } else {
      addToast(t('owners.offer.withdrawn', 'Offer withdrawn.'), 'success');
    }
    reload();
  };

  const header = (
    <AdminHeader
      title={t('owners.title', 'Owners')}
      description={t(
        'owners.description',
        'Owners look after the plan and payments, and decide who else owns this workspace. Every owner has the same say.',
      )}
    />
  );

  if (error) {
    return (
      <div className="space-y-6">
        {header}
        <Alert>
          <AlertDescription>
            {forbidden
              ? t('owners.forbidden', 'Only people in this workspace can see who owns it.')
              : t('owners.loadError', 'The owners could not be loaded. Try again in a minute.')}
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  if (data === null) {
    return (
      <div className="space-y-6">
        {header}
        <Skeleton className="h-40 w-full rounded-card" />
      </div>
    );
  }

  const isOwner = data.is_owner;

  return (
    <div className="space-y-6">
      {header}

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle>{t('owners.list.title', 'Who owns this workspace')}</CardTitle>
            <CardDescription>
              {isOwner
                ? t('owners.list.ownerHint', 'Hand it over to someone else, or share it with a co-owner.')
                : t('owners.list.memberHint', 'Ask an owner about the plan, payments or ownership.')}
            </CardDescription>
          </div>
          {isOwner && (
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => openDialog('co_owner')}>{t('owners.addCoOwner', 'Add a co-owner')}</Button>
              <Button variant="outline" onClick={() => openDialog('transfer')}>
                {t('owners.transfer', 'Hand over ownership')}
              </Button>
            </div>
          )}
        </CardHeader>
        <CardContent>
          <ul className="divide-y divide-border" data-testid="owners-list">
            {data.owners.map((o) => {
              const me = o.profile_id === user?.id;
              return (
                <li key={o.profile_id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                  <Avatar name={o.name || o.email} size={32} />
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-foreground">
                      {o.name || o.email}
                      {me && <span className="ms-2 text-sm text-muted-foreground">{t('owners.you', '(you)')}</span>}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {t('owners.since', 'Owner since {date}', { date: formatDate(o.since) })}
                    </p>
                  </div>
                  <Badge variant="outline">{t('owners.badge', 'Owner')}</Badge>
                </li>
              );
            })}
          </ul>
        </CardContent>
      </Card>

      {isOwner && data.offers.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t('owners.offers.title', 'Waiting for an answer')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="divide-y divide-border" data-testid="owners-offers">
              {data.offers.map((offer) => (
                <li key={offer.id} className="flex min-h-12 flex-wrap items-center gap-3 py-2">
                  <Avatar name={offer.to.name} size={32} />
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-foreground">
                      {offer.kind === 'transfer'
                        ? t('owners.offers.transfer', 'Hand over to {name}', { name: offer.to.name })
                        : t('owners.offers.coOwner', 'Co-owner: {name}', { name: offer.to.name })}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {t('owners.offers.until', 'Offered by {from}, open until {date}', {
                        from: offer.from.name,
                        date: formatDate(offer.expires_at),
                      })}
                    </p>
                  </div>
                  <Badge variant="outline" className="border-dashed">
                    {t('owners.offers.waiting', 'Waiting')}
                  </Badge>
                  <Button variant="outline" size="sm" onClick={() => void withdraw(offer)}>
                    {t('owners.offers.withdraw', 'Withdraw')}
                  </Button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <Dialog open={dialog !== null} onOpenChange={(open) => !open && setDialog(null)}>
        <DialogContent data-testid="owners-offer-dialog">
          <DialogHeader>
            <DialogTitle>
              {dialog === 'transfer'
                ? t('owners.transfer.title', 'Hand over ownership')
                : t('owners.coOwner.title', 'Add a co-owner')}
            </DialogTitle>
            <DialogDescription>
              {dialog === 'transfer'
                ? t(
                    'owners.transfer.description',
                    'They become the owner when they accept, within 7 days. Until then nothing changes. You confirm it’s you first.',
                  )
                : t(
                    'owners.coOwner.description',
                    'They get the same say as you: the plan, payments and who owns the workspace. They accept within 7 days. You confirm it’s you first.',
                  )}
            </DialogDescription>
          </DialogHeader>

          {data.candidates.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('owners.offer.noOne', 'There’s no one else to offer it to. Invite people to the workspace first.')}
            </p>
          ) : (
            <div className="space-y-4">
              <div className="space-y-1">
                <label htmlFor="owner-person" className="text-sm font-medium text-foreground">
                  {t('owners.offer.person', 'Person')}
                </label>
                <FieldSelect
                  id="owner-person"
                  value={chosen}
                  onChange={setChosen}
                  placeholder={t('owners.offer.choose', 'Choose a person.')}
                  options={data.candidates.map((c) => ({ value: String(c.profile_id), label: c.name || c.email }))}
                />
              </div>
              {dialog === 'transfer' && (
                <fieldset className="space-y-2">
                  <legend className="text-sm font-medium text-foreground">{t('owners.transfer.after', 'After they accept')}</legend>
                  <label className="flex items-start gap-2">
                    <Checkbox checked={leaveAfter} onCheckedChange={(v) => setLeaveAfter(v === true)} />
                    <span>
                      <span className="block text-foreground">{t('owners.transfer.leave', 'Leave the workspace')}</span>
                      <span className="block text-sm text-muted-foreground">
                        {leaveAfter
                          ? t('owners.transfer.leaveOn', 'You lose access to this workspace once they accept. Only they can invite you back.')
                          : t('owners.transfer.stay', 'Otherwise you stay on as an admin.')}
                      </span>
                    </span>
                  </label>
                </fieldset>
              )}
            </div>
          )}

          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setDialog(null)} disabled={busy}>
              {t('owners.cancel', 'Cancel')}
            </Button>
            <Button onClick={() => void startOffer()} loading={busy} disabled={data.candidates.length === 0}>
              {t('owners.offer.continue', 'Continue')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmItsYouDialog
        check={pending?.check ?? null}
        busy={busy}
        error={codeError}
        onCancel={() => setPending(null)}
        onClearError={() => setCodeError(null)}
        onConfirm={(code) => void confirm(code)}
      />
    </div>
  );
}
