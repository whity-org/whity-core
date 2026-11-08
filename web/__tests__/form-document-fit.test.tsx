/**
 * The warning under a form's final document template, and what its buttons ask for.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { FormDocumentFit } from '../../packages/features/src/forms/form-document-fit';
import type { DocumentFit, FieldDraft } from '../../packages/features/src/forms/types';

const FIT: DocumentFit = {
  hidden: false,
  template: { id: 5, title: 'Supplier letter', lang: 'en', published: true },
  filled: 1,
  needs: [
    { path: 'template.delivery_date', label: 'Delivery date', reason: 'not_asked', field_key: 'delivery_date' },
    { path: 'template.po', label: 'Order number', reason: 'not_asked', field_key: 'po' },
    { path: 'submission.office', label: 'Office note', reason: 'staff_only', field_key: null },
    { path: 'patient.name', label: 'Patient', reason: 'other_record', field_key: null },
  ],
  unused: [{ field_key: 'notes', label: { en: 'Notes' }, in_draft: false, left_out: false }],
  can_edit_template: true,
  route_uses_other_template: false,
};

function setup(overrides: Partial<DocumentFit> = {}, props: Partial<React.ComponentProps<typeof FormDocumentFit>> = {}) {
  const handlers = {
    onAddToForm: jest.fn(),
    onAddToTemplate: jest.fn(),
    onLeaveOut: jest.fn(),
    onAskAgain: jest.fn(),
    onOpenTemplate: jest.fn(),
  };
  render(
    <FormDocumentFit
      fit={{ ...FIT, ...overrides }}
      fields={[]}
      formClosed={false}
      formOpen={false}
      readOnly={false}
      busy={false}
      dirty={false}
      {...handlers}
      {...props}
    />
  );
  return handlers;
}

describe('FormDocumentFit', () => {
  it('adds only the ticked template fields to the form', async () => {
    const { onAddToForm } = setup();

    await userEvent.click(screen.getByLabelText('Order number'));
    await userEvent.click(screen.getByRole('button', { name: 'Add to the form' }));

    expect(onAddToForm).toHaveBeenCalledWith([FIT.needs[0]]);
  });

  it('says why a field cannot be added instead of offering it', () => {
    setup();

    expect(screen.queryByLabelText('Office note')).not.toBeInTheDocument();
    expect(screen.getByText(/Only your team answers/)).toBeInTheDocument();
    expect(screen.getByText(/another kind of record/)).toBeInTheDocument();
  });

  it('stops warning about a question added but not saved yet', () => {
    const added = { field_key: 'delivery_date' } as FieldDraft;
    setup({ needs: [FIT.needs[0]] }, { fields: [added] });

    expect(screen.queryByTestId('document-fit-needs')).not.toBeInTheDocument();
  });

  it('offers no adding on a closed form', () => {
    setup({}, { formClosed: true });

    expect(screen.getByText(/Reopen it to add questions/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add to the form' })).not.toBeInTheDocument();
  });

  it('sends unused questions to the template or leaves them out', async () => {
    const { onAddToTemplate, onLeaveOut } = setup();

    await userEvent.click(screen.getByRole('button', { name: 'Add to the template' }));
    expect(onAddToTemplate).toHaveBeenCalledWith(['notes']);
    await userEvent.click(screen.getByRole('button', { name: 'Leave out of the document' }));
    expect(onLeaveOut).toHaveBeenCalledWith(['notes']);
  });

  it('does not offer the template to someone who cannot change it', () => {
    setup({ can_edit_template: false });

    expect(screen.queryByRole('button', { name: 'Add to the template' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Leave out of the document' })).toBeInTheDocument();
  });

  it('shows a question in the draft as waiting, with the way to publish it', async () => {
    const { onOpenTemplate } = setup({ needs: [], unused: [{ ...FIT.unused[0], in_draft: true }] });

    expect(screen.queryByTestId('document-fit-unused')).not.toBeInTheDocument();
    expect(screen.getByText('Waiting to be published')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Open the template' }));
    expect(onOpenTemplate).toHaveBeenCalledWith(5);
  });

  it('keeps a question left out quiet, and can ask again', async () => {
    const { onAskAgain } = setup({ needs: [], unused: [{ ...FIT.unused[0], left_out: true }] });

    expect(screen.queryByTestId('document-fit-unused')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Ask about these again' }));
    expect(onAskAgain).toHaveBeenCalled();
  });

  it('says so when everything fits', () => {
    setup({ needs: [], unused: [] });

    expect(screen.getByText(/Every field on this template has an answer/)).toBeInTheDocument();
  });

  it('is read-only for someone who cannot manage the form', () => {
    setup({}, { readOnly: true });

    expect(screen.queryByRole('button', { name: 'Add to the form' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Leave out of the document' })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Notes')).toBeDisabled();
  });
});
