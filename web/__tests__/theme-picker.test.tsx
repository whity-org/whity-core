/**
 * WHIT-1225 (TH.13, TH.14): the free theme picker lists installed themes,
 * applies one through the branding_theme setting and says in words what the
 * guard rails changed.
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ThemePicker, adjustmentText, changeText, fontStack, overridesValue } from '@/components/theme-picker';

const GET = jest.fn();
const PATCH = jest.fn();
const POST = jest.fn();
const DELETE = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { GET: (...a: unknown[]) => GET(...a), PATCH: (...a: unknown[]) => PATCH(...a), POST: (...a: unknown[]) => POST(...a), DELETE: (...a: unknown[]) => DELETE(...a) } }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
const refresh = jest.fn();
jest.mock('@/lib/branding-context', () => ({ useRefreshBranding: () => refresh }));
jest.mock('@/lib/theme-mode-context', () => ({ useThemeMode: () => ({ resolved: 'light' }) }));

const swatch = { background: '#eeeeee', card: '#ffffff', foreground: '#252525', 'muted-foreground': '#696969', primary: '#0961bb', 'primary-foreground': '#ffffff', border: '#e5e5e5', tertiary: '#7a5a9e' };
const list = {
  current: 'whity.default',
  themes: [
    { id: 'whity.default', version: '1.0.0', name: { en: 'Whity' }, source: 'whity', current: true, preview: { light: swatch, dark: swatch }, adjustments: [{ mode: 'light', role: 'ring', change: 'darkened' }] },
    { id: 'whity.harbour', version: '1.0.0', name: { en: 'Harbour' }, source: 'whity', current: false, main_color_replaces_seed: true, fonts: { body: 'IBM Plex Sans', heading: 'IBM Plex Sans' }, preview: { light: { ...swatch, primary: 'red;}' }, dark: swatch, radius: '12px' } },
    { id: 'acme.sea', version: '1.0.0', name: { en: 'Sea' }, source: 'import', current: false, preview: { light: swatch, dark: swatch } },
  ],
};

beforeEach(() => {
  jest.clearAllMocks();
  GET.mockResolvedValue({ data: { data: list } });
  PATCH.mockResolvedValue({ data: {}, error: undefined });
  DELETE.mockResolvedValue({ data: {}, error: undefined });
});

it('lists themes, marks the one in use and explains the guard rails', async () => {
  render(<ThemePicker scope="tenant" canWrite />);
  expect(await screen.findByText('Harbour')).toBeInTheDocument();
  expect(screen.getByText('In use')).toBeInTheDocument();
  expect(screen.getByText('Text in IBM Plex Sans')).toBeInTheDocument();
  expect(screen.getByText(/replaces the theme’s own/)).toBeInTheDocument();
  expect(screen.getByTestId('theme-adjustments')).toHaveTextContent('The focus outline was darkened a little');
});

it('never writes a preview value that is not a plain colour', async () => {
  const { container } = render(<ThemePicker scope="tenant" canWrite />);
  await screen.findByText('Harbour');
  expect(container.innerHTML).not.toContain('red;}');
});

it('applies a theme through the workspace setting and refreshes the app', async () => {
  render(<ThemePicker scope="tenant" canWrite />);
  await screen.findByText('Harbour');
  fireEvent.click(screen.getAllByRole('button', { name: 'Use this theme' })[0]);
  await waitFor(() => expect(PATCH).toHaveBeenCalledWith('/api/v1/settings', { body: { settings: { branding_theme: 'whity.harbour' } } }));
  expect(refresh).toHaveBeenCalled();
});

it('the installation picks Whity\'s own themes through the global setting, with no import', async () => {
  render(<ThemePicker scope="global" canWrite />);
  await screen.findByText('Harbour');
  expect(screen.queryByRole('button', { name: 'Add a theme file' })).not.toBeInTheDocument();
  fireEvent.click(screen.getAllByRole('button', { name: 'Use this theme' })[0]);
  await waitFor(() => expect(PATCH).toHaveBeenCalledWith('/api/v1/settings/global', expect.anything()));
});

it('only offers Remove on imported themes, and nothing without write access', async () => {
  const { unmount } = render(<ThemePicker scope="tenant" canWrite />);
  await screen.findByText('Sea');
  expect(screen.getAllByRole('button', { name: /Remove/ })).toHaveLength(1);
  unmount();
  render(<ThemePicker scope="tenant" canWrite={false} />);
  await screen.findByText('Sea');
  expect(screen.queryByRole('button', { name: 'Use this theme' })).not.toBeInTheDocument();
});

it('words every guard-rail change', () => {
  const t = ((_k: string, fallback: string, vars?: Record<string, string>) =>
    fallback.replace(/\{(\w+)\}/g, (_m, k: string) => vars?.[k] ?? '')) as never;
  expect(adjustmentText('override-dropped', 'background', t)).toContain('The page');
  expect(adjustmentText('theme-missing', 'theme', t)).toContain('removed');
});

// TH.15 and TH.16: free tweaks, export, and what a newer version changes.
const t = ((_k: string, fallback: string, vars?: Record<string, string>) =>
  fallback.replace(/\{(\w+)\}/g, (_m, k: string) => vars?.[k] ?? '')) as never;

it('keeps the colour overrides when the tweaks are saved, and clears an empty value', () => {
  expect(overridesValue({ light: { background: '#f4f1ea' }, dark: {} }, { shape: 'round', density: null, font: 'cairo' })).toBe(
    '{"light":{"background":"#f4f1ea"},"shape":"round","font":"cairo"}',
  );
  expect(overridesValue({ light: {}, dark: {} }, { shape: null, density: null, font: null })).toBe('');
});

it('shows the tweaks only to people who can change them', async () => {
  GET.mockResolvedValue({
    data: { data: { ...list, tweaks: { shape: 'round', density: null, font: null }, options: { shape: ['whity', 'round'], density: ['standard'], font: [{ id: 'noto', name: 'Noto Sans' }] } } },
  });
  const { unmount } = render(<ThemePicker scope="tenant" canWrite />);
  expect(await screen.findByTestId('theme-tweaks')).toHaveTextContent('Corners');
  expect(screen.getByTestId('theme-tweak-shape')).toHaveTextContent('Round');
  expect(screen.getByTestId('theme-tweak-density')).toHaveTextContent('As the theme sets it');
  unmount();
  render(<ThemePicker scope="tenant" canWrite={false} />);
  await screen.findByText('Harbour');
  expect(screen.queryByTestId('theme-tweaks')).not.toBeInTheDocument();
});

it('exports the theme in use as a file', async () => {
  GET.mockImplementation((path: string) =>
    Promise.resolve(path === '/api/v1/themes/export' ? { data: { data: { theme: { id: 'workspace.whity' }, filename: 'workspace.whity-1.0.0.json' } } } : { data: { data: list } }),
  );
  const createObjectURL = jest.fn(() => 'blob:theme');
  Object.assign(URL, { createObjectURL, revokeObjectURL: jest.fn() });
  const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  render(<ThemePicker scope="tenant" canWrite={false} />);
  await screen.findByText('Harbour');
  fireEvent.click(screen.getByRole('button', { name: 'Export theme' }));
  await waitFor(() => expect(click).toHaveBeenCalled());
  expect(GET).toHaveBeenCalledWith('/api/v1/themes/export');
  click.mockRestore();
});

it('says what a newer version changes before replacing it', async () => {
  POST.mockImplementation((_path: string, init: { params?: { query?: { check?: string } } }) =>
    Promise.resolve(
      init.params?.query?.check === '1'
        ? { data: { data: { id: 'acme.sea', version: '1.1.0', installed_version: '1.0.0', changes: [{ field: 'shape.preset', from: 'soft', to: 'round' }] } } }
        : { data: { data: { id: 'acme.sea', version: '1.1.0' } }, error: undefined },
    ),
  );
  render(<ThemePicker scope="tenant" canWrite />);
  await screen.findByText('Sea');
  const file = new File(['{"id":"acme.sea"}'], 'sea.json', { type: 'application/json' });
  Object.defineProperty(file, 'text', { value: () => Promise.resolve('{"id":"acme.sea"}') });
  fireEvent.change(screen.getByLabelText('Add a theme file', { selector: 'input' }), { target: { files: [file] } });
  const dialog = await screen.findByTestId('theme-import-changes');
  expect(dialog).toHaveTextContent('Update Sea from 1.0.0 to 1.1.0?');
  expect(dialog).toHaveTextContent('Corners: soft → round');
  expect(POST).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Update' }));
  await waitFor(() => expect(POST).toHaveBeenCalledTimes(2));
});

it('words a change field by field', () => {
  expect(changeText({ field: 'density', from: null, to: 'compact' }, t)).toBe('Spacing: none → compact');
});

it('previews the chosen font pair in English and Arabic, with only a well-formed stack (TH.20)', async () => {
  const stack = 'var(--font-rubik), ui-sans-serif, system-ui, sans-serif';
  GET.mockResolvedValue({
    data: {
      data: {
        ...list,
        tweaks: { shape: null, density: null, font: 'rubik' },
        options: { shape: ['whity'], density: ['standard'], font: [{ id: 'rubik', name: 'Rubik', stack, heading_stack: stack }] },
      },
    },
  });
  render(<ThemePicker scope="tenant" canWrite />);
  const preview = await screen.findByTestId('theme-font-preview');
  expect(preview.querySelector('[lang="ar"]')).toHaveStyle({ fontFamily: stack });
  expect(fontStack('Comic Sans; } body {')).toBeUndefined();
  expect(fontStack(stack)).toBe(stack);
});
