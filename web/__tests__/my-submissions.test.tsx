/**
 * What a person sent, where each one is, and taking one back. Example Bakery's supplier
 * form, with invented answers.
 */

import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MySubmissionsScreen, type FormsScreenEnv } from '@whity/features/forms';

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 5,
  form_id: 7,
  form_version: 1,
  submitted_by_profile_id: 11,
  document_id: null,
  reference: 'SUP-0005',
  data: {},
  submitted_at: '2026-11-05T08:00:00Z',
  state: 'not routed',
  current_step: null,
  form_key: 'supplier_request',
  form_name: { en: 'Supplier request' },
  ...overrides,
});

const draft = { form_id: 9, form_name: { en: 'Leave request' }, step: 1, updated_at: '2026-11-05T08:00:00Z' };

function setUp(rows: unknown[], drafts: unknown[] = [], withdrawStatus = 200, withdrawBody: unknown = { data: row({ state: 'withdrawn' }) }) {
  const calls: Array<[string, string]> = [];
  let current = rows;
  const notify = jest.fn();
  const navigate = jest.fn();
  const env: FormsScreenEnv = {
    notify,
    navigate,
    transport: {
      request: async (method: string, path: string) => {
        calls.push([method, path]);
        if (path.startsWith('/api/v1/me/form-drafts')) return { status: 200, body: { data: drafts } };
        if (path.endsWith('/withdraw')) {
          if (withdrawStatus === 200) current = (current as Array<Record<string, unknown>>).map((r) => ({ ...r, state: 'withdrawn' }));
          return { status: withdrawStatus, body: withdrawBody };
        }
        if (method === 'DELETE') return { status: 200, body: { data: { deleted: true } } };
        if (/\/me\/form-submissions\/\d+$/.test(path)) {
          return {
            status: 200,
            body: {
              data: {
                ...row({ data: { supplier: 'Example Mill' } }),
                form_version_now: 1,
                fields: [{ field_key: 'supplier', field_type: 'text', label: { en: 'Supplier' }, options: [], validation: [] }],
              },
            },
          };
        }
        return { status: 200, body: { data: current } };
      },
    } as FormsScreenEnv['transport'],
  };
  render(<MySubmissionsScreen env={env} />);

  return { calls, notify, navigate };
}

async function openMenu(name: string) {
  await userEvent.click(await screen.findByRole('button', { name: `More actions for ${name}` }));
}

describe('My submissions', () => {
  it('withdraws only after a dialog that says it cannot be undone', async () => {
    const { calls, notify } = setUp([row()]);

    await openMenu('Supplier request');
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Withdraw' }));

    const dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).getByText(/can't be undone/)).toBeTruthy();
    expect(calls.some(([, path]) => path.endsWith('/withdraw'))).toBe(false);

    await userEvent.click(within(dialog).getByRole('button', { name: 'Withdraw submission' }));

    await waitFor(() => expect(calls).toContainEqual(['POST', '/api/v1/me/form-submissions/5/withdraw']));
    await waitFor(() => expect(notify).toHaveBeenCalledWith('Supplier request was withdrawn.', 'success'));
    expect(await screen.findByText('Withdrawn')).toBeTruthy();
  });

  it('says a withdrawn one is withdrawn, not that it went nowhere, and offers nothing more', async () => {
    setUp([row({ state: 'withdrawn' })]);

    expect(await screen.findByText('Withdrawn')).toBeTruthy();
    expect(screen.queryByText('Not sent anywhere')).toBeNull();

    await openMenu('Supplier request');
    expect(screen.queryByRole('menuitem', { name: 'Withdraw' })).toBeNull();
  });

  it('does not offer to withdraw a finished one', async () => {
    setUp([row({ state: 'completed' })]);

    await openMenu('Supplier request');

    expect(screen.queryByRole('menuitem', { name: 'Withdraw' })).toBeNull();
  });

  it('explains when somebody has acted on it since the list loaded', async () => {
    const { notify } = setUp([row()], [], 409, { error: 'decided', details: { reason: 'already_decided' } });

    await openMenu('Supplier request');
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Withdraw' }));
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Withdraw submission' }));

    await waitFor(() => expect(notify).toHaveBeenCalledWith("Somebody has already acted on this, so it can't be withdrawn.", 'error'));
  });

  it('opens a sent one from its own address, with what was answered', async () => {
    const { calls } = setUp([row()]);

    await userEvent.click(await screen.findByText('SUP-0005'));

    expect(await screen.findByText('Example Mill')).toBeTruthy();
    expect(calls).toContainEqual(['GET', '/api/v1/me/form-submissions/5']);
    expect(calls.some(([, path]) => path.startsWith('/api/v1/form-submissions/'))).toBe(false);
  });

  it('lists drafts first, and opening one carries on with it', async () => {
    const { navigate } = setUp([row()], [draft]);

    expect(await screen.findByText('Draft · step 2')).toBeTruthy();
    const cells = screen.getAllByRole('row').map((r) => r.textContent ?? '');
    expect(cells[1]).toContain('Leave request');

    await userEvent.click(screen.getByText('Leave request'));

    expect(navigate).toHaveBeenCalledWith('/forms/9');
  });

  it('deletes a draft after asking', async () => {
    const { calls } = setUp([], [draft]);

    await openMenu('Leave request');
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Delete draft' }));
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Delete draft' }));

    await waitFor(() => expect(calls).toContainEqual(['DELETE', '/api/v1/me/forms/9/draft']));
  });

  it('finds one by its name or reference', async () => {
    setUp([row(), row({ id: 6, reference: 'EXP-0219', form_name: { en: 'Expense claim' } })]);

    await screen.findByText('SUP-0005');
    await userEvent.type(screen.getByRole('searchbox', { name: 'Search your submissions' }), 'exp-02');

    expect(screen.queryByText('SUP-0005')).toBeNull();
    expect(screen.getByText('EXP-0219')).toBeTruthy();
  });
});
