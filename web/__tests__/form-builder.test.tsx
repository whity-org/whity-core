/**
 * The form builder: the pure editing rules, and the record page against a fake API.
 * The fixture is an intake form for Example Bakery, with invented questions.
 */

import { readFileSync } from 'fs';
import { join } from 'path';
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { FormBuilderScreen, FormsListScreen } from '@whity/features/forms';
import { ResponsesTab } from '../../packages/features/src/forms/responses-tab';
import type { FormsScreenEnv } from '@whity/features/forms';
import {
  reorder,
  problemsOf,
  snapshot,
  questionIndexIn,
  duplicateField,
  groupBySection,
  KIND_ORDER,
  moveField,
  newField,
  relabel,
  removeField,
} from '../../packages/features/src/forms/builder-model';
import { fieldBody } from '../../packages/features/src/forms/api';
import type { FieldDraft, FieldKind, FieldValidation } from '../../packages/features/src/forms/types';

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
  const vectors = JSON.parse(
    readFileSync(join(__dirname, '../../tests/Unit/Core/Form/question-body-vectors.json'), 'utf8')
  ) as Record<string, Record<string, unknown>>;

  /** A new question as an author finishes it: every wording filled in. */
  function written(kind: FieldKind): FieldDraft {
    const field = newField(kind, [], null);
    const validation = field.validation as FieldValidation;
    return {
      ...field,
      label: { en: 'Question', ar: 'سؤال' },
      options: field.options.map((o) => ({ ...o, label: { en: 'Choice', ar: 'خيار' } })),
      validation: Array.isArray(field.validation) || validation.columns === undefined
        ? field.validation
        : { ...validation, columns: validation.columns.map((c) => ({ ...c, label: { en: 'Column', ar: 'عمود' } })) },
    };
  }

  it('holds every kind to the vectors the server runs', () => {
    expect(Object.keys(vectors).sort()).toEqual([...KIND_ORDER].sort());
  });

  it.each([...KIND_ORDER])('sends a new %s question as the server keeps it, and sees no change when it comes back', (kind) => {
    const sent = fieldBody(written(kind));
    expect(sent).toEqual(vectors[kind]);
    const back = { ...(vectors[kind] as unknown as FieldDraft), id: 3, help_text_i18n: [] as [], extra: 'server' };
    expect(snapshot(fieldBody(back))).toBe(snapshot(sent));
  });

  it.each([...KIND_ORDER])('forgets what a %s question no longer uses', (kind) => {
    const was: FieldDraft = {
      ...written(kind),
      label: { en: ' Question ', ar: 'سؤال ' },
      help_text_i18n: { en: '  ' },
      options: [{ value: 'kept', label: { en: 'Kept' } }],
      validation: { ...(written(kind).validation as FieldValidation), pattern: ' ', minRows: 2 },
    };
    const body = fieldBody(was) as { label: unknown; help_text: unknown; options: unknown[]; validation: Record<string, unknown> };
    expect(body.label).toEqual({ en: 'Question', ar: 'سؤال' });
    expect(body.help_text).toBeNull();
    expect(body.validation.pattern).toBeUndefined();
    if (kind !== 'select' && kind !== 'multiselect') expect(body.options).toEqual([]);
    if (kind !== 'repeating') expect(body.validation.columns).toBeUndefined();
    if (kind !== 'repeating') expect(body.validation.minRows).toBeUndefined();
  });

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

  it('compares objects by their contents, because the server returns labels in another order', () => {
    const typed = [{ field_key: 'kind', label: { en: 'Kind', ar: 'نوع' }, options: [{ value: 'a', label: { en: 'A', ar: 'أ' } }] }];
    const stored = [{ options: [{ label: { ar: 'أ', en: 'A' }, value: 'a' }], label: { ar: 'نوع', en: 'Kind' }, field_key: 'kind' }];
    expect(snapshot(typed)).toBe(snapshot(stored));
  });

  it('still sees a changed answer and a changed order', () => {
    expect(snapshot({ label: { en: 'Kind' } })).not.toBe(snapshot({ label: { en: 'Sort' } }));
    expect(snapshot([{ field_key: 'a' }, { field_key: 'b' }])).not.toBe(snapshot([{ field_key: 'b' }, { field_key: 'a' }]));
  });

  it('holds a save while a new choice has no wording', () => {
    const fields = [draft('kind', {
      field_type: 'select',
      options: [{ value: 'a', label: { en: 'Flour' } }, { value: 'b', label: {} }],
    })];
    expect(problemsOf(fields, { en: 'Form' }, ['en']).map((p) => [p.code, p.blocking])).toEqual([['blankChoice', true]]);
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

  it('stops flagging a language that is switched off', () => {
    const fields = [draft('a', { label: { en: 'Only English' } })];
    expect(problemsOf(fields, { en: 'F' }, ['en', 'ar']).map((p) => p.code)).toEqual(['missingLanguage']);
    expect(problemsOf(fields, { en: 'F' }, ['en'])).toEqual([]);
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

/** Selects the one saved question and gives back its English wording box. */
async function screenLabel() {
  await userEvent.click(await screen.findByText(/Supplier name/));
  return screen.findByLabelText(/English/, { selector: '#q-label-en' });
}

function fakeEnv(calls: Array<{ method: string; path: string; body?: unknown }>, form: Record<string, unknown> = FORM): FormsScreenEnv {
  return {
    transport: {
      async request(method: string, path: string, body?: unknown) {
        calls.push({ method, path, body });
        if (method === 'GET' && path === '/api/v1/forms/7') return { status: 200, body: { data: { ...form, fields: [] } } };
        if (method === 'GET' && path === '/api/v1/forms/7/fields') {
          return {
            status: 200,
            body: {
              data: [draft('supplier', { label: { en: 'Supplier name' }, is_required: true, section_key: (form as { sections: unknown[] }).sections.length ? 's1' : null })],
              meta: { field_types: ['text', 'textarea', 'select'] },
            },
          };
        }
        if (method === 'GET' && path.startsWith('/api/v1/forms')) return { status: 200, body: { data: [form] } };
        if (method === 'POST' && path === '/api/v1/forms/7/member-access') return { status: 200, body: { data: { ...form, open_to_members: true } } };
        if (method === 'DELETE' && path === '/api/v1/forms/7') return { status: 200, body: { data: { deleted: true } } };
        if (method === 'PUT') return { status: 200, body: { data: (body as { fields: FieldDraft[] }).fields } };
        return { status: 404, body: null };
      },
    },
    notify: jest.fn(),
    navigate: jest.fn(),
  };
}

describe('FormsListScreen', () => {
  it('shows how many responses a form has, and deletes an unanswered draft after asking', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    const env = fakeEnv(calls, { ...FORM, submission_count: 4 });
    render(<FormsListScreen env={env} canManage />);
    await screen.findByText('Supplier form');
    expect(screen.getByText('4')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /More actions/ }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }));
    expect(await screen.findByText(/It has 4 responses/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Delete form' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE')).toBe(true));
  });

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

  it('keeps the question settings in view while the questions scroll, and starts each question at the top', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByText(/Supplier name/));
    const scroller = screen.getByTestId('question-panel-scroll');
    expect(scroller.className).toMatch(/lg:sticky/);
    expect(scroller.className).toMatch(/lg:overflow-y-auto/);
    // A box between the panel and the page that clips with `overflow-hidden`
    // becomes the sticky's scroll container, and the panel scrolls away (WHIT-1713).
    for (let el = scroller.parentElement; el !== null; el = el.parentElement) {
      expect(el.className).not.toMatch(/(^|\s)overflow-hidden(\s|$)/);
    }
    scroller.scrollTop = 120;
    await userEvent.click(screen.getByRole('button', { name: /Add question/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'Short text' }));
    await waitFor(() => expect(scroller.scrollTop).toBe(0));
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

  it('saves a draft by itself after a pause, and leaves an open form to the save bar', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    render(<FormBuilderScreen env={fakeEnv(calls)} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByRole('button', { name: /Add question/ }));
    await userEvent.click(await screen.findByRole('button', { name: 'Short text' }));
    await userEvent.type(await screen.findByLabelText(/English/, { selector: '#q-label-en' }), 'Delivery day');
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true), { timeout: 6000 });
  }, 12000);

  it('saves a table of rows once, however differently the server words it back', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    const env = fakeEnv(calls);
    const request = env.transport.request.bind(env.transport);
    env.transport.request = (async (method: string, path: string, body?: unknown) => {
      const reply = await request(method, path, body);
      if (method !== 'PUT') return reply;
      // Whatever a server adds to what it was sent is not somebody's edit.
      const rows = (body as { fields: FieldDraft[] }).fields.map((f) => ({ ...f, id: 5, help_text_i18n: {}, label: { en: 'Worded by the server' }, validation: { ...f.validation, added: true } }));
      return { status: 200, body: { data: rows } };
    }) as typeof env.transport.request;
    render(<FormBuilderScreen env={env} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.type(await screenLabel(), '!');
    await waitFor(() => expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(1), { timeout: 6000 });
    await new Promise((resolve) => setTimeout(resolve, 3500));
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(1);
  }, 15000);

  it('tries a refused save once, and again only after the next edit', async () => {
    const calls: Array<{ method: string; path: string; body?: unknown }> = [];
    const env = fakeEnv(calls);
    const request = env.transport.request.bind(env.transport);
    env.transport.request = (async (method: string, path: string, body?: unknown) => {
      const reply = await request(method, path, body);
      return method === 'PUT' ? { status: 422, body: { error: 'Refused' } } : reply;
    }) as typeof env.transport.request;
    render(<FormBuilderScreen env={env} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.type(await screenLabel(), '!');
    await waitFor(() => expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(1), { timeout: 6000 });
    await new Promise((resolve) => setTimeout(resolve, 3500));
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(1);
    expect(env.notify).toHaveBeenCalledTimes(1);
  }, 15000);

  it('collapses a section to its bar and opens it again', async () => {
    const withSections = { ...FORM, sections: [{ key: 's1', title: { en: 'Part one' }, description: {} }] };
    render(<FormBuilderScreen env={fakeEnv([], withSections)} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    const bar = screen.getByRole('button', { name: /Part one/ });
    await userEvent.click(bar);
    expect(screen.queryByText(/Supplier name/)).not.toBeInTheDocument();
    await userEvent.click(bar);
    expect(await screen.findByText(/Supplier name/)).toBeInTheDocument();
  });

  it('shows one language at a time from the selector, and drops a language switched off', async () => {
    render(<FormBuilderScreen env={fakeEnv([])} formId={7} canManage />);
    await screen.findByText(/Supplier name/);
    await userEvent.click(screen.getByText(/Supplier name/));
    expect(document.getElementById('q-label-en')).not.toBeNull();
    expect(document.getElementById('q-label-ar')).not.toBeNull();

    await userEvent.click(screen.getByRole('radio', { name: 'Arabic' }));
    expect(document.getElementById('q-label-en')).toBeNull();
    expect(document.getElementById('q-label-ar')).not.toBeNull();

    await userEvent.click(screen.getByRole('tab', { name: 'Settings' }));
    await userEvent.click(await screen.findByRole('switch', { name: 'Arabic' }));
    await userEvent.click(screen.getByRole('tab', { name: 'Questions' }));
    await userEvent.click(screen.getByText(/Supplier name/));
    expect(document.getElementById('q-label-ar')).toBeNull();
    expect(document.getElementById('q-label-en')).not.toBeNull();
  });
});

