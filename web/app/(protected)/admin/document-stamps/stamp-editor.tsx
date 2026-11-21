'use client';

/**
 * Adding or editing one company stamp (#1729): its name, the legal name it
 * stands for, its image, and who holds it (named people and roles).
 *
 * The image is read in the browser as a data URL after a type and size check;
 * the server checks it again. A refusal that names a field is shown under that
 * field, anything else above the buttons.
 */

import { useState } from 'react';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { AudiencePeoplePicker, type AudiencePersonOption } from '@whity/ui/audience-people-picker';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Input } from '@whity/ui/input';
import { useTranslation } from '@whity/features/i18n';
import {
  draftOf,
  imageProblem,
  readAsDataUrl,
  type CompanyStamp,
  type StampDraft,
  type StampField,
} from './model';

export interface Catalogue<T> {
  items: T[];
  /** Why there is nothing to choose from (a 403, say). */
  unavailable: string | null;
  /** Why the list may be incomplete. */
  incomplete: string | null;
}

export interface StampEditorProps {
  open: boolean;
  /** The stamp being edited, or null to add one. */
  stamp: CompanyStamp | null;
  people: Catalogue<AudiencePersonOption> | null;
  roles: Catalogue<{ id: number; name: string }> | null;
  saving: boolean;
  problem: { message: string; field: StampField | null } | null;
  onOpenChange: (open: boolean) => void;
  onSave: (draft: StampDraft) => void;
}

export function StampEditor({ open, onOpenChange, ...rest }: StampEditorProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Keyed so every opening starts from the stamp as saved. */}
      {open && <StampEditorBody key={rest.stamp?.id ?? 'new'} onOpenChange={onOpenChange} {...rest} />}
    </Dialog>
  );
}

