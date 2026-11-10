'use client';

/**
 * New template (A31): start blank, from one of Whity's starters, or from a copy
 * of a template the person can already see. A starter or a copy goes through the
 * same "Make a copy" dialog, so the new template is always the person's own
 * draft.
 *
 * Starters come in pairs (`editor.invoice` and `editor.invoice.ar`), so they
 * show as one card per starter, named in the screen's language, with a button
 * for each language it comes in. Listed one row per language, the 24 names
 * interleaved English and Arabic in a short box and said nothing about which
 * was which.
 */

import { useMemo } from 'react';
import { IconFilePlus, IconTag, IconTemplate } from '@tabler/icons-react';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
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

/** One starter in every language it comes in. */
interface StarterGroup {
  key: string;
  rows: TemplateRow[];
}

/** `editor.invoice.ar` and `editor.invoice` are the same starter. */
export function starterBase(key: string): string {
  return key.replace(/\.(ar|en)$/, '');
}

export function groupStarters(rows: TemplateRow[], ui: TemplateRow['lang'] = 'en'): StarterGroup[] {
  const groups = new Map<string, TemplateRow[]>();
  for (const row of rows) {
    if (row.starter_key === null) continue;
    const base = starterBase(row.starter_key);
    groups.set(base, [...(groups.get(base) ?? []), row]);
  }
  return [...groups.entries()].map(([key, members]) => ({
    key,
    // The screen's language first: the button most people want comes first.
    rows: [...members].sort((a, b) => (a.lang === b.lang ? 0 : a.lang === ui ? -1 : 1)),
  }));
}

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
  const ui = useFormattingLocale()?.startsWith('ar') ? 'ar' : 'en';

  const starters = useMemo(() => groupStarters(templates, ui), [templates, ui]);
  const copies = templates.filter((row) => row.starter_key === null);

  const languageName = (lang: TemplateRow['lang']) =>
    lang === 'ar' ? t('documentTemplates.new.lang.ar', 'Arabic') : t('documentTemplates.new.lang.en', 'English');

  const kindName = (row: TemplateRow) =>
    row.kind === 'label'
      ? t('documentTemplates.kind.label', 'Label sheet')
      : row.kind === 'template'
        ? t('documentTemplates.kind.template', 'Template')
        : t('documentTemplates.kind.document', 'Document');

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t('documentTemplates.new.title', 'New template')}</DialogTitle>
          <DialogDescription>
            {t('documentTemplates.new.description', 'Start from nothing, from one of Whity’s starters, or from a copy.')}
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] space-y-5 overflow-y-auto pe-1">
          <Button variant="outline" className="w-full justify-start" onClick={onBlank}>
            <IconFilePlus className="me-2 size-4" aria-hidden />
            {t('documentTemplates.new.blank', 'Start blank')}
          </Button>

          {starters.length > 0 && (
            <section className="space-y-2" aria-labelledby="new-template-starters">
              <h3 id="new-template-starters" className="text-sm font-semibold text-foreground">
                {t('documentTemplates.new.starters', 'From a Whity starter')}
              </h3>
              <ul className="grid gap-2 sm:grid-cols-2">
                {starters.map((group) => {
                  const named = group.rows.find((row) => row.lang === ui) ?? group.rows[0];
                  const Icon = named.kind === 'label' ? IconTag : IconTemplate;
                  return (
                    <li
                      key={group.key}
                      className="flex min-h-12 items-center gap-3 rounded-lg border border-border bg-card px-3 py-2"
                    >
                      <Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                      <span className="min-w-0 flex-1">
                        <span dir="auto" className="block truncate font-medium text-foreground">
                          {nameOf(named)}
                        </span>
                        <span className="block text-sm text-muted-foreground">{kindName(named)}</span>
                      </span>
                      <span className="flex shrink-0 gap-1">
                        {group.rows.map((row) => (
                          <Button
                            key={row.id}
                            variant="outline"
                            size="sm"
                            onClick={() => onFrom(row)}
                            aria-label={t('documentTemplates.new.useStarter', 'Use {name} in {language}', {
                              name: nameOf(row),
                              language: languageName(row.lang),
                            })}
                          >
                            {group.rows.length > 1 ? languageName(row.lang) : t('documentTemplates.new.use', 'Use')}
                          </Button>
                        ))}
                      </span>
                    </li>
                  );
                })}
              </ul>
            </section>
          )}

          {copies.length > 0 && (
            <section className="space-y-1" aria-labelledby="new-template-copies">
              <h3 id="new-template-copies" className="text-sm font-semibold text-foreground">
                {t('documentTemplates.new.copies', 'From a copy of one of yours')}
              </h3>
              <ul className="space-y-1">
                {copies.map((row) => (
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
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
