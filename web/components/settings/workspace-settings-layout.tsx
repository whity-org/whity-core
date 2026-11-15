'use client';

/**
 * Layout for the workspace pages that used to be the sidebar's Workspace group
 * (Billing, Plugins, Audit log, Notification log; WHIT-1082). For a workspace
 * they open inside the Settings area; the system tenant keeps them in its
 * Platform group, so there they render on their own as before.
 */

import type { ReactNode } from 'react';
import { useAuth } from '@/lib/auth-context';
import { SettingsArea } from '@/components/settings/settings-area';

export function WorkspaceSettingsLayout({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  if (user?.tenant_id === 0) return <>{children}</>;
  return <SettingsArea>{children}</SettingsArea>;
}
