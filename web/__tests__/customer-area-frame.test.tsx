/**
 * The customer area's frame (WHIT-1416, design section 41 §5 and §7): a
 * signed-out visitor sees the workspace's front door with Sign in; someone
 * signed in but not a customer can join in one tap; a customer gets the tabs
 * the server allowed, Home first and Account last, and the page itself.
 */

import React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';

jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));
jest.mock('next/navigation', () => ({
  usePathname: () => '/@example-bakery/bills',
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));
jest.mock('@/lib/auth-context', () => ({ useAuth: () => ({ logout: jest.fn() }) }));
// The switcher needs the app's language provider; the shell is what's tested here.
jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  LanguageSwitcher: () => null,
}));

const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: { GET: (...a: unknown[]) => GET(...a), POST: (...a: unknown[]) => POST(...a) },
}));

import { CustomerAreaFrame } from '@/components/customer-area/customer-area-frame';

const ok = (data: unknown, status = 200) => ({ data, error: undefined, response: new Response(null, { status }) });

const area = (over: Record<string, unknown> = {}) => ({
  workspace: { name: 'Example Bakery', handle: 'example-bakery', brand: { name: 'Example Bakery', logo_wide_url: null, logo_square_url: null } },
  enabled: true,
  signup: 'open',
  booking: true,
  chat: true,
  welcome: { en: '', ar: '' },
  me: null,
  tabs: [],
  ...over,
});

async function open() {
  await act(async () => {
    render(
      <CustomerAreaFrame handle="example-bakery">
        <p>the page</p>
      </CustomerAreaFrame>,
    );
  });
}

beforeEach(() => {
  GET.mockReset();
  POST.mockReset();
});

it('shows a signed-out visitor the front door with Sign in, not the page', async () => {
  GET.mockResolvedValue(ok({ data: area() }));
  await open();

  expect(screen.getByTestId('customer-door')).toBeInTheDocument();
  expect(screen.getByTestId('customer-sign-in')).toBeInTheDocument();
  expect(screen.queryByText('the page')).not.toBeInTheDocument();
  expect(GET).toHaveBeenCalledWith('/api/v1/customer-area/{handle}', { params: { path: { handle: 'example-bakery' } } });
});

it('lets someone signed in join in one tap when sign-up is open', async () => {
  GET.mockResolvedValue(ok({ data: area({ me: { name: 'Ahmad Khalil', email: 'ahmad@example.test', state: 'none', staff: false } }) }));
  POST.mockResolvedValue(ok({ data: { result: 'joined' } }));
  await open();

  await act(async () => {
    fireEvent.click(screen.getByTestId('customer-join'));
  });
  expect(POST).toHaveBeenCalledWith('/api/v1/customer-area/{handle}/join', { params: { path: { handle: 'example-bakery' } } });
});

it('says plainly when the business has not opened accounts', async () => {
  GET.mockResolvedValue(ok({ data: area({ signup: 'off', me: { name: 'Ahmad Khalil', email: 'ahmad@example.test', state: 'none', staff: false } }) }));
  await open();

  expect(screen.queryByTestId('customer-join')).not.toBeInTheDocument();
  expect(screen.getByText(/hasn’t opened accounts/)).toBeInTheDocument();
});

it('gives a customer the tabs the server allowed, Home first and Account last', async () => {
  GET.mockResolvedValue(
    ok({
      data: area({
        me: { name: 'Ahmad Khalil', email: 'ahmad@example.test', state: 'customer', staff: false },
        tabs: [
          { id: 'bills', count: 2 },
          { id: 'messages', count: 0 },
        ],
      }),
    }),
  );
  await open();

  expect(screen.getByText('the page')).toBeInTheDocument();
  const tabs = screen.getByTestId('customer-tabs');
  const labels = Array.from(tabs.querySelectorAll('a')).map((a) => a.getAttribute('href'));
  expect(labels).toEqual(['/@example-bakery/home', '/@example-bakery/bills', '/@example-bakery/messages', '/@example-bakery/account']);
  expect(tabs.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/@example-bakery/bills');
  expect(screen.getByTestId('customer-phone-tabs').querySelectorAll('a')).toHaveLength(4);
});

it('names no business at an address that has none', async () => {
  GET.mockResolvedValue(ok({ error: 'This page is not here.' }, 404));
  await open();

  expect(screen.getByText('There is no business at this address')).toBeInTheDocument();
});
