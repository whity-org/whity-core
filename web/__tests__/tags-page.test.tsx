/**
 * A46 — the one Tags page: groups with their tags in a line, and a drawer to
 * rename a group and add, rename or remove its tags.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({ useSearchParams: () => new URLSearchParams() }));

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

import TagsPage from '@/app/(protected)/admin/tags/page';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const TAGS = [
  { id: 1, group_id: 5, name: 'Urgent' },
  { id: 2, group_id: 5, name: 'Normal' },
];
const GROUPS = [{ id: 5, key: 'priority', display_name: { en: 'Priority' } }];

function wire(tags: unknown[], groups: unknown[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/tags') return jsonResponse(200, { data: tags });
    if (url === '/api/v1/tag-groups') return jsonResponse(200, { data: groups });
    return jsonResponse(200, { data: {} });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe('TagsPage', () => {
  it('lists each group with its tags in a line', async () => {
    wire(TAGS, GROUPS);
    render(<TagsPage />);
    expect(await screen.findByText('Priority')).toBeInTheDocument();
    expect(screen.getByText('Urgent, Normal')).toBeInTheDocument();
    expect(screen.queryByText('#5')).toBeNull();
  });

  it('says there are no groups yet, and offers New tag group to someone who can manage', async () => {
    wire([], []);
    render(<TagsPage />);
    expect(await screen.findByText('No tag groups yet')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'New tag group' }).length).toBeGreaterThan(0);
  });

  it('hides write controls without tags:manage', async () => {
    hasPermission.mockReturnValue(false);
    wire(TAGS, GROUPS);
    render(<TagsPage />);
    await screen.findByText('Priority');
    expect(screen.queryByRole('button', { name: 'New tag group' })).toBeNull();
    await userEvent.click(screen.getByText('Priority'));
    expect(await screen.findByText('Urgent')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add tag' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull();
  });

  it('a row opens the drawer, where a tag can be added', async () => {
    wire(TAGS, GROUPS);
    render(<TagsPage />);
    await userEvent.click(await screen.findByText('Priority'));
    await userEvent.type(await screen.findByLabelText('New tag'), 'Later');
    await userEvent.click(screen.getByRole('button', { name: 'Add tag' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/tags',
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ group_id: 5, name: 'Later' }) })
      )
    );
  });

  it('removing a tag asks first, then deletes it', async () => {
    wire(TAGS, GROUPS);
    render(<TagsPage />);
    await userEvent.click(await screen.findByText('Priority'));
    await userEvent.click((await screen.findAllByRole('button', { name: 'Remove' }))[0]);
    await userEvent.click(await screen.findByRole('button', { name: 'Remove tag' }));
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/tags/1', { method: 'DELETE' })
    );
  });

  it('keeps the technical key out of the drawer by default', async () => {
    wire(TAGS, GROUPS);
    render(<TagsPage />);
    await userEvent.click(await screen.findByText('Priority'));
    await screen.findByLabelText('New tag');
    expect(screen.queryByLabelText('Key')).toBeNull();
  });
});
