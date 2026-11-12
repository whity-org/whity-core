'use client';

/**
 * Settings › Fields (WHIT-955, design 3.3 A9, `TplCustomFields`): the
 * workspace's own fields on its records, without code. A tab per record type
 * (Contacts in core; a plugin's types appear when it registers them). The
 * list shows the record's built-in fields (marked) and the workspace's own,
 * with their kind and where they're used. Add a field is the main button and
 * opens the kinds as tiles.
 *
 * A new field is usable at once: on the record, in the list and its filters,
 * as `{{contact.<key>}}` in documents, and in imports.
 */

import { useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { fieldLabel } from '@/components/contacts/field-input';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { Switch } from '@whity/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { Textarea } from '@whity/ui/textarea';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  CONTACTS_READ,
  FIELDS_MANAGE,
  contactErrorText,
  customFieldsApi,
  type CustomField,
  type CustomFieldKind,
} from '@/lib/contacts/api';

/** The kinds this screen offers. A person field needs a people picker first. */
const KINDS: { kind: CustomFieldKind; icon: IconName }[] = [
  { kind: 'text', icon: 'text' },
  { kind: 'textarea', icon: 'page' },
  { kind: 'select', icon: 'list-bullet' },
  { kind: 'multiselect', icon: 'list-todo' },
  { kind: 'number', icon: 'money' },
  { kind: 'date', icon: 'calendar' },
  { kind: 'checkbox', icon: 'check' },
];

