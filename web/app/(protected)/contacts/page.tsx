'use client';

/**
 * /contacts (WHIT-955, design 3.3 A8, `TplContacts`): customers and suppliers
 * in one place. A list page (section 21): Everyone, Customers and Suppliers,
 * a tag filter, search, and the workspace's own fields that show in the list
 * (as columns, and as a filter for choose-one fields). Import is the outline
 * button; New contact is the one main button.
 *
 * The balance and branch columns in the card come with Front Office, which
 * holds the money; core has no balances to show.
 */

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { ContactFormDialog } from '@/components/contacts/contact-form-dialog';
import { fieldLabel, isEditableHere } from '@/components/contacts/field-input';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { EmptyFiltered } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  CONTACTS_IMPORT,
  CONTACTS_READ,
  CONTACTS_WRITE,
  contactsApi,
  customFieldsApi,
  fieldText,
  type Contact,
  type CustomField,
} from '@/lib/contacts/api';

type Kind = 'all' | 'customer' | 'supplier';
const PER_PAGE = 50;

export default function ContactsPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const router = useRouter();
  const { has, loading: accessLoading } = useCapabilities();
  const canRead = has(CONTACTS_READ);
  const canWrite = has(CONTACTS_WRITE);
  const canImport = has(CONTACTS_IMPORT);

  const [kind, setKind] = useState<Kind>('all');
  const [tagId, setTagId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [fieldFilter, setFieldFilter] = useState<{ key: string; value: string } | null>(null);
  const [page, setPage] = useState(1);
  const [adding, setAdding] = useState(false);

  // Search waits for a pause in typing, then asks the server.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(id);
  }, [search]);

  const meta = useFetch(async () => {
    const [tags, fields, everyone, customers, suppliers] = await Promise.all([
      contactsApi.tags(),
      customFieldsApi.list('contact'),
      contactsApi.list({ perPage: 1 }),
      contactsApi.list({ kind: 'customer', perPage: 1 }),
      contactsApi.list({ kind: 'supplier', perPage: 1 }),
    ]);
    return {
      tags,
      fields,
      counts: {
        all: everyone.pagination.total,
        customer: customers.pagination.total,
        supplier: suppliers.pagination.total,
      },
    };
  }, []);

  const list = useFetch(
    () =>
      contactsApi.list({
        kind: kind === 'all' ? null : kind,
        tagId,
        q: query,
        page,
        perPage: PER_PAGE,
        fields: fieldFilter ? { [fieldFilter.key]: fieldFilter.value } : undefined,
      }),
    [kind, tagId, query, page, fieldFilter?.key, fieldFilter?.value]
  );

  const fields = useMemo(() => (meta.data?.fields ?? []).filter(isEditableHere), [meta.data]);
  const listFields = fields.filter((f) => f.show_in_list);
  const filterField = fields.find((f) => f.show_in_list && f.kind === 'select') ?? null;

  if (!accessLoading && !canRead) {
    return <NoAccess area={t('contacts.area', 'contacts')} permission={CONTACTS_READ} />;
  }

  const kindWord = (c: Contact) =>
    c.is_customer && c.is_supplier
      ? t('contacts.kind.both', 'Customer and supplier')
      : c.is_supplier
        ? t('contacts.kind.supplier', 'Supplier')
        : t('contacts.kind.customer', 'Customer');

  const columns: DataTableColumn<Contact>[] = [
    {
      id: 'name',
      header: t('contacts.column.name', 'Name'),
      cell: (row) => (
        <div className="flex items-center gap-3">
          <Avatar name={row.name} pattern={`contact-${row.id}`} size={32} />
          <div className="min-w-0">
            <div className="truncate font-medium">{row.name}</div>
            <div className="text-xs text-muted-foreground">{kindWord(row)}</div>
          </div>
        </div>
      ),
    },
    {
      id: 'tags',
      header: t('contacts.column.tags', 'Tags'),
      cell: (row) => (
        <div className="flex flex-wrap gap-1">
          {row.tags.map((tag) => (
            <Badge key={tag.id} variant="secondary">
              {tag.name}
            </Badge>
          ))}
        </div>
      ),
    },
    {
      id: 'mobile',
      header: t('contacts.column.mobile', 'Mobile'),
      cell: (row) => (
        <span dir="ltr" className="font-mono text-sm">
          {row.mobile}
        </span>
      ),
    },
    ...listFields.map(
      (field): DataTableColumn<Contact> => ({
        id: `field-${field.key}`,
        header: fieldLabel(field, language),
        cell: (row) =>
          fieldText(
            field,
            (row.fields as Record<string, unknown> | undefined)?.[field.key],
            t('contacts.yes', 'Yes'),
            t('contacts.no', 'No')
          ),
      })
    ),
  ];

  const filtered = kind !== 'all' || tagId !== null || query !== '' || fieldFilter !== null;
  const counts = meta.data?.counts;
  const pagination = list.data?.pagination;

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('contacts.title', 'Contacts')}
        description={t('contacts.description', 'Customers and suppliers in one place')}
        action={
          <div className="flex gap-2">
            {canImport && (
              <Button variant="outline" asChild>
                <Link href="/contacts/import" data-testid="contacts-import">
                  <Icon name="upload" />
                  {t('contacts.import', 'Import')}
                </Link>
              </Button>
            )}
            {canWrite && (
              <Button onClick={() => setAdding(true)} data-testid="contacts-new">
                <Icon name="plus" />
                {t('contacts.new', 'New contact')}
              </Button>
            )}
          </div>
        }
      />

      <Tabs
        value={kind}
        onValueChange={(v) => {
          setKind(v as Kind);
          setPage(1);
        }}
      >
        <TabsList>
          <TabsTrigger value="all">
            {t('contacts.tab.everyone', 'Everyone')}
            {counts && <span className="ms-2 font-mono text-xs">{counts.all}</span>}
          </TabsTrigger>
          <TabsTrigger value="customer">
            {t('contacts.tab.customers', 'Customers')}
            {counts && <span className="ms-2 font-mono text-xs">{counts.customer}</span>}
          </TabsTrigger>
          <TabsTrigger value="supplier">
            {t('contacts.tab.suppliers', 'Suppliers')}
            {counts && <span className="ms-2 font-mono text-xs">{counts.supplier}</span>}
          </TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full max-w-sm">
          <SearchField
            value={search}
            onValueChange={setSearch}
            placeholder={t('contacts.search', 'Search by name, mobile, email or tax number')}
            label={t('contacts.search.label', 'Search contacts')}
            clearLabel={t('contacts.search.clear', 'Clear search')}
          />
        </div>
        {(meta.data?.tags.length ?? 0) > 0 && (
          <div className="w-48">
            <Select
              value={tagId === null ? 'any' : String(tagId)}
              onValueChange={(v) => {
                setTagId(v === 'any' ? null : Number(v));
                setPage(1);
              }}
            >
              <SelectTrigger aria-label={t('contacts.filter.tag', 'Tag')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="any">{t('contacts.filter.tagAny', 'Tag: any')}</SelectItem>
                {meta.data?.tags.map((tag) => (
                  <SelectItem key={tag.id} value={String(tag.id)}>
                    {tag.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
        {filterField && (
          <FieldFilter
            field={filterField}
            label={fieldLabel(filterField, language)}
            anyLabel={t('contacts.filter.any', 'Any')}
            value={fieldFilter?.key === filterField.key ? fieldFilter.value : ''}
            onChange={(value) => {
              setFieldFilter(value === '' ? null : { key: filterField.key, value });
              setPage(1);
            }}
          />
        )}
      </div>

      {list.error ? (
        <LoadProblem
          forbidden={list.forbidden}
          message={t('contacts.error.load', 'The contacts could not be loaded.')}
          onRetry={list.refetch}
          area={t('contacts.area', 'contacts')}
        />
      ) : filtered && list.data && list.data.data.length === 0 ? (
        <EmptyFiltered
          title={t('contacts.filtered', 'No contact matches these filters')}
          clearLabel={t('contacts.filtered.clear', 'Clear filters')}
          onClear={() => {
            setKind('all');
            setTagId(null);
            setSearch('');
            setFieldFilter(null);
          }}
        />
      ) : (
        <DataTable
          columns={columns}
          data={list.data?.data ?? []}
          isLoading={list.loading && list.data === null}
          getRowId={(row) => String(row.id)}
          onRowClick={(row) => router.push(`/contacts/${row.id}`)}
          pagination={
            pagination
              ? {
                  pageIndex: pagination.page - 1,
                  pageSize: pagination.perPage,
                  pageCount: Math.max(1, pagination.totalPages),
                  total: pagination.total,
                  onPaginationChange: (index) => setPage(index + 1),
                }
              : undefined
          }
          emptyState={{
            title: t('contacts.empty.title', 'No contacts yet'),
            description: canWrite
              ? t('contacts.empty.write', 'Add your customers and suppliers, or import them from a spreadsheet.')
              : t('contacts.empty.read', 'Someone who keeps contacts adds them here.'),
          }}
        />
      )}

      {adding && (
        <ContactFormDialog
          open={adding}
          onOpenChange={setAdding}
          contact={null}
          fields={meta.data?.fields ?? []}
          onSaved={(contact) => router.push(`/contacts/${contact.id}`)}
        />
      )}
    </div>
  );
}

function FieldFilter({
  field,
  label,
  anyLabel,
  value,
  onChange,
}: {
  field: CustomField;
  label: string;
  anyLabel: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="w-48">
      <Select value={value === '' ? '__any' : value} onValueChange={(v) => onChange(v === '__any' ? '' : v)}>
        <SelectTrigger aria-label={label}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="__any">
            {label}: {anyLabel}
          </SelectItem>
          {field.options.map((option) => (
            <SelectItem key={option} value={option}>
              {label}: {option}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
