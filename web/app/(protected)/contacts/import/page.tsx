'use client';

/**
 * /contacts/import (WHIT-955, design 3.3 A8): bring customers and suppliers
 * in from Excel or CSV, on the shared import screen (WHIT-1326, design
 * section 34 §6.5).
 */

import Link from 'next/link';
import { useTranslation } from '@whity/features/i18n';
import { ImportScreen } from '@/components/import/import-screen';

export default function ContactImportPage() {
  const t = useTranslation('admin');
  return (
    <ImportScreen
      area="contacts"
      kind="contacts"
      breadcrumb={
        <Link href="/contacts" className="hover:underline">
          {t('contacts.title', 'Contacts')}
        </Link>
      }
    />
  );
}
