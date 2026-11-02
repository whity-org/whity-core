/**
 * Several files, a table of rows and a signature, through the same FormField the
 * pages use. The fixtures are invented.
 */

import React, { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import {
  FormField,
  type Answer,
  type FormFieldSpec,
  type UploadedFileRef,
} from '@/components/forms/form-fields';
import { FormFill, type FillableField } from '@/components/forms/form-fill';

function spec(overrides: Partial<FormFieldSpec>): FormFieldSpec {
  return {
    field_key: 'q',
    field_type: 'text',
    label: { en: 'Question' },
    help_text: null,
    is_required: false,
    options: [],
    multi_valued: false,
    position: 0,
    ...overrides,
  };
}

function Harness({
  field,
  upload,
  onValue,
}: {
  field: FormFieldSpec;
  upload?: (file: File) => Promise<UploadedFileRef>;
  onValue: (value: Answer) => void;
}) {
  const [value, setValue] = useState<Answer | undefined>(undefined);

  return (
    <FormField
      field={field}
      value={value}
      preferArabic={false}
      upload={upload}
      onChange={(next) => {
        setValue(next);
        onValue(next);
      }}
    />
  );
}

const stored = (name: string): UploadedFileRef => ({
  reference: `tenants/1/form-uploads/7/${name}`,
  filename: name,
  content_type: 'application/pdf',
  byte_size: 2048,
  checksum_sha256: 'a'.repeat(64),
});

const file = (name: string) => new File(['%PDF-1.7'], name, { type: 'application/pdf' });

describe('several files on one question', () => {
  const field = spec({ field_type: 'file', multi_valued: true, validation: { maxFiles: 2 } });

  it('answers with a list of references, and each can be removed', async () => {
    const onValue = jest.fn();
    const upload = jest.fn(async (f: File) => stored(f.name));
    render(<Harness field={field} upload={upload} onValue={onValue} />);

    await userEvent.upload(screen.getByLabelText(/Question/), [file('a.pdf'), file('b.pdf')]);

    await waitFor(() => expect(onValue).toHaveBeenLastCalledWith([stored('a.pdf').reference, stored('b.pdf').reference]));
    expect(screen.getByText(/a\.pdf/)).toBeTruthy();

    await userEvent.click(screen.getAllByRole('button', { name: 'Remove' })[0]);
    expect(onValue).toHaveBeenLastCalledWith([stored('b.pdf').reference]);
  });

  it('keeps the file that worked when another fails, and says why', async () => {
    const onValue = jest.fn();
    const upload = jest
      .fn()
      .mockResolvedValueOnce(stored('a.pdf'))
      .mockRejectedValueOnce(new Error('That file is too large.'));
    render(<Harness field={field} upload={upload} onValue={onValue} />);

    await userEvent.upload(screen.getByLabelText(/Question/), [file('a.pdf'), file('b.pdf')]);

    expect((await screen.findByRole('alert')).textContent).toBe('That file is too large.');
    expect(onValue).toHaveBeenLastCalledWith([stored('a.pdf').reference]);
  });

  it('does not upload past the limit, and says so', async () => {
    const upload = jest.fn(async (f: File) => stored(f.name));
    render(<Harness field={field} upload={upload} onValue={jest.fn()} />);

    await userEvent.upload(screen.getByLabelText(/Question/), [file('a.pdf'), file('b.pdf'), file('c.pdf')]);

    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('up to 2'));
    expect(upload).toHaveBeenCalledTimes(2);
  });

  it('offers Word files in the picker', () => {
    render(<Harness field={spec({ field_type: 'file' })} upload={jest.fn()} onValue={jest.fn()} />);

    expect(screen.getByLabelText(/Question/).getAttribute('accept')).toContain('wordprocessingml');
  });
});