export default function FieldsSettingsPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canManage = has(FIELDS_MANAGE);
  const [recordType, setRecordType] = useState('contact');
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<CustomField | null>(null);

  const types = useFetch(() => customFieldsApi.recordTypes(), []);
  const list = useFetch(() => customFieldsApi.list(recordType, true), [recordType]);

  const kindName: Record<string, string> = {
    text: t('fields.kind.text', 'Short text'),
    textarea: t('fields.kind.textarea', 'Long text'),
    select: t('fields.kind.select', 'Choose one'),
    multiselect: t('fields.kind.multiselect', 'Choose several'),
    number: t('fields.kind.number', 'Amount'),
    date: t('fields.kind.date', 'Date'),
    checkbox: t('fields.kind.checkbox', 'Yes or no'),
    profile_ref: t('fields.kind.person', 'A person'),
  };

  if (!accessLoading && !canManage && !has(CONTACTS_READ)) {
    return <NoAccess area={t('fields.area', 'fields')} permission={FIELDS_MANAGE} />;
  }

  const builtIn =
    recordType === 'contact'
      ? [
          t('contacts.field.name', 'Name'),
          t('contacts.field.contactPerson', 'Contact person'),
          t('contacts.field.mobile', 'Mobile'),
          t('contacts.field.email', 'Email'),
          t('contacts.field.taxNumber', 'Tax number'),
          t('contacts.field.paymentTerms', 'How they pay'),
          t('contacts.field.address', 'Address'),
        ]
      : [];
  const typeLabel = (key: string) => {
    const type = types.data?.find((ty) => ty.key === key);
    if (!type) return key;
    return language === 'ar' && type.label_ar !== '' ? type.label_ar : type.label;
  };

  const archive = async (field: CustomField, archived: boolean) => {
    try {
      await (archived ? customFieldsApi.archive(field.id) : customFieldsApi.restore(field.id));
      list.refetch();
      toast.addToast(
        archived
          ? t('fields.archived', '{name} is archived. Its values are kept.', { name: fieldLabel(field, language) })
          : t('fields.restored', '{name} is back.', { name: fieldLabel(field, language) }),
        'success'
      );
    } catch (e) {
      toast.addToast(contactErrorText(e), 'error');
    }
  };

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('fields.title', 'Fields on {type}', { type: typeLabel(recordType) })}
        description={t('fields.description', 'Add what your company needs to know. A new field works at once in lists, filters, documents and imports.')}
        action={
          canManage ? (
            <Button onClick={() => setAdding(true)} data-testid="fields-add">
              <Icon name="plus" />
              {t('fields.add', 'Add a field')}
            </Button>
          ) : undefined
        }
      />

      {(types.data?.length ?? 0) > 1 && (
        <Tabs value={recordType} onValueChange={setRecordType}>
          <TabsList>
            {types.data?.map((ty) => (
              <TabsTrigger key={ty.key} value={ty.key}>
                {typeLabel(ty.key)}
                <span className="ms-2 font-mono text-xs">{ty.count}</span>
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      )}

      {list.error ? (
        <LoadProblem forbidden={list.forbidden} message={t('fields.error.load', 'The fields could not be loaded.')} onRetry={list.refetch} area={t('fields.area', 'fields')} />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border">
          <table className="w-full text-sm">
            <thead className="bg-muted text-start text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 text-start font-semibold">{t('fields.column.field', 'Field')}</th>
                <th className="px-4 py-2 text-start font-semibold">{t('fields.column.kind', 'Kind')}</th>
                <th className="px-4 py-2 text-start font-semibold">{t('fields.column.used', 'Where it’s used')}</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {builtIn.map((name) => (
                <tr key={name} className="h-(--row-h)">
                  <td className="px-4 font-medium">
                    {name} <Badge variant="outline">{t('fields.builtIn', 'Built in')}</Badge>
                  </td>
                  <td className="px-4 text-muted-foreground" />
                  <td className="px-4 text-muted-foreground" />
                  <td className="px-4" />
                </tr>
              ))}
              {(list.data ?? []).map((field) => (
                <tr key={field.id} className={cn('h-(--row-h)', field.archived && 'text-muted-foreground')}>
                  <td className="px-4 font-medium">
                    {fieldLabel(field, language)}
                    {field.required && <Badge variant="secondary" className="ms-2">{t('fields.required', 'Required')}</Badge>}
                    {field.archived && <Badge variant="outline" className="ms-2">{t('fields.archivedBadge', 'Archived')}</Badge>}
                    <code dir="ltr" className="ms-2 font-mono text-xs text-muted-foreground">
                      {field.merge_field}
                    </code>
                  </td>
                  <td className="px-4">{kindName[field.kind] ?? field.kind}</td>
                  <td className="px-4 text-muted-foreground">
                    {[
                      field.show_in_list ? t('fields.used.list', 'List and filters') : null,
                      t('fields.used.documents', 'Documents'),
                      t('fields.used.records', '{count} records', { count: field.used_by }),
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </td>
                  <td className="px-4 text-end">
                    {canManage && (
                      <div className="flex justify-end gap-2">
                        {!field.archived && (
                          <Button size="sm" variant="outline" onClick={() => setEditing(field)}>
                            {t('fields.change', 'Change')}
                          </Button>
                        )}
                        <Button size="sm" variant="outline" onClick={() => void archive(field, !field.archived)}>
                          {field.archived ? t('fields.restore', 'Restore') : t('fields.archive', 'Archive')}
                        </Button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list.data?.length === 0 && (
            <p className="border-t border-border px-4 py-6 text-sm text-muted-foreground">
              {t('fields.empty', 'No fields of your own yet. Add one for anything your company needs to know, such as a delivery window or a preferred branch.')}
            </p>
          )}
        </div>
      )}

      {(adding || editing !== null) && (
        <FieldDialog
          recordType={recordType}
          recordLabel={typeLabel(recordType)}
          field={editing}
          kindName={kindName}
          onClose={() => {
            setAdding(false);
            setEditing(null);
          }}
          onSaved={() => {
            setAdding(false);
            setEditing(null);
            list.refetch();
            types.refetch();
          }}
        />
      )}
    </div>
  );
}

function FieldDialog({
  recordType,
  recordLabel,
  field,
  kindName,
  onClose,
  onSaved,
}: {
  recordType: string;
  recordLabel: string;
  field: CustomField | null;
  kindName: Record<string, string>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [kind, setKind] = useState<CustomFieldKind>(field?.kind ?? 'text');
  const [nameEn, setNameEn] = useState(field?.label.en ?? '');
  const [nameAr, setNameAr] = useState(field?.label.ar ?? '');
  const [choices, setChoices] = useState((field?.options ?? []).join('\n'));
  const [showInList, setShowInList] = useState(field?.show_in_list ?? true);
  const [required, setRequired] = useState(field?.required ?? false);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const hasChoices = kind === 'select' || kind === 'multiselect';
  const key = (nameEn.trim() !== '' ? nameEn : 'field')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
  const preview = field?.merge_field ?? `{{${recordType.split('.').pop()}.${/^[a-z]/.test(key) ? key : `field_${key}`}}}`;

  const save = async () => {
    if (nameEn.trim() === '' && nameAr.trim() === '') {
      setProblem(t('fields.error.name', 'Give the field a name.'));
      return;
    }
    const options = choices
      .split(/\r?\n/)
      .map((c) => c.trim())
      .filter((c) => c !== '');
    if (hasChoices && options.length === 0) {
      setProblem(t('fields.error.choices', 'Add at least one choice, one per line.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      const body = {
        label: { en: nameEn.trim(), ar: nameAr.trim() },
        options: hasChoices ? options : undefined,
        show_in_list: showInList,
        required,
      };
      if (field === null) {
        await customFieldsApi.create({ ...body, record_type: recordType, kind });
      } else {
        await customFieldsApi.update(field.id, body);
      }
      onSaved();
    } catch (e) {
      setProblem(contactErrorText(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="field-dialog">
        <DialogHeader>
          <DialogTitle>
            {field === null ? t('fields.add.title', 'Add a field to {type}', { type: recordLabel }) : t('fields.change.title', 'Change a field')}
          </DialogTitle>
          <DialogDescription>
            {t('fields.add.description', 'Usable at once in lists, filters and imports, and in documents as {merge}.', { merge: preview })}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          {field === null ? (
            <div role="radiogroup" aria-label={t('fields.kind', 'Kind')} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {KINDS.map(({ kind: k, icon }) => (
                <button
                  key={k}
                  type="button"
                  role="radio"
                  aria-checked={kind === k}
                  onClick={() => setKind(k)}
                  className={cn(
                    'flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg border p-2 text-sm',
                    kind === k ? 'border-primary bg-primary/5 text-primary' : 'border-border hover:bg-muted'
                  )}
                >
                  <Icon name={icon} size={18} />
                  {kindName[k]}
                </button>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              {t('fields.kindFixed', 'Kind: {kind}. A field’s kind and key don’t change, so documents that use it keep working.', { kind: kindName[field.kind] ?? field.kind })}
            </p>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <Input id="field-name-en" label={t('fields.nameEn', 'Name in English')} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />
            <Input id="field-name-ar" dir="rtl" label={t('fields.nameAr', 'Name in Arabic')} value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
          </div>
          {hasChoices && (
            <div className="space-y-1.5">
              <label htmlFor="field-choices" className="text-sm font-medium">
                {t('fields.choices', 'Choices, one per line')}
              </label>
              <Textarea id="field-choices" value={choices} onChange={(e) => setChoices(e.target.value)} />
            </div>
          )}
          <label className="flex min-h-(--control-h) items-center justify-between gap-3 text-sm">
            {t('fields.showInList', 'Show in the list')}
            <Switch checked={showInList} onCheckedChange={setShowInList} />
          </label>
          <label className="flex min-h-(--control-h) items-center justify-between gap-3 text-sm">
            <span>
              {t('fields.requiredLabel', 'Required')}
              <span className="block text-xs text-muted-foreground">{t('fields.requiredHint', 'New records can’t be saved without it.')}</span>
            </span>
            <Switch checked={required} onCheckedChange={setRequired} />
          </label>
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="field-save">
              {field === null ? t('fields.add.submit', 'Add the field') : t('fields.change.submit', 'Save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
