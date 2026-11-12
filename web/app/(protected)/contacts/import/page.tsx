'use client';

/**
 * /contacts/import (WHIT-955, design 3.3 A8, `TplImport`): bring customers
 * and suppliers in from Excel or CSV.
 *
 * 1. Choose a file: Whity finds the header row.
 * 2. Match columns: each column with an example and where it goes, the
 *    workspace's own fields included. Whity's suggestions are filled in;
 *    doubtful ones are amber; empty columns are skipped.
 * 3. Check: "What will happen" updates as the matching changes: new,
 *    updated, skipped with reasons (downloadable), new tags. Nothing changes
 *    until Import.
 * 4. Import, then Undo for the whole import for 24 hours.
 */

import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { NoAccess } from '@/components/no-access';
import { useCapabilities } from '@/hooks/useCapabilities';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { ColumnMatcher, type ColumnMatchRow, type ColumnMatchStatus } from '@whity/ui/column-matcher';
import { Icon } from '@whity/ui/icon';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useTranslation } from '@whity/features/i18n';
import { CONTACTS_IMPORT, contactErrorText, contactsApi, type ContactImport } from '@/lib/contacts/api';

type Step = 'file' | 'match' | 'done';
const SKIP = '__skip';

export default function ContactImportPage() {
  const t = useTranslation('admin');
  const { has, loading: accessLoading } = useCapabilities();
  const [step, setStep] = useState<Step>('file');
  const [file, setFile] = useState<File | null>(null);
  const [upload, setUpload] = useState<ContactImport | null>(null);
  const [checked, setChecked] = useState<ContactImport | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [matchBy, setMatchBy] = useState<string>('auto');
  const [country, setCountry] = useState('');
  const [defaultKind, setDefaultKind] = useState<'customer' | 'supplier' | 'both'>('customer');
  const [busy, setBusy] = useState(false);
  const [checking, setChecking] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [checkProblem, setCheckProblem] = useState<string | null>(null);
  const checkRun = useRef(0);

  const targetLabel = useMemo(() => {
    const names: Record<string, string> = {
      name: t('contacts.field.name', 'Name'),
      contact_person: t('contacts.field.contactPerson', 'Contact person'),
      email: t('contacts.field.email', 'Email'),
      mobile: t('contacts.field.mobile', 'Mobile'),
      phone: t('contacts.field.phone', 'Phone'),
      tax_number: t('contacts.field.taxNumber', 'Tax number'),
      payment_terms: t('contacts.field.paymentTerms', 'How they pay'),
      address: t('contacts.field.address', 'Address'),
      notes: t('contacts.field.notes', 'Notes'),
      tags: t('contacts.field.tags', 'Tags'),
      type: t('contacts.import.target.type', 'Customer or supplier'),
    };
    return (key: string, fallback: string) => names[key] ?? fallback;
  }, [t]);

  // Every change to the matching asks the server what would happen. Nothing changes.
  useEffect(() => {
    if (step !== 'match' || upload === null) return;
    if (!Object.values(mapping).includes('name')) return;
    const run = ++checkRun.current;
    const timer = setTimeout(async () => {
      setChecking(true);
      try {
        const result = await contactsApi.check(upload.id, {
          mapping: Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== SKIP)),
          match_by: matchBy === 'auto' ? null : (matchBy as 'tax_number' | 'mobile' | 'email' | 'name'),
          country_code: country.trim() === '' ? null : country.trim(),
          default_kind: defaultKind,
        });
        if (run === checkRun.current) {
          setChecked(result);
          setCheckProblem(null);
        }
      } catch (e) {
        if (run === checkRun.current) {
          setChecked(null);
          setCheckProblem(contactErrorText(e));
        }
      } finally {
        if (run === checkRun.current) setChecking(false);
      }
    }, 400);
    return () => clearTimeout(timer);
  }, [step, upload, mapping, matchBy, country, defaultKind]);

  if (!accessLoading && !has(CONTACTS_IMPORT)) {
    return <NoAccess area={t('contacts.import.area', 'importing contacts')} permission={CONTACTS_IMPORT} />;
  }

  const read = async () => {
    if (file === null) return;
    setBusy(true);
    setProblem(null);
    try {
      const result = await contactsApi.upload(file);
      const initial: Record<string, string> = {};
      for (const column of result.columns) {
        initial[String(column.index)] = column.empty || !column.suggestion?.target ? SKIP : column.suggestion.target;
      }
      setUpload(result);
      setMapping(initial);
      setStep('match');
    } catch (e) {
      setProblem(contactErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const run = async () => {
    if (checked === null) return;
    setBusy(true);
    setProblem(null);
    try {
      setChecked(await contactsApi.run(checked.id));
      setStep('done');
    } catch (e) {
      setProblem(contactErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const undo = async () => {
    if (checked === null) return;
    setBusy(true);
    setProblem(null);
    try {
      setChecked(await contactsApi.undo(checked.id));
    } catch (e) {
      setProblem(contactErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const needsName = step === 'match' && !Object.values(mapping).includes('name');
  const shownProblem = needsName ? t('contacts.import.needName', 'Choose the column with each contact’s name.') : checkProblem;
  const summary = needsName ? null : (checked?.summary ?? null);
  const fixedColumns = new Set((summary?.fixes ?? []).map((f) => f.column));
  const rows: ColumnMatchRow[] = (upload?.columns ?? []).map((column) => {
    const target = mapping[String(column.index)] ?? SKIP;
    let status: ColumnMatchStatus = 'matched';
    let statusLabel = t('contacts.import.status.matched', 'Matched');
    if (column.empty) {
      status = 'skipped';
      statusLabel = t('contacts.import.status.empty', 'Empty, skipped');
    } else if (target === SKIP) {
      status = 'skipped';
      statusLabel = t('contacts.import.status.skipped', 'Not imported');
    } else if (target === 'tags') {
      status = 'new';
      statusLabel = t('contacts.import.status.tag', 'Becomes a tag');
    } else if (target.startsWith('field:')) {
      status = 'matched';
      statusLabel = t('contacts.import.status.yourField', 'Your field');
    } else if (fixedColumns.has(target)) {
      status = 'fixed';
      statusLabel = t('contacts.import.status.fixed', 'Matched, tidied');
    } else if (column.suggestion?.target === target && column.suggestion.confidence === 'doubtful') {
      status = 'check';
      statusLabel = t('contacts.import.status.check', 'Check this match');
    }
    return {
      source: column.header !== '' ? column.header : t('contacts.import.column', 'Column {n}', { n: column.index + 1 }),
      example: column.empty ? t('contacts.import.emptyColumn', '(empty)') : column.examples[0],
      status,
      statusLabel,
      target: (
        <Select value={target} onValueChange={(v) => setMapping((m) => ({ ...m, [String(column.index)]: v }))}>
          <SelectTrigger aria-label={t('contacts.import.goesTo', 'Where {column} goes', { column: column.header })}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={SKIP}>{t('contacts.import.dontImport', 'Don’t import')}</SelectItem>
            {(upload?.targets ?? []).map((tg) => (
              <SelectItem key={tg.key} value={tg.key}>
                {targetLabel(tg.key, tg.label)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      ),
    };
  });

  const toImport = (summary?.create ?? 0) + (summary?.update ?? 0);
  const skipped = (summary?.rows ?? []).filter((r) => r.action === 'skip');
  const stepNames: [Step, string][] = [
    ['file', t('contacts.import.step.file', 'Choose a file')],
    ['match', t('contacts.import.step.match', 'Match and check')],
    ['done', t('contacts.import.step.done', 'Import')],
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Link href="/contacts" className="hover:underline">
            {t('contacts.title', 'Contacts')}
          </Link>
        }
        title={t('contacts.import.title', 'Import contacts')}
        description={t('contacts.import.description', 'From Excel or a CSV file. Nothing changes until you import, and you can undo the whole import for 24 hours.')}
      />

      <ol className="flex flex-wrap gap-6 text-sm">
        {stepNames.map(([id, label], i) => (
          <li key={id} className={id === step ? 'font-semibold text-primary' : 'text-muted-foreground'} aria-current={id === step ? 'step' : undefined}>
            <span className="me-2 inline-flex size-6 items-center justify-center rounded-full border border-current font-mono text-xs">{i + 1}</span>
            {label}
          </li>
        ))}
      </ol>

      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      {step === 'file' && (
        <section className="max-w-xl space-y-4 rounded-lg border border-border p-5">
          <Input
            id="import-file"
            type="file"
            accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            label={t('contacts.import.file', 'Spreadsheet')}
            helperText={t('contacts.import.fileHint', 'Excel (.xlsx) or CSV, up to 5 MB and 5,000 rows. Whity finds the header row.')}
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
          <div className="flex justify-end">
            <Button onClick={() => void read()} disabled={file === null} loading={busy} data-testid="import-read">
              {t('contacts.import.read', 'Read the file')}
            </Button>
          </div>
        </section>
      )}

      {step === 'match' && upload && (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
          <section className="space-y-4 rounded-lg border border-border p-5">
            <div>
              <h2 className="font-semibold">{t('contacts.import.match.title', 'Match the columns')}</h2>
              <p className="text-sm text-muted-foreground">
                {t('contacts.import.match.subtitle', '{file} · {rows} rows', { file: upload.file_name, rows: upload.row_count })}
              </p>
            </div>
            <ColumnMatcher
              rows={rows}
              labels={{
                file: t('contacts.import.inFile', 'In your file'),
                whity: t('contacts.import.inWhity', 'In Whity'),
                status: t('contacts.import.status', 'Status'),
                goesTo: t('contacts.import.goesToShort', 'goes to'),
              }}
            />
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="space-y-1.5">
                <label htmlFor="import-match-by" className="text-sm font-medium">
                  {t('contacts.import.matchBy', 'Find existing contacts by')}
                </label>
                <Select value={matchBy} onValueChange={setMatchBy}>
                  <SelectTrigger id="import-match-by">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="auto">{t('contacts.import.matchBy.auto', 'Tax number, then mobile, then email')}</SelectItem>
                    <SelectItem value="tax_number">{t('contacts.field.taxNumber', 'Tax number')}</SelectItem>
                    <SelectItem value="mobile">{t('contacts.field.mobile', 'Mobile')}</SelectItem>
                    <SelectItem value="email">{t('contacts.field.email', 'Email')}</SelectItem>
                    <SelectItem value="name">{t('contacts.field.name', 'Name')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Input
                id="import-country"
                dir="ltr"
                label={t('contacts.import.country', 'Country code for local numbers')}
                placeholder="+962"
                value={country}
                onChange={(e) => setCountry(e.target.value)}
              />
              <div className="space-y-1.5">
                <label htmlFor="import-kind" className="text-sm font-medium">
                  {t('contacts.import.kind', 'Rows without a type are')}
                </label>
                <Select value={defaultKind} onValueChange={(v) => setDefaultKind(v as 'customer' | 'supplier' | 'both')}>
                  <SelectTrigger id="import-kind">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="customer">{t('contacts.tab.customers', 'Customers')}</SelectItem>
                    <SelectItem value="supplier">{t('contacts.tab.suppliers', 'Suppliers')}</SelectItem>
                    <SelectItem value="both">{t('contacts.kind.both', 'Customer and supplier')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </section>

          <aside className="space-y-4">
            <section className="space-y-3 rounded-lg border border-border p-5" aria-live="polite" aria-busy={checking}>
              <h2 className="font-semibold">{t('contacts.import.what', 'What will happen')}</h2>
              {shownProblem ? (
                <p className="text-sm text-warning-foreground">{shownProblem}</p>
              ) : summary ? (
                <dl className="divide-y divide-border text-sm">
                  <div className="flex justify-between py-2">
                    <dt>{t('contacts.import.new', 'New contacts')}</dt>
                    <dd className="font-mono font-semibold">{summary.create}</dd>
                  </div>
                  <div className="flex justify-between py-2">
                    <dt>{t('contacts.import.updated', 'Updated')}</dt>
                    <dd className="font-mono font-semibold">{summary.update}</dd>
                  </div>
                  <div className="flex justify-between py-2">
                    <dt>{t('contacts.import.skippedCount', 'Skipped, with reasons')}</dt>
                    <dd className={(summary.skip ?? 0) > 0 ? 'font-mono font-semibold text-destructive' : 'font-mono font-semibold'}>{summary.skip}</dd>
                  </div>
                  {(summary.tags?.length ?? 0) > 0 && (
                    <div className="flex justify-between gap-3 py-2">
                      <dt>{t('contacts.import.tags', 'Tags')}</dt>
                      <dd className="text-end">{summary.tags?.join(', ')}</dd>
                    </div>
                  )}
                </dl>
              ) : (
                <p className="text-sm text-muted-foreground">{t('contacts.import.working', 'Working it out…')}</p>
              )}
              {skipped.length > 0 && checked && (
                <>
                  <ul className="max-h-40 space-y-1 overflow-y-auto text-xs text-muted-foreground">
                    {skipped.slice(0, 20).map((r) => (
                      <li key={r.row}>{t('contacts.import.skipRow', 'Row {row}: {reason}', { row: r.row, reason: r.reason ?? '' })}</li>
                    ))}
                  </ul>
                  <a href={contactsApi.skippedUrl(checked.id)} className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                    <Icon name="download" size={16} />
                    {t('contacts.import.downloadSkipped', 'Download the skipped rows')}
                  </a>
                </>
              )}
            </section>
            <Alert>
              <AlertDescription>
                {t('contacts.import.nothingChanges', 'Nothing changes until you import. You can undo the whole import for 24 hours.')}
              </AlertDescription>
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
                {t('contacts.import.back', 'Back')}
              </Button>
              <Button onClick={() => void run()} disabled={checked === null || needsName || toImport === 0 || checking} loading={busy} data-testid="import-run">
                <Icon name="upload" />
                {t('contacts.import.run', 'Import {count} contacts', { count: toImport })}
              </Button>
            </div>
          </aside>
        </div>
      )}

      {step === 'done' && checked && (
        <section className="max-w-xl space-y-4 rounded-lg border border-border p-5">
          {checked.status === 'undone' ? (
            <p>{t('contacts.import.undone', 'The import is undone. Contacts are as they were before it.')}</p>
          ) : (
            <p>
              {t('contacts.import.doneText', '{create} added and {update} updated.', {
                create: summary?.create ?? 0,
                update: summary?.update ?? 0,
              })}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            {checked.can_undo && (
              <Button variant="outline" onClick={() => void undo()} loading={busy} data-testid="import-undo">
                <Icon name="undo" />
                {t('contacts.import.undo', 'Undo this import')}
              </Button>
            )}
            <Button asChild>
              <Link href="/contacts">{t('contacts.import.toContacts', 'Go to contacts')}</Link>
            </Button>
          </div>
        </section>
      )}
    </div>
  );
}
