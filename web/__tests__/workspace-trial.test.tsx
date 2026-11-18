import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * WHIT-1207 (owner 2026-11-18): the payment step after creating a further
 * workspace, and the banner that says a trial is ending or the workspace is
 * read-only, with Pay. The first, free workspace never sees either (the
 * server answers null).
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
  memberships: [{ tenant_id: 2, tenant_name: 'Example Cafe' }],
};
jest.mock('@/lib/auth-context', () => ({ useAuth: () => mockAuth }));

const granted = new Set<string>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, hasPermission: (p: string) => granted.has(p) }),
}));

const assign = jest.fn();
jest.mock('@/lib/external-navigate', () => ({ navigateExternal: (url: string) => assign(url) }));

const replace = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ replace, push: jest.fn() }) }));

const templatePickerPath = jest.fn();
jest.mock('@/lib/workspace-template', () => ({ templatePickerPath: () => templatePickerPath() }));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({ date: (iso: string | null) => (iso ? '18 Dec 2026' : null) }),
}));

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string, params?: Record<string, string | number>) =>
    params ? fallback.replace(/\{(\w+)\}/g, (m, k: string) => (params[k] === undefined ? m : String(params[k]))) : fallback,
}));

import WorkspacePaymentStepPage from '@/app/(protected)/setup/pay/page';
import { WorkspaceTrialBanner } from '@/components/billing/workspace-trial-banner';
import { reminderHidden, trialBannerKind, type WorkspaceTrial } from '@/lib/billing/workspace-trial';

function trial(over: Partial<WorkspaceTrial> = {}): WorkspaceTrial {
  return {
    plan_key: 'team',
    plan_name: 'Team',
    status: 'trialing',
    trial_ends_at: '2026-12-18T10:00:00Z',
    days_left: 30,
    read_only: false,
    card_added: false,
    price: { unit_amount: 6900, currency: 'USD', billing_period: 'month' },
    pay_online: true,
    card_step: true,
    ...over,
  };
}

function answer(state: WorkspaceTrial | null) {
  get.mockResolvedValue({ data: { data: state }, response: { ok: true, status: 200 } });
}


beforeEach(() => {
  get.mockReset();
  post.mockReset();
  replace.mockReset();
  assign.mockReset();
  templatePickerPath.mockReset().mockResolvedValue('/setup/template');
  granted.clear();
  granted.add('billing:pay');
  window.localStorage.clear();
});

describe('trialBannerKind', () => {
  it('shows nothing for a workspace with no billed plan', () => {
    expect(trialBannerKind(null, true)).toBeNull();
  });

  it('shows read-only to everyone', () => {
    expect(trialBannerKind(trial({ read_only: true, days_left: 0 }), false)).toBe('read_only');
  });

  it('reminds payers from 7 days before, while no card is on file', () => {
    expect(trialBannerKind(trial({ days_left: 8 }), true)).toBeNull();
    expect(trialBannerKind(trial({ days_left: 7 }), true)).toBe('reminder');
    expect(trialBannerKind(trial({ days_left: 3 }), false)).toBeNull();
    expect(trialBannerKind(trial({ days_left: 3, card_added: true }), true)).toBeNull();
  });

  it('reads a stored hide time', () => {
    expect(reminderHidden(null)).toBe(false);
    expect(reminderHidden(new Date(Date.now() + 60_000).toISOString())).toBe(true);
    expect(reminderHidden(new Date(Date.now() - 60_000).toISOString())).toBe(false);
  });
});

