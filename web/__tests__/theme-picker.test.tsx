/**
 * WHIT-1225 (TH.13, TH.14): the free theme picker lists installed themes,
 * applies one through the branding_theme setting and says in words what the
 * guard rails changed.
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ThemePicker, adjustmentText } from '@/components/theme-picker';

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
