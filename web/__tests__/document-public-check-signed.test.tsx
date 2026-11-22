/**
 * The public check's `signed` level on the verify page (#1730): the signatures
 * and stamps as capacities with dates, the names only when the workspace chose
 * them, the warning when the trail's chain is broken, and "Check a file", which
 * hashes the PDF in the browser and sends only the hash.
 */
import React, { Suspense } from 'react';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/fetch-like-api').fetchLikeClient());
jest.mock('@/components/auth/public-shell', () => ({
  PublicShell: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

import VerifyDocumentPage from '@/app/verify/[token]/page';

const VERIFIED = {
  verified: true,
  reference: '9F2A-4C11-8B03',
  issuer: 'Example Bakery',
  issued_on: '2026-11-21',
  stage: 'signed',
  stage_on: '2026-11-21',
  kind: 'Supply agreement',
  revision: 'A',
  pages: 3,
  trail_intact: true,
};

const SIGNATURES = [
  { kind: 'signature', capacity: 'Director', on_behalf_of: 'Example Bakery', version: 2, signed_on: '2026-11-21' },
  { kind: 'stamp', capacity: 'Example Bakery LLC', on_behalf_of: null, version: 2, signed_on: '2026-11-21' },
];

type FetchCall = [string, RequestInit | undefined];

/** The public answer, the file check's answer, and 401 for the signed-in follow-through. */
function answer(data: Record<string, unknown>, fileMatch: { status: number; match?: string } = { status: 200, match: 'none' }) {
  global.fetch = jest.fn(async (url: string) => {
    const u = String(url);
    if (u.includes('/files/')) {
      return {
        ok: fileMatch.status === 200,
        status: fileMatch.status,
        json: async () => ({ data: { match: fileMatch.match } }),
      } as Response;
    }
    if (u.includes('/document-verifications/')) {
      return { ok: true, status: 200, json: async () => ({ data }) } as Response;
    }
    return { ok: false, status: 401, json: async () => ({}) } as Response;
  }) as unknown as typeof fetch;
}

async function renderPage() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <VerifyDocumentPage params={Promise.resolve({ token: 'abc' })} />
      </Suspense>
    );
  });
  await waitFor(() => expect(screen.getByTestId('verify-title')).toHaveTextContent('This document is genuine'));
}

describe('the verify page at the signed level', () => {
  it('lists signatures and stamps as capacities with dates, without names', async () => {
    answer({ ...VERIFIED, signatures: SIGNATURES });
    await renderPage();

    const rows = within(screen.getByTestId('verify-signatures')).getAllByTestId('verify-signature');
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent('Signed as Director, for Example Bakery · 21 Nov 2026');
    expect(rows[1]).toHaveTextContent('Company stamp of Example Bakery LLC · 21 Nov 2026');
    expect(screen.queryByTestId('verify-signature-name')).not.toBeInTheDocument();
    // One version for all of them: saying it would be noise.
    expect(screen.queryByText(/On version/)).not.toBeInTheDocument();
    expect(screen.queryByTestId('verify-trail-changed')).not.toBeInTheDocument();
    expect(screen.getByText('Current stage').nextSibling).toHaveTextContent('Signed');
  });

  it('names who signed when the workspace chose to, and the version when they differ', async () => {
    answer({
      ...VERIFIED,
      signatures: [
        { ...SIGNATURES[0], name: 'Amro Saleh', version: 1 },
        { kind: 'signature', capacity: 'Finance manager', on_behalf_of: null, version: 2, signed_on: '2026-11-22', name: 'Awss Haddad' },
      ],
    });
    await renderPage();

    const names = screen.getAllByTestId('verify-signature-name').map((n) => n.textContent);
    expect(names).toEqual(['Amro Saleh', 'Awss Haddad']);
    const rows = screen.getAllByTestId('verify-signature');
    expect(rows[1]).toHaveTextContent('Signed as Finance manager · 22 Nov 2026');
    expect(rows[0]).toHaveTextContent('On version 1');
    expect(rows[1]).toHaveTextContent('On version 2');
  });

  it('says calmly when the record of the signing was changed', async () => {
    answer({ ...VERIFIED, signatures: SIGNATURES, trail_intact: false });
    await renderPage();

    const warning = screen.getByTestId('verify-trail-changed');
    expect(warning).toHaveTextContent(
      'The record of how this was signed has been changed since. Check with Example Bakery before relying on it.'
    );
    expect(warning.className).toContain('bg-warning-tint');
    expect(warning.className).not.toMatch(/destructive/);
  });

  it('shows no signatures, warning or file check for a code that did not verify', async () => {
    answer({ verified: false, reason: 'unrecognised' });
    await act(async () => {
      render(
        <Suspense fallback={null}>
          <VerifyDocumentPage params={Promise.resolve({ token: 'abc' })} />
        </Suspense>
      );
    });
    await waitFor(() => expect(screen.getByTestId('verify-title')).toHaveTextContent('We can’t find this document'));
    expect(screen.queryByTestId('verify-signatures')).not.toBeInTheDocument();
    expect(screen.queryByTestId('verify-trail-changed')).not.toBeInTheDocument();
    expect(screen.queryByTestId('verify-file-check')).not.toBeInTheDocument();
  });
});