describe('ResponsesTab', () => {
  it('shows the counts and an export link above the table', async () => {
    const env: FormsScreenEnv = {
      transport: {
        async request(_method: string, path: string) {
          if (path.endsWith('/responses/summary')) {
            return {
              status: 200,
              body: {
                data: {
                  total: 3,
                  per_week: [{ week: '2026-10-26', count: 3 }],
                  routing: { not_routed: 1, in_progress: 2, completed: 0 },
                  questions: [{ field_key: 'role', label: { en: 'Role' }, options: [{ value: 'a', label: { en: 'Baker' }, count: 2 }] }],
                },
              },
            };
          }
          if (path.startsWith('/api/v1/form-submissions')) {
            return { status: 200, body: { data: [{ id: 1, form_id: 7, form_version: 1, submitted_by_profile_id: 2, submitted_by_name: 'Lina Saad', document_id: null, reference: 'S-1', data: {}, submitted_at: '2026-11-01 10:00:00' }] } };
          }
          return { status: 404, body: null };
        },
      },
      notify: jest.fn(),
      navigate: jest.fn(),
    };
    render(<ResponsesTab env={env} formId={7} />);
    expect(await screen.findByText('Baker')).toBeInTheDocument();
    expect(screen.getByText('Where they are')).toBeInTheDocument();
    expect(screen.getByText('Lina Saad')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Export responses' })).toHaveAttribute('href', '/api/v1/forms/7/responses/export?language=en');
  });
});
