/**
 * The audit page shows an applied company stamp (#1729) as the stamp's image,
 * its name and legal name, and who applied it, not as a drawn signature.
 */

import React from 'react';
import { render, screen, within } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
jest.mock('next/navigation', () => ({ useParams: () => ({ id: '1' }) }));

import AuditPage from '@/app/(protected)/admin/document-library/[id]/audit/page';

const IMAGE = 'data:image/png;base64,iVBORw0KGgo=';

function json(status: number, body: unknown) {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as unknown as Response;
}

const record = {
  id: 4,
  event_id: 30,
  subject_version: 2,
  content_hash: 'd'.repeat(64),
  by: { profile_id: 21, name: 'Amro Saleh', email: null },
  capacity: null,
  on_behalf_of: null,
  confirmed_by: 'totp',
  at: '2026-11-21T09:00:00Z',
  ip_address: null,
  user_agent: null,
  consent_version: '2026-11-stamp-1',
  consent_wording: null,
  appearance: null,
  record_hash: 'e'.repeat(64),
  seal_key_id: 'k1',
  seal_valid: true,
  details_withheld: true,
  kind: 'stamp',
  stamp: { id: 3, name: 'Company seal', legal_name: 'Example Bakery LLC', image: IMAGE },
};

it('shows a stamp record with its image, names and who applied it', async () => {
  mockApiClient.mockImplementation(async (url: string) => {
    if (url === '/api/v1/documents/1/audit') {
      return json(200, {
        data: {
          document_id: 1,
          chain: { intact: true, entries: 1, linked: 1, earlier: 0, detached: 0, broken_at_event_id: null, signed_without_record: [] },
          events: [],
          signatures: [record],
          keys: [],
        },
      });
    }
    if (url === '/api/v1/documents/1/people') return json(200, { data: {} });
    return json(404, {});
  });

  render(<AuditPage />);

  const item = await screen.findByTestId('audit-signature');
  expect(item).toHaveAttribute('data-kind', 'stamp');
  expect(within(item).getByText('Applied by Amro Saleh')).toBeInTheDocument();
  expect(within(item).getByText('Company seal')).toBeInTheDocument();
  expect(within(item).getByText('Stands for Example Bakery LLC')).toBeInTheDocument();
  expect(within(item).getByRole('img', { name: 'The image of the stamp Company seal' })).toHaveAttribute('src', IMAGE);
  expect(within(item).queryByText('Signing as')).toBeNull();
  expect(
    within(item).getByText('An authorised person applied the image of this stamp. It is not a certified electronic seal.')
  ).toBeInTheDocument();
  // The stamp consent wording, shown from its version.
  expect(within(item).getByText(/^I hold this company stamp/)).toBeInTheDocument();
});