describe('checking a file on the verify page', () => {
  const DIGEST = new Uint8Array(32).map((_, i) => i * 7);
  const HEX = Array.from(DIGEST, (b) => b.toString(16).padStart(2, '0')).join('');
  const SECRET = '%PDF-1.7 private contents of the agreement';
  const digest = jest.fn(async () => DIGEST.buffer);
  const realCrypto = globalThis.crypto;

  beforeEach(() => {
    digest.mockClear();
    Object.defineProperty(globalThis, 'crypto', { value: { subtle: { digest } }, configurable: true });
  });

  afterAll(() => {
    Object.defineProperty(globalThis, 'crypto', { value: realCrypto, configurable: true });
  });

  async function checkFile() {
    const file = new File([SECRET], 'agreement.pdf', { type: 'application/pdf' });
    await userEvent.upload(screen.getByTestId('verify-file-input'), file);
    await waitFor(() => expect(screen.getByTestId('verify-file-answer')).toHaveAttribute('data-match'));
  }

  it('says the file never leaves the device', async () => {
    answer(VERIFIED);
    await renderPage();

    expect(screen.getByTestId('verify-file-check')).toHaveTextContent(
      'It is checked on this device and never uploaded.'
    );
  });

  it('hashes in the browser and sends only the hash', async () => {
    answer(VERIFIED, { status: 200, match: 'current' });
    await renderPage();
    await checkFile();

    expect(digest).toHaveBeenCalledWith('SHA-256', expect.anything());
    const calls = (global.fetch as jest.Mock).mock.calls as FetchCall[];
    const fileCalls = calls.filter(([url]) => String(url).includes('/files/'));
    expect(fileCalls).toHaveLength(1);
    expect(fileCalls[0][0]).toBe(`/api/v1/document-verifications/abc/files/${HEX}`);
    expect(fileCalls[0][1]?.method).toBe('GET');
    expect(fileCalls[0][1]?.body).toBeUndefined();
    expect(fileCalls[0][1]?.credentials).toBe('omit');
    // Nothing the page sent anywhere carries the file's contents or its name.
    for (const [url, init] of calls) {
      expect(String(url)).not.toContain('agreement');
      expect(String(init?.body ?? '')).not.toContain('PDF');
    }
  });

  it('says when the file is the one the issuer issued', async () => {
    answer(VERIFIED, { status: 200, match: 'current' });
    await renderPage();
    await checkFile();

    expect(screen.getByTestId('verify-file-answer')).toHaveTextContent('This file is the one Example Bakery issued.');
  });

  it('says when the file is an earlier version', async () => {
    answer(VERIFIED, { status: 200, match: 'earlier' });
    await renderPage();
    await checkFile();

    expect(screen.getByTestId('verify-file-answer')).toHaveTextContent(
      'This file is an earlier version of this document. Ask for the current one.'
    );
  });

  it('says when the file does not match', async () => {
    answer(VERIFIED, { status: 200, match: 'none' });
    await renderPage();
    await checkFile();

    expect(screen.getByTestId('verify-file-answer')).toHaveTextContent(
      'This file doesn’t match. It may have been changed, or it may be a different document.'
    );
  });

  it('asks the reader to wait when the checks are throttled', async () => {
    answer(VERIFIED, { status: 429 });
    await renderPage();
    const file = new File([SECRET], 'agreement.pdf', { type: 'application/pdf' });
    await userEvent.upload(screen.getByTestId('verify-file-input'), file);

    await waitFor(() =>
      expect(screen.getByTestId('verify-file-answer')).toHaveTextContent('Too many checks.')
    );
  });
});
