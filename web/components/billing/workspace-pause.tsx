'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useAuth } from '@/lib/auth-context';
import { BILLING_PAY } from '@/lib/capabilities';
import { fetchWorkspacePause, isOpenWhilePaused, type WorkspacePause } from '@/lib/billing/workspace-pause';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { LockedScreen } from '@whity/ui/locked-screen';
import { useTranslation } from '@whity/features/i18n';

/**
 * A workspace paused because its plan expired or was canceled (2026-11-22).
 *
 * - People who can pay (owners): the switch into it succeeds and a line across
 *   the app points to Billing. Where the wall refuses reads too (`block_all`,
 *   how an unpaid workspace is gated) every page but Billing and the person's
 *   own pages sends them to Billing; where it refuses only changes, the
 *   workspace is read-only, like a trial that ended unpaid.
 * - Everyone else: a plain page that says it is paused until an owner pays and
 *   names the owners. The sidebar stays, so they can open another workspace or
 *   sign out.
 *
 * One read of `GET /api/v1/billing/pause` per workspace, shared by the banner
 * and the gate. A failed read shows nothing extra: the server still refuses
 * what it refuses.
 */
interface PauseState {
  pause: WorkspacePause | null;
  canPay: boolean;
  ready: boolean;
}

const PauseContext = createContext<PauseState>({ pause: null, canPay: false, ready: false });

export function WorkspacePauseProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const { hasPermission, loading } = useCapabilities();
  const tenantId = user?.tenant_id ?? null;
  const [read, setRead] = useState<{ tenantId: number | null; pause: WorkspacePause | null }>({
    tenantId: null,
    pause: null,
  });

  useEffect(() => {
    if (tenantId === null) return;
    let live = true;
    fetchWorkspacePause()
      .then((pause) => {
        if (live) setRead({ tenantId, pause });
      })
      .catch(() => {
        if (live) setRead({ tenantId, pause: null });
      });
    return () => {
      live = false;
    };
  }, [tenantId]);

  // An answer for the workspace before a switch never applies to the one after.
  const current = read.tenantId === tenantId;
  const value: PauseState = {
    pause: current ? read.pause : null,
    canPay: !loading && hasPermission(BILLING_PAY),
    ready: current && !loading,
  };

  return <PauseContext.Provider value={value}>{children}</PauseContext.Provider>;
}

function useWorkspaceName(): string {
  const t = useTranslation('admin');
  const { user, memberships } = useAuth();
  const tenantId = user?.tenant_id ?? null;

  return (
    memberships.find((m) => m.tenant_id === tenantId)?.tenant_name ||
    t('billing.trial.yourWorkspace', 'Your workspace')
  );
}

/** The line across the app for people who can pay. */
export function WorkspacePauseBanner() {
  const t = useTranslation('admin');
  const { pause, canPay, ready } = useContext(PauseContext);
  const pathname = usePathname() ?? '';
  const workspace = useWorkspaceName();
  if (!ready || pause === null || !canPay) return null;

  const onBilling = pathname.startsWith('/billing');

  return (
    <div
      role="status"
      data-testid="workspace-paused-banner"
      className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-warning-tint px-6 py-2 text-sm text-foreground md:px-8"
    >
      <Icon name="lock" size={18} className="text-warning-text" />
      <span className="min-w-0 flex-1">
        <span className="font-semibold">
          {pause.reads_refused
            ? t('billing.pause.owner.titlePaused', '{workspace} is paused.', { workspace })
            : t('billing.pause.owner.title', '{workspace} is read-only.', { workspace })}
        </span>{' '}
        {t('billing.pause.owner.why', 'Its plan ended without payment. Nothing was deleted.')}{' '}
        {pause.reads_refused
          ? t('billing.pause.owner.nextPaused', 'Choose a plan on Billing to open it again.')
          : t('billing.pause.owner.next', 'Choose a plan on Billing to make changes again.')}
      </span>
      {!onBilling && (
        <Button asChild size="sm" variant="outline" data-testid="workspace-paused-billing">
          <Link href="/billing">{t('billing.pause.owner.goToBilling', 'Go to Billing')}</Link>
        </Button>
      )}
    </div>
  );
}

/** The page itself: the paused page for people who can't pay, else the page. */
export function WorkspacePauseGate({ children }: { children: ReactNode }) {
  const { pause, canPay, ready } = useContext(PauseContext);
  const pathname = usePathname() ?? '';
  const router = useRouter();
  const sendToBilling = ready && pause !== null && canPay && pause.reads_refused && !isOpenWhilePaused(pathname);

  useEffect(() => {
    if (sendToBilling) router.replace('/billing');
  }, [sendToBilling, router]);

  if (!ready || pause === null) return <>{children}</>;
  if (!canPay) return <WorkspacePausedPage pause={pause} />;
  if (sendToBilling) return null;

  return <>{children}</>;
}

export function WorkspacePausedPage({ pause }: { pause: WorkspacePause }) {
  const t = useTranslation('admin');
  const workspace = useWorkspaceName();

  return (
    <LockedScreen
      data-testid="workspace-paused-page"
      icon={<Icon name="lock" />}
      title={t('billing.pause.member.title', '{workspace} is paused', { workspace })}
      description={
        <div className="flex flex-col gap-4">
          <p>
            {t(
              'billing.pause.member.why',
              'Its plan has ended, so it’s paused until an owner pays. Nothing was deleted.',
            )}
          </p>
          {pause.owners.length > 0 ? (
            <div className="flex flex-col items-center gap-2">
              <p className="text-foreground">
                {t('billing.pause.member.owners', 'Ask an owner to pay:', { count: pause.owners.length })}
              </p>
              <ul className="flex flex-col gap-2" data-testid="workspace-paused-owners">
                {pause.owners.map((owner) => (
                  <li key={owner.name} className="flex items-center gap-2 text-foreground">
                    <Avatar name={owner.name} size={32} />
                    <span>{owner.name}</span>
                  </li>
                ))}
              </ul>
            </div>
          ) : (
            <p>{t('billing.pause.member.noOwners', 'Ask the people who run this Whity who can pay for it.')}</p>
          )}
          <p>
            {t(
              'billing.pause.member.meanwhile',
              'Meanwhile you can open another workspace from the menu, or sign out.',
            )}
          </p>
        </div>
      }
    />
  );
}
