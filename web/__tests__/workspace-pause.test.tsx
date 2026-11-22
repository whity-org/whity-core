import React from 'react';
import { render, renderHook, screen, waitFor } from '@testing-library/react';

/**
 * 2026-11-22: an EXPIRED workspace (Almolakhas Org. on app.whity.dev) could not
 * be opened: the switch answered 402, the page jumped to Billing and the
 * switcher said nothing. Now an owner gets it read-only with Billing, people
 * who can't pay get a plain paused page naming the owners, and a refused
 * switch always says why, in plain words.
 */

const get = jest.fn();
const post = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (path: string) => get(path),
    POST: (path: string, init: unknown) => post(path, init),
  },
  apiFetch: jest.fn(),
}));

const mockAuth = {
  user: { id: 10, tenant_id: 2 },
  memberships: [{ tenant_id: 2, tenant_name: 'Example Studio' }],
};
jest.mock('@/lib/auth-context', () => ({ useAuth: () => mockAuth }));

const granted = new Set<string>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, hasPermission: (p: string) => granted.has(p) }),
}));

const replace = jest.fn();
let mockPath = '/dashboard';
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push: jest.fn() }),
  usePathname: () => mockPath,
}));

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string, params?: Record<string, string | number>) =>
    params ? fallback.replace(/\{(\w+)\}/g, (m, k: string) => (params[k] === undefined ? m : String(params[k]))) : fallback,
}));

import {
  WorkspacePauseBanner,
  WorkspacePauseGate,
  WorkspacePauseProvider,
} from '@/components/billing/workspace-pause';
import { isOpenWhilePaused, type WorkspacePause } from '@/lib/billing/workspace-pause';
import { trialBannerKind, type WorkspaceTrial } from '@/lib/billing/workspace-trial';
import {
  CONFINED_REFUSAL,
  WorkspaceSwitchRefused,
  switchRefusalReason,
  useSwitchRefusalMessage,
} from '@/lib/workspace-switch';

function paused(over: Partial<WorkspacePause> = {}): WorkspacePause {
  return { status: 'expired', reads_refused: false, owners: [{ name: 'Amro Saleh' }, { name: 'Ibrahim Mansour' }], ...over };
}

function answer(state: WorkspacePause | null) {
  get.mockResolvedValue({ data: { data: state }, response: { ok: true, status: 200 } });
}

function shell() {
  return render(
    <WorkspacePauseProvider>
      <WorkspacePauseBanner />
      <WorkspacePauseGate>
        <p>The page</p>
      </WorkspacePauseGate>
    </WorkspacePauseProvider>,
  );
}

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  replace.mockReset();
  granted.clear();
  mockPath = '/dashboard';
});

