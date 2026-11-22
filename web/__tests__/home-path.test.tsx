/**
 * #2386: the instance's home page and the way back to the product beside it.
 */
import { render, screen } from '@testing-library/react';
import { DEFAULT_HOME_PATH, parseBackToProduct, safeHomePath } from '@/lib/home-path';
import { BrandingProvider, parseBrandingResponse, useHomePath } from '@/lib/branding-context';
import NotFound from '@/app/not-found';

jest.mock('@whity/features/i18n', () => ({
  useTranslation: () => (_key: string, fallback: string) => fallback,
}));

describe('safeHomePath', () => {
  it.each([
    ['/dashboard', '/dashboard'],
    ['/admin/x/forms-catalog?tab=open', '/admin/x/forms-catalog?tab=open'],
    ['//evil.example', DEFAULT_HOME_PATH],
    ['/\\evil.example', DEFAULT_HOME_PATH],
    ['https://evil.example', DEFAULT_HOME_PATH],
    ['javascript:alert(1)', DEFAULT_HOME_PATH],
    [undefined, DEFAULT_HOME_PATH],
    [42, DEFAULT_HOME_PATH],
  ])('%p becomes %p', (value, expected) => {
    expect(safeHomePath(value)).toBe(expected);
  });
});

describe('parseBackToProduct', () => {
  it('keeps a local path or an https address with a label', () => {
    expect(parseBackToProduct({ url: '/', label: 'Back' })).toEqual({ url: '/', label: 'Back' });
    expect(parseBackToProduct({ url: 'https://bakery.example', label: 'Bakery' })).toEqual({ url: 'https://bakery.example', label: 'Bakery' });
  });

  it('drops anything else', () => {
    expect(parseBackToProduct(null)).toBeNull();
    expect(parseBackToProduct({ url: 'javascript:alert(1)', label: 'x' })).toBeNull();
    expect(parseBackToProduct({ url: 'http://bakery.example', label: 'x' })).toBeNull();
    expect(parseBackToProduct({ url: '/', label: '' })).toBeNull();
  });
});

describe('branding carries the home page', () => {
  it('parses homePath and backToProduct from the API body', () => {
    const parsed = parseBrandingResponse({
      data: { siteName: 'Whity', homePath: '/tasks', backToProduct: { url: '/', label: 'Back to Example Bakery' } },
    });
    expect(parsed?.branding.homePath).toBe('/tasks');
    expect(parsed?.branding.backToProduct).toEqual({ url: '/', label: 'Back to Example Bakery' });
  });

  function Probe() {
    return <span data-testid="home">{useHomePath()}</span>;
  }

  it('is the dashboard without a provider value, and the operator’s page with one', () => {
    const { unmount } = render(<Probe />);
    expect(screen.getByTestId('home').textContent).toBe('/dashboard');
    unmount();

    render(
      <BrandingProvider initial={{ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null, builtOnWhity: true, homePath: '/tasks' }}>
        <Probe />
      </BrandingProvider>,
    );
    expect(screen.getByTestId('home').textContent).toBe('/tasks');
  });
});

describe('not found', () => {
  const brand = (homePath: string) => ({ siteName: 'Whity', logoWideUrl: null, logoSquareUrl: null, faviconUrl: null, builtOnWhity: true, homePath });

  it('offers the dashboard by default', () => {
    render(
      <BrandingProvider initial={brand('/dashboard')}>
        <NotFound />
      </BrandingProvider>,
    );
    expect(screen.getByRole('link', { name: 'Go to dashboard' })).toHaveAttribute('href', '/dashboard');
  });

  it('offers the instance home page when the operator chose another', () => {
    render(
      <BrandingProvider initial={brand('/admin/x/forms-catalog')}>
        <NotFound />
      </BrandingProvider>,
    );
    expect(screen.getByRole('link', { name: 'Go to the home page' })).toHaveAttribute('href', '/admin/x/forms-catalog');
  });
});
