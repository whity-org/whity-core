import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The workspace document theme screen (WHIT-1024; design 3.4,
 * `TplFormPrintStyle`, FP.1-FP.4): what a save sends, and what a free
 * workspace is shown (the plan gate and every form option locked, B
 * headings, the marks still open).
 */

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 10, email: 'amro@example.com', role: '', tenant_id: 1 },
    memberships: [{ tenant_id: 1, tenant_name: 'Example Bakery', role: 'owner' }],
  }),
}));

// The screen calls the API through apiFetch (WHIT-1046); route it to the same mock.
jest.mock('@/lib/api/client', () => ({
  apiFetch: (...args: unknown[]) => mockApiClient(...args),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

const granted = new Set<string>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, hasPermission: (p: string) => granted.has(p) }),
}));

import DocumentThemesPage from '@/app/(protected)/admin/document-themes/page';
import {
  changedKeys,
  contrastOnWhite,
  formValue,
  newThemeKey,
  savePayload,
  type FormOptionSchema,
  type ThemeSchema,
} from '@/app/(protected)/admin/document-themes/model';

const opt = (key: string, values: [string, string][]): FormOptionSchema => ({
  key: `form.${key}`,
  standard: values[0][0],
  label: key,
  labelAr: key,
  values: values.map(([value, label]) => ({ value, label, labelAr: label })),
});

function schema(allowed: boolean): ThemeSchema {
  return {
    tokens: [
      {
        key: 'accentUse',
        type: 'enum',
        overridable: false,
        label: 'Print colour on',
        labelAr: 'لون الطباعة على',
        values: [
          { value: 'title', label: 'The title only', labelAr: 'العنوان فقط' },
          { value: 'title-and-headings', label: 'The title and headings', labelAr: 'العنوان والعناوين الفرعية' },
        ],
      },
    ],
    form: {
      entitlement: 'documents.form_printouts',
      allowed,
      options: [
        { ...opt('officeDetails', [['decisionLine', 'In the decision line'], ['strip', 'A separate strip']]), label: 'Office details' },
        {
          ...opt('sectionStyle', [
            ['mark', 'B · Whity mark'],
            ['number', 'A · Number'],
            ['eyebrow', 'C · Eyebrow'],
            ['rail', 'D · Rail'],
            ['tab', 'E · Tab'],
            ['plane', 'F · Plane'],
          ]),
          label: 'Section headings',
        },
      ],
      footer: [
        { key: 'reference', label: 'Reference number', labelAr: 'الرقم المرجعي', standard: true },
        { key: 'pageCount', label: 'Page x of y', labelAr: 'الصفحة x من y', standard: true, fixed: true },
        { key: 'qrEveryPage', label: 'Small QR on every page', labelAr: 'رمز QR صغير في كل صفحة', standard: false },
      ],
      marks: { max: 3, heightMm: 8 },
    },
  };
}

const THEME = {
  ref: 'tenant.workspace',
  scope: 'tenant',
  key: 'workspace',
  name: 'Example Bakery',
  name_ar: 'المخبز',
  tokens: { accent: '#b74c05', form: { sectionStyle: 'eyebrow' } },
  version: 3,
  archived: false,
  default_for: ['workspace'],
  resolved: { tokens: { accent: '#b74c05' } },
};

function respond(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    blob: () => Promise.resolve(new Blob()),
  });
}

function serve(allowed: boolean, subscription: unknown = { status: 'active' }) {
  mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (init?.method === 'PATCH') return respond(200, { data: THEME });
    if (url === '/api/v1/document-themes/schema') return respond(200, { data: schema(allowed) });
    if (url === '/api/v1/document-themes') {
      return respond(200, {
        data: [{ ...THEME, ref: 'whity.standard', scope: 'system', default_for: [] }, { ...THEME, resolved: undefined }],
      });
    }
    if (url === '/api/v1/document-themes/tenant.workspace') return respond(200, { data: THEME });
    if (url === '/api/v1/subscription') return respond(200, { data: subscription });
    if (url === '/api/v1/document-themes/form-printout-preview') return respond(404, { error: 'none yet' });
    return respond(404, {});
  });
}

