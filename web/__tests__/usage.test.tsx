import React from 'react';
import { render, screen } from '@testing-library/react';

/**
 * Section 33 §5: the usage screen. Every meter, its base and the warning
 * state; past a base warns and nothing is ever refused.
 */

const apiGet = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: async (path: string, init?: unknown) => {
      const res = (await apiGet(path, init)) as { ok: boolean; status?: number; json: () => Promise<unknown> };
      return {
        data: res.ok ? await res.json() : undefined,
        response: { ok: res.ok, status: res.status ?? (res.ok ? 200 : 500) },
      };
    },
  },
}));

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string, params?: Record<string, string>) =>
    params ? fallback.replace(/\{(\w+)\}/g, (_m, k: string) => params[k] ?? '') : fallback,
}));

jest.mock('@whity/features/datetime', () => ({
  useDateDisplay: () => ({
    date: (v: string | null) => (v ? v.slice(0, 10) : null),
    calendarLabel: (v: string | null) => (v ? v.slice(0, 7) : null),
  }),
}));

jest.mock('@/components/admin/admin-header', () => ({
  AdminHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

import UsagePage from '@/app/(protected)/billing/usage/page';
import { formatBytes, shownDimensions, usageOverBy, usagePercent } from '@/lib/billing/usage';

function meter(over: Record<string, unknown>) {
  return {
    key: 'k',
    kind: 'counted',
    unit: 'count',
    used: 0,
    peak: 0,
    base: null,
    state: 'unlimited',
    dimensions: {},
    resets_at: '2026-12-01T00:00:00+02:00',
    ...over,
  };
}

function report(meters: unknown[]) {
  apiGet.mockResolvedValue({
    ok: true,
    json: () =>
      Promise.resolve({
        data: { period: '2026-11', current: true, resets_at: '2026-12-01T00:00:00+02:00', meters, periods: ['2026-11', '2026-10'] },
      }),
  });
}

describe('UsagePage', () => {
  beforeEach(() => apiGet.mockReset());

  it('shows each meter against its base and warns when one is past it', async () => {
    report([
      meter({ meter: 'api.calls', key: 'api.calls.per_month', used: 10500, base: 10000, state: 'over', dimensions: { mcp: 10500 } }),
      meter({ meter: 'messages.outbound', used: 420, base: 500, state: 'near', dimensions: { email: 400, webchat: 20 } }),
      meter({ meter: 'storage.bytes', kind: 'snapshot', unit: 'bytes', used: 1610612736, peak: 1610612736, base: 2147483648, state: 'ok', resets_at: null }),
    ]);

    render(<UsagePage />);

    expect(await screen.findByTestId('usage-passed')).toHaveTextContent('nothing is removed');
    expect(screen.getByTestId('usage-api.calls')).toHaveTextContent('10,500 of 10,000');
    expect(screen.getByTestId('usage-api.calls')).toHaveTextContent('500 over');
    expect(screen.getByTestId('usage-messages.outbound')).toHaveTextContent('Email 400 · Web chat 20');
    expect(screen.getByTestId('usage-messages.outbound')).toHaveTextContent('Near');
    expect(screen.getByTestId('usage-storage.bytes')).toHaveTextContent('1.5 GB of 2 GB');
    expect(screen.getByRole('meter', { name: 'API and MCP calls' })).toHaveAttribute('aria-valuenow', '10500');
  });

  it('says there is no limit where the plan sets none', async () => {
    report([meter({ meter: 'seats', kind: 'snapshot', used: 4, peak: 4, resets_at: null })]);

    render(<UsagePage />);

    expect(await screen.findByTestId('usage-seats')).toHaveTextContent('4 used, no limit');
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    expect(screen.queryByTestId('usage-passed')).not.toBeInTheDocument();
    expect(screen.queryByTestId('usage-near')).not.toBeInTheDocument();
  });
});

describe('usage helpers', () => {
  it('writes bytes in powers of 1024', () => {
    expect(formatBytes(0)).toBe('0 bytes');
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(2147483648)).toBe('2 GB');
    expect(formatBytes(15 * 1024 * 1024)).toBe('15 MB');
  });

  it('draws a meter full when past its base, and nothing without one', () => {
    expect(usagePercent({ used: 50, base: 100 })).toBe(50);
    expect(usagePercent({ used: 150, base: 100 })).toBe(100);
    expect(usagePercent({ used: 5, base: null })).toBe(0);
    expect(usageOverBy({ used: 150, base: 100 })).toBe(50);
    expect(usageOverBy({ used: 150, base: null })).toBe(0);
  });

  it('lists named dimensions, largest first', () => {
    expect(shownDimensions({ dimensions: { '': 3, email: 2, sms: 5, whatsapp: 0 } })).toEqual([
      ['sms', 5],
      ['email', 2],
    ]);
  });
});
