'use client';

/**
 * The one import screen (WHIT-1326, design section 34 §6.5, `TplImport`):
 * contacts, items, opening stock, supplier prices, and every kind added later,
 * all from the server's description of each kind (`GET /api/v1/imports/kinds`).
 * A new kind shows up here without a change to this screen.
 *
 * 1. Choose what to import (when more than one kind is offered), then a file:
 *    Whity finds the header row.
 * 2. Match columns: each column with an example and where it goes. Whity's
 *    suggestions are filled in; doubtful ones are amber; empty columns are
 *    skipped. The kind's own choices (the place, the supplier, how contacts
 *    are found) sit under the matching.
 * 3. Check: "What will happen" updates as the matching changes: new, updated,
 *    skipped with reasons (downloadable). Nothing changes until Import.
 * 4. Import, then Undo for the whole import for 24 hours.
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { NoAccess } from '@/components/no-access';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { ColumnMatcher, type ColumnMatchRow, type ColumnMatchStatus } from '@whity/ui/column-matcher';
import { Icon } from '@whity/ui/icon';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { isForbiddenError } from '@/lib/forbidden';
import {
  importApi,
  importProblem,
  localText,
  type ImportJob,
  type ImportKind,
  type ImportKindOption,
} from '@/lib/import/api';

type Step = 'file' | 'match' | 'done';
const SKIP = '__skip';
/** A select's choice whose value is null ("Tax number, then mobile, then email"). */
const NONE = '__none';

type OptionValue = string | number | null;

export interface ImportScreenProps {
  /** Only the kinds of one list page (`contacts`, `inventory`); all when left out. */
  area?: string;
  /** The kind to start on, such as `inventory.items`. */
  kind?: string;
  /** The breadcrumb above the title (the list page it was opened from). */
  breadcrumb?: ReactNode;
}

function initialOptions(kind: ImportKind): Record<string, OptionValue> {
  const out: Record<string, OptionValue> = {};
  for (const option of kind.options) {
    out[option.key] = (option.default as OptionValue | undefined) ?? null;
  }
  return out;
}

