'use client';

/**
 * Adding or changing a customer or supplier (WHIT-953, `TplContacts`): name,
 * customer and/or supplier, contact details, tags, and the workspace's own
 * fields (WHIT-954). One main button.
 */

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import {
  contactErrorText,
  contactsApi,
  type Contact,
  type ContactRequest,
  type CustomField,
} from '@/lib/contacts/api';
import { FieldInput, isEditableHere } from './field-input';

type Draft = {
  name: string;
  contact_person: string;
  email: string;
  mobile: string;
  phone: string;
  tax_number: string;
  payment_terms: string;
  address: string;
  notes: string;
  is_customer: boolean;
  is_supplier: boolean;
  tags: string;
  fields: Record<string, unknown>;
};

function draftOf(contact: Contact | null): Draft {
  return {
    name: contact?.name ?? '',
    contact_person: contact?.contact_person ?? '',
    email: contact?.email ?? '',
    mobile: contact?.mobile ?? '',
    phone: contact?.phone ?? '',
    tax_number: contact?.tax_number ?? '',
    payment_terms: contact?.payment_terms ?? '',
    address: contact?.address ?? '',
    notes: contact?.notes ?? '',
    is_customer: contact?.is_customer ?? true,
    is_supplier: contact?.is_supplier ?? false,
    tags: (contact?.tags ?? []).map((tag) => tag.name).join(', '),
    fields: { ...((contact?.fields as Record<string, unknown> | undefined) ?? {}) },
  };
}

export function ContactFormDialog({
  open,
  onOpenChange,
  contact,
  fields,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The contact to change, or null to add one. */
  contact: Contact | null;
  fields: CustomField[];
  onSaved: (contact: Contact) => void;
}) {
  const t = useTranslation('admin');
  const [draft, setDraft] = useState<Draft>(() => draftOf(contact));
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const editable = fields.filter(isEditableHere);

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

  const save = async () => {
    if (draft.name.trim() === '') {
      setProblem(t('contacts.error.name', 'Give the contact a name.'));
      return;
    }
    if (!draft.is_customer && !draft.is_supplier) {
      setProblem(t('contacts.error.kind', 'A contact is a customer, a supplier, or both.'));
      return;
    }
    const before = (contact?.fields as Record<string, unknown> | undefined) ?? {};
    const changedFields: Record<string, unknown> = {};
    for (const field of editable) {
      const value = draft.fields[field.key];
      const empty = value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
      if (contact === null) {
        if (!empty) changedFields[field.key] = value;
      } else if (JSON.stringify(value ?? null) !== JSON.stringify(before[field.key] ?? null)) {
        changedFields[field.key] = empty ? null : value;
      }
    }
    const body: ContactRequest = {
      name: draft.name.trim(),
      contact_person: draft.contact_person,
      email: draft.email,
      mobile: draft.mobile,
      phone: draft.phone,
      tax_number: draft.tax_number,
      payment_terms: draft.payment_terms,
      address: draft.address,
      notes: draft.notes,
      is_customer: draft.is_customer,
      is_supplier: draft.is_supplier,
      tags: draft.tags
        .split(/[,،]/)
        .map((tag) => tag.trim())
        .filter((tag) => tag !== ''),
      fields: changedFields,
    };
    setSaving(true);
    setProblem(null);
    try {
      onSaved(contact === null ? await contactsApi.create(body) : await contactsApi.update(contact.id, body));
    } catch (error) {
      setProblem(contactErrorText(error));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="contact-form">
        <DialogHeader>
          <DialogTitle>
            {contact === null ? t('contacts.new', 'New contact') : t('contacts.edit.title', 'Change {name}', { name: contact.name })}
          </DialogTitle>
          <DialogDescription>
            {t('contacts.form.description', 'Customers and suppliers in one place. A contact can be both.')}
          </DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <Input id="contact-name" label={t('contacts.field.name', 'Name')} value={draft.name} onChange={(e) => set('name', e.target.value)} />
          <div className="flex flex-wrap gap-6">
            <label className="flex min-h-(--control-h) items-center gap-3 text-sm">
              <Switch checked={draft.is_customer} onCheckedChange={(c) => set('is_customer', c)} />
              {t('contacts.kind.customer', 'Customer')}
            </label>
            <label className="flex min-h-(--control-h) items-center gap-3 text-sm">
              <Switch checked={draft.is_supplier} onCheckedChange={(c) => set('is_supplier', c)} />
              {t('contacts.kind.supplier', 'Supplier')}
            </label>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              id="contact-person"
              label={t('contacts.field.contactPerson', 'Contact person')}
              value={draft.contact_person}
              onChange={(e) => set('contact_person', e.target.value)}
            />
            <Input id="contact-email" type="email" dir="ltr" label={t('contacts.field.email', 'Email')} value={draft.email} onChange={(e) => set('email', e.target.value)} />
            <Input id="contact-mobile" dir="ltr" inputMode="tel" label={t('contacts.field.mobile', 'Mobile')} value={draft.mobile} onChange={(e) => set('mobile', e.target.value)} />
            <Input id="contact-phone" dir="ltr" inputMode="tel" label={t('contacts.field.phone', 'Phone')} value={draft.phone} onChange={(e) => set('phone', e.target.value)} />
            <Input
              id="contact-tax"
              dir="ltr"
              className="font-mono"
              label={t('contacts.field.taxNumber', 'Tax number')}
              value={draft.tax_number}
              onChange={(e) => set('tax_number', e.target.value)}
            />
            <Input
              id="contact-terms"
              label={t('contacts.field.paymentTerms', 'How they pay')}
              value={draft.payment_terms}
              onChange={(e) => set('payment_terms', e.target.value)}
            />
          </div>
          <Input id="contact-address" label={t('contacts.field.address', 'Address')} value={draft.address} onChange={(e) => set('address', e.target.value)} />
          <Input
            id="contact-tags"
            label={t('contacts.field.tags', 'Tags')}
            helperText={t('contacts.field.tagsHint', 'Separate tags with commas. New tags are made as you save.')}
            value={draft.tags}
            onChange={(e) => set('tags', e.target.value)}
          />
          <div className="space-y-1.5">
            <label htmlFor="contact-notes" className="text-sm font-medium">
              {t('contacts.field.notes', 'Notes')}
            </label>
            <Textarea id="contact-notes" value={draft.notes} onChange={(e) => set('notes', e.target.value)} />
          </div>
          {editable.length > 0 && (
            <section className="space-y-3 border-t border-border pt-4">
              <h3 className="text-sm font-semibold">{t('contacts.yourFields', 'Your fields')}</h3>
              {editable.map((field) => (
                <FieldInput
                  key={field.key}
                  field={field}
                  value={draft.fields[field.key]}
                  onChange={(value) => setDraft((d) => ({ ...d, fields: { ...d.fields, [field.key]: value } }))}
                />
              ))}
            </section>
          )}
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving} data-testid="contact-form-save">
              {contact === null ? t('contacts.new.submit', 'Add contact') : t('contacts.edit.submit', 'Save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
