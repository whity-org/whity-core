'use client';

import { useState } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
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
import type { NumberingAssign, TemplateRow } from './types';

const SEQUENCE = /\{SEQ(?::([0-9]+))?\}/;

/**
 * What the first number from a format would look like, or null while the format has
 * no running number. Only a preview, to show what the tokens do: the server owns the
 * rules and the sequence, and refuses a bad format with its own sentence.
 */
export function numberingSample(pattern: string, now: Date = new Date()): string | null {
  const trimmed = pattern.trim();
  const sequence = SEQUENCE.exec(trimmed);
  if (sequence === null) return null;
  const year = String(now.getUTCFullYear());

  return trimmed
    .replace(SEQUENCE, '1'.padStart(Number(sequence[1] ?? 1), '0'))
    .replaceAll('{YYYY}', year)
    .replaceAll('{YY}', year.slice(-2))
    .replaceAll('{MM}', String(now.getUTCMonth() + 1).padStart(2, '0'));
}

/**
 * How documents from a template are numbered (WHIT-684). Choosing it is a publish
 * action server-side, as numbers go on paper people keep, so the row action is gated
 * on `documents:publish` and the server's 403 and 422 sentences are shown as they come.
 */
export function NumberingDialog({
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
  const [pattern, setPattern] = useState(row.numbering?.pattern ?? '');
  const [assign, setAssign] = useState<NumberingAssign>(row.numbering?.assign ?? 'on_issue');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const trimmed = pattern.trim();
  const sample = numberingSample(trimmed);

  const submit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const failure = await patchTemplate(
        apiClient,
        row.id,
        { numbering: trimmed === '' ? null : { pattern: trimmed, assign } },
        t('documentTemplates.numbering.failed', 'Failed to save the numbering')
      );
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(t('documentTemplates.numbering.saved', 'Numbering updated for {name}', { name }), 'success');
      onSaved();
    } catch {
      setError(t('documentTemplates.numbering.failed', 'Failed to save the numbering'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('documentTemplates.numbering.title', 'How “{name}” is numbered', { name })}</DialogTitle>
          <DialogDescription>
            {t(
              'documentTemplates.numbering.description',
              'Documents from this template get a number people can quote. A change applies to documents issued from now on, and a number already given never changes.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="numbering-pattern">
              {t('documentTemplates.numbering.pattern', 'Number format')}
            </label>
            <Input
              id="numbering-pattern"
              dir="ltr"
              className="font-mono"
              placeholder="INV-{YYYY}-{SEQ:4}"
              maxLength={64}
              value={pattern}
              onChange={(event) => setPattern(event.target.value)}
            />
            <p className="text-sm text-muted-foreground">
              {t(
                'documentTemplates.numbering.patternHint',
                'Your own text, plus {YYYY} for the year, {MM} for the month, and {SEQ:4} for the running number with 4 digits. A format with the year starts again at 1 each year.'
              )}
            </p>
            {trimmed === '' ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  'documentTemplates.numbering.noPattern',
                  "Leave this empty for no number of its own. A plugin's rule for this template still applies."
                )}
              </p>
            ) : sample === null ? (
              <p className="text-sm text-destructive" data-testid="numbering-needs-sequence">
                {t('documentTemplates.numbering.needsSequence', 'The format needs {SEQ} once, for the running number.')}
              </p>
            ) : (
              <p className="text-sm" data-testid="numbering-sample">
                {t('documentTemplates.numbering.sample', 'The next number could look like {sample}', { sample })}
              </p>
            )}
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="numbering-assign">
              {t('documentTemplates.numbering.when', 'When a document gets its number')}
            </label>
            <Select value={assign} onValueChange={(value) => setAssign(value as NumberingAssign)} disabled={trimmed === ''}>
              <SelectTrigger id="numbering-assign" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="on_issue">{t('documentTemplates.numbering.onIssue', 'When it is issued')}</SelectItem>
                <SelectItem value="on_final_approval">
                  {t('documentTemplates.numbering.onFinalApproval', 'When its route is finally approved')}
                </SelectItem>
              </SelectContent>
            </Select>
            {assign === 'on_final_approval' && (
              <p className="text-sm text-muted-foreground">
                {t(
                  'documentTemplates.numbering.onFinalApprovalHint',
                  'Until then it is a draft with no number. A route with no decision step never numbers a document.'
                )}
              </p>
            )}
          </div>
        </div>

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button onClick={submit} loading={submitting} disabled={trimmed !== '' && sample === null}>
            {t('documentTemplates.numbering.submit', 'Save numbering')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
