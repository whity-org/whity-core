/**
 * Unit names in Arabic and English (#821, WHIT-978): the create and edit forms
 * send every language and which one is the original.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { CreateOuModal } from '@/app/(protected)/admin/ous/create-modal';
import { EditOuModal } from '@/app/(protected)/admin/ous/edit-modal';
import type { OU } from '@/app/(protected)/admin/ous/types';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

beforeEach(() => {
  mockApiClient.mockReset();
  mockApiClient.mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ data: {} }) });
  addToast.mockReset();
});

const KITCHEN: OU = {
  id: 10,
  tenant_id: 1,
  parent_id: null,
  name: 'Kitchen',
  name_language: 'en',
  names: { en: 'Kitchen' },
  slug: 'kitchen',
  description: '',
  created_at: '2026-11-07T09:00:00Z',
};

function sentBody(): Record<string, unknown> {
  const call = mockApiClient.mock.calls.find((c) => c[1]?.method === 'PATCH' || c[1]?.method === 'POST');
  return JSON.parse(call![1].body);
}

describe('unit names (#821)', () => {
  it('edits an Arabic original with its English name', async () => {
    const user = userEvent.setup();
    render(<EditOuModal isOpen onClose={() => {}} onSuccess={() => {}} ou={KITCHEN} ous={[KITCHEN]} />);

    await user.type(document.getElementById('ou-edit-name-ar')!, 'المطبخ');
    await user.click(document.getElementById('ou-edit-name-original-ar')!);
    await user.click(screen.getByRole('button', { name: 'Update' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(sentBody()).toMatchObject({
      name: 'المطبخ',
      names: { en: 'Kitchen', ar: 'المطبخ' },
      name_language: 'ar',
    });
  });

  it('refuses to save when the original language is empty', async () => {
    const user = userEvent.setup();
    render(<EditOuModal isOpen onClose={() => {}} onSuccess={() => {}} ou={KITCHEN} ous={[KITCHEN]} />);

    await user.click(document.getElementById('ou-edit-name-original-ar')!);
    await user.click(screen.getByRole('button', { name: 'Update' }));

    expect(await screen.findByTestId('ou-edit-name-error')).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });

  it('creates a unit named only in Arabic with Arabic as the original', async () => {
    const user = userEvent.setup();
    render(<CreateOuModal isOpen onClose={() => {}} onSuccess={() => {}} ous={[]} />);

    await user.type(document.getElementById('ou-create-name-ar')!, 'كلية الهندسة');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(sentBody()).toMatchObject({
      name: 'كلية الهندسة',
      names: { ar: 'كلية الهندسة' },
      name_language: 'ar',
    });
  });
});
