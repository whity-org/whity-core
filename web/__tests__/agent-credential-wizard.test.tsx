/**
 * WHIT-1040 (TplAgentCredential): the four-step key for an unattended agent.
 *
 *  - the starting grant reads before any key exists, and a permission the
 *    person lacks is shown Off and can't be changed, with a note;
 *  - only the rows moved off their default are sent as `grant:` entries;
 *  - a refusal above the issuer is shown on the grant step and issues nothing;
 *  - the key shows once, and Done waits for "I've put the key somewhere safe".
 */

import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { startingEntries } from '@/components/agents/agent-credential-wizard';
import type { GrantRow } from '@/components/agents/agent-grant';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, email: 'amro@examplebakery.jo', display_name: 'Amro Saleh', role: 'Owner', tenant_id: 1 } }),
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

import NewAgentKeyPage from '@/app/(protected)/admin/ai-principals/new-agent-key/page';

const json = (status: number, body: unknown) =>
  Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });

function row(permission: string, overrides: Partial<GrantRow> = {}): GrantRow {
  return { permission, mode: null, modes: ['off', 'ask', 'allow'], withheld: {}, effective: { read: 'allow', write: null }, ...overrides };
}

const starting = {
  scope: ['tools:call', 'resources:read', 'prompts:read'],
  permissions: [
    row('stock:read'),
    row('orders:send', {
      modes: ['off', 'ask'],
      effective: { read: null, write: 'ask' },
      withheld: { allow: { reason: 'always_asks', message: 'x' } },
    }),
  ],
  notHeld: ['payments:make'],
};

const issued = {
  jti: 'jti-1',
  token: 'eyJ.secret.key',
  name: 'Nightly stock check',
  scope: [],
  agent: true,
  expires_at: '2027-02-13T00:00:00+00:00',
};

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
  push.mockReset();
});

async function toGrantStep() {
  render(<NewAgentKeyPage />);
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Nightly stock check' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  expect(screen.getByText('You, Amro Saleh')).toBeInTheDocument();
  expect(screen.getByText('Owner · it can never do more than you can')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await screen.findByRole('radiogroup', { name: 'Stock · Read' });
}

describe('startingEntries', () => {
  it('sends only the rows moved off their default', () => {
    expect(startingEntries(starting.permissions, { 'stock:read': 'allow', 'orders:send': 'off' })).toEqual(['grant:orders:send=off']);
    expect(startingEntries(starting.permissions, {})).toEqual([]);
  });
});

describe('New key for an unattended agent', () => {
  it('needs a name before going on', () => {
    mockApiClient.mockReturnValue(json(200, { data: starting }));
    render(<NewAgentKeyPage />);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByText('Give it a name, so people know what it does.')).toBeInTheDocument();
  });

  it('starts at the default, and what the person lacks is Off and fixed', async () => {
    mockApiClient.mockReturnValue(json(200, { data: starting }));
    await toGrantStep();
    expect(within(screen.getByRole('radiogroup', { name: 'Stock · Read' })).getByRole('radio', { checked: true })).toHaveTextContent('Allowed');
    const send = screen.getByRole('radiogroup', { name: 'Orders · Send' });
    expect(within(send).getByRole('radio', { checked: true })).toHaveTextContent('Ask first');
    expect(within(send).getByRole('radio', { name: 'Allowed' })).toBeDisabled();
    const pay = screen.getByRole('radiogroup', { name: 'Payments · Make' });
    expect(within(pay).getByRole('radio', { checked: true })).toHaveTextContent('Off');
    expect(within(pay).getByRole('radio', { name: 'Ask first' })).toBeDisabled();
    expect(screen.getByText('You can’t give “Payments: make”')).toBeInTheDocument();
  });

  it('makes the key with the changed rows, shows it once, and Done waits for the tick', async () => {
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'POST') {
        expect(url).toBe('/api/v1/mcp/tokens');
        expect(JSON.parse(String(init.body))).toEqual({
          name: 'Nightly stock check',
          agent: true,
          scope: ['tools:call', 'resources:read', 'prompts:read', 'grant:stock:read=ask'],
        });
        return json(201, issued);
      }
      return json(200, { data: starting });
    });
    await toGrantStep();
    fireEvent.click(within(screen.getByRole('radiogroup', { name: 'Stock · Read' })).getByRole('radio', { name: 'Ask first' }));
    fireEvent.click(screen.getByRole('button', { name: 'Make the key' }));
    expect(await screen.findByText('eyJ.secret.key')).toBeInTheDocument();
    const done = screen.getByRole('button', { name: 'Done' });
    expect(done).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox'));
    expect(done).toBeEnabled();
    fireEvent.click(done);
    expect(push).toHaveBeenCalledWith('/admin/ai-principals');
  });

  it('shows a refusal above the issuer on the grant step and issues nothing', async () => {
    mockApiClient.mockImplementation((_url: string, init?: RequestInit) =>
      init?.method === 'POST'
        ? json(422, { error: "stock:read: You don't have this permission", code: 'grant_above_issuer' })
        : json(200, { data: starting }),
    );
    await toGrantStep();
    fireEvent.click(screen.getByRole('button', { name: 'Make the key' }));
    expect(await screen.findByText('That’s more than you can give')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Make the key' })).toBeInTheDocument();
    expect(screen.queryByText('Copy the key now')).not.toBeInTheDocument();
  });
});
