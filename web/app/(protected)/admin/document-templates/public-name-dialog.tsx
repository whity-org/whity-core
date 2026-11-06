'use client';

import { useState } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { patchTemplate, type AddToast, type ApiClient } from './api';
import type { TemplateRow } from './types';

/** The longest public name the server accepts. */
export const PUBLIC_NAME_MAX = 120;

/**
 * What kind of document a template makes, in words for strangers (#1627). The public
 * check shows it when somebody scans the QR on a document from this template, if the
 * workspace chose to describe documents there. It is its own field because a title
 * often names a customer. Saving it is a publish action server-side, so the row
 * action is gated on `documents:publish` and the server's sentences are shown as
 * they come.
 */
export function PublicNameDialog({
  row,
  name,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  row: TemplateRow;
  /** The row's title as the page shows it ("Untitled" for an empty one). */
  name: string;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [value, setValue] = useState(row.public_name ?? '');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const trimmed = value.trim();

  const submit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const failure = await patchTemplate(
        apiClient,
        row.id,
        { public_name: trimmed === '' ? null : trimmed },
        t('documentTemplates.publicName.failed', 'Failed to save the public name')
      );
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(t('documentTemplates.publicName.saved', 'Public name updated for {name}', { name }), 'success');
      onSaved();
    } catch {
      setError(t('documentTemplates.publicName.failed', 'Failed to save the public name'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('documentTemplates.publicName.title', 'What “{name}” makes, for the public check', { name })}</DialogTitle>
          <DialogDescription>
            {t(
              'documentTemplates.publicName.description',
              'When someone scans the code on a document from this template, the check can say what kind of document it is. It shows only if your workspace chose to describe documents there.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <label className="text-sm font-medium" htmlFor="public-name">
            {t('documentTemplates.publicName.label', 'Kind of document')}
          </label>
          <Input
            id="public-name"
            dir="auto"
            placeholder={t('documentTemplates.publicName.placeholder', 'Handover certificate')}
            maxLength={PUBLIC_NAME_MAX}
            value={value}
            onChange={(event) => setValue(event.target.value)}
            data-testid="public-name-input"
          />
          <p className="text-sm text-muted-foreground">
            {t(
              'documentTemplates.publicName.hint',
              'Anyone holding the paper can read this. Use a general name, never a customer, a price or what the document is about. Leave it empty to show no kind.'
            )}
          </p>
        </div>

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button onClick={submit} loading={submitting}>
            {t('documentTemplates.publicName.submit', 'Save public name')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