describe('the payment step', () => {
  it('asks for a card, says the trial is free and when the first charge is', async () => {
    answer(trial());
    post.mockResolvedValue({ data: { data: { url: 'https://checkout.example.test/1', trial_days: 30 } }, response: { ok: true, status: 200 } });

    render(<WorkspacePaymentStepPage />);

    expect(await screen.findByRole('heading', { name: 'Add a card for Example Cafe' })).toBeInTheDocument();
    expect(screen.getByText('Your 30-day trial of Team is free.')).toBeInTheDocument();
    expect(screen.getByTestId('workspace-pay-price')).toHaveTextContent('$69.00');
    expect(screen.getByTestId('workspace-pay-step')).toHaveTextContent('a month from 18 Dec 2026');

    await userEvent.click(screen.getByRole('button', { name: 'Add a card' }));

    expect(post).toHaveBeenCalledWith('/api/v1/billing/checkout', { body: { plan_key: 'team', after: 'setup' } });
    expect(assign).toHaveBeenCalledWith('https://checkout.example.test/1');
  });

  it('Not now keeps the trial and goes on to the template picker', async () => {
    answer(trial());

    render(<WorkspacePaymentStepPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Not now' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/setup/template'));
    expect(post).not.toHaveBeenCalled();
  });

  it('goes straight on when no card can be taken this way', async () => {
    answer(trial({ card_step: false }));
    templatePickerPath.mockResolvedValue(null);

    render(<WorkspacePaymentStepPage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/dashboard'));
    expect(screen.queryByRole('button', { name: 'Add a card' })).not.toBeInTheDocument();
  });

  it('goes straight on for someone who cannot pay', async () => {
    granted.clear();
    answer(trial());

    render(<WorkspacePaymentStepPage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/setup/template'));
  });

  it('says so when the card page cannot be opened', async () => {
    answer(trial());
    post.mockResolvedValue({ data: undefined, response: { ok: false, status: 503 } });

    render(<WorkspacePaymentStepPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Add a card' }));

    expect(await screen.findByTestId('workspace-pay-error')).toBeInTheDocument();
    expect(assign).not.toHaveBeenCalled();
  });
});

describe('WorkspaceTrialBanner', () => {
  it('shows nothing for a workspace with no billed plan', async () => {
    answer(null);

    const { container } = render(<WorkspaceTrialBanner />);

    await waitFor(() => expect(get).toHaveBeenCalledWith('/api/v1/billing/trial'));
    expect(container).toBeEmptyDOMElement();
  });

  it('read-only: a payer gets Pay, straight to checkout', async () => {
    answer(trial({ read_only: true, days_left: 0, card_step: false }));
    post.mockResolvedValue({ data: { data: { url: 'https://checkout.example.test/2' } }, response: { ok: true, status: 200 } });

    render(<WorkspaceTrialBanner />);

    const banner = await screen.findByTestId('workspace-read-only-banner');
    expect(banner).toHaveTextContent('Example Cafe is read-only.');
    expect(banner).toHaveTextContent('The trial of Team ended without payment. Nothing was deleted.');
    expect(banner).toHaveTextContent('Pay $69.00 a month to make changes again.');

    await userEvent.click(screen.getByRole('button', { name: 'Pay' }));
    expect(post).toHaveBeenCalledWith('/api/v1/billing/checkout', { body: { plan_key: 'team' } });
    expect(assign).toHaveBeenCalledWith('https://checkout.example.test/2');
  });

  it('read-only: a member is told to ask an owner, with no button', async () => {
    granted.clear();
    answer(trial({ read_only: true, days_left: 0 }));

    render(<WorkspaceTrialBanner />);

    expect(await screen.findByTestId('workspace-read-only-banner')).toHaveTextContent('Ask an owner to pay');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('read-only with no online payments says who to ask', async () => {
    answer(trial({ read_only: true, days_left: 0, pay_online: false, card_step: false }));

    render(<WorkspaceTrialBanner />);

    expect(await screen.findByTestId('workspace-read-only-banner')).toHaveTextContent('Ask the people who run this Whity how to pay.');
    expect(screen.queryByRole('button', { name: 'Pay' })).not.toBeInTheDocument();
  });

  it('reminds a payer in the last week, with Add a card and Not now', async () => {
    answer(trial({ days_left: 5 }));

    render(<WorkspaceTrialBanner />);

    const banner = await screen.findByTestId('workspace-trial-reminder');
    expect(banner).toHaveTextContent('The free trial of Example Cafe ends in 5 days.');
    expect(banner).toHaveTextContent('Add a card to keep making changes after 18 Dec 2026; it’s $69.00 a month.');
    expect(screen.getByRole('button', { name: 'Add a card' })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Not now' }));
    expect(screen.queryByTestId('workspace-trial-reminder')).not.toBeInTheDocument();
  });

  it('does not remind before the last week', async () => {
    answer(trial({ days_left: 20 }));

    const { container } = render(<WorkspaceTrialBanner />);

    await waitFor(() => expect(get).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});
