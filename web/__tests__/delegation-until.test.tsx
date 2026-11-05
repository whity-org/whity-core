/**
 * @jest-environment ./jest.amman-environment.js
 *
 * The date picked in the delegate dialog has to reach the server. When it did not,
 * the field showed the date and the delegation saved with no end date, so it never
 * expired. The server's side of expiry is tested in PeopleServerRealEngineTest.
 */

import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockGet = jest.fn();
const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockGet(...args),
    POST: (...args: unknown[]) => mockPost(...args),
  },
}));

jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));

import { CreateDelegationModal } from '@/app/(protected)/admin/delegations/create-modal';

jest.setTimeout(30000);

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

const page = (data: unknown[]) => ({
  data: { data, pagination: { page: 1, perPage: 100, total: data.length, totalPages: 1 } },
  error: undefined,
  response: { ok: true, status: 200 },
});

beforeEach(() => {
  jest.clearAllMocks();
  mockGet.mockImplementation(async (path: string) => {
    if (path === '/api/v1/permissions') return page([{ id: 1, name: 'relations:read', source: 'db' }]);
    if (path === '/api/v1/roles') return page([{ id: 2, name: 'Baker', tenant_id: 1, permission_count: 0 }]);
    return page([]);
  });
  mockPost.mockResolvedValue({ error: undefined, response: { ok: true, status: 201 } });
});

const fillIn = async (user: ReturnType<typeof userEvent.setup>) => {
  render(<CreateDelegationModal isOpen onOpenChange={jest.fn()} onSuccess={jest.fn()} />);

  const dialog = await screen.findByRole('dialog');
  const pickers = await within(dialog).findAllByRole('combobox');
  await user.click(pickers[1]);
  await user.click(await screen.findByRole('option', { name: /Baker/ }));
  await user.click(
    (await waitFor(() => {
      const box = dialog.querySelector('label[data-permission="relations:read"] input[type="checkbox"]');
      expect(box).not.toBeNull();
      return box;
    })) as HTMLElement
  );

  return dialog;
};

const submit = async (user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement) => {
  await user.click(within(dialog).getByRole('button', { name: 'Create Delegation' }));
  await waitFor(() => expect(mockPost).toHaveBeenCalledTimes(1));

  return (mockPost.mock.calls[0][1] as { body: { expiresAt: string | null } }).body;
};

describe('Delegating until a date', () => {
  it('sends the end of the picked day, in the person\'s own time', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    const dialog = await fillIn(user);

    await user.click(within(dialog).getByLabelText('Until (optional)'));
    await user.click(await screen.findByRole('button', { name: '15' }));

    const now = new Date();
    const endOfDay = new Date(now.getFullYear(), now.getMonth(), 15, 23, 59, 59);
    expect((await submit(user, dialog)).expiresAt).toBe(endOfDay.toISOString());
  });

  it('sends no end date only when none was picked', async () => {
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    const dialog = await fillIn(user);

    expect((await submit(user, dialog)).expiresAt).toBeNull();
  });
});
