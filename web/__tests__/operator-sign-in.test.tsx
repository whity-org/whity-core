import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import OperatorSignInPage from '@/app/operator-sign-in/page';
import { IdentityLinkCard } from '@/components/settings/identity-link-card';

/**
 * WHIT-1350: a platform operator with no Whity ID gets a one-time link from
 * the server, signs in with it, and links Whity ID in Settings › Security.
 */

let searchParams = new URLSearchParams();
jest.mock('next/navigation', () => ({
  useSearchParams: () => searchParams,
}));

jest.mock('@/lib/branding-context', () => ({
  useBranding: () => ({ siteName: 'Whity', logoWideUrl: null }),
}));

const post = jest.fn();
const get = jest.fn();
const del = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    POST: (...args: unknown[]) => post(...args),
    GET: (...args: unknown[]) => get(...args),
    DELETE: (...args: unknown[]) => del(...args),
  },
}));

describe('the operator sign-in page', () => {
  beforeEach(() => {
    post.mockReset();
  });

  test('sends the token only when the operator presses the button, then clears it', async () => {
    window.history.replaceState(null, '', '/operator-sign-in#token=abc.def.ghi');
    post.mockResolvedValue({ data: { next: '/settings/security?link=whity_id' }, response: { ok: false, status: 410 } });
    render(<OperatorSignInPage />);

    expect(post).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/v1/auth/operator-link', { body: { token: 'abc.def.ghi' } }));
    expect(window.location.hash).toBe('');
    expect(await screen.findByTestId('operator-sign-in-refused')).toBeInTheDocument();
  });

  test('says how to get a new link when there is no token', async () => {
    window.history.replaceState(null, '', '/operator-sign-in');
    render(<OperatorSignInPage />);

    expect(await screen.findByTestId('operator-sign-in-refused')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sign in' })).not.toBeInTheDocument();
  });
});

describe('linking Whity ID in Settings › Security', () => {
  beforeEach(() => {
    get.mockReset();
    get.mockImplementation(async (path: string) =>
      path === '/api/v1/auth/sso/providers'
        ? { data: { data: [{ provider_key: 'whity_id', display_name: 'Whity ID' }] }, response: { ok: true } }
        : { data: { data: [] }, response: { ok: true } },
    );
  });

  test('offers to link the server provider, and nudges an operator who came in with a one-time link', async () => {
    searchParams = new URLSearchParams('link=whity_id');
    render(<IdentityLinkCard />);

    const link = await screen.findByRole('link', { name: /Link Whity ID/ });
    expect(link).toHaveAttribute('href', '/api/v1/auth/sso/whity_id/start?link=1');
    expect(screen.getByTestId('identity-link-operator')).toBeInTheDocument();
  });

  test('says what happened when the provider sends the person back', async () => {
    searchParams = new URLSearchParams('identity_link=taken');
    render(<IdentityLinkCard />);

    expect(await screen.findByTestId('identity-link-outcome')).toHaveTextContent('already linked to someone else');
  });
});