beforeEach(() => {
  mockApiClient.mockReset();
  addToast.mockReset();
  granted.clear();
  granted.add('documents:read');
  granted.add('documents:themes');
});

describe('the save payload', () => {
  it('PATCHes the whole token set with the version as If-Match', () => {
    const { url, init } = savePayload({ ref: 'tenant.workspace', version: 3 }, { accent: '#b74c05', form: { officeDetails: 'strip' } });
    expect(url).toBe('/api/v1/document-themes/tenant.workspace');
    expect(init.method).toBe('PATCH');
    expect(init.headers['If-Match']).toBe('"3"');
    expect(JSON.parse(init.body)).toEqual({ tokens: { accent: '#b74c05', form: { officeDetails: 'strip' } } });
  });

  it('names what changed, by schema key', () => {
    const s = schema(true);
    expect(changedKeys({ form: { sectionStyle: 'eyebrow' } }, { form: { sectionStyle: 'tab', footer: { qrEveryPage: true } } }, s)).toEqual([
      'form.sectionStyle',
      'form.footer',
    ]);
  });

  it('shows the standard when locked, whatever is stored', () => {
    const option = schema(false).form.options[1];
    expect(formValue({ form: { sectionStyle: 'eyebrow' } }, option, true)).toBe('mark');
    expect(formValue({ form: { sectionStyle: 'eyebrow' } }, option, false)).toBe('eyebrow');
  });
});

describe('the Form printouts tab', () => {
  it('saves a changed option with the rest of the theme, after the save bar names it', async () => {
    serve(true);
    const user = userEvent.setup();
    render(<DocumentThemesPage />);

    await screen.findByRole('heading', { name: 'Example Bakery', level: 2 });
    await user.click(screen.getByRole('tab', { name: 'Form printouts' }));
    expect(screen.getByRole('radio', { name: /C · Eyebrow/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByText('Paid plans')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'A separate strip' }));
    expect(await screen.findByText('You changed office details.')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(mockApiClient.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(true));
    const [url, init] = mockApiClient.mock.calls.find(([, i]) => i?.method === 'PATCH') as [string, RequestInit];
    expect(url).toBe('/api/v1/document-themes/tenant.workspace');
    expect((init.headers as Record<string, string>)['If-Match']).toBe('"3"');
    expect(JSON.parse(init.body as string)).toEqual({
      tokens: { accent: '#b74c05', form: { sectionStyle: 'eyebrow', officeDetails: 'strip' } },
    });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Theme saved', 'success'));
  });

  it('says how long a trial has left', async () => {
    const end = new Date(Date.now() + 9 * 86_400_000 - 60_000).toISOString();
    serve(true, { status: 'trialing', current_period_end: end });
    const user = userEvent.setup();
    render(<DocumentThemesPage />);
    await user.click(await screen.findByRole('tab', { name: 'Form printouts' }));
    expect(await screen.findByText('Free trial · 9 days left')).toBeInTheDocument();
  });

  it('locks every option on a free workspace, with the plan gate and B headings', async () => {
    serve(false);
    const user = userEvent.setup();
    render(<DocumentThemesPage />);
    await user.click(await screen.findByRole('tab', { name: 'Form printouts' }));

    const gate = await screen.findByRole('region', { name: 'Your own form printouts' });
    expect(within(gate).getByRole('link', { name: 'See paid plans' })).toHaveAttribute('href', '/billing');
    expect(screen.getAllByText('Paid plans').length).toBeGreaterThan(1);

    // What a free workspace prints: the standard, not the stored eyebrow.
    expect(screen.getByRole('radio', { name: /B · Whity mark/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: /C · Eyebrow/ })).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('button', { name: 'In the decision line' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'A separate strip' })).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: 'Small QR on every page' })).toBeDisabled();

    // Marks print on every plan.
    expect(screen.getByRole('button', { name: 'Add a mark' })).toBeEnabled();
  });

  it('offers to make the workspace theme while it uses Whity’s standard', async () => {
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/document-themes/schema') return respond(200, { data: schema(true) });
      if (url === '/api/v1/document-themes' && !init) {
        return respond(200, { data: [{ ...THEME, ref: 'whity.standard', scope: 'system', default_for: ['workspace'] }] });
      }
      if (url === '/api/v1/document-themes' && init?.method === 'POST') return respond(201, { data: THEME });
      if (url === '/api/v1/settings') return respond(200, { data: {} });
      return respond(403, {});
    });
    const user = userEvent.setup();
    render(<DocumentThemesPage />);

    await user.click(await screen.findByRole('button', { name: 'Make the workspace theme' }));
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/settings', expect.objectContaining({ method: 'PATCH' })));
    const created = mockApiClient.mock.calls.find(([u, i]) => u === '/api/v1/document-themes' && i?.method === 'POST') as [string, RequestInit];
    expect(JSON.parse(created[1].body as string)).toMatchObject({ key: 'workspace', name: 'Example Bakery', tokens: {} });
    const setting = mockApiClient.mock.calls.find(([u, i]) => u === '/api/v1/settings' && i?.method === 'PATCH') as [string, RequestInit];
    expect(JSON.parse(setting[1].body as string)).toEqual({ settings: { 'documents.theme': 'tenant.workspace' } });
  });
});

