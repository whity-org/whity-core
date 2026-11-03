'use client';

import { FormsCatalogScreen } from '@whity/features/forms';
import { useFormsEnv } from '@/lib/forms-env';

/** The forms a person can fill in: `/admin/x/forms-catalog`. */
export default function FormsCatalogPage() {
  return <FormsCatalogScreen env={useFormsEnv()} />;
}
