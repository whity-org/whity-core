/**
 * Settings › Branding (TplAdminBranding): one form with one Save, a live
 * preview, logos that upload at once, and the two layers (a workspace's own
 * branding; the system tenant's platform defaults and web address).
 *
 * The branding-upload helpers, the API client and the hooks are mocked, so no
 * server is needed.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockUploadBrandingAsset = jest.fn();
const mockClearBrandingAsset = jest.fn();
const mockSetBrandingHost = jest.fn();
jest.mock('@/lib/api/branding-upload', () => ({
  uploadBrandingAsset: (...args: unknown[]) => mockUploadBrandingAsset(...args),
  clearBrandingAsset: (...args: unknown[]) => mockClearBrandingAsset(...args),
  setBrandingHost: (...args: unknown[]) => mockSetBrandingHost(...args),
}));

const mockApiGet = jest.fn();
const mockApiPatch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
  },
}));

// After a save the app's branding (and so the theme) is re-read in the
// browser, not by a router refresh: the server render cannot see the session
// (cookies are Path=/api) and would theme by host (#1716).
const mockRefresh = jest.fn(() => Promise.resolve());
jest.mock('@/lib/branding-context', () => ({
  useRefreshBranding: () => mockRefresh,
}));

let mockMode: 'light' | 'dark' = 'light';
jest.mock('@/lib/theme-mode-context', () => ({
  useThemeMode: () => ({ resolved: mockMode, preference: mockMode, setPreference: jest.fn(), toggle: jest.fn() }),
}));

// The first-visit scene is a Lottie moment; it has nothing to do with the form.
jest.mock('@/components/first-visit-scene', () => ({ FirstVisitScene: () => null }));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { BrandingSettings } from '@/components/branding-settings';
import type { Branding } from '@/lib/api/branding-upload';

const PLAIN: Branding = {
  siteName: 'Acme',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
  mainColor: null,
  theme: null,
  builtOnWhity: true,
};
const WITH_WIDE: Branding = { ...PLAIN, logoWideUrl: '/api/v1/branding/asset/1/logo_wide-abc.png' };
const ORANGE: Branding = { ...PLAIN, mainColor: '#B74C05' };

function serve(branding: Branding = PLAIN) {
  mockApiGet.mockImplementation(() => Promise.resolve({ data: { data: branding }, error: undefined }));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockMode = 'light';
  serve();
  mockUploadBrandingAsset.mockResolvedValue(WITH_WIDE);
  mockClearBrandingAsset.mockResolvedValue(PLAIN);
  mockSetBrandingHost.mockResolvedValue(undefined);
  mockApiPatch.mockResolvedValue({ data: { data: {} }, error: undefined });
});

async function renderForm(props: Partial<React.ComponentProps<typeof BrandingSettings>> = {}) {
  render(<BrandingSettings scope="tenant" canWrite {...props} />);
  await screen.findByDisplayValue('Acme');
}

describe('Branding — the card', () => {
  it('has the card’s parts: product name, main colour, logos, favicon, Built on Whity and a preview', async () => {
    await renderForm();
    expect(screen.getByRole('heading', { name: 'Your brand' })).toBeInTheDocument();
    expect(screen.getByLabelText('Product name')).toHaveValue('Acme');
    expect(screen.getByTestId('branding-main-colour-tenant')).toBeInTheDocument();
    for (const key of ['logo_wide', 'logo_square', 'favicon']) {
      expect(screen.getByTestId(`branding-upload-btn-${key}-tenant`)).toBeInTheDocument();
    }
    expect(screen.getByTestId('branding-built-on-whity')).toBeChecked();
    expect(screen.getByRole('heading', { name: 'Preview' })).toBeInTheDocument();
    expect(screen.getByTestId('branding-preview-name')).toHaveTextContent('Acme');
  });

  it('has one Save, in the header, off until something changes', async () => {
    await renderForm();
    const save = screen.getByTestId('branding-save');
    expect(save).toHaveAttribute('data-variant', 'default');
    expect(save).toBeDisabled();
    // Logos and the colour have no Save of their own any more.
    expect(screen.queryByText('Save colour')).not.toBeInTheDocument();
    expect(screen.getAllByRole('button').filter((b) => b.getAttribute('data-variant') === 'default' && !b.closest('[inert]'))).toHaveLength(1);
  });
});

describe('Branding — save', () => {
  it('saves the name and the colour together to the workspace', async () => {
    await renderForm();
    fireEvent.change(screen.getByLabelText('Product name'), { target: { value: 'Example Office' } });
    fireEvent.click(screen.getByTestId('branding-colour-swatch-b74c05'));
    fireEvent.click(screen.getByTestId('branding-save'));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith('/api/v1/settings', {
        body: { settings: { site_name: 'Example Office', branding_main_color: '#b74c05' } },
      })
    );
    expect(mockRefresh).toHaveBeenCalled();
    expect(addToast).toHaveBeenCalledWith('Branding saved.', 'success');
    expect(mockSetBrandingHost).not.toHaveBeenCalled();
  });

  it('sends only what changed', async () => {
    await renderForm();
    fireEvent.click(screen.getByTestId('branding-colour-swatch-00792f'));
    fireEvent.click(screen.getByTestId('branding-save'));
    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith('/api/v1/settings', {
        body: { settings: { branding_main_color: '#00792f' } },
      })
    );
  });

  it('Reset clears the colour, back to the default', async () => {
    serve(ORANGE);
    await renderForm();
    expect(screen.getByTestId('branding-colour-value-tenant')).toHaveTextContent('#b74c05');
    fireEvent.click(screen.getByTestId('branding-colour-reset-tenant'));
    fireEvent.click(screen.getByTestId('branding-save'));
    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith('/api/v1/settings', {
        body: { settings: { branding_main_color: '' } },
      })
    );
  });

  it('shows the server’s reason and keeps the draft when the save fails', async () => {
    mockApiPatch.mockResolvedValue({ data: undefined, error: { error: 'branding_main_color must be a #RRGGBB hex colour' } });
    await renderForm();
    fireEvent.click(screen.getByTestId('branding-colour-swatch-c53829'));
    fireEvent.click(screen.getByTestId('branding-save'));
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('branding_main_color must be a #RRGGBB hex colour', 'error')
    );
    expect(mockRefresh).not.toHaveBeenCalled();
    expect(screen.getByTestId('branding-colour-value-tenant')).toHaveTextContent('#c53829');
  });
});

describe('Branding — the preview', () => {
  it('re-themes as the colour is picked, before anything is saved', async () => {
    await renderForm();
    const preview = screen.getByTestId('branding-live-preview');
    expect(preview.style.getPropertyValue('--primary')).toBe('#0961bb');
    fireEvent.click(screen.getByTestId('branding-colour-swatch-b74c05'));
    expect(preview.style.getPropertyValue('--primary')).toBe('#b74c05');
    expect(mockApiPatch).not.toHaveBeenCalled();
  });

  it('uses the colour on dark in dark mode (decision 2.8)', async () => {
    mockMode = 'dark';
    serve(ORANGE);
    await renderForm();
    expect(screen.getByTestId('branding-live-preview').style.getPropertyValue('--primary')).toBe('#e97c47');
  });

  it('follows the name as it is typed, and cannot be focused', async () => {
    await renderForm();
    fireEvent.change(screen.getByLabelText('Product name'), { target: { value: 'Example Office' } });
    expect(screen.getByTestId('branding-preview-name')).toHaveTextContent('Example Office');
    expect(screen.getByTestId('branding-live-preview')).toHaveAttribute('inert');
  });

  it('hides Built on Whity when the plan removes it', async () => {
    serve({ ...PLAIN, builtOnWhity: false });
    await renderForm();
    expect(screen.getByTestId('branding-built-on-whity')).not.toBeChecked();
    expect(screen.getByText('Hidden: your plan includes removing it.')).toBeInTheDocument();
  });
});

describe('Branding — logos', () => {
  it('uploads a logo the moment a file is picked', async () => {
    await renderForm();
    const file = new File(['png'], 'logo.png', { type: 'image/png' });
    fireEvent.change(screen.getByTestId('branding-file-input-logo_wide-tenant'), { target: { files: [file] } });
    await waitFor(() => expect(mockUploadBrandingAsset).toHaveBeenCalledWith('tenant', 'logo_wide', file));
    expect(addToast).toHaveBeenCalledWith('Wide logo uploaded successfully.', 'success');
    expect(await screen.findByTestId('branding-clear-btn-logo_wide-tenant')).toBeInTheDocument();
    expect(mockRefresh).toHaveBeenCalled();
  });

  it('clears a logo', async () => {
    serve(WITH_WIDE);
    await renderForm();
    fireEvent.click(await screen.findByTestId('branding-clear-btn-logo_wide-tenant'));
    await waitFor(() => expect(mockClearBrandingAsset).toHaveBeenCalledWith('tenant', 'logo_wide'));
    await waitFor(() => expect(screen.queryByTestId('branding-clear-btn-logo_wide-tenant')).not.toBeInTheDocument());
  });
});

describe('Branding — workspace and platform', () => {
  it('a workspace cannot set its web address; it is told who can', async () => {
    await renderForm();
    expect(screen.queryByTestId('branding-custom-host-input')).not.toBeInTheDocument();
    expect(screen.getByTestId('branding-domain-note')).toBeInTheDocument();
  });

  it('the system tenant edits the platform defaults and the web address', async () => {
    await renderForm({ scope: 'global' });
    expect(screen.getByRole('heading', { name: 'Platform brand' })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Domain'), { target: { value: 'app.example.com' } });
    fireEvent.click(screen.getByTestId('branding-colour-swatch-643b9a'));
    fireEvent.click(screen.getByTestId('branding-save'));
    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith('/api/v1/settings/global', {
        body: { settings: { branding_main_color: '#643b9a' } },
      })
    );
    expect(mockSetBrandingHost).toHaveBeenCalledWith(0, 'app.example.com');
  });

  it('the platform name cannot be emptied', async () => {
    await renderForm({ scope: 'global' });
    fireEvent.change(screen.getByLabelText('Product name'), { target: { value: '  ' } });
    fireEvent.click(screen.getByTestId('branding-save'));
    expect(addToast).toHaveBeenCalledWith('The product name cannot be empty.', 'error');
    expect(mockApiPatch).not.toHaveBeenCalled();
  });

  it('platform logos upload to the global layer', async () => {
    await renderForm({ scope: 'global' });
    const file = new File(['ico'], 'favicon.ico', { type: 'image/x-icon' });
    fireEvent.change(screen.getByTestId('branding-file-input-favicon-global'), { target: { files: [file] } });
    await waitFor(() => expect(mockUploadBrandingAsset).toHaveBeenCalledWith('global', 'favicon', file));
  });
});

describe('Branding — read-only', () => {
  it('shows the branding with no Save, Upload or Clear', async () => {
    serve(WITH_WIDE);
    await renderForm({ canWrite: false });
    expect(screen.queryByTestId('branding-save')).not.toBeInTheDocument();
    expect(screen.queryByTestId('branding-upload-btn-logo_wide-tenant')).not.toBeInTheDocument();
    expect(screen.queryByTestId('branding-clear-btn-logo_wide-tenant')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Product name')).toBeDisabled();
    expect(screen.getByTestId('branding-colour-swatch-0961bb')).toBeDisabled();
    expect(screen.getByText('You can see the branding but not change it.')).toBeInTheDocument();
  });
});
