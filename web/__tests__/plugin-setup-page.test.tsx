/**
 * WHIT-1148: a plugin's guided setup opens at the first step its status route
 * says is not done, and Back and Next move between the steps.
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PluginSetupPage from '@/app/(protected)/admin/setup/[plugin]/page';
import { api, apiFetch } from '@/lib/api/client';

jest.mock('@/lib/api/client', () => ({
  api: { GET: jest.fn() },
  apiFetch: jest.fn(),
}));
jest.mock('next/navigation', () => ({
  useParams: () => ({ plugin: 'Salons' }),
}));
jest.mock('@/components/plugin/blocks/block-renderer', () => ({
  BlockRenderer: ({ blocks }: { blocks: Array<{ type: string; value?: string }> }) => (
    <div data-testid="blocks">{blocks.map((b) => b.value ?? b.type).join(',')}</div>
  ),
}));

const mockGet = api.GET as jest.Mock;
const mockFetch = apiFetch as jest.Mock;

const SETUP = {
  plugin: 'Salons',
  title: 'Set up your salon',
  description: null,
  status: '/api/v1/salons/onboarding',
  steps: [
    { id: 'business', title: 'Your business', description: null, blocks: [{ type: 'text', value: 'business form' }] },
    { id: 'hours', title: 'Opening hours', description: null, blocks: [{ type: 'text', value: 'hours form' }] },
    { id: 'link', title: 'Your booking link', description: null, blocks: [{ type: 'text', value: 'link step' }] },
  ],
};

describe('PluginSetupPage', () => {
  beforeEach(() => {
    mockGet.mockResolvedValue({ data: { data: [SETUP] } });
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ data: { steps: { business: true, hours: false, link: false } } }) });
  });

  it('opens at the first step not done and moves with Next and Back', async () => {
    const user = userEvent.setup();
    render(<PluginSetupPage />);

    expect(await screen.findByText('hours form')).toBeInTheDocument();
    expect(mockFetch).toHaveBeenCalledWith('/api/v1/salons/onboarding');

    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByText('link step')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Finish' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Back' }));
    expect(await screen.findByText('hours form')).toBeInTheDocument();
  });

  it('says so when the plugin is off or has no setup here', async () => {
    mockGet.mockResolvedValue({ data: { data: [] } });
    render(<PluginSetupPage />);

    expect(await screen.findByText('Nothing to set up')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Go to Settings › Plugins' })).toHaveAttribute('href', '/admin/plugins');
  });
});