describe('a table of rows', () => {
  const field = spec({
    field_type: 'repeating',
    multi_valued: true,
    validation: {
      maxRows: 2,
      columns: [
        { key: 'name', type: 'text', label: { en: 'Name' }, required: true },
        { key: 'crates', type: 'number', label: { en: 'Crates' } },
      ],
    },
  });

  it('starts with a row to type into, and answers with rows keyed by column', async () => {
    const onValue = jest.fn();
    render(<Harness field={field} onValue={onValue} />);

    await userEvent.type(screen.getByLabelText(/Name/), 'M');

    expect(onValue).toHaveBeenLastCalledWith([{ name: 'M' }]);
  });

  it('adds rows up to the limit and removes them', async () => {
    const onValue = jest.fn();
    render(<Harness field={field} onValue={onValue} />);

    await userEvent.type(screen.getByLabelText(/Name/), 'M');
    await userEvent.click(screen.getByRole('button', { name: 'Add a row' }));

    expect(screen.getAllByLabelText(/Name/)).toHaveLength(2);
    expect((screen.getByRole('button', { name: 'Add a row' }) as HTMLButtonElement).disabled).toBe(true);

    await userEvent.click(screen.getAllByRole('button', { name: 'Remove row' })[1]);
    expect(onValue).toHaveBeenLastCalledWith([{ name: 'M' }]);
  });

  it('never draws a table question as a list of choices', () => {
    render(<Harness field={field} onValue={jest.fn()} />);

    expect(screen.queryByRole('checkbox')).toBeNull();
  });
});

describe('a signature', () => {
  it('answers with the name alone, so the time stays the server’s', async () => {
    const onValue = jest.fn();
    render(<Harness field={spec({ field_type: 'signature' })} onValue={onValue} />);

    await userEvent.type(screen.getByLabelText(/Question/), 'R');

    expect(onValue).toHaveBeenLastCalledWith({ name: 'R' });
    expect(screen.getByText(/counts as your signature/)).toBeTruthy();
  });
});

describe('required, and Review, for these kinds', () => {
  function Fill({ fields, submit }: { fields: FillableField[]; submit: jest.Mock }) {
    const [answers, setAnswers] = useState<Record<string, Answer>>({
      table: [{ name: 'Example Mill', crates: '4' }],
      signed: { name: 'Rana Example' },
      none: { name: '  ' },
      empty_table: [{ name: '' }],
    });

    return (
      <FormFill
        fields={fields}
        sections={[
          { key: 'a', field_keys: fields.map((f) => f.field_key) },
          { key: 'b', field_keys: ['extra'] },
        ]}
        answers={answers}
        onAnswersChange={setAnswers}
        preferArabic={false}
        submitLabel="Send"
        submit={submit}
      />
    );
  }

  const columns = [{ key: 'name', type: 'text', label: { en: 'Name' } }];

  it('treats a blank signature and a table of blank rows as unanswered', async () => {
    const fields: FillableField[] = [
      spec({ field_key: 'none', field_type: 'signature', label: { en: 'Sign here' }, is_required: true, position: 1 }),
      spec({ field_key: 'empty_table', field_type: 'repeating', label: { en: 'Suppliers' }, is_required: true, position: 2, validation: { columns } }),
      spec({ field_key: 'extra', position: 3 }),
    ];
    render(<Fill fields={fields} submit={jest.fn()} />);

    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    const alerts = screen.getAllByRole('alert').map((alert) => alert.textContent);
    expect(alerts).toEqual(['Sign here is required.', 'Suppliers is required.']);
  });

  it('shows rows and the signature in plain words on Review', async () => {
    const fields: FillableField[] = [
      spec({ field_key: 'table', field_type: 'repeating', label: { en: 'Suppliers' }, position: 1, validation: { columns } }),
      spec({ field_key: 'signed', field_type: 'signature', label: { en: 'Signature' }, position: 2 }),
      spec({ field_key: 'extra', position: 3 }),
    ];
    render(<Fill fields={fields} submit={jest.fn()} />);

    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));

    expect(screen.getByText('Name: Example Mill')).toBeTruthy();
    expect(screen.getByText('Rana Example')).toBeTruthy();
    expect(document.body.textContent).not.toContain('[object Object]');
  });
});
