'use client';

import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { Input } from '@whity/ui/input';
import { cn } from '@whity/ui/utils';
import { useTranslation } from '@whity/features/i18n';
import { STANDARD_REF, themeName, type ThemeRow } from './model';

/**
 * The themes the workspace can use (`TplDocThemes`): Whity's standard, which
 * can't be changed, then the workspace's own, the workspace default marked.
 * Choosing one opens it beside the list. "New theme" asks for a name and
 * makes one based on the workspace default.
 */
export function ThemeList({
  themes,
  selectedRef,
  workspaceRef,
  accentOf,
  canWrite,
  creating,
  ar,
  onSelect,
  onCreate,
}: {
  themes: ThemeRow[];
  selectedRef: string;
  workspaceRef: string;
  /** The print colour to draw each theme's thumbnail in. */
  accentOf: (row: ThemeRow) => string;
  canWrite: boolean;
  creating: boolean;
  ar: boolean;
  onSelect: (ref: string) => void;
  onCreate: (name: string) => void;
}) {
  const t = useTranslation('documents');
  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');
  const byRef = new Map(themes.map((row) => [row.ref, row]));

  const subtitle = (row: ThemeRow) => {
    if (row.ref === STANDARD_REF) {
      return row.ref === workspaceRef
        ? t('themes.list.standardDefault', 'Whity · workspace default · can’t be changed')
        : t('themes.list.standard', 'Whity · can’t be changed');
    }
    const base = row.extends ? byRef.get(row.extends) : undefined;
    const based = base ? t('themes.list.basedOn', 'based on {name}', { name: themeName(base, ar) }) : '';
    if (row.ref === workspaceRef) {
      return based ? t('themes.list.defaultBased', 'Workspace default · {based}', { based }) : t('themes.list.default', 'Workspace default');
    }
    return based ? based.charAt(0).toUpperCase() + based.slice(1) : t('themes.list.own', 'Your workspace’s');
  };

  return (
    <nav aria-label={t('themes.list.label', 'Document themes')} className="grid content-start gap-2.5">
      <ul className="m-0 grid list-none gap-2.5 p-0">
        {themes.map((row) => {
          const on = row.ref === selectedRef;
          return (
            <li key={row.ref}>
              <button
                type="button"
                aria-current={on ? 'true' : undefined}
                onClick={() => onSelect(row.ref)}
                className={cn(
                  'grid w-full grid-cols-[48px_minmax(0,1fr)] items-center gap-3 rounded-card border bg-card px-3 py-2.5 text-start',
                  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                  on ? 'border-2 border-primary px-[11px] py-[9px]' : 'border-border hover:bg-accent'
                )}
              >
                <Thumb accent={accentOf(row)} />
                <span className="grid min-w-0">
                  <span className="truncate font-medium text-foreground">{themeName(row, ar)}</span>
                  <span className="text-sm text-muted-foreground">{subtitle(row)}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>

      {canWrite &&
        (naming ? (
          <form
            className="grid gap-2 rounded-card border border-border bg-card p-3"
            onSubmit={(event) => {
              event.preventDefault();
              if (name.trim() !== '') onCreate(name.trim());
            }}
          >
            <Input
              label={t('themes.list.newName', 'Name of the new theme')}
              value={name}
              autoFocus
              maxLength={120}
              onChange={(event) => setName(event.target.value)}
            />
            <div className="flex flex-wrap gap-2">
              <Button type="submit" variant="outline" loading={creating} disabled={name.trim() === ''}>
                {t('themes.list.create', 'Make the theme')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  setNaming(false);
                  setName('');
                }}
              >
                {t('themes.list.cancel', 'Cancel')}
              </Button>
            </div>
          </form>
        ) : (
          <Button variant="outline" icon="plus" onClick={() => setNaming(true)}>
            {t('themes.list.new', 'New theme')}
          </Button>
        ))}
    </nav>
  );
}

/** A tiny page: a bar in the print colour over grey lines. A picture, not text. */
function Thumb({ accent }: { accent: string }) {
  return (
    <span aria-hidden="true" className="light relative block h-[62px] w-12 overflow-hidden rounded-sm bg-card shadow-sm">
      <i className="absolute inset-x-[7px] top-[9px] block h-[5px] rounded-sm" style={{ background: accent, insetInlineEnd: 14 }} />
      <span className="absolute inset-x-[7px] top-[22px] bottom-2 grid content-start gap-[5px]">
        {[0, 1, 2, 3, 4].map((i) => (
          <i key={i} className="block h-0.5 bg-border" />
        ))}
      </span>
    </span>
  );
}
