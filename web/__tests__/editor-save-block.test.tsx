/**
 * Editor milestone M7 (EditorCustomBlocks; E.21, E.22): Save as block asks
 * for a name, Synced (the default) or Copy, and offers Everyone in the
 * workspace only to people who manage the library.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { SaveBlockDialog } from '@whity/ui/editor/save-block-dialog';

it('saves a synced block for me by default, once it has a name', async () => {
  const onSave = jest.fn().mockResolvedValue(undefined);
  const onOpenChange = jest.fn();
  render(<SaveBlockDialog open onOpenChange={onOpenChange} blockCount={3} canShare={false} onSave={onSave} labels={EDITOR_LABELS} />);

  expect(screen.getByText('Saves the 3 selected blocks as one block you can insert from the / menu.')).toBeInTheDocument();
  expect(screen.queryByText(EDITOR_LABELS.saveBlockEveryone)).not.toBeInTheDocument();
  const save = screen.getByRole('button', { name: EDITOR_LABELS.saveBlockSave });
  expect(save).toBeDisabled();

  fireEvent.change(screen.getByLabelText(EDITOR_LABELS.saveBlockName), { target: { value: ' Bank details ' } });
  fireEvent.click(save);

  await waitFor(() => expect(onSave).toHaveBeenCalledWith({ name: 'Bank details', mode: 'synced', sharing: 'me' }));
  expect(onOpenChange).toHaveBeenCalledWith(false);
});

it('offers a copy, and the workspace library to those who manage it', async () => {
  const onSave = jest.fn().mockResolvedValue(undefined);
  render(<SaveBlockDialog open onOpenChange={() => undefined} blockCount={1} canShare onSave={onSave} labels={EDITOR_LABELS} />);

  fireEvent.change(screen.getByLabelText(EDITOR_LABELS.saveBlockName), { target: { value: 'Signature' } });
  fireEvent.click(screen.getByRole('radio', { name: new RegExp(EDITOR_LABELS.saveBlockCopy) }));
  fireEvent.click(screen.getByRole('radio', { name: new RegExp(EDITOR_LABELS.saveBlockEveryone) }));
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.saveBlockSave }));

  await waitFor(() => expect(onSave).toHaveBeenCalledWith({ name: 'Signature', mode: 'copy', sharing: 'workspace' }));
});

it('stays open and says so when saving fails', async () => {
  const onOpenChange = jest.fn();
  render(
    <SaveBlockDialog open onOpenChange={onOpenChange} blockCount={2} canShare={false} onSave={jest.fn().mockRejectedValue(new Error('x'))} labels={EDITOR_LABELS} />
  );

  fireEvent.change(screen.getByLabelText(EDITOR_LABELS.saveBlockName), { target: { value: 'Terms' } });
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.saveBlockSave }));

  expect(await screen.findByRole('alert')).toHaveTextContent(EDITOR_LABELS.collabFailed);
  expect(onOpenChange).not.toHaveBeenCalledWith(false);
});