function StampEditorBody({
  stamp,
  people,
  roles,
  saving,
  problem,
  onOpenChange,
  onSave,
}: Omit<StampEditorProps, 'open'>) {
  const t = useTranslation('documents');
  const [draft, setDraft] = useState<StampDraft>(() => draftOf(stamp));
  const [imageError, setImageError] = useState<string | null>(null);

  // People already holding the stamp keep their names even when the people
  // list could not be read or did not reach them.
  const peopleItems: AudiencePersonOption[] = [...(people?.items ?? [])];
  for (const holder of stamp?.holders.people ?? []) {
    if (!peopleItems.some((p) => p.id === holder.profile_id)) {
      peopleItems.push({ id: holder.profile_id, name: holder.name });
    }
  }
  const roleItems = [...(roles?.items ?? [])];
  for (const holder of stamp?.holders.roles ?? []) {
    if (!roleItems.some((r) => r.id === holder.role_id)) roleItems.push({ id: holder.role_id, name: holder.name });
  }

  const fieldError = (field: StampField) => (problem !== null && problem.field === field ? problem.message : undefined);
  const shownImageError = imageError ?? fieldError('image');

  const chooseImage = async (file: File | undefined) => {
    setImageError(null);
    if (file === undefined) return;
    const why = imageProblem(file);
    if (why === 'type') {
      setImageError(t('stamps.image.type', 'Choose a PNG or JPEG file.'));
      return;
    }
    if (why === 'size') {
      setImageError(t('stamps.image.size', 'The image is too large. Use a file under 512 KB.'));
      return;
    }
    try {
      const image = await readAsDataUrl(file);
      setDraft((d) => ({ ...d, image }));
    } catch {
      setImageError(t('stamps.image.unreadable', 'The file could not be read. Choose it again.'));
    }
  };

  const toggleRole = (id: number, on: boolean) =>
    setDraft((d) => ({ ...d, roles: on ? [...d.roles, id] : d.roles.filter((r) => r !== id) }));

  const ready =
    draft.name.trim() !== '' &&
    draft.legal_name.trim() !== '' &&
    draft.image !== '' &&
    (draft.people.length > 0 || draft.roles.length > 0) &&
    !saving;

  return (
    <DialogContent
      className="max-h-[90vh] max-w-2xl overflow-y-auto"
      closeLabel={t('stamps.editor.close', 'Close')}
      data-testid="stamp-editor"
    >
      <DialogHeader>
        <DialogTitle>
          {stamp === null ? t('stamps.editor.addTitle', 'Add a company stamp') : t('stamps.editor.editTitle', 'Edit {name}', { name: stamp.name })}
        </DialogTitle>
        <DialogDescription>
          {t(
            'stamps.editor.description',
            'Only the people and roles you choose here can apply this stamp on a document route. Applying it puts its image on the document with an audit trail; it is not a certified electronic seal.'
          )}
        </DialogDescription>
      </DialogHeader>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (ready) onSave({ ...draft, name: draft.name.trim(), legal_name: draft.legal_name.trim() });
        }}
      >
        <Input
          label={t('stamps.field.name', 'Name')}
          value={draft.name}
          maxLength={200}
          onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
          errorText={fieldError('name')}
          required
        />
        <Input
          label={t('stamps.field.legalName', 'Legal name it stands for')}
          value={draft.legal_name}
          maxLength={300}
          onChange={(e) => setDraft((d) => ({ ...d, legal_name: e.target.value }))}
          errorText={fieldError('legal_name')}
          required
        />

        <div className="space-y-2" data-slot="stamp-editor-image">
          <label htmlFor="stamp-image" className="text-sm font-medium text-foreground">
            {t('stamps.field.image', 'Image of the stamp')}
          </label>
          <p className="text-xs text-muted-foreground">
            {t('stamps.field.imageHelp', 'A PNG or JPEG scan of the stamp, under 512 KB. A transparent background prints best.')}
          </p>
          <div className="flex flex-wrap items-center gap-4">
            {draft.image !== '' && (
              // A data URL read in this browser; next/image adds nothing here.
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={draft.image}
                alt={t('stamps.image.preview', 'The chosen image')}
                className="size-24 shrink-0 rounded-md border border-border object-contain p-1"
              />
            )}
            <input
              id="stamp-image"
              type="file"
              accept="image/png,image/jpeg"
              className="text-sm text-foreground"
              onChange={(e) => void chooseImage(e.target.files?.[0])}
              data-testid="stamp-editor-file"
            />
          </div>
          {shownImageError !== undefined && (
            <p className="text-sm text-destructive" role="alert">
              {shownImageError}
            </p>
          )}
        </div>

        <fieldset className="space-y-3" data-slot="stamp-editor-holders">
          <legend className="text-sm font-medium text-foreground">{t('stamps.field.holders', 'Who holds it')}</legend>
          <p className="text-xs text-muted-foreground">
            {t('stamps.field.holdersHelp', 'Name people, choose roles, or both. Someone holding one of the roles can apply the stamp.')}
          </p>

          <div className="space-y-1">
            <span className="text-sm text-foreground">{t('stamps.field.people', 'People')}</span>
            {people === null ? (
              <p className="text-xs text-muted-foreground">{t('signing.loading', 'Loading…')}</p>
            ) : (
              <AudiencePeoplePicker
                id="stamp-holders-people"
                people={peopleItems}
                value={draft.people}
                onChange={(ids) => setDraft((d) => ({ ...d, people: ids }))}
                unavailableReason={people.unavailable}
                incompleteReason={people.incomplete}
                searchPlaceholder={t('stamps.people.search', 'Search people by name')}
                emptyLabel={t('stamps.people.empty', 'There is nobody here to name.')}
                nothingSelectedLabel={t('stamps.people.none', 'Nobody named yet.')}
                noMatchesLabel={t('stamps.people.noMatches', 'Nobody matches that.')}
                moreMatchesLabel={(shown, total) =>
                  t('stamps.people.more', 'Showing {shown} of {total} matches. Keep typing to narrow it down.', {
                    shown,
                    total,
                  })
                }
                removeLabel={(name) => t('stamps.people.remove', 'Remove {name}', { name })}
                unknownPersonLabel={() => t('stamps.people.unknown', 'Someone in this workspace')}
              />
            )}
          </div>

          <div className="space-y-1">
            <span className="text-sm text-foreground">{t('stamps.field.roles', 'Roles')}</span>
            {roles === null ? (
              <p className="text-xs text-muted-foreground">{t('signing.loading', 'Loading…')}</p>
            ) : roleItems.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                {roles.unavailable ?? t('stamps.roles.empty', 'There are no roles to choose.')}
              </p>
            ) : (
              <>
                {roles.unavailable !== null && <p className="text-xs text-muted-foreground">{roles.unavailable}</p>}
                {roles.incomplete !== null && <p className="text-xs text-muted-foreground">{roles.incomplete}</p>}
                <ul className="grid gap-2 sm:grid-cols-2">
                  {roleItems.map((role) => (
                    <li key={role.id}>
                      <label className="flex items-center gap-2 text-sm text-foreground">
                        <Checkbox
                          checked={draft.roles.includes(role.id)}
                          onCheckedChange={(v) => toggleRole(role.id, v === true)}
                        />
                        {role.name}
                      </label>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
          {fieldError('holders') !== undefined && (
            <p className="text-sm text-destructive" role="alert">
              {fieldError('holders')}
            </p>
          )}
        </fieldset>

        {problem !== null && problem.field === null && (
          <Alert variant="destructive">
            <AlertDescription>{problem.message}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t('stamps.editor.cancel', 'Cancel')}
          </Button>
          <Button type="submit" disabled={!ready} loading={saving} data-testid="stamp-editor-save">
            {stamp === null ? t('stamps.editor.add', 'Add stamp') : t('stamps.editor.save', 'Save stamp')}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
