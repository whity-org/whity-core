/**
 * A long form is steps, and a question is only asked when it applies. The fixture is
 * a supplier form for Example Bakery, with invented answers.
 */

import React, { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import type { Answer } from '@/components/forms/form-fields';
import { FormFill, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import type { RefusalBody } from '@/components/forms/form-refusals';

function field(key: string, overrides: Partial<FillableField> = {}): FillableField {
  return {
    field_key: key,
    field_type: 'text',
    label: { en: key.replace('_', ' '), ar: `ع ${key}` },
    help_text: null,
    is_required: false,
    options: [],
    multi_valued: false,
    position: 0,
    visible_when: null,
    ...overrides,
  };
}

const FIELDS: FillableField[] = [
  field('supplier', { is_required: true, position: 1 }),
  field('imports', { field_type: 'checkbox', position: 2 }),
  field('customs_code', { is_required: true, position: 3, visible_when: { field: 'imports', equals: true } }),
  field('crates', { field_type: 'number', position: 4 }),
];

const SECTIONS: FormSectionSpec[] = [
  { key: 'about', field_keys: ['supplier', 'imports', 'customs_code'], title: { en: 'About', ar: 'عن المورد' } },
  { key: 'goods', field_keys: ['crates'], title: { en: 'Goods' } },
];

function Harness({
  fields = FIELDS,
  sections = SECTIONS,
  submit,
  preferArabic = false,
}: {
  fields?: FillableField[];
  sections?: FormSectionSpec[];
  submit: (answers: Record<string, Answer>) => Promise<RefusalBody | null>;
  preferArabic?: boolean;
}) {
  const [answers, setAnswers] = useState<Record<string, Answer>>({});

  return (
    <FormFill
      fields={fields}
      sections={sections}
      answers={answers}
      onAnswersChange={setAnswers}
      preferArabic={preferArabic}
      submitLabel="Send request"
      submit={submit}
    />
  );
}

const button = (name: string) => screen.getByRole('button', { name });
const box = (name: string) => screen.getByLabelText(new RegExp(name));

describe('FormFill', () => {
  it('is one page with no Stepper when there is one section', async () => {
    const submit = jest.fn().mockResolvedValue(null);
    render(<Harness submit={submit} sections={[{ key: null, field_keys: FIELDS.map((f) => f.field_key) }]} />);

    expect(screen.queryByRole('navigation')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Continue' })).toBeNull();

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Send request'));

    await waitFor(() => expect(submit).toHaveBeenCalledWith({ supplier: 'Example Mill' }));
  });

  it('shows one section at a time, then a Review with the wording the form chose', async () => {
    render(<Harness submit={jest.fn()} />);

    expect(box('supplier')).toBeTruthy();
    expect(screen.queryByLabelText(/crates/)).toBeNull();

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));

    expect(box('crates')).toBeTruthy();
    expect(screen.queryByLabelText(/supplier/)).toBeNull();

    await userEvent.click(button('Continue'));

    expect(screen.getByText('Example Mill')).toBeTruthy();
    expect(button('Send request')).toBeTruthy();
  });

  it('will not leave a step with a required question unanswered, and says which', async () => {
    render(<Harness submit={jest.fn()} />);

    await userEvent.click(button('Continue'));

    expect(screen.getByRole('alert').textContent).toBe('supplier is required.');
    expect(box('supplier')).toBeTruthy();
  });

  it('does not ask a conditional question until its answer matches, and then requires it', async () => {
    render(<Harness submit={jest.fn()} />);

    expect(screen.queryByLabelText(/customs code/)).toBeNull();

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(box('imports'));
    expect(box('customs code')).toBeTruthy();

    await userEvent.click(button('Continue'));
    expect(screen.getByRole('alert').textContent).toBe('customs code is required.');
  });

  it('does not send an answer to a question that stopped being asked', async () => {
    const submit = jest.fn().mockResolvedValue(null);
    render(<Harness submit={submit} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(box('imports'));
    await userEvent.type(box('customs code'), 'X1');
    await userEvent.click(box('imports'));

    await userEvent.click(button('Continue'));
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Send request'));

    await waitFor(() => expect(submit).toHaveBeenCalledTimes(1));
    expect(submit.mock.calls[0][0]).toEqual({ supplier: 'Example Mill', imports: false });
  });

  it('leaves out a step with nothing to ask, so one left over is one page', () => {
    const fields = [
      field('supplier', { position: 1 }),
      field('customs_code', { position: 2, visible_when: { field: 'supplier', equals: 'x' } }),
    ];

    render(
      <Harness
        submit={jest.fn()}
        fields={fields}
        sections={[
          { key: 'a', field_keys: ['supplier'] },
          { key: 'b', field_keys: ['customs_code'] },
        ]}
      />
    );

    expect(screen.queryByRole('navigation')).toBeNull();
    expect(button('Send request')).toBeTruthy();
  });

  it('takes the reader back to the question the server refused', async () => {
    const submit = jest.fn().mockResolvedValue({
      error: 'supplier is not in the expected format',
      details: { reason: 'bad_format', field_key: 'supplier' },
    });
    render(<Harness submit={submit} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Send request'));

    await screen.findByRole('alert');
    expect(screen.getByRole('alert').textContent).toBe('supplier is not in the expected format.');
    expect(box('supplier')).toBeTruthy();
  });

  it('shows a refusal about no question on the page it happened', async () => {
    const submit = jest.fn().mockResolvedValue({ error: 'This form is not accepting submissions right now' });
    render(<Harness submit={submit} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Send request'));

    expect((await screen.findByRole('alert')).textContent).toBe('This form is not accepting submissions right now');
  });

  it('says so when the connection fails, and keeps the answers', async () => {
    const submit = jest.fn().mockRejectedValue(new Error('network'));
    render(<Harness submit={submit} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Send request'));

    expect((await screen.findByRole('alert')).textContent).toContain('connection');
    expect(screen.getByText('Example Mill')).toBeTruthy();
  });

  it('uses the section title in the reader language, and a plain part number without one', () => {
    render(<Harness submit={jest.fn()} preferArabic />);

    expect(screen.getAllByText('عن المورد').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Goods').length).toBeGreaterThan(0);
  });

  it('keeps the answers of a section after Back and Continue', async () => {
    render(<Harness submit={jest.fn()} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));
    // A number box, set directly: simulated typing into one is not reliable in jsdom.
    fireEvent.change(box('crates'), { target: { value: '12' } });
    await userEvent.click(button('Back'));

    expect((box('supplier') as HTMLInputElement).value).toBe('Example Mill');
    await userEvent.click(button('Continue'));
    expect((box('crates') as HTMLInputElement).value).toBe('12');
  });

  describe('a form with more sections than the Stepper holds', () => {
    // Ten sections and Review, like an ethics application (WHIT-943).
    const many = Array.from({ length: 10 }, (_, index) => field(`q${index}`, { is_required: index === 0, position: index }));
    const manySections: FormSectionSpec[] = many.map((one, index) => ({
      key: `s${index}`,
      field_keys: [one.field_key],
      title: { en: `Part title ${index + 1}` },
    }));

    it('says which step of how many, names the section, and does not stack every section', () => {
      render(<Harness submit={jest.fn()} fields={many} sections={manySections} />);

      expect(screen.getByText('Step 1 of 11')).toBeTruthy();
      expect(screen.getByRole('heading', { name: 'Part title 1' })).toBeTruthy();
      expect(screen.queryByText('Part title 2')).toBeNull();
      expect(screen.queryByRole('list')).toBeNull();
    });

    it('opens a section already reached from the menu, and not one ahead of it', async () => {
      render(<Harness submit={jest.fn()} fields={many} sections={manySections} />);

      await userEvent.type(box('q0'), 'Example Mill');
      await userEvent.click(button('Continue'));
      await userEvent.click(button('Continue'));
      expect(screen.getByText('Step 3 of 11')).toBeTruthy();

      await userEvent.click(button('All sections'));
      expect(screen.getByRole('menuitem', { name: /Part title 5/ })).toHaveAttribute('aria-disabled', 'true');
      await userEvent.click(screen.getByRole('menuitem', { name: /Part title 1(?!0)/ }));

      expect(screen.getByText('Step 1 of 11')).toBeTruthy();
      expect((box('q0') as HTMLInputElement).value).toBe('Example Mill');
    });
  });

  it('goes back from Review to the step that holds an answer', async () => {
    render(<Harness submit={jest.fn()} />);

    await userEvent.type(box('supplier'), 'Example Mill');
    await userEvent.click(button('Continue'));
    await userEvent.click(button('Continue'));
    await userEvent.click(screen.getAllByRole('button', { name: 'Change' })[0]);

    expect(box('supplier')).toBeTruthy();
  });
});
