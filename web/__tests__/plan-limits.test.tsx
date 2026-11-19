import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * WHIT-932: the warning a workspace's admins see when it passes a plan limit,
 * and "Built on Whity" following the plan. Passing a limit locks nothing, so
 * the only thing that can go wrong here is the warning not reaching a person.
 */

const apiClient = jest.fn();
// One object, as the real provider keeps one apiClient for the session.
const mockAuth = {
  apiClient: (...args: unknown[]) => apiClient(...args),
  user: { id: 10, tenant_id: 1 },
  memberships: [{ tenant_id: 1, tenant_name: 'Example Bakery' }],
};
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => mockAuth,
}));

// Both screens read the limits through the typed client (WHIT-1046): route its
// GET to the same mock and answer in its { data, response } shape.
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: async (path: string) => {
      const res = (await apiClient(path)) as { ok: boolean; status?: number; json: () => Promise<unknown> };
      return {
        data: res.ok ? await res.json() : undefined,
        response: { ok: res.ok, status: res.status ?? (res.ok ? 200 : 500) },
      };
    },
  },
}));

const granted = new Set<string>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, hasPermission: (p: string) => granted.has(p) }),
}));

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string, params?: Record<string, string>) =>
    params ? fallback.replace(/\{(\w+)\}/g, (_m, k: string) => params[k] ?? '') : fallback,
  LanguageSwitcher: () => null,
}));

let branding = { siteName: 'Example Bakery', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null, builtOnWhity: true };
jest.mock('@/lib/branding-context', () => ({
  useBranding: () => branding,
}));

import userEvent from '@testing-library/user-event';
import { PlanLimits } from '@/app/(protected)/billing/plan-limits';
import { PlanLimitBanner } from '@/components/billing/plan-limit-banner';
import { bannerHidden, meterPercent, stateOf } from '@/lib/billing/plan-limits';
import { PublicShell } from '@/components/auth/public-shell';

function limits(data: unknown) {
  apiClient.mockResolvedValue({ ok: true, json: () => Promise.resolve({ data }) });
}

