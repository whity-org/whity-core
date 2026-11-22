/**
 * TH.25 (30-themes 11): the live theme editor previews a draft as the server
 * compiles it, light and dark side by side, and saves the workspace's own
 * theme as its next version or anything else as a copy.
 */
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { ThemeEditor, nextVersion, themeToSave, valueAt, withValue } from '@/components/theme-editor';
import { previewThemeCss } from '@/lib/brand-theme-css';

const POST = jest.fn();
const PATCH = jest.fn();
jest.mock('@/lib/api/client', () => ({ api: { POST: (...a: unknown[]) => POST(...a), PATCH: (...a: unknown[]) => PATCH(...a) } }));
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));
const refresh = jest.fn();
jest.mock('@/lib/branding-context', () => ({ useRefreshBranding: () => refresh }));
const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const valid = {
  valid: true,
  problems: [],
  light: { background: '#fafafa', primary: '#0b6e74' },
  dark: { background: '#101010', primary: '#5fc3c9' },
  vars: { 'wy-radius-control': '20px' },
  fonts: { body: 'Noto Sans', heading: 'Noto Sans' },
  adjustments: [],
  roles: ['primary', 'background'],
};
const harbour = { schemaVersion: 1, id: 'whity.harbour', version: '1.0.0', name: { en: 'Harbour', ar: 'المرفأ' }, author: { name: 'Whity' }, colour: { seeds: { primary: '#0b6e74' } } };

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  POST.mockImplementation((path: string) =>
    Promise.resolve(path === '/api/v1/themes/preview' ? { data: { data: valid } } : { data: { data: { id: 'x', version: '1.0.0' } }, error: undefined }),
  );
  PATCH.mockResolvedValue({ data: {}, error: undefined });
});
afterEach(() => jest.useRealTimers());

const settle = async () => {
  await act(async () => {
    jest.advanceTimersByTime(300);
  });
};

it('sets and removes values by path, dropping empty groups', () => {
  const theme = withValue({ id: 'a.b' }, ['colour', 'seeds', 'primary'], '#123456');
  expect(valueAt(theme, ['colour', 'seeds', 'primary'])).toBe('#123456');
  expect(withValue(theme, ['colour', 'seeds', 'primary'], undefined)).toEqual({ id: 'a.b' });
});

it('saves the workspace’s own theme as its next version and anything else as a copy', () => {
  expect(nextVersion('1.4.2')).toBe('1.4.3');
  expect(nextVersion('x')).toBe('1.0.0');
  expect(themeToSave({ id: 'workspace.sea', version: '1.0.1', name: { en: 'Sea' } }, true)).toMatchObject({ id: 'workspace.sea', version: '1.0.2' });
  const copy = themeToSave({ ...harbour, name: { en: 'Café Harbour!' } }, false);
  expect(copy).toMatchObject({ id: 'workspace.cafe-harbour', version: '1.0.0' });
  expect(copy).not.toHaveProperty('author');
  // Never a whity. id, even when marked editable.
  expect(themeToSave(harbour, true).id).toBe('workspace.harbour');
});

it('writes the draft only onto the two preview elements, checked like the workspace theme', () => {
  const css = previewThemeCss({ ...valid, light: { ...valid.light, evil: 'red;}body{x:y' } });
  expect(css).toContain('[data-theme-preview="light"]{--background:#fafafa;--primary:#0b6e74;--wy-radius-control:20px;}');
  expect(css).toContain('[data-theme-preview="dark"]{');
  expect(css).not.toContain(':root');
  expect(css).not.toContain('red;}');
});

it('previews light and dark side by side and re-themes as a choice changes', async () => {
  const { container } = render(<ThemeEditor start={harbour} editable={false} fonts={[{ id: 'noto', name: 'Noto Sans' }]} />);
  await settle();
  expect(POST).toHaveBeenCalledWith('/api/v1/themes/preview', { body: expect.objectContaining({ name: { en: 'Harbour (copy)' } }) });
  expect(screen.getByTestId('theme-editor-preview-light')).toBeInTheDocument();
  expect(screen.getByTestId('theme-editor-preview-dark')).toHaveClass('dark');
  await waitFor(() => expect(container.querySelector('style')?.textContent).toContain('[data-theme-preview="dark"]'));

  fireEvent.change(screen.getByLabelText('Name in English'), { target: { value: 'Harbour for us' } });
  await settle();
  expect(POST).toHaveBeenLastCalledWith('/api/v1/themes/preview', { body: expect.objectContaining({ name: { en: 'Harbour for us' } }) });
});

