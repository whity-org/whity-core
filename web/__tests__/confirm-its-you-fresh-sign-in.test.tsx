/**
 * "Confirm it's you" by signing in again (WHIT-1147), found broken on app.whity.dev on
 * 2026-11-22: going to Whity ID unloads the page, so the approval being confirmed has to be kept
 * and offered back on return, with what the server said about the sign-in. Before, the dialog was
 * gone on return and every outcome ended as "That code is not right or has expired".
 */

import React, { useState } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import {
  ConfirmItsYouDialog,
  takeFreshSignInReturn,
  useFreshSignInReturn,
  type IdentityCheck,
} from '@/components/security/confirm-its-you-dialog';

const CHECK: IdentityCheck = {
  method: 'fresh_sign_in',
  email_hint: null,
  provider_key: 'whity-id',
  purpose: 'workspace-ownership:offer-7-42-co_owner',
};

interface Pending {
  to: number;
  check: IdentityCheck;
}

function OwnersLike({ initial, error = null, onConfirm }: { initial: Pending | null; error?: string | null; onConfirm: (code: string) => void }) {
  const [pending, setPending] = useState<Pending | null>(initial);
  const signInOutcome = useFreshSignInReturn<Pending>(setPending);
  return (
    <>
      <p data-testid="pending-to">{pending?.to ?? 'none'}</p>
      <ConfirmItsYouDialog
        check={pending?.check ?? null}
        busy={false}
        error={error}
        onCancel={() => setPending(null)}
        onConfirm={onConfirm}
        resume={pending}
        signInOutcome={signInOutcome}
      />
    </>
  );
}

function at(path: string) {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  window.sessionStorage.clear();
  at('/admin/settings/owners');
  // jsdom does not navigate; the click must still keep the approval first.
  jest.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  jest.restoreAllMocks();
});

it('keeps the approval when the person goes to sign in again', () => {
  render(<OwnersLike initial={{ to: 42, check: CHECK }} onConfirm={jest.fn()} />);

  fireEvent.click(screen.getByRole('button', { name: 'Sign in again' }));

  const kept = JSON.parse(window.sessionStorage.getItem('whity.identity-check.resume') ?? 'null');
  expect(kept.path).toBe('/admin/settings/owners');
  expect(kept.state).toEqual({ to: 42, check: CHECK });
});

it('reopens the same approval on return and approves it with no code', async () => {
  const first = render(<OwnersLike initial={{ to: 42, check: CHECK }} onConfirm={jest.fn()} />);
  fireEvent.click(screen.getByRole('button', { name: 'Sign in again' }));
  first.unmount();

  at('/admin/settings/owners?identity_check=signed_in');
  const onConfirm = jest.fn();
  await act(async () => {
    render(<OwnersLike initial={null} onConfirm={onConfirm} />);
  });

  expect(screen.getByTestId('pending-to')).toHaveTextContent('42');
  expect(screen.getByText('You signed in again. Approve within five minutes.')).toBeInTheDocument();
  // The outcome is read once: a reload doesn't show it again.
  expect(window.location.search).toBe('');
  expect(window.sessionStorage.getItem('whity.identity-check.resume')).toBeNull();

  fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
  expect(onConfirm).toHaveBeenCalledWith('');
});

it.each([
  ['sign_in_not_fresh', 'That sign-in was not a new one, so it does not confirm it’s you. Sign in again and enter your details there.'],
  ['sign_in_mismatch', 'You signed in as someone else. Sign in again with your own account.'],
  ['sign_in_failed', 'Signing in again did not work. Try once more.'],
  ['anything-else', 'Signing in again did not work. Try once more.'],
])('says what went wrong when the sign-in came back %s', async (outcome, words) => {
  window.sessionStorage.setItem(
    'whity.identity-check.resume',
    JSON.stringify({ path: '/admin/settings/owners', at: Date.now(), state: { to: 42, check: CHECK } }),
  );
  at(`/admin/settings/owners?identity_check=${outcome}`);
  await act(async () => {
    render(<OwnersLike initial={null} onConfirm={jest.fn()} />);
  });

  expect(screen.getByText(words)).toBeInTheDocument();
});

it('never calls a refused approval a wrong code: there is none', () => {
  render(<OwnersLike initial={{ to: 42, check: CHECK }} error="That code is not right or has expired." onConfirm={jest.fn()} />);

  expect(screen.getByRole('alert')).toHaveTextContent('Sign in again first. It counts for five minutes and for this approval only.');
  expect(screen.queryByText('That code is not right or has expired.')).toBeNull();
});

it('does not offer back an approval kept on another page or too long ago', () => {
  window.sessionStorage.setItem(
    'whity.identity-check.resume',
    JSON.stringify({ path: '/admin/approvals', at: Date.now(), state: { to: 1 } }),
  );
  at('/admin/settings/owners?identity_check=signed_in');
  expect(takeFreshSignInReturn()).toEqual({ outcome: 'signed_in', state: null });

  window.sessionStorage.setItem(
    'whity.identity-check.resume',
    JSON.stringify({ path: '/admin/settings/owners', at: Date.now() - 60 * 60 * 1000, state: { to: 1 } }),
  );
  at('/admin/settings/owners?identity_check=signed_in');
  expect(takeFreshSignInReturn()).toEqual({ outcome: 'signed_in', state: null });
});

it('is not a return without the outcome on the address', () => {
  at('/admin/settings/owners');
  expect(takeFreshSignInReturn()).toBeNull();
});