describe('PlanLimits', () => {
  beforeEach(() => apiClient.mockReset());

  it('warns when a limit is passed and says nothing is locked', async () => {
    limits([
      { key: 'members.max', kind: 'count', used: 6, limit: 5, over: true, resets_at: null },
      { key: 'customers.per_month', kind: 'count', used: 120, limit: 500, over: false, resets_at: '2026-12-01T00:00:00+02:00' },
    ]);

    render(<PlanLimits />);

    expect(await screen.findByTestId('plan-limits-passed')).toHaveTextContent('nothing is removed');
    // TplPlanLimit: "6 of 5", the word beside the amber meter, and what counts.
    expect(screen.getByTestId('plan-limit-members.max')).toHaveTextContent('6 of 5');
    expect(screen.getByTestId('plan-limit-members.max')).toHaveTextContent('1 over');
    expect(screen.getByTestId('plan-limit-members.max')).toHaveTextContent('Agents don’t count.');
    expect(screen.getByRole('meter', { name: 'Staff' })).toHaveAttribute('aria-valuenow', '6');
    expect(screen.getByRole('meter', { name: 'Staff' })).toHaveAttribute('aria-valuemax', '5');
    expect(screen.getByTestId('plan-limit-customers.per_month')).toHaveTextContent('120 of 500');
    expect(screen.queryByTestId('plan-limits-near')).not.toBeInTheDocument();
    expect(apiClient).toHaveBeenCalledWith('/api/v1/billing/limits');
  });

  it('says quietly when a limit is near (80%), with Near beside it', async () => {
    limits([{ key: 'customers.per_month', kind: 'count', used: 412, limit: 500, over: false, resets_at: null }]);

    render(<PlanLimits />);

    expect(await screen.findByTestId('plan-limits-near')).toHaveTextContent('everything keeps working');
    expect(screen.getByTestId('plan-limit-customers.per_month')).toHaveTextContent('Near');
    expect(screen.queryByTestId('plan-limits-passed')).not.toBeInTheDocument();
  });

  it('shows no warning within the limits and leaves out unlimited ones', async () => {
    limits([
      { key: 'members.max', kind: 'count', used: 3, limit: 5, over: false, resets_at: null },
      { key: 'customers.per_month', kind: 'count', used: 9000, limit: null, over: false, resets_at: null },
    ]);

    render(<PlanLimits />);

    expect(await screen.findByTestId('plan-limit-members.max')).toBeInTheDocument();
    expect(screen.queryByTestId('plan-limits-passed')).not.toBeInTheDocument();
    expect(screen.queryByTestId('plan-limit-customers.per_month')).not.toBeInTheDocument();
  });

  it('meters items in inventory past the plan, each variant counted (IV.24)', async () => {
    limits([{ key: 'inventory.items.max', kind: 'count', used: 104, limit: 100, over: true, resets_at: null }]);

    render(<PlanLimits />);

    expect(await screen.findByTestId('plan-limits-passed')).toHaveTextContent('nothing is removed');
    expect(screen.getByTestId('plan-limit-inventory.items.max')).toHaveTextContent('104 of 100');
    expect(screen.getByTestId('plan-limit-inventory.items.max')).toHaveTextContent('Each size or colour counts.');
    expect(screen.getByRole('meter', { name: 'Items in inventory' })).toHaveAttribute('aria-valuenow', '104');
  });

  it('names a brand feature in use that the plan does not include', async () => {
    limits([{ key: 'brand.custom_domain', kind: 'feature', used: null, limit: null, over: true, resets_at: null }]);

    render(<PlanLimits />);

    expect(await screen.findByTestId('plan-limit-brand.custom_domain')).toHaveTextContent('It keeps working');
  });

  it('renders nothing when the limits cannot be read', async () => {
    apiClient.mockResolvedValue({ ok: false, status: 403, json: () => Promise.resolve({}) });

    const { container } = render(<PlanLimits />);

    await waitFor(() => expect(apiClient).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});

describe('PlanLimitBanner (TplPlanLimit, BM.11)', () => {
  beforeEach(() => {
    apiClient.mockReset();
    granted.clear();
    window.localStorage.clear();
  });

  const over = [{ key: 'members.max', kind: 'count', used: 7, limit: 5, over: true, resets_at: null }];

  it('tells people who can change the plan, in words that promise nothing stops', async () => {
    granted.add('billing:pay');
    limits(over);

    render(<PlanLimitBanner />);

    const banner = await screen.findByTestId('plan-limit-banner');
    expect(banner).toHaveTextContent('Example Bakery has gone past its plan’s staff limit.');
    expect(banner).toHaveTextContent('Everything keeps working and nothing is removed.');
    expect(screen.getByRole('link', { name: 'See plan' })).toHaveAttribute('href', '/billing');
  });

  it('shows members nothing and does not even ask', async () => {
    limits(over);

    const { container } = render(<PlanLimitBanner />);

    await new Promise((r) => setTimeout(r, 0));
    expect(container).toBeEmptyDOMElement();
    expect(apiClient).not.toHaveBeenCalled();
  });

  it('stays out of the way for 7 days once hidden', async () => {
    granted.add('billing:pay');
    limits(over);
    const user = userEvent.setup();

    const { unmount } = render(<PlanLimitBanner />);
    await user.click(await screen.findByRole('button', { name: 'Hide for 7 days' }));
    expect(screen.queryByTestId('plan-limit-banner')).not.toBeInTheDocument();

    unmount();
    render(<PlanLimitBanner />);
    await waitFor(() => expect(apiClient.mock.calls.length).toBeGreaterThan(1));
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByTestId('plan-limit-banner')).not.toBeInTheDocument();
  });

  it('says nothing across the app when a limit is only near', async () => {
    granted.add('billing:pay');
    limits([{ key: 'customers.per_month', kind: 'count', used: 450, limit: 500, over: false, resets_at: null }]);

    const { container } = render(<PlanLimitBanner />);

    await waitFor(() => expect(apiClient).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});

describe('the limits model', () => {
  it('reads near at 80%, over from the server, and draws over full', () => {
    expect(stateOf({ key: 'members.max', kind: 'count', used: 4, limit: 5, over: false, resets_at: null })).toBe('near');
    expect(stateOf({ key: 'members.max', kind: 'count', used: 3, limit: 5, over: false, resets_at: null })).toBe('fine');
    expect(stateOf({ key: 'members.max', kind: 'count', used: 7, limit: 5, over: true, resets_at: null })).toBe('over');
    expect(meterPercent({ key: 'members.max', kind: 'count', used: 7, limit: 5, over: true, resets_at: null })).toBe(100);
    expect(bannerHidden(new Date(Date.now() + 60_000).toISOString())).toBe(true);
    expect(bannerHidden(new Date(Date.now() - 60_000).toISOString())).toBe(false);
    expect(bannerHidden(null)).toBe(false);
  });
});

describe('Built on Whity', () => {
  it('shows on a public page by default', () => {
    branding = { ...branding, builtOnWhity: true };
    render(<PublicShell>page</PublicShell>);
    expect(screen.getByText('Built on Whity')).toBeInTheDocument();
  });

  it('is left off when the workspace plan removes it', () => {
    branding = { ...branding, builtOnWhity: false };
    render(<PublicShell>page</PublicShell>);
    expect(screen.queryByText('Built on Whity')).not.toBeInTheDocument();
  });
});