it('says every problem and won’t save a draft that has them', async () => {
  POST.mockResolvedValue({ data: { data: { valid: false, problems: ['shape.preset must be one of: square, round.', 'density must be one of: standard.'] } } });
  render(<ThemeEditor start={harbour} editable={false} fonts={[]} />);
  await settle();
  const problems = await screen.findByTestId('theme-editor-problems');
  expect(problems).toHaveTextContent('shape.preset must be one of');
  expect(problems).toHaveTextContent('density must be one of');
  expect(screen.getByRole('button', { name: 'Save and use' })).toBeDisabled();
});

it('saves a copy from the editor and puts it in use', async () => {
  render(<ThemeEditor start={harbour} editable={false} fonts={[]} />);
  await settle();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save and use' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Save and use' }));
  await waitFor(() => expect(push).toHaveBeenCalledWith('/admin/settings/branding'));
  expect(POST).toHaveBeenCalledWith('/api/v1/themes', {
    body: { theme: expect.objectContaining({ id: 'workspace.harbour-copy', version: '1.0.0' }), source: 'editor' },
  });
  expect(PATCH).toHaveBeenCalledWith('/api/v1/settings', { body: { settings: { branding_theme: 'workspace.harbour-copy' } } });
  expect(refresh).toHaveBeenCalled();
});

it('compares the draft with where it started, side by side', async () => {
  POST.mockImplementation((path: string) =>
    Promise.resolve(
      path === '/api/v1/themes/compare'
        ? { data: { data: { changes: [{ field: 'name', from: 'Harbour', to: 'Harbour (copy)' }], before: valid, after: valid } } }
        : { data: { data: valid } },
    ),
  );
  render(<ThemeEditor start={harbour} editable={false} fonts={[]} />);
  await settle();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Compare' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Compare' }));
  expect(await screen.findByTestId('theme-editor-compare')).toHaveTextContent('Compare with where you started');
  await waitFor(() => expect(screen.getByTestId('theme-diff-changes')).toHaveTextContent('Name: Harbour → Harbour (copy)'));
  expect(POST).toHaveBeenCalledWith('/api/v1/themes/compare', {
    body: { before: harbour, after: expect.objectContaining({ name: { en: 'Harbour (copy)' } }) },
  });
});

it('publishes: saves the theme, packages it for the store and hands over the file', async () => {
  POST.mockImplementation((path: string) => {
    if (path === '/api/v1/themes/preview') return Promise.resolve({ data: { data: valid } });
    if (path === '/api/v1/themes/package') {
      return Promise.resolve({
        data: { data: { plugin: 'WorkspaceHarbourCopyTheme', filename: 'WorkspaceHarbourCopyTheme-1.0.0.zip', media_type: 'application/zip', sha256: 'ab'.repeat(32), size_bytes: 3, content_base64: btoa('zip') } },
      });
    }
    return Promise.resolve({ data: { data: { id: 'workspace.harbour-copy', version: '1.0.0' } }, error: undefined });
  });
  const createObjectURL = jest.fn((_blob: Blob) => 'blob:pkg');
  Object.assign(URL, { createObjectURL, revokeObjectURL: jest.fn() });
  const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  render(<ThemeEditor start={harbour} editable={false} fonts={[]} />);
  await settle();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Publish' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Publish' }));
  const dialog = await screen.findByTestId('theme-publish');
  expect(POST).toHaveBeenCalledWith('/api/v1/themes', { body: { theme: expect.objectContaining({ id: 'workspace.harbour-copy' }), source: 'editor' } });
  expect(POST).toHaveBeenCalledWith('/api/v1/themes/package', { body: { theme: expect.objectContaining({ id: 'workspace.harbour-copy', version: '1.0.0' }) } });
  expect(dialog).toHaveTextContent('WorkspaceHarbourCopyTheme-1.0.0.zip');
  expect(dialog).toHaveTextContent('ab'.repeat(32));
  fireEvent.click(screen.getByRole('button', { name: 'Download the package' }));
  expect(click).toHaveBeenCalled();
  expect(createObjectURL.mock.calls[0][0].type).toBe('application/zip');
  expect(push).not.toHaveBeenCalled();
  click.mockRestore();
});
