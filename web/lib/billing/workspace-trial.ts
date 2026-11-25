/**
 * A further workspace's trial and how to pay for it (WHIT-1207).
 *
 * Owner 2026-11-18: right after creating a further workspace (WHIT-1203) its
 * owner is asked for a card, the 30-day trial stays free and the first charge
 * is on day 30; "Not now" keeps the trial, and the workspace turns read-only
 * when it ends unpaid. A banner across the app says when it is read-only, with
 * Pay for those who can, and reminds payers from 7 days before the trial ends.
 * The first, free workspace never sees any of it (the server answers null).
 */
import { api } from '@/lib/api/client';
import { navigateExternal } from '@/lib/external-navigate';
import type { components } from '@whity/api-client';

export type WorkspaceTrial = components['schemas']['WorkspaceTrialState'];

export const PAYMENT_STEP_PATH = '/setup/pay';

/** The reminder shows from this many days before the trial ends (owner 2026-11-18). */
export const REMINDER_DAYS = 7;

/** "Not now" on the reminder puts it away for a day on this device. */
export const REMINDER_HIDE_HOURS = 24;

export async function fetchWorkspaceTrial(): Promise<WorkspaceTrial | null> {
  const { data, response } = await api.GET('/api/v1/billing/trial');
  if (!response.ok || !data) {
    throw new Error(`billing/trial ${response.status}`);
  }

  return data.data ?? null;
}

export type TrialBannerKind = 'read_only' | 'reminder' | null;

/**
 * Which banner, if any. Read-only is for everyone (members are told to ask an
 * owner). The reminder is only for people who can pay, only while no card is
 * on file, and only in the last {@link REMINDER_DAYS} days.
 */
export function trialBannerKind(trial: WorkspaceTrial | null, canPay: boolean): TrialBannerKind {
  if (trial === null) return null;
  // Expired or canceled: the paused banner says it (2026-11-22). This one's
  // words are about a TRIAL that ended, which stays `trialing`.
  if (trial.read_only && trial.status !== 'trialing') return null;
  if (trial.read_only) return 'read_only';
  if (
    canPay &&
    !trial.card_added &&
    trial.status === 'trialing' &&
    trial.days_left !== null &&
    trial.days_left <= REMINDER_DAYS
  ) {
    return 'reminder';
  }

  return null;
}

export function reminderHideKey(tenantId: number | null): string {
  return `whity.trialReminder.hiddenUntil.${tenantId ?? 'none'}`;
}

/** Whether a stored "hidden until" time is still in the future. */
export function reminderHidden(stored: string | null, now: number = Date.now()): boolean {
  if (stored === null) return false;
  const until = Date.parse(stored);

  return Number.isFinite(until) && until > now;
}

/**
 * Start paying for this workspace's plan and go to the provider. While the
 * trial runs the server keeps it free (first charge when it ends); after it,
 * the payment is taken now. Resolves to an error message when it can't start.
 */
export async function startWorkspaceCheckout(planKey: string, after?: 'setup'): Promise<string | null> {
  const body: components['schemas']['CheckoutStartRequest'] = after ? { plan_key: planKey, after } : { plan_key: planKey };
  const { data, response } = await api.POST('/api/v1/billing/checkout', { body });
  const url = (data as { data?: { url?: string } } | undefined)?.data?.url;
  if (!response.ok || typeof url !== 'string' || url === '') {
    return `checkout ${response.status}`;
  }
  try {
    navigateExternal(url);
  } catch {
    // Not an https address: refused, and said so rather than left hanging.
    return 'checkout url refused';
  }

  return null;
}
