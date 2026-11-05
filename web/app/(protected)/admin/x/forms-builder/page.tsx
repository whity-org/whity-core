'use client';

import { FormsListScreen } from '@whity/features/forms';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFormsEnv } from '@/lib/forms-env';

/** The form builder list: `/admin/x/forms-builder`. */
export default function FormsBuilderPage() {
  const env = useFormsEnv();
  const { hasPermission } = useCapabilities();

  return <FormsListScreen env={env} canManage={hasPermission('forms:manage')} />;
}
