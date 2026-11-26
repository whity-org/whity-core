/**
 * WHIT-1429 (decisions WT.1-WT.3): what a template adds, in plain words; the
 * update notice for a template the workspace uses; and See what's new, where
 * each changed item can take the template's version and Apply update adds
 * only the new pieces.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
async function viaRouter(
  method: string,
  template: string,
  options?: { params?: { path?: Record<string, unknown> }; body?: unknown }
) {
  const url = template.replace(/\{(\w+)\}/g, (_m, k: string) => String(options?.params?.path?.[k]));
  const res = (await (method === 'GET' ? mockApiClient(url) : mockApiClient(url, { method, body: JSON.stringify(options?.body) }))) as {
    ok: boolean;
    status: number;
    json: () => Promise<unknown>;
  };
  const body = await res.json();
  return { data: res.ok ? body : undefined, error: res.ok ? undefined : body, response: new Response(null, { status: res.status }) };
}
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...a: [string, never]) => viaRouter('GET', ...a),
    POST: (...a: [string, never]) => viaRouter('POST', ...a),
  },
}));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
jest.mock('@/hooks/useCapabilities', () => ({ useCapabilities: () => ({ hasPermission: () => true, loading: false }) }));
jest.mock('@/lib/branding-context', () => ({ useHomePath: () => '/dashboard' }));
const mockPush = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push: mockPush }), useParams: () => ({ key: 'clinic' }) }));
jest.mock('next/link', () => ({
  __esModule: true,
  default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));

import { TemplatePlan } from '@/components/workspace-templates/template-plan';
import { TemplateUpdateNotices } from '@/components/workspace-templates/template-updates';
import TemplateUpdatePage from '@/app/(protected)/setup/template/[key]/update/page';

const json = (body: unknown, status = 200) => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

const clinicCard = (update: unknown) => ({
  key: 'clinic',
  name: 'Clinic',
  summary: 'Appointments and a patient intake form.',
  setsUp: ['Visits you confirm'],
  hasExamples: true,
  hasSetup: true,
  plugin: 'ClinicTemplate',
  offer: null,
  theme: null,
  update,
});

const list = (update: unknown, chosen = ['clinic']) => ({
  data: { templates: [clinicCard(update), { ...clinicCard(null), key: 'blank', name: 'Start blank' }], chosen, offerPicker: false, examples: 0 },
});

const update = { from: 1, to: 2, parts: [{ key: 'template:clinic', from: 1, to: 2 }], changes: [{ version: 2, summary: 'A doctor reviews each intake' }] };

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
  mockPush.mockReset();
});

describe('TemplatePlan', () => {
  it('names each item and says what happens to it, never a key or a raw error', () => {
    render(
      <TemplatePlan
        items={[
          { part: 'services', key: 'consultation', action: 'add', label: 'Consultation' },
          { part: 'settings', key: 'timezone', action: 'add' },
          { part: 'roles', key: 'reception', action: 'keep', label: 'Reception' },
          { part: 'pipelines', key: 'intake-review', action: 'skip', label: 'Intake review', reason: 'You may not design review routes, so this one was left out.' },
          { part: 'documents', key: 'price-list', action: 'skip', label: 'Price list', reason: 'SQLSTATE[23505]: something broke' },
        ]}
      />
    );

    expect(screen.getByText('Consultation')).toBeInTheDocument();
    expect(screen.getByText('Time zone')).toBeInTheDocument();
    expect(screen.getByText('Already there: kept as you have it')).toBeInTheDocument();
    expect(screen.getByText('Can’t add: you don’t have permission for this.')).toBeInTheDocument();
    expect(screen.getByText('Can’t add this now.')).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE|consultation|intake-review/)).not.toBeInTheDocument();
    expect(screen.getByText('Review routes')).toBeInTheDocument();
  });

  it('says when there is nothing to add', () => {
    render(<TemplatePlan items={[{ part: 'services', key: 'consultation', action: 'done', label: 'Consultation' }]} only={['add']} />);

    expect(screen.getByText('Nothing to add: your workspace already has all of it.')).toBeInTheDocument();
  });
});

describe('TemplateUpdateNotices', () => {
  it('shows a notice for a chosen template with a new version, and none otherwise', async () => {
    mockApiClient.mockImplementation(() => json(list(update)));
    const { unmount } = render(<TemplateUpdateNotices />);

    expect(await screen.findByText('Your Clinic template has new pieces.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'See what’s new' })).toHaveAttribute('href', '/setup/template/clinic/update');
    unmount();

    mockApiClient.mockImplementation(() => json(list(null)));
    const { container } = render(<TemplateUpdateNotices />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(2));
    expect(container).toBeEmptyDOMElement();
  });
});

describe('See what’s new', () => {
  function serve(items: unknown[]) {
    mockApiClient.mockImplementation((url: string, init?: { method?: string }) => {
      if (url === '/api/v1/workspace/templates') return json(list(update));
      if (url === '/api/v1/workspace/templates/clinic/preview') return json({ data: { template: 'clinic', version: 2, appliedVersion: 1, made: {}, skipped: [], items } });
      if (url === '/api/v1/workspace/templates/clinic/items/services/consultation/adopt' && init?.method === 'POST') {
        return json({ data: { template: 'clinic', part: 'services', key: 'consultation', action: 'add' } });
      }
      if (url === '/api/v1/workspace/templates/clinic/apply') return json({ data: { template: 'clinic', made: {}, skipped: [], items: [] } });
      return json({ data: {} });
    });
  }

  it('lists what is new, what it adds and each changed item, and asks for neither examples nor the look', async () => {
    serve([
      { part: 'pipelines', key: 'intake-review', action: 'add', label: 'Intake review' },
      { part: 'services', key: 'consultation', action: 'changed', label: 'Consultation' },
      { part: 'services', key: 'check-up', action: 'done', label: 'Check-up' },
    ]);
    render(<TemplateUpdatePage />);

    expect(await screen.findByRole('heading', { name: 'What’s new in Clinic' })).toBeInTheDocument();
    expect(screen.getByText('A doctor reviews each intake')).toBeInTheDocument();
    expect(screen.getByText('Intake review')).toBeInTheDocument();
    expect(screen.getByText('Consultation')).toBeInTheDocument();
    expect(screen.queryByText('Check-up')).not.toBeInTheDocument();
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/workspace/templates/clinic/preview',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ examples: false, theme: false }) })
    );
  });

  it('takes the template’s version of one item when asked', async () => {
    serve([{ part: 'services', key: 'consultation', action: 'changed', label: 'Consultation' }]);
    render(<TemplateUpdatePage />);

    const row = (await screen.findByText('Consultation')).closest('li') as HTMLElement;
    expect(within(row).getByText('Takes its name, length and price')).toBeInTheDocument();
    await userEvent.click(within(row).getByRole('button', { name: 'Use the template’s version' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/workspace/templates/clinic/items/services/consultation/adopt', expect.objectContaining({ method: 'POST' }))
    );
    expect(addToast).toHaveBeenCalledWith('Consultation now uses the template’s version.', 'success');
  });

  it('applies the update with examples and the look left out, then goes home', async () => {
    serve([{ part: 'pipelines', key: 'intake-review', action: 'add', label: 'Intake review' }]);
    render(<TemplateUpdatePage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Apply update' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/workspace/templates/clinic/apply',
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ examples: false, theme: false }) })
      )
    );
    expect(addToast).toHaveBeenCalledWith('Your Clinic template is up to date.', 'success');
    expect(mockPush).toHaveBeenCalledWith('/dashboard');
  });
});
