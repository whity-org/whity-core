import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * WHIT-932: the warning a workspace's admins see when it passes a plan limit,
 * and "Built on Whity" following the plan. Passing a limit locks nothing, so
 * the only thing that can go wrong here is the warning not reaching a person.
 */

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => apiClient(...args) }),
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

import { PlanLimits } from '@/app/(protected)/billing/plan-limits';
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
    expect(screen.getByTestId('plan-limit-members.max')).toHaveTextContent('6 / 5');
    expect(screen.getByTestId('plan-limit-members.max')).toHaveTextContent('Over the limit');
    expect(screen.getByTestId('plan-limit-customers.per_month')).toHaveTextContent('120 / 500');
    expect(apiClient).toHaveBeenCalledWith('/api/v1/billing/limits');
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
