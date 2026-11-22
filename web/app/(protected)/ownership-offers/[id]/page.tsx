'use client';

/**
 * An ownership offer made to me (WHIT-1170, WHIT-1171): who offers, which
 * workspace, what owning it means, until when, and Accept (after "Confirm
 * it's you") or Decline. The notification and the email link here; it works
 * from whichever workspace the person is signed in to.
 *
 * No card yet (brand ask: brand-ask-workspace-owners.md §2); built on
 * TplJoinWorkspace, the invitation screen.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { invalidateWorkspaceCreateState } from '@/lib/use-workspace-create';
import {
  ConfirmItsYouDialog,
  isIdentityCheckFailure,
  startIdentityCheck,
  type IdentityCheck,
} from '@/components/security/confirm-its-you-dialog';
import { useRefusalText } from '@/lib/ownership-words';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useHomePath } from '@/lib/branding-context';

interface Offer {
  id: number;
  tenant_id: number;
  workspace_name: string;
  kind: 'transfer' | 'co_owner';
  from: { profile_id: number; name: string };
  leave_after: boolean;
  status: string;
  expires_at: string;
}

export default function OwnershipOfferPage() {
  const t = useTranslation('admin');
  const refusalText = useRefusalText();
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const router = useRouter();
  const homePath = useHomePath();
  const { addToast } = useToast();
  const { user, switchTenant } = useAuth();
  const dates = useDateDisplay();
  const formatDate = (value: string): string => dates.date(value) ?? '';
  const [version, setVersion] = useState(0);
  const [check, setCheck] = useState<IdentityCheck | null>(null);
  const [busy, setBusy] = useState(false);
  const [codeError, setCodeError] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  // `{ offer: null }` is "not mine, or gone"; `null` from useFetch is still loading.
  const { data: loaded, error } = useFetch<{ offer: Offer | null }>(async () => {
    const { data, response } = await api.GET('/api/v1/me/ownership-offers/{id}', {
      params: { path: { id } },
      cache: 'no-store',
    });
    if (response.status === 404) return { offer: null };
    if (!response.ok) throw new Error('offer');
    return { offer: (data as { data: Offer }).data };
  }, [id, version]);
  const offer = loaded?.offer ?? null;

  const failed = t('owners.error.generic', 'That didn’t work. Nothing changed. Try again in a minute.');

  const startAccept = async () => {
    setBusy(true);
    setProblem(null);
    const started = await startIdentityCheck(() =>
      api.POST('/api/v1/me/ownership-offers/{id}/identity-check', { params: { path: { id } } }),
    );
    setBusy(false);
    if ('error' in started) {
      setProblem(started.error ?? failed);
      return;
    }
    setCheck(started.check);
  };

  const accept = async (code: string) => {
    if (!offer) return;
    setBusy(true);
    setCodeError(null);
    try {
      const { error: err, response } = await api.POST('/api/v1/me/ownership-offers/{id}/accept', {
        params: { path: { id } },
        body: { code },
      });
      if (!response.ok && isIdentityCheckFailure(err)) {
        setCodeError(t('twoStep.identity.wrong', 'That code is not right or has expired.'));
        return;
      }
      setCheck(null);
      if (!response.ok) {
        const body = (err ?? {}) as { details?: { code?: string }; error?: string };
        setProblem(refusalText(body.details?.code, body.error ?? failed));
        setVersion((v) => v + 1);
        return;
      }
      addToast(
        offer.kind === 'transfer'
          ? t('ownershipOffer.accepted.transfer', 'You now own {workspace}.', { workspace: offer.workspace_name })
          : t('ownershipOffer.accepted.coOwner', 'You’re now a co-owner of {workspace}.', { workspace: offer.workspace_name }),
        'success',
      );
      invalidateWorkspaceCreateState();
      if (user?.tenant_id !== offer.tenant_id) {
        await switchTenant(offer.tenant_id);
      }
      router.push('/admin/settings/owners');
    } catch {
      setProblem(failed);
    } finally {
      setBusy(false);
    }
  };

  const decline = async () => {
    setBusy(true);
    setProblem(null);
    const { error: err, response } = await api.POST('/api/v1/me/ownership-offers/{id}/decline', {
      params: { path: { id } },
    });
    setBusy(false);
    if (!response.ok) {
      const body = (err ?? {}) as { details?: { code?: string }; error?: string };
      setProblem(refusalText(body.details?.code, failed));
    } else {
      addToast(t('ownershipOffer.declined', 'You declined. Nothing changed.'), 'success');
    }
    setVersion((v) => v + 1);
  };

  const back = (
    <Button asChild variant="outline">
      <Link href={homePath}>{t('ownershipOffer.back', 'Back to your workspace')}</Link>
    </Button>
  );

  if (error) {
    return (
      <div className="mx-auto max-w-xl space-y-4">
        <Alert>
          <AlertDescription>{t('ownershipOffer.loadError', 'The offer could not be loaded. Try again in a minute.')}</AlertDescription>
        </Alert>
        {back}
      </div>
    );
  }
  if (loaded === null) {
    return (
      <div className="mx-auto max-w-xl">
        <Skeleton className="h-64 w-full rounded-card" />
      </div>
    );
  }
  if (offer === null) {
    return (
      <div className="mx-auto max-w-xl space-y-4" data-testid="ownership-offer-missing">
        <Alert>
          <AlertDescription>{t('ownershipOffer.missing', 'This offer isn’t for you, or it no longer exists.')}</AlertDescription>
        </Alert>
        {back}
      </div>
    );
  }

  const open = offer.status === 'open';
  const transfer = offer.kind === 'transfer';

  return (
    <div className="mx-auto max-w-xl space-y-6" data-testid="ownership-offer">
      <Card>
        <CardHeader className="items-center text-center">
          <Avatar name={offer.from.name} size={56} />
          <CardTitle className="mt-3">
            {transfer
              ? t('ownershipOffer.title.transfer', '{name} wants to hand {workspace} over to you', {
                  name: offer.from.name,
                  workspace: offer.workspace_name,
                })
              : t('ownershipOffer.title.coOwner', '{name} invites you to co-own {workspace}', {
                  name: offer.from.name,
                  workspace: offer.workspace_name,
                })}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-muted-foreground">
            {t(
              'ownershipOffer.means',
              'As an owner you look after the plan and payments, and decide who else owns the workspace. Every owner has the same say.',
            )}
          </p>
          {transfer && (
            <p className="text-muted-foreground">
              {offer.leave_after
                ? t('ownershipOffer.giverLeaves', '{name} leaves the workspace when you accept.', { name: offer.from.name })
                : t('ownershipOffer.giverStays', '{name} stays on as an admin.', { name: offer.from.name })}
            </p>
          )}

          {open ? (
            <p className="text-sm text-muted-foreground">
              {t('ownershipOffer.until', 'Open until {date}. You confirm it’s you before you accept.', {
                date: formatDate(offer.expires_at),
              })}
            </p>
          ) : (
            <Alert>
              <AlertDescription>
                {offer.status === 'accepted'
                  ? t('ownershipOffer.status.accepted', 'You accepted this offer.')
                  : offer.status === 'declined'
                    ? t('ownershipOffer.status.declined', 'You declined this offer.')
                    : offer.status === 'expired'
                      ? t('ownershipOffer.status.expired', 'This offer expired.')
                      : t('ownershipOffer.status.closed', 'This offer was withdrawn.')}
              </AlertDescription>
            </Alert>
          )}

          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}

          {open ? (
            <div className="flex flex-wrap gap-3">
              <Button size="lg" onClick={() => void startAccept()} loading={busy}>
                {transfer ? t('ownershipOffer.accept.transfer', 'Accept and become owner') : t('ownershipOffer.accept.coOwner', 'Accept and co-own')}
              </Button>
              <Button size="lg" variant="outline" onClick={() => void decline()} disabled={busy}>
                {t('ownershipOffer.decline', 'Decline')}
              </Button>
            </div>
          ) : (
            back
          )}
        </CardContent>
      </Card>

      <ConfirmItsYouDialog
        check={check}
        busy={busy}
        error={codeError}
        onCancel={() => setCheck(null)}
        onClearError={() => setCodeError(null)}
        onConfirm={(code) => void accept(code)}
      />
    </div>
  );
}
