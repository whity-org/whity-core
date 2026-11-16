'use client';

import { useState } from 'react';
import { FormsListScreen, type FormsNewRequest } from '@whity/features/forms';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFormsEnv } from '@/lib/forms-env';
import { useNewIntent } from '@/lib/commands/new-intent';

/** The form builder list: `/admin/x/forms-builder`. */
export default function FormsBuilderPage() {
  const env = useFormsEnv();
  const { hasPermission } = useCapabilities();
  // ⌘K `/new form <name>` (WHIT-1069) opens New form with the name typed.
  const [newRequest, setNewRequest] = useState<FormsNewRequest | null>(null);
  useNewIntent('form', ({ text }) => setNewRequest((was) => ({ id: (was?.id ?? 0) + 1, name: text })));

  return <FormsListScreen env={env} canManage={hasPermission('forms:manage')} newRequest={newRequest} />;
}
