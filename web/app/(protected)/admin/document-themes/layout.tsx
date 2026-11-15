import { SettingsArea } from '@/components/settings/settings-area';

/** Document themes are a workspace setting (WHIT-1082); they had no link anywhere before. */
export default function DocumentThemesLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <SettingsArea>{children}</SettingsArea>;
}