describe('the theme list (TplDocThemes, WHIT-785)', () => {
  const OTHER = {
    ...THEME,
    ref: 'tenant.winter',
    key: 'winter',
    name: 'Winter',
    name_ar: 'الشتاء',
    extends: 'whity.standard',
    tokens: { accent: '#2f3a45' },
    default_for: [],
    resolved: { tokens: { accent: '#2f3a45' } },
  };

  function serveList(extra: (url: string, init?: RequestInit) => Promise<unknown> | null = () => null) {
    mockApiClient.mockImplementation((url: string, init?: RequestInit) => {
      const own = extra(url, init);
      if (own) return own;
      if (url === '/api/v1/document-themes/schema') {
        const s = schema(true);
        s.tokens.unshift({ key: 'accent', type: 'color', overridable: true, label: 'Print colour', labelAr: 'لون الطباعة' });
        return respond(200, { data: s });
      }
      if (url === '/api/v1/document-themes' && !init) {
        return respond(200, {
          data: [
            { ...THEME, ref: 'whity.standard', scope: 'system', key: 'standard', name: 'Whity standard', default_for: [], resolved: undefined },
            { ...THEME, extends: 'whity.standard', resolved: undefined },
            { ...OTHER, resolved: undefined },
          ],
        });
      }
      if (url === '/api/v1/document-themes/tenant.workspace') return respond(200, { data: { ...THEME, extends: 'whity.standard' } });
      if (url === '/api/v1/document-themes/tenant.winter') return respond(200, { data: OTHER });
      if (url === '/api/v1/document-themes/whity.standard') {
        return respond(200, {
          data: { ...THEME, ref: 'whity.standard', scope: 'system', name: 'Whity standard', tokens: { accent: '#0961bb' }, default_for: [], resolved: { tokens: { accent: '#0961bb' } } },
        });
      }
      if (url === '/api/v1/document-themes/tenant.workspace/usage') {
        return respond(200, { data: { drafts: [], published: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }], defaults: ['workspace'] } });
      }
      if (url === '/api/v1/settings' && !init) {
        return respond(200, { data: { effective: { 'company.address': 'Mecca Street 112, Amman', 'company.tax_id': '0123456' } } });
      }
      return respond(404, {});
    });
  }

  it('lists Whity standard and the workspace’s themes, the default marked and open', async () => {
    serveList();
    render(<DocumentThemesPage />);

    expect(await screen.findByRole('heading', { name: 'Document themes', level: 1 })).toBeInTheDocument();
    const list = screen.getByRole('navigation', { name: 'Document themes' });
    expect(within(list).getByRole('button', { name: /^Whity standard.*can’t be changed/ })).toBeInTheDocument();
    expect(within(list).getByRole('button', { name: /Example Bakery.*Workspace default · based on Whity standard/ })).toHaveAttribute(
      'aria-current',
      'true'
    );
    expect(within(list).getByRole('button', { name: /Winter.*Based on Whity standard/ })).toBeInTheDocument();
    expect(await screen.findByRole('heading', { name: 'Example Bakery', level: 2 })).toBeInTheDocument();
  });

  it('says how the print colour reads on paper and previews a quote with the letterhead', async () => {
    serveList();
    render(<DocumentThemesPage />);

    expect(await screen.findByText('Reads on white (5.1:1)')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'An example quote printed with this theme' })).toHaveTextContent(
      'Mecca Street 112, Amman · Tax no. 0123456'
    );
  });

  it('says which published templates change at their next publish', async () => {
    serveList();
    render(<DocumentThemesPage />);

    expect(await screen.findByText('Used by 4 published templates')).toBeInTheDocument();
    expect(screen.getByText('They change at their next publish. Issued documents never change.')).toBeInTheDocument();
  });

  it('opens Whity standard read-only, and another theme with an offer to make it the default', async () => {
    serveList();
    const user = userEvent.setup();
    render(<DocumentThemesPage />);

    await user.click(await screen.findByRole('button', { name: /^Whity standard/ }));
    expect(await screen.findByText('Whity’s standard theme can’t be changed')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Winter/ }));
    expect(await screen.findByRole('heading', { name: 'Winter', level: 2 })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Make it the workspace default' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/settings', expect.objectContaining({ method: 'PATCH' }))
    );
    const setting = mockApiClient.mock.calls.find(([u, i]) => u === '/api/v1/settings' && i?.method === 'PATCH') as [string, RequestInit];
    expect(JSON.parse(setting[1].body as string)).toEqual({ settings: { 'documents.theme': 'tenant.winter' } });
  });

  it('makes a new theme based on the workspace default and opens it', async () => {
    serveList((url, init) => (url === '/api/v1/document-themes' && init?.method === 'POST' ? respond(201, { data: {} }) : null));
    const user = userEvent.setup();
    render(<DocumentThemesPage />);

    await user.click(await screen.findByRole('button', { name: 'New theme' }));
    await user.type(screen.getByRole('textbox', { name: 'Name of the new theme' }), 'Summer offers');
    await user.click(screen.getByRole('button', { name: 'Make the theme' }));

    await waitFor(() => expect(mockApiClient.mock.calls.some(([u, i]) => u === '/api/v1/document-themes' && i?.method === 'POST')).toBe(true));
    const created = mockApiClient.mock.calls.find(([u, i]) => u === '/api/v1/document-themes' && i?.method === 'POST') as [string, RequestInit];
    expect(JSON.parse(created[1].body as string)).toEqual({
      key: 'summer_offers',
      name: 'Summer offers',
      name_ar: 'Summer offers',
      // A copy of the workspace default to start from; every theme extends Whity's standard.
      tokens: THEME.tokens,
      extends: 'whity.standard',
    });
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/document-themes/tenant.summer_offers'));
  });
});

describe('the contrast note', () => {
  it('reads a hex colour against white', () => {
    expect(contrastOnWhite('#b74c05')).toBe(5.1);
    expect(contrastOnWhite('#ffffff')).toBe(1);
    expect(contrastOnWhite('#000000')).toBe(21);
    expect(contrastOnWhite('var(--primary)')).toBeNull();
    expect(newThemeKey('Summer offers!', [])).toBe('summer_offers');
    expect(newThemeKey('Summer offers', ['summer_offers'])).toBe('summer_offers_2');
    expect(newThemeKey('عرض', [])).toBe('theme');
  });
});
