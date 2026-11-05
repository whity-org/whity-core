/**
 * The public check's `described` level on the verify page, and the template's
 * public name that feeds it (#1627).
 */
import { Suspense } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import VerifyDocumentPage from '@/app/verify/[token]/page';
import { PublicNameDialog } from '@/app/(protected)/admin/document-templates/public-name-dialog';
import type { TemplateRow } from '@/app/(protected)/admin/document-templates/types';

const template: TemplateRow = {
  id: 7,
  kind: 'template',
  title: 'Handover for Example Bakery',
  lang: 'en',
  record_type: null,
  own_fields: [],
  draft_version: 1,
  published_version: 1,
  scope: 'tenant',
  required_permission: null,
  owner_ou_id: null,
  created_by: 1,
  updated_at: '2026-11-05 10:00:00',
  starter_key: null,
  legacy_template_id: null,
  numbering: null,
  public_name: null,
};

/** The public answer for the first fetch; the signed-in follow-through answers 401. */
function answer(data: Record<string, unknown>) {
  global.fetch = jest.fn(async (url: string) =>
    String(url).includes('/document-verifications/')
      ? ({ ok: true, status: 200, json: async () => ({ data }) } as Response)
      : ({ ok: false, status: 401, json: async () => ({}) } as Response)
  ) as unknown as typeof fetch;
}

async function renderPage() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <VerifyDocumentPage params={Promise.resolve({ token: 'abc' })} />
      </Suspense>
    );
  });
}

describe('the verify page at the described level', () => {
  it('shows the kind, revision and pages', async () => {
    answer({
      verified: true,
      reference: '9F2A-4C11-8B03',
      issuer: 'Example Courts',
      issued_on: '2026-10-14',
      stage: 'issued',
      stage_on: '2026-10-14',
      kind: 'Handover certificate',
      revision: 'A',
      pages: 2,
    });
    await renderPage();

    await waitFor(() => expect(screen.getByTestId('verify-title')).toHaveTextContent('This document is genuine'));
    expect(screen.getByText('Handover certificate')).toBeInTheDocument();
    expect(screen.getByText('Revision').nextSibling).toHaveTextContent('A');
    expect(screen.getByText('Pages').nextSibling).toHaveTextContent('2');
  });

  it('shows no kind, revision or pages at the other levels', async () => {
    answer({ verified: true, reference: '9F2A-4C11-8B03', issuer: 'Example Courts', issued_on: '2026-10-14' });
    await renderPage();

    await waitFor(() => expect(screen.getByTestId('verify-title')).toHaveTextContent('This document is genuine'));
    expect(screen.queryByText('Document')).not.toBeInTheDocument();
    expect(screen.queryByText('Revision')).not.toBeInTheDocument();
    expect(screen.queryByText('Pages')).not.toBeInTheDocument();
  });

  it('says which revision replaced a revised document', async () => {
    answer({
      verified: false,
      reason: 'superseded',
      revoked_on: '2026-11-01',
      replaced_by: { revision: 'B', issued_on: '2026-11-01' },
    });
    await renderPage();

    await waitFor(() => expect(screen.getByTestId('verify-replaced-by')).toBeInTheDocument());
    expect(screen.getByTestId('verify-title')).toHaveTextContent('This copy has been replaced');
    expect(screen.getByTestId('verify-replaced-by')).toHaveTextContent('Revision B replaced it.');
  });
});

describe('the public name dialog', () => {
  function setup(publicName: string | null = null, ok = true) {
    const apiClient = jest.fn(async () => ({
      ok,
      json: async () => ({ error: 'Naming a template for the public check requires documents:publish' }),
    })) as unknown as Parameters<typeof PublicNameDialog>[0]['apiClient'];
    const onSaved = jest.fn();
    render(
      <PublicNameDialog
        row={{ ...template, public_name: publicName }}
        name="Handover for Example Bakery"
        apiClient={apiClient}
        addToast={jest.fn()}
        onClose={jest.fn()}
        onSaved={onSaved}
      />
    );

    return { apiClient: apiClient as unknown as jest.Mock, onSaved };
  }

  it('saves a trimmed name', async () => {
    const { apiClient, onSaved } = setup();
    await userEvent.type(screen.getByLabelText('Kind of document'), '  Handover certificate ');
    await userEvent.click(screen.getByRole('button', { name: 'Save public name' }));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const [url, init] = apiClient.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/v1/document-sources/7');
    expect(init.method).toBe('PATCH');
    expect(JSON.parse(String(init.body))).toEqual({ public_name: 'Handover certificate' });
  });

  it('clears the name when emptied', async () => {
    const { apiClient } = setup('Handover certificate');
    await userEvent.clear(screen.getByLabelText('Kind of document'));
    await userEvent.click(screen.getByRole('button', { name: 'Save public name' }));

    await waitFor(() => expect(apiClient).toHaveBeenCalled());
    expect(JSON.parse(String((apiClient.mock.calls[0] as [string, RequestInit])[1].body))).toEqual({ public_name: null });
  });

  it("shows the server's refusal", async () => {
    const { onSaved } = setup(null, false);
    await userEvent.type(screen.getByLabelText('Kind of document'), 'Certificate');
    await userEvent.click(screen.getByRole('button', { name: 'Save public name' }));

    expect(await screen.findByText('Naming a template for the public check requires documents:publish')).toBeInTheDocument();
    expect(onSaved).not.toHaveBeenCalled();
  });
});
