'use client';

/**
 * /contacts/[id] (WHIT-955, `TplContacts` record): a customer or supplier.
 * WhatsApp, Call and Email open the person's own apps; the details; Your
 * fields, with a link to add one for people who manage fields; and the
 * person in Whity the contact is linked to. Change is the main button;
 * archiving can be undone, so it asks nothing and offers Restore.
 *
 * Money (owes us, this year) and New invoice come with Front Office.
 */

import { use, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { ContactFormDialog } from '@/components/contacts/contact-form-dialog';
import { fieldLabel, isEditableHere } from '@/components/contacts/field-input';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  CONTACTS_READ,
  CONTACTS_WRITE,
  FIELDS_MANAGE,
  contactErrorText,
  contactsApi,
  customFieldsApi,
  fieldText,
  type Contact,
} from '@/lib/contacts/api';

function digits(phone: string): string {
  return phone.replace(/[^\d]/g, '');
}

export default function ContactPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const contactId = Number(id);
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const toast = useToast();
  const { has, loading: accessLoading } = useCapabilities();
  const canWrite = has(CONTACTS_WRITE);
  const canManageFields = has(FIELDS_MANAGE);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const [changed, setChanged] = useState<Contact | null>(null);
  const { data, loading, error, forbidden, refetch } = useFetch(async () => {
    const [contact, fields] = await Promise.all([contactsApi.get(contactId), customFieldsApi.list('contact')]);
    return { contact, fields };
  }, [contactId]);

  if (!accessLoading && !has(CONTACTS_READ)) {
    return <NoAccess area={t('contacts.area', 'contacts')} permission={CONTACTS_READ} />;
  }
  if (error) {
    return (
      <LoadProblem
        forbidden={forbidden}
        message={t('contacts.error.loadOne', 'The contact could not be loaded. It may have been removed.')}
        onRetry={refetch}
        area={t('contacts.area', 'contacts')}
      />
    );
  }
  if (loading || !data) {
    return <div className="h-40 animate-pulse rounded-lg bg-muted" aria-busy="true" />;
  }

  const contact = changed !== null && changed.id === data.contact.id ? changed : data.contact;
  const values = (contact.fields as Record<string, unknown> | undefined) ?? {};
  const fields = data.fields.filter(isEditableHere);
  const kind =
    contact.is_customer && contact.is_supplier
      ? t('contacts.kind.both', 'Customer and supplier')
      : contact.is_supplier
        ? t('contacts.kind.supplier', 'Supplier')
        : t('contacts.kind.customer', 'Customer');

  const setContact = (next: Contact) => setChanged(next);

  const archive = async (archived: boolean) => {
    setBusy(true);
    setProblem(null);
    try {
      const next = archived ? await contactsApi.archive(contact.id) : await contactsApi.restore(contact.id);
      setContact(next);
      toast.addToast(
        archived ? t('contacts.archived', '{name} is archived.', { name: contact.name }) : t('contacts.restored', '{name} is back.', { name: contact.name }),
        'success'
      );
    } catch (e) {
      setProblem(contactErrorText(e));
    } finally {
      setBusy(false);
    }
  };

  const details: { label: string; value: string; mono?: boolean }[] = [
    { label: t('contacts.field.contactPerson', 'Contact person'), value: contact.contact_person },
    { label: t('contacts.field.mobile', 'Mobile'), value: contact.mobile, mono: true },
    { label: t('contacts.field.phone', 'Phone'), value: contact.phone, mono: true },
    { label: t('contacts.field.email', 'Email'), value: contact.email, mono: true },
    { label: t('contacts.field.taxNumber', 'Tax number'), value: contact.tax_number, mono: true },
    { label: t('contacts.field.paymentTerms', 'How they pay'), value: contact.payment_terms },
    { label: t('contacts.field.address', 'Address'), value: contact.address },
  ].filter((d) => d.value !== '');

  return (
    <div className="space-y-6">
      <AdminHeader
        breadcrumb={
          <Link href="/contacts" className="hover:underline">
            {t('contacts.title', 'Contacts')}
          </Link>
        }
        title={contact.name}
        description={kind}
        action={
          canWrite && !contact.archived ? (
            <Button onClick={() => setEditing(true)} data-testid="contact-edit">
              <Icon name="edit" />
              {t('contacts.edit', 'Change')}
            </Button>
          ) : undefined
        }
      />

      {contact.archived && (
        <Alert>
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            {t('contacts.archived.notice', 'This contact is archived. It is kept, but left out of lists.')}
            {canWrite && (
              <Button variant="outline" loading={busy} onClick={() => void archive(false)}>
                <Icon name="undo" />
                {t('contacts.restore', 'Restore')}
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}
      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Avatar name={contact.name} pattern={`contact-${contact.id}`} size={48} />
        {contact.mobile !== '' && (
          <Button variant="outline" asChild>
            <a href={`https://wa.me/${digits(contact.mobile)}`} target="_blank" rel="noreferrer">
              <Icon name="whatsapp" />
              {t('contacts.whatsapp', 'WhatsApp')}
            </a>
          </Button>
        )}
        {(contact.mobile !== '' || contact.phone !== '') && (
          <Button variant="outline" asChild>
            <a href={`tel:${contact.mobile !== '' ? contact.mobile : contact.phone}`}>
              <Icon name="phone" />
              {t('contacts.call', 'Call')}
            </a>
          </Button>
        )}
        {contact.email !== '' && (
          <Button variant="outline" asChild>
            <a href={`mailto:${contact.email}`}>
              <Icon name="mail" />
              {t('contacts.email', 'Email')}
            </a>
          </Button>
        )}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <section className="space-y-3 rounded-lg border border-border p-5">
          <h2 className="text-sm font-semibold text-muted-foreground">{t('contacts.details', 'Details')}</h2>
          {details.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('contacts.details.none', 'No details yet.')}</p>
          ) : (
            <dl className="divide-y divide-border">
              {details.map((d) => (
                <div key={d.label} className="grid grid-cols-[10rem_1fr] gap-3 py-2.5 text-sm">
                  <dt className="text-muted-foreground">{d.label}</dt>
                  <dd className={d.mono ? 'font-mono' : undefined} dir={d.mono ? 'ltr' : undefined}>
                    {d.value}
                  </dd>
                </div>
              ))}
            </dl>
          )}
          {contact.tags.length > 0 && (
            <div className="flex flex-wrap gap-1 pt-2">
              {contact.tags.map((tag) => (
                <Badge key={tag.id} variant="secondary">
                  {tag.name}
                </Badge>
              ))}
            </div>
          )}
          {contact.profile && (
            <p className="flex items-center gap-2 pt-2 text-sm">
              <Avatar name={contact.profile.display_name ?? ''} pattern={String(contact.profile.id ?? '')} size={24} />
              {t('contacts.linked', 'Linked to {name} in Whity', { name: contact.profile.display_name ?? '' })}
            </p>
          )}
          {contact.notes !== '' && <p className="whitespace-pre-wrap pt-2 text-sm">{contact.notes}</p>}
        </section>

        <section className="space-y-3 rounded-lg border border-border p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
            <Icon name="field" size={16} />
            {t('contacts.yourFields', 'Your fields')}
          </h2>
          {fields.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('contacts.yourFields.none', 'Your workspace has no fields of its own on contacts yet.')}
            </p>
          ) : (
            <dl className="divide-y divide-border">
              {fields.map((field) => (
                <div key={field.key} className="grid grid-cols-[10rem_1fr] gap-3 py-2.5 text-sm">
                  <dt className="text-muted-foreground">{fieldLabel(field, language)}</dt>
                  <dd>{fieldText(field, values[field.key], t('contacts.yes', 'Yes'), t('contacts.no', 'No')) || '–'}</dd>
                </div>
              ))}
            </dl>
          )}
          {canManageFields && (
            <Link href="/admin/settings/fields" className="inline-block text-sm text-primary hover:underline">
              {t('contacts.yourFields.add', 'Add a field to all contacts')}
            </Link>
          )}
        </section>
      </div>

      {canWrite && !contact.archived && (
        <div className="border-t border-border pt-4">
          <Button variant="outline" loading={busy} onClick={() => void archive(true)} data-testid="contact-archive">
            {t('contacts.archive', 'Archive')}
          </Button>
        </div>
      )}

      {editing && (
        <ContactFormDialog
          open={editing}
          onOpenChange={setEditing}
          contact={contact}
          fields={data.fields}
          onSaved={(next) => {
            setContact(next);
            setEditing(false);
            toast.addToast(t('contacts.saved', 'Saved.'), 'success');
          }}
        />
      )}
    </div>
  );
}
