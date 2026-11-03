'use client';

/**
 * New template (A31): start blank, from one of Whity's starters, or from a copy
 * of a template the person can already see. A starter or a copy goes through the
 * same "Make a copy" dialog, so the new template is always the person's own
 * draft.
 */

import { IconFilePlus, IconTemplate } from '@tabler/icons-react';
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
import type { TemplateRow } from './types';

export function NewTemplateDialog({
  templates,
  nameOf,
  onClose,
  onBlank,
  onFrom,
}: {
  templates: TemplateRow[];
  nameOf: (row: TemplateRow) => string;
  onClose: () => void;
  onBlank: () => void;
  onFrom: (row: TemplateRow) => void;
}) {
  const t = useTranslation('admin');
  const starters = templates.filter((row) => row.starter_key !== null);
  const copies = templates.filter((row) => row.starter_key === null);

  const group = (heading: string, rows: TemplateRow[]) =>
    rows.length === 0 ? null : (
      <section className="space-y-1">
        <h3 className="text-sm font-semibold text-foreground">{heading}</h3>
        <ul className="max-h-48 space-y-1 overflow-y-auto">
          {rows.map((row) => (
            <li key={row.id}>
              <Button variant="ghost" className="w-full justify-start" onClick={() => onFrom(row)}>
                <IconTemplate className="me-2 size-4" aria-hidden />
                <span dir="auto" className="truncate">
                  {nameOf(row)}
                </span>
              </Button>
            </li>
          ))}
        </ul>
      </section>
    );

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('documentTemplates.new.title', 'New template')}</DialogTitle>
          <DialogDescription>
            {t('documentTemplates.new.description', 'Start from nothing, from one of Whity’s starters, or from a copy.')}
          </DialogDescription>
        </DialogHeader>

        <Button variant="outline" className="w-full justify-start" onClick={onBlank}>
          <IconFilePlus className="me-2 size-4" aria-hidden />
          {t('documentTemplates.new.blank', 'Start blank')}
        </Button>
        {group(t('documentTemplates.new.starters', 'From a Whity starter'), starters)}
        {group(t('documentTemplates.new.copies', 'From a copy of one of yours'), copies)}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
