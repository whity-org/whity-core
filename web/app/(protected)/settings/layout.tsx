import { SettingsArea } from '@/components/settings/settings-area';

/** Your own settings live in the one Settings area, above the workspace's (WHIT-1081). */
export default function MySettingsLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