export function ImportScreen({ area, kind: startKind, breadcrumb }: ImportScreenProps) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const [kinds, setKinds] = useState<ImportKind[] | null>(null);
  const [loadProblem, setLoadProblem] = useState<'forbidden' | 'failed' | null>(null);
  const [kind, setKind] = useState<ImportKind | null>(null);
  const [step, setStep] = useState<Step>('file');
  const [file, setFile] = useState<File | null>(null);
  const [upload, setUpload] = useState<ImportJob | null>(null);
  const [checked, setChecked] = useState<ImportJob | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [options, setOptions] = useState<Record<string, OptionValue>>({});
  const [busy, setBusy] = useState(false);
  const [checking, setChecking] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [checkProblem, setCheckProblem] = useState<string | null>(null);
  const checkRun = useRef(0);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let live = true;
    importApi
      .kinds(area)
      .then((list) => {
        if (!live) return;
        setKinds(list);
        setLoadProblem(null);
        const start = list.find((k) => k.key === startKind) ?? (list.length === 1 ? list[0] : null);
        if (start) {
          setKind(start);
          setOptions(initialOptions(start));
        }
      })
      .catch((e: unknown) => {
        if (live) setLoadProblem(isForbiddenError(e) ? 'forbidden' : 'failed');
      });
    return () => {
      live = false;
    };
  }, [area, startKind, reload]);

  const text = (value: Parameters<typeof localText>[0]) => localText(value, language);
  const targetNames = useMemo(() => {
    const names: Record<string, string> = {};
    for (const target of kind?.targets ?? []) names[target.key] = localText(target.label, language);
    return names;
  }, [kind, language]);
  const targetName = (key: string, fallback?: string) => targetNames[key] ?? fallback ?? key;

  const mapped = useMemo(() => new Set(Object.values(mapping).filter((v) => v !== SKIP)), [mapping]);

  /** What the matching still needs before Whity can work anything out. */
  const missing: string | null = (() => {
    if (kind === null || step !== 'match') return null;
    for (const target of kind.required) {
      if (!mapped.has(target)) {
        return t('import.needColumn', 'Choose the column with the {target}.', { target: targetNames[target] ?? target });
      }
    }
    for (const option of kind.options) {
      if (option.required_unless && !mapped.has(option.required_unless) && (options[option.key] ?? null) === null) {
        return t('import.needOption', 'Choose “{option}”, or the column with the {target}.', {
          option: localText(option.label, language),
          target: targetNames[option.required_unless] ?? option.required_unless,
        });
      }
    }
    return null;
  })();

  // Every change to the matching asks the server what would happen. Nothing changes.
  useEffect(() => {
    if (step !== 'match' || upload === null || kind === null || missing !== null) return;
    const run = ++checkRun.current;
    const timer = setTimeout(async () => {
      setChecking(true);
      try {
        const body: Record<string, unknown> = {
          mapping: Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== SKIP)),
        };
        for (const option of kind.options) {
          const value = options[option.key] ?? null;
          body[option.key] = typeof value === 'string' && value.trim() === '' ? null : typeof value === 'string' ? value.trim() : value;
        }
        const result = await importApi.check(kind, upload.id, body);
        if (run === checkRun.current) {
          setChecked(result);
          setCheckProblem(null);
        }
      } catch (e) {
        if (run === checkRun.current) {
          setChecked(null);
          setCheckProblem(importProblem(e) ?? t('import.checkFailed', 'Whity couldn’t work the import out. Try again.'));
        }
      } finally {
        if (run === checkRun.current) setChecking(false);
      }
    }, 400);
    return () => clearTimeout(timer);
  }, [step, upload, kind, mapping, options, missing, t]);

  if (loadProblem === 'forbidden') {
    return <NoAccess area={t('import.area', 'importing')} />;
  }

  const chooseKind = (next: ImportKind) => {
    setKind(next);
    setOptions(initialOptions(next));
    setStep('file');
    setFile(null);
    setUpload(null);
    setChecked(null);
    setMapping({});
    setProblem(null);
    setCheckProblem(null);
  };

  const read = async () => {
    if (file === null || kind === null) return;
    setBusy(true);
    setProblem(null);
    try {
      const result = await importApi.upload(kind, file);
      const initial: Record<string, string> = {};
      for (const column of result.columns) {
        initial[String(column.index)] = column.empty || !column.suggestion?.target ? SKIP : column.suggestion.target;
      }
      setUpload(result);
      setChecked(null);
      setMapping(initial);
      setStep('match');
    } catch (e) {
      setProblem(importProblem(e) ?? t('import.readFailed', 'Whity couldn’t read the file. Check it is Excel or CSV and try again.'));
    } finally {
      setBusy(false);
    }
  };

  const run = async () => {
    if (checked === null || kind === null) return;
    setBusy(true);
    setProblem(null);
    try {
      setChecked(await importApi.run(kind, checked.id));
      setStep('done');
    } catch (e) {
      setProblem(importProblem(e) ?? t('import.runFailed', 'The import didn’t run. Nothing changed; try again.'));
    } finally {
      setBusy(false);
    }
  };

  const undo = async () => {
    if (checked === null || kind === null) return;
    setBusy(true);
    setProblem(null);
    try {
      setChecked(await importApi.undo(kind, checked.id));
    } catch (e) {
      setProblem(importProblem(e) ?? t('import.undoFailed', 'The import couldn’t be undone. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  const shownProblem = missing ?? checkProblem;
  const summary = missing ? null : (checked?.summary ?? null);
  const fixedColumns = new Set((summary?.fixes ?? []).map((f) => f.column));
  const hintFor = (key: string) => kind?.targets.find((target) => target.key === key)?.hint ?? null;
  const rows: ColumnMatchRow[] = (upload?.columns ?? []).map((column) => {
    const target = mapping[String(column.index)] ?? SKIP;
    let status: ColumnMatchStatus = 'matched';
    let statusLabel = t('import.status.matched', 'Matched');
    if (column.empty) {
      status = 'skipped';
      statusLabel = t('import.status.empty', 'Empty, skipped');
    } else if (target === SKIP) {
      status = 'skipped';
      statusLabel = t('import.status.skipped', 'Not imported');
    } else if (target === 'tags') {
      status = 'new';
      statusLabel = t('import.status.tag', 'Becomes a tag');
    } else if (target.startsWith('field:')) {
      statusLabel = t('import.status.yourField', 'Your field');
    } else if (fixedColumns.has(target)) {
      status = 'fixed';
      statusLabel = t('import.status.fixed', 'Matched, tidied');
    } else if (column.suggestion?.target === target && column.suggestion.confidence === 'doubtful') {
      status = 'check';
      statusLabel = t('import.status.check', 'Check this match');
    }
    const header = column.header !== '' ? column.header : t('import.column', 'Column {n}', { n: column.index + 1 });
    return {
      source: header,
      example: column.empty ? t('import.emptyColumn', '(empty)') : column.examples[0],
      status,
      statusLabel,
      target: (
        <Select value={target} onValueChange={(v) => setMapping((m) => ({ ...m, [String(column.index)]: v }))}>
          <SelectTrigger aria-label={t('import.goesTo', 'Where {column} goes', { column: header })}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={SKIP}>{t('import.dontImport', 'Don’t import')}</SelectItem>
            {(upload?.targets ?? []).map((tg) => (
              <SelectItem key={tg.key} value={tg.key}>
                {targetName(tg.key, tg.label)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ),
    };
  });

  // A note for each matched target that has one (the option columns name the option).
  const hints = [...mapped].flatMap((key) => {
    const hint = hintFor(key);
    return hint ? [[key, hint] as const] : [];
  });
  const toImport = (summary?.create ?? 0) + (summary?.update ?? 0);
  const skipped = (summary?.rows ?? []).filter((r) => r.action === 'skip');
  const offered = kinds ?? [];
  const showPicker = offered.length > 1;
  const stepNames: [Step, string][] = [
    ['file', t('import.step.file', 'Choose a file')],
    ['match', t('import.step.match', 'Match and check')],
    ['done', t('import.step.done', 'Import')],
  ];
  const title = kind ? text(kind.words.title) : t('import.title', 'Import');

  const optionField = (option: ImportKindOption) => {
    const id = `import-option-${option.key}`;
    const label = text(option.label);
    const value = options[option.key] ?? null;
    if (option.type === 'text') {
      return (
        <Input
          key={option.key}
          id={id}
          dir="ltr"
          label={label}
          helperText={option.hint ? text(option.hint) : undefined}
          placeholder={option.placeholder ?? undefined}
          value={typeof value === 'string' ? value : value === null ? '' : String(value)}
          onChange={(e) => setOptions((o) => ({ ...o, [option.key]: e.target.value }))}
        />
      );
    }
    const choices = option.choices ?? [];
    const optional = option.required_unless !== null || choices.some((c) => c.value === null);
    return (
      <div key={option.key} className="space-y-1.5">
        <label htmlFor={id} className="text-sm font-medium">
          {label}
        </label>
        <Select
          value={value === null ? NONE : String(value)}
          onValueChange={(v) => {
            const choice = choices.find((c) => String(c.value) === v);
            setOptions((o) => ({ ...o, [option.key]: v === NONE ? null : ((choice?.value as OptionValue | undefined) ?? v) }));
          }}
        >
          <SelectTrigger id={id}>
            <SelectValue placeholder={t('import.choose', 'Choose…')} />
          </SelectTrigger>
          <SelectContent>
            {optional && !choices.some((c) => c.value === null) && (
              <SelectItem value={NONE}>
                {option.required_unless === null
                  ? t('import.none', 'None')
                  : mapped.has(option.required_unless)
                    ? t('import.fromColumn', 'From the {target} column', { target: targetName(option.required_unless) })
                    : t('import.choose', 'Choose…')}
              </SelectItem>
            )}
            {choices.map((choice) => (
              <SelectItem key={String(choice.value ?? NONE)} value={choice.value === null ? NONE : String(choice.value)}>
                {text(choice.label)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {option.hint && <p className="text-sm text-muted-foreground">{text(option.hint)}</p>}
        {choices.length === 0 && option.required_unless !== null && (
          <p className="text-sm text-muted-foreground">
            {t('import.noChoices', 'There is nothing to choose yet. Match the {target} column instead.', {
              target: targetName(option.required_unless),
            })}
          </p>
        )}
      </div>
    );
  };

  return (
    <div className="@container space-y-6">
      <AdminHeader
        breadcrumb={breadcrumb}
        title={title}
        description={
          kind
            ? `${text(kind.description)} ${t('import.description', 'From Excel or a CSV file. Nothing changes until you import, and you can undo the whole import for 24 hours.')}`
            : t('import.description', 'From Excel or a CSV file. Nothing changes until you import, and you can undo the whole import for 24 hours.')
        }
      />

      {loadProblem === 'failed' && (
        <Alert variant="destructive">
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            {t('import.loadFailed', 'Whity couldn’t load what you can import.')}
            <Button variant="outline" size="sm" onClick={() => setReload((n) => n + 1)}>
              {t('import.tryAgain', 'Try again')}
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {kinds !== null && offered.length === 0 && (
        <p className="text-muted-foreground">{t('import.nothing', 'There is nothing you can import here. Ask someone who manages the workspace for access.')}</p>
      )}

      {showPicker && step === 'file' && (
        <fieldset className="space-y-3">
          <legend className="mb-2 font-semibold">{t('import.what', 'What are you importing?')}</legend>
          <div className="grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3" role="radiogroup">
            {offered.map((k) => {
              const on = kind?.key === k.key;
              return (
                <button
                  key={k.key}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => chooseKind(k)}
                  data-testid={`import-kind-${k.key}`}
                  className={`rounded-lg border p-4 text-start transition-colors focus-visible:outline-2 focus-visible:outline-ring ${
                    on ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'
                  }`}
                >
                  <span className="block font-semibold">{text(k.label)}</span>
                  <span className="mt-1 block text-sm text-muted-foreground">{text(k.description)}</span>
                </button>
              );
            })}
          </div>
        </fieldset>
      )}

      {kind && (
        <ol className="flex flex-wrap gap-6 text-sm">
          {stepNames.map(([id, label], i) => (
            <li key={id} className={id === step ? 'font-semibold text-primary' : 'text-muted-foreground'} aria-current={id === step ? 'step' : undefined}>
              <span className="me-2 inline-flex size-6 items-center justify-center rounded-full border border-current font-mono text-xs">{i + 1}</span>
              {label}
            </li>
          ))}
        </ol>
      )}

      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      {kind && step === 'file' && (
        <section className="max-w-xl space-y-4 rounded-lg border border-border p-5">
          <Input
            id="import-file"
            type="file"
            accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            label={t('import.file', 'Spreadsheet')}
            helperText={t('import.fileHint', 'Excel (.xlsx) or CSV, up to 5 MB and 5,000 rows. Whity finds the header row.')}
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
          <div className="flex justify-end">
            <Button onClick={() => void read()} disabled={file === null} loading={busy} data-testid="import-read">
              {t('import.read', 'Read the file')}
            </Button>
          </div>
        </section>
      )}

      {kind && step === 'match' && upload && (
        <div className="grid items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
          <section className="space-y-4 rounded-lg border border-border p-5">
            <div>
              <h2 className="font-semibold">{t('import.match.title', 'Match the columns')}</h2>
              <p className="text-sm text-muted-foreground">
                {t('import.match.subtitle', '{file} · {rows} rows', { file: upload.file_name, rows: upload.row_count })}
              </p>
            </div>
            <ColumnMatcher
              rows={rows}
              labels={{
                file: t('import.inFile', 'In your file'),
                whity: t('import.inWhity', 'In Whity'),
                status: t('import.status', 'Status'),
                goesTo: t('import.goesToShort', 'goes to'),
              }}
            />
            {hints.length > 0 && (
              <ul className="space-y-1 text-sm text-muted-foreground" data-testid="import-hints">
                {hints.map(([key, hint]) => (
                  <li key={key}>
                    <span className="font-medium text-foreground">{targetName(key)}:</span> {text(hint)}
                  </li>
                ))}
              </ul>
            )}
            {kind.options.length > 0 && (
              <div className="grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-4">{kind.options.map(optionField)}</div>
            )}
          </section>

          <aside className="space-y-4">
            <section className="space-y-3 rounded-lg border border-border p-5" aria-live="polite" aria-busy={checking}>
              <h2 className="font-semibold">{t('import.whatHappens', 'What will happen')}</h2>
              {shownProblem ? (
                <p className="text-sm text-warning-text" data-testid="import-problem">
                  {shownProblem}
                </p>
              ) : summary ? (
                <dl className="divide-y divide-border text-sm">
                  <div className="flex justify-between py-2">
                    <dt>{text(kind.words.create)}</dt>
                    <dd className="font-mono font-semibold" data-testid="import-create">
                      {summary.create}
                    </dd>
                  </div>
                  <div className="flex justify-between py-2">
                    <dt>{text(kind.words.update)}</dt>
                    <dd className="font-mono font-semibold">{summary.update}</dd>
                  </div>
                  <div className="flex justify-between py-2">
                    <dt>{t('import.skippedCount', 'Skipped, with reasons')}</dt>
                    <dd className={summary.skip > 0 ? 'font-mono font-semibold text-destructive' : 'font-mono font-semibold'}>{summary.skip}</dd>
                  </div>
                  {(summary.tags?.length ?? 0) > 0 && (
                    <div className="flex justify-between gap-3 py-2">
                      <dt>{t('import.tags', 'Tags')}</dt>
                      <dd className="text-end">{summary.tags?.join(', ')}</dd>
                    </div>
                  )}
                </dl>
              ) : (
                <p className="text-sm text-muted-foreground">{t('import.working', 'Working it out…')}</p>
              )}
              {skipped.length > 0 && checked && (
                <>
                  <ul className="max-h-40 space-y-1 overflow-y-auto text-sm text-muted-foreground">
                    {skipped.slice(0, 20).map((r) => (
                      <li key={r.row}>{t('import.skipRow', 'Row {row}: {reason}', { row: r.row, reason: r.reason ?? '' })}</li>
                    ))}
                  </ul>
                  <a href={importApi.skippedUrl(kind, checked.id)} className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                    <Icon name="download" size={16} />
                    {t('import.downloadSkipped', 'Download the skipped rows')}
                  </a>
                </>
              )}
            </section>
            <Alert>
              <AlertDescription>{t('import.nothingChanges', 'Nothing changes until you import. You can undo the whole import for 24 hours.')}</AlertDescription>
            </Alert>
            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setStep('file');
                  setUpload(null);
                  setChecked(null);
                }}
              >
                {t('import.back', 'Back')}
              </Button>
              <Button onClick={() => void run()} disabled={checked === null || missing !== null || toImport === 0 || checking} loading={busy} data-testid="import-run">
                <Icon name="upload" />
                {text(kind.words.run).replace('{count}', String(toImport))}
              </Button>
            </div>
          </aside>
        </div>
      )}

      {kind && step === 'done' && checked && (
        <section className="max-w-xl space-y-4 rounded-lg border border-border p-5">
          {checked.status === 'undone' ? (
            <p data-testid="import-undone">{t('import.undone', 'The import is undone. Everything is as it was before it.')}</p>
          ) : (
            <p>
              {t('import.doneText', '{create} added and {update} updated.', {
                create: checked.summary?.create ?? 0,
                update: checked.summary?.update ?? 0,
              })}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            {checked.can_undo && (
              <Button variant="outline" onClick={() => void undo()} loading={busy} data-testid="import-undo">
                <Icon name="undo" />
                {t('import.undo', 'Undo this import')}
              </Button>
            )}
            <Button variant="outline" onClick={() => chooseKind(kind)}>
              {t('import.another', 'Import another file')}
            </Button>
            <Button asChild>
              <Link href={kind.back.href}>{text(kind.back.label)}</Link>
            </Button>
          </div>
        </section>
      )}
    </div>
  );
}