describe('a paused workspace', () => {
  it('an owner opens it read-only, with a way to Billing', async () => {
    granted.add('billing:pay');
    answer(paused());

    shell();

    const banner = await screen.findByTestId('workspace-paused-banner');
    expect(get).toHaveBeenCalledWith('/api/v1/billing/pause');
    expect(banner).toHaveTextContent('Example Studio is read-only.');
    expect(banner).toHaveTextContent('Nothing was deleted.');
    expect(screen.getByRole('link', { name: 'Go to Billing' })).toHaveAttribute('href', '/billing');
    expect(screen.getByText('The page')).toBeInTheDocument();
    expect(screen.queryByTestId('workspace-paused-page')).not.toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it('an owner of a workspace walled on reads too is taken to Billing', async () => {
    granted.add('billing:pay');
    answer(paused({ reads_refused: true }));

    shell();

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/billing'));
  });

  it('on Billing itself the owner stays, and the banner has no button', async () => {
    granted.add('billing:pay');
    mockPath = '/billing';
    answer(paused({ reads_refused: true }));

    shell();

    const banner = await screen.findByTestId('workspace-paused-banner');
    expect(banner).toHaveTextContent('Example Studio is paused.');
    expect(banner).toHaveTextContent('Choose a plan on Billing to open it again.');
    expect(screen.getByText('The page')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Go to Billing' })).not.toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it('someone who can’t pay sees the paused page naming the owners', async () => {
    answer(paused());

    shell();

    const page = await screen.findByTestId('workspace-paused-page');
    expect(page).toHaveTextContent('Example Studio is paused');
    expect(page).toHaveTextContent('it’s paused until an owner pays');
    expect(screen.getByTestId('workspace-paused-owners')).toHaveTextContent('Amro Saleh');
    expect(screen.getByTestId('workspace-paused-owners')).toHaveTextContent('Ibrahim Mansour');
    expect(screen.queryByText('The page')).not.toBeInTheDocument();
    expect(screen.queryByTestId('workspace-paused-banner')).not.toBeInTheDocument();
    expect(screen.queryByText(/Payment Required|402/)).not.toBeInTheDocument();
  });

  it('with no owners on record it says who to ask instead', async () => {
    answer(paused({ owners: [] }));

    shell();

    expect(await screen.findByTestId('workspace-paused-page')).toHaveTextContent('Ask the people who run this Whity');
  });

  it('a workspace that is not paused shows the page and nothing else', async () => {
    answer(null);

    shell();

    await waitFor(() => expect(get).toHaveBeenCalled());
    expect(screen.getByText('The page')).toBeInTheDocument();
    expect(screen.queryByTestId('workspace-paused-banner')).not.toBeInTheDocument();
  });

  it('keeps Billing and the person’s own pages open', () => {
    expect(isOpenWhilePaused('/billing')).toBe(true);
    expect(isOpenWhilePaused('/billing/return')).toBe(true);
    expect(isOpenWhilePaused('/settings/profile')).toBe(true);
    expect(isOpenWhilePaused('/contacts')).toBe(false);
    expect(isOpenWhilePaused('/billingx')).toBe(false);
  });
});

describe('the trial-ended banner, unchanged', () => {
  const trial = (over: Partial<WorkspaceTrial>): WorkspaceTrial => ({
    plan_key: 'team',
    plan_name: 'Team',
    status: 'trialing',
    trial_ends_at: '2026-11-01T10:00:00Z',
    days_left: 0,
    read_only: true,
    card_added: false,
    price: { unit_amount: 6900, currency: 'USD', billing_period: 'month' },
    pay_online: true,
    card_step: false,
    ...over,
  });

  it('still shows read-only for a trial that ended unpaid', () => {
    expect(trialBannerKind(trial({}), true)).toBe('read_only');
    expect(trialBannerKind(trial({}), false)).toBe('read_only');
  });

  it('leaves an expired plan to the paused banner, whose words fit it', () => {
    expect(trialBannerKind(trial({ status: 'expired' }), true)).toBeNull();
  });
});

describe('a refused workspace switch', () => {
  it('reads the reason from the answer', () => {
    expect(switchRefusalReason(new WorkspaceSwitchRefused(0, null))).toBe('unreachable');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(401, 'Unauthorized'))).toBe('signed_out');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(402, 'Payment Required'))).toBe('paused');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(403, CONFINED_REFUSAL))).toBe('confined');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(403, 'Access to the requested tenant is forbidden'))).toBe('not_member');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(429, null))).toBe('too_many');
    expect(switchRefusalReason(new WorkspaceSwitchRefused(503, null))).toBe('unreachable');
    expect(switchRefusalReason(new Error('boom'))).toBe('other');
  });

  it('says it in plain words, never the server’s text', () => {
    const { result } = renderHook(() => useSwitchRefusalMessage());
    const say = result.current;

    expect(say(new WorkspaceSwitchRefused(402, 'Payment Required'))).toBe('That workspace is paused until an owner pays.');
    expect(say(new WorkspaceSwitchRefused(403, 'Access to the requested tenant is forbidden'))).toBe(
      'You’re no longer a member of that workspace. Ask one of its owners to add you again.',
    );
    expect(say(new WorkspaceSwitchRefused(0, null))).toMatch(/couldn’t be reached/);
    expect(say(new Error('anything'))).toBe('That workspace couldn’t be opened. Try again.');
    for (const status of [0, 400, 401, 402, 403, 429, 500]) {
      const text = say(new WorkspaceSwitchRefused(status, 'Payment Required'));
      expect(text).not.toBe('');
      expect(text).not.toMatch(/Payment Required|tenant|forbidden/i);
    }
  });
});

