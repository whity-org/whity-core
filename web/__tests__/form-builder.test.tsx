/**
 * The form builder: the pure editing rules, and the record page against a fake API.
 * The fixture is an intake form for Example Bakery, with invented questions.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { FormBuilderScreen, FormsListScreen } from '@whity/features/forms';
import type { FormsScreenEnv } from '@whity/features/forms';
import {
  reorder,
  problemsOf,
  questionIndexIn,
  duplicateField,
  groupBySection,
  moveField,
  newField,
  relabel,
  removeField,
} from '../../packages/features/src/forms/builder-model';
import type { FieldDraft } from '../../packages/features/src/forms/types';

function draft(key: string, overrides: Partial<FieldDraft> = {}): FieldDraft {
  return {
    id: 1,
    field_key: key,
    field_type: 'text',
    label: { en: key },
    help_text_i18n: {},
    is_required: false,
    options: [],
    validation: {},
    prefill_source: null,
    section_key: null,
    visible_when: null,
    audience: 'applicant',
    ...overrides,
  };
}

describe('builder model', () => {
  it('gives a new question its own key and renames it from its wording until it is saved', () => {
    const fields = [draft('supplier')];
    const added = newField('text', fields, null);
    expect(added.field_key).toBe('question');
    const renamed = relabel([...fields, added], 1, { en: 'Delivery day' });
    expect(renamed[1].field_key).toBe('delivery_day');
  });

  it('keeps the key of a saved question and rewrites rules in a new one that read it', () => {
    const fields = [
      draft('imports', { field_type: 'checkbox' }),
      { ...newField('text', [], null), field_key: 'customs', visible_when: { field: 'imports', equals: true } },
    ];
    const kept = relabel(fields, 0, { en: 'Brings goods in' });
    expect(kept[0].field_key).toBe('imports');
    const renamed = relabel([{ ...fields[1], id: undefined }, { ...fields[0], id: undefined, field_key: 'x' }], 1, { en: 'Imports' });
    expect(renamed[1].field_key).toBe('imports');
  });

  it('moves a question only among the questions of its own section', () => {
    const fields = [draft('a', { section_key: 's1' }), draft('b', { section_key: 's2' }), draft('c', { section_key: 's1' })];
    expect(moveField(fields, 2, -1).map((f) => f.field_key)).toEqual(['c', 'b', 'a']);
    expect(moveField(fields, 0, -1).map((f) => f.field_key)).toEqual(['a', 'b', 'c']);
  });

  it('drops a rule when the question it reads is removed, and copies without history', () => {
    const fields = [draft('imports', { field_type: 'checkbox' }), draft('customs', { visible_when: { field: 'imports', equals: true } })];
    expect(removeField(fields, 0)[0].visible_when).toBeNull();
    const copy = duplicateField(fields, 0);
    expect(copy[1].id).toBeUndefined();
    expect(copy[1].field_key).toBe('imports_copy');
  });

  it('drops a dragged question into the place and section of the one it lands on', () => {
    const fields = [draft('a', { section_key: 's1' }), draft('b', { section_key: 's1' }), draft('c', { section_key: 's2' })];
    const next = reorder(fields, 0, 2);
    expect(next.map((f) => f.field_key)).toEqual(['b', 'c', 'a']);
    expect(next[2].section_key).toBe('s2');
  });

  it('names what is missing: wording, choices, and a language left empty', () => {
    const fields = [
      draft('a', { label: {} }),
      draft('b', { field_type: 'select', options: [{ value: 'x', label: {} }] }),
      draft('c', { label: { en: 'Only English' } }),
    ];
    const found = problemsOf(fields, { en: 'Form' }).map((p) => [p.code, p.index, p.blocking]);
    expect(found).toEqual([
      ['noWording', 0, true],
      ['noChoices', 1, true],
      ['missingLanguage', 1, false],
      ['missingLanguage', 2, false],
    ]);
    expect(problemsOf([], {}).map((p) => p.code)).toEqual(['noName']);
    expect(questionIndexIn('fields[3].label is required')).toBe(3);
    expect(questionIndexIn('Failed')).toBeNull();
  });

  it('groups questions under their sections, unsectioned first', () => {
    const groups = groupBySection([draft('a', { section_key: 's1' }), draft('b')], ['s1', 's2']);
    expect(groups.map((g) => [g.key, g.items.length])).toEqual([[null, 1], ['s1', 1], ['s2', 0]]);
  });
});

const FORM = {
  id: 7,
  form_key: 'supplier',
  name: { en: 'Supplier form', ar: 'نموذج المورد' },
  description: null,
  description_i18n: {},
  submit_label: null,
  reference_format: null,
  status: 'draft',
  version: 1,
  route_template_id: null,
  created_at: '2026-11-01 10:00:00',
  updated_at: '2026-11-02 10:00:00',
  public_enabled: false,
  public_slug: null,
  public_url: null,
  public_opens_at: null,
  public_closes_at: null,
  sections: [],
};

function fakeEnv(calls: Array<{ method: string; path: string; body?: unknown }>, form: Record<string, unknown> = FORM): FormsScreenEnv {
  return {
    transport: {
      async request(method: string, path: string, body?: unknown) {
        calls.push({ method, path, body });
        if (method === 'GET' && path === '/api/v1/forms/7') return { status: 200, body: { data: { ...form, fields: [], sections: [] } } };
        if (method === 'GET' && path === '/api/v1/forms/7/fields') {
          return {
            status: 200,
            body: {
              data: [draft('supplier', { label: { en: 'Supplier name' }, is_required: true })],
              meta: { field_types: ['text', 'textarea', 'select'] },
            },
          };
        }
        if (method === 'GET' && path.startsWith('/api/v1/forms')) return { status: 200, body: { data: [form] } };
        if (method === 'POST' && path === '/api/v1/forms/7/member-access') return { status: 200, body: { data: { ...form, open_to_members: true } } };
        if (method === 'PUT') return { status: 200, body: { data: (body as { fields: FieldDraft[] }).fields } };
        return { status: 404, body: null };
      },
    },
    notify: jest.fn(),
    navigate: jest.fn(),
  };
}

describe('FormsListScreen', () => {
  it('lists forms with their status and opens one', async () => {
    const env = fakeEnv([]);
    render(<FormsListScreen env={env} canManage />);
    const row = await screen.findByText('Supplier form');
    expect(screen.getByText('Draft')).toBeInTheDocument();
    await userEvent.click(row);
    expect(env.navigate).toHaveBeenCalledWith('/admin/x/forms-builder/7');
  });
});

describe('FormBuilderScreen', () => {
  it('shows the questions, and saves a changed set through one request', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    const env = fakeEnv(calls);
    render(<FormBuilderScreen env={env} formId={7} canManage />);

    await screen.findByText(/Supplier name/);
    expect(screen.queryByText('Save changes')).not.toBeInTheDocument();

    await userEvent.click(screen.getByText(/Supplier name/));
    await userEvent.click(screen.getByLabelText('Required'));

    await userEvent.click(await screen.findByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true));
    const put = calls.find((c) => c.method === 'PUT');
    expect(put?.path).toBe('/api/v1/forms/7/fields');
    expect((put?.body as { fields: Array<{ is_required: boolean }> }).fields[0].is_required).toBe(false);
  });

  it('offers only the kinds this server can store', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('button', { name: /Add question/ }));
    expect(await screen.findByRole('button', { name: 'Choose one' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Signature' })).not.toBeInTheDocument();
  });

  it('is read-only without permission to manage', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage={false} />);
    await screen.findByText(/Supplier name/);
    expect(screen.getByText('You can look, not change')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Add question/ })).not.toBeInTheDocument();
  });

  it('shows the open-to-members switch once the server offers it, and turns it on', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    const env = fakeEnv(calls, { ...FORM, status: 'published', open_to_members: false });
    render(<FormBuilderScreen env={env} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    await userEvent.click(await screen.findByRole('switch', { name: 'Not open to members' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/api/v1/forms/7/member-access')).toBe(true));
    expect(await screen.findByRole('switch', { name: 'Open to members' })).toBeChecked();
  });

  it('does not show the switch while the server does not offer it', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    expect(screen.queryByText('Not open to members')).not.toBeInTheDocument();
  });

  it('puts the cursor in the wording of a question just added', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('button', { name: /Add question/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'Short text' }));
    await waitFor(() => expect(document.activeElement?.id).toBe('q-label-en'));
  });

  it('refuses to save a question with no wording, and says which one', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    render(<FormBuilderScreen env={fakeEnv(calls)} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('button', { name: /Add question/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'Short text' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Question 2 has no wording yet.');
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
  });

  it('asks before opening a form, and says what it is opening', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }));
    expect(await screen.findByText('Open this form?')).toBeInTheDocument();
    expect(screen.getByText(/It has 1 questions, 1 of them required/)).toBeInTheDocument();
  });
});
