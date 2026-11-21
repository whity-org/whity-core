import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { useRouter } from 'next/navigation';
import LoginPage from '@/app/login/page';
import { AuthProvider } from '@/lib/auth-context';
import { ToastProvider } from '@/lib/toast-context';

/**
 * WHIT-1168: a server that signs people in only through its identity provider
 * (auth.password_sign_in off) shows the provider and nothing else: no email,
 * no password, no "forgot your password", no password sign-up link.
 */

jest.mock('next/navigation', () => ({
  useRouter: jest.fn(),
  useSearchParams: () => new URLSearchParams(),
}));

// The buttons report what the providers list said; the real one fetches it.
let passwordSignIn = true;
jest.mock('@/components/sso-login-buttons', () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { useEffect } = require('react');
  return {
    SsoLoginButtons: ({ onLoaded, form }: { onLoaded?: (info: { providers: unknown[]; passwordSignIn: boolean }) => void; form?: string }) => {
      useEffect(() => {
        onLoaded?.({ providers: [{ provider_key: 'whity_id', display_name: 'Whity ID' }], passwordSignIn });
      }, [onLoaded]);
      return <span data-testid="sso-buttons" data-form={form ?? ''}>Continue with Whity ID</span>;
    },
  };
});

jest.mock('@whity/features/i18n', () => ({
  ...jest.requireActual('@whity/features/i18n'),
  LanguageSwitcher: () => null,
}));

jest.mock('@/components/auth/sign-in-brand-panel', () => ({
  SignInBrandPanel: () => null,
}));

jest.mock('@/lib/api/client', () => {
  const failed = async () => ({ data: undefined, error: undefined, response: { ok: false, status: 401 } });
  return { api: { GET: failed, POST: failed } };
});

function renderLogin() {
  render(
    <AuthProvider>
      <ToastProvider>
        <LoginPage />
      </ToastProvider>
    </AuthProvider>
  );
}

describe('LoginPage when password sign-in is off', () => {
  beforeEach(() => {
    (useRouter as jest.Mock).mockReturnValue({ push: jest.fn() });
  });

  test('shows only the identity provider', async () => {
    passwordSignIn = false;
    renderLogin();

    await waitFor(() => expect(screen.getByTestId('provider-only-note')).toBeInTheDocument());
    expect(screen.getByText('Continue with Whity ID')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Enter your password')).not.toBeInTheDocument();
    expect(screen.queryByText('Forgot your password?')).not.toBeInTheDocument();
    expect(screen.queryByText('Create a workspace')).not.toBeInTheDocument();
  });

  // #2355: signing in from a form's public link hands the form to the provider
  // buttons, so a new person joins the form's workspace and returns to it.
  test('passes a form from its public link on to the provider buttons', async () => {
    passwordSignIn = false;
    const slug = 'ab12'.repeat(16);
    window.history.replaceState(null, '', `/login?form=${slug}&next=/forms/5`);
    renderLogin();

    await waitFor(() => expect(screen.getByTestId('sso-buttons')).toHaveAttribute('data-form', slug));
    window.history.replaceState(null, '', '/login');
  });

  test('ignores a form that is not a public form slug', async () => {
    passwordSignIn = false;
    window.history.replaceState(null, '', '/login?form=https://evil.test');
    renderLogin();

    await waitFor(() => expect(screen.getByTestId('provider-only-note')).toBeInTheDocument());
    expect(screen.getByTestId('sso-buttons')).toHaveAttribute('data-form', '');
    window.history.replaceState(null, '', '/login');
  });

  test('keeps email and password when it is on', async () => {
    passwordSignIn = true;
    renderLogin();

    await waitFor(() => expect(screen.getByPlaceholderText('Enter your password')).toBeInTheDocument());
    expect(screen.queryByTestId('provider-only-note')).not.toBeInTheDocument();
  });
});
