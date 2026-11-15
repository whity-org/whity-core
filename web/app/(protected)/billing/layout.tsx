import { WorkspaceSettingsLayout } from '@/components/settings/workspace-settings-layout';

/** Inside the Settings area for a workspace (WHIT-1082); Platform for the system tenant. */
export default function Layout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <WorkspaceSettingsLayout>{children}</WorkspaceSettingsLayout>;
}
